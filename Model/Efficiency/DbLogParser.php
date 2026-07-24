<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

/**
 * Parses a Magento db_logger file (var/debug/db.log, written with include_stacktrace=1) into a
 * flat list of executed *queries*, each carrying its SQL, the primary table it hit, the
 * wall-clock time, and the PHP call stack (class + method per frame).
 *
 * The real record format {@see \Magento\Framework\DB\Logger\LoggerAbstract::getStats()} is:
 *
 *   ## <pid> ## QUERY
 *   SQL: SELECT ... FROM `foo`
 *   BIND: array(...)          (optional)
 *   AFF: 3                    (optional)
 *   TIME: 0.0021
 *   TRACE: #1 Webkul\Marketplace\Observer\Foo#..#->execute($observer) called at [.../Foo.php:40]
 *          #2 ...
 *
 * Only QUERY records are returned — CONNECT / TRANSACTION / COMMIT records are logged the same
 * way but are not queries and must not inflate per-operation counts. The TRACE frames are what
 * let {@see ModuleAttributor} blame a query on the third-party module that fired it.
 */
class DbLogParser
{
    /** Record header: "## <pid> ## <TYPE>" (TYPE = QUERY|CONNECT|TRANSACTION|…). */
    private const HEADER_RE = '/^## \d+ ## (\w+)/';

    /** A stack frame: "#<n> Class[\Real]#hash#->method(" — the object hash segment is optional (static calls). */
    private const FRAME_RE = '/^#\d+\s+([A-Za-z0-9_\\\\]+)(?:\[([A-Za-z0-9_\\\\]+)\])?(?:#[^#]*#)?(?:->|::)(\w+)\(/';

    /** First real table in a SQL statement. */
    private const TABLE_RE = '/(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z0-9_]+)`?/i';

    /** Lines that terminate multi-line SQL capture. */
    private const SQL_TERMINATORS = ['BIND:', 'AFF:', 'TIME:', 'TRACE:'];

    /**
     * @return array<int, array{sql:string, table:?string, time:float, frames:array<int, array{class:string, method:string}>}>
     */
    public function parse(string $logPath): array
    {
        if (!is_readable($logPath)) {
            return [];
        }
        $handle = fopen($logPath, 'rb');
        if ($handle === false) {
            return [];
        }

        $queries = [];
        $current = null;        // in-progress record
        $collectingSql = false;

        $flush = static function (?array $rec) use (&$queries): void {
            if ($rec !== null && $rec['type'] === 'QUERY') {
                unset($rec['type']);
                $queries[] = $rec;
            }
        };

        while (($line = fgets($handle)) !== false) {
            $line = rtrim($line, "\r\n");

            if (preg_match(self::HEADER_RE, $line, $m)) {
                $flush($current);
                $current = ['type' => strtoupper($m[1]), 'sql' => '', 'table' => null, 'time' => 0.0, 'frames' => []];
                $collectingSql = false;
                continue;
            }

            if ($current === null) {
                continue; // banner / preamble before the first record
            }

            if (str_starts_with($line, 'SQL:')) {
                $current['sql'] = trim(substr($line, 4));
                $collectingSql = true;
                continue;
            }

            if ($collectingSql) {
                if ($this->isSqlTerminator($line) || preg_match(self::FRAME_RE, $line) || str_starts_with($line, 'TRACE:')) {
                    $collectingSql = false;
                    $current['table'] = $this->extractTable($current['sql']);
                    // fall through so this same line is still handled below
                } else {
                    $current['sql'] .= ' ' . trim($line);
                    continue;
                }
            }

            if (str_starts_with($line, 'TIME:')) {
                $current['time'] = (float) trim(substr($line, 5));
                continue;
            }

            if (str_starts_with($line, 'TRACE:')) {
                $line = trim(substr($line, 6)); // the first frame may sit on the TRACE: line
            }

            if ($line !== '' && preg_match(self::FRAME_RE, $line, $fm)) {
                // Skip DI interceptor plumbing (___callPlugins / ___callParent / ___init) — it hides
                // the real method that issued the query.
                if (str_starts_with($fm[3], '___')) {
                    continue;
                }
                $class = $fm[2] !== '' ? $fm[2] : $fm[1];
                $current['frames'][] = ['class' => $this->normalizeClass($class), 'method' => $fm[3]];
            }
        }
        fclose($handle);
        $flush($current);

        return $queries;
    }

    private function isSqlTerminator(string $line): bool
    {
        foreach (self::SQL_TERMINATORS as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private function extractTable(string $sql): ?string
    {
        if (preg_match(self::TABLE_RE, $sql, $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    /** Drop the trailing \Interceptor segment DI generates so it maps to the source class. */
    private function normalizeClass(string $class): string
    {
        return preg_replace('/\\\\Interceptor$/', '', $class) ?? $class;
    }
}
