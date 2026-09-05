<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Model\OpenSearch;

/** Bound encoded request bytes; split transient failures and never swallow item errors. */
class BoundedBulkWriter
{
    public function __construct(
        private readonly int $maxBytes = 1048576,
        private readonly int $maxRetries = 3,
        private readonly int $retryDelayMicros = 100000
    ) {
        if ($maxBytes < 1024 || $maxRetries < 0 || $retryDelayMicros < 0) {
            throw new \InvalidArgumentException('Invalid OpenSearch bulk limits.');
        }
    }

    /** @param iterable<array{id:string|int,body:array}> $documents */
    public function write(object $client, string $index, iterable $documents): int
    {
        $batch = []; $bytes = 0; $count = 0;
        foreach ($documents as $document) {
            $record = json_encode(['index' => ['_index' => $index, '_id' => (string)$document['id']]], JSON_THROW_ON_ERROR) . "\n"
                . json_encode($document['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $length = strlen($record);
            if ($length > $this->maxBytes) {
                throw new \RuntimeException(sprintf('OpenSearch document %s/%s is %d bytes, above the %d-byte bulk limit. Reduce the document projection or explicitly configure a tested higher limit.', $index, $document['id'], $length, $this->maxBytes));
            }
            if ($batch && $bytes + $length > $this->maxBytes) {
                $this->send($client, $batch); $batch = []; $bytes = 0;
            }
            $batch[] = $record; $bytes += $length; $count++;
        }
        if ($batch) { $this->send($client, $batch); }
        return $count;
    }

    private function send(object $client, array $records, int $attempt = 0): void
    {
        try {
            $response = $client->bulk(['body' => implode('', $records)]);
        } catch (\Throwable $e) {
            $transient = in_array((int)$e->getCode(), [429, 502, 503, 504], true)
                || str_contains($e->getMessage(), 'circuit_breaking_exception')
                || str_contains($e->getMessage(), 'es_rejected_execution_exception');
            if (!$transient) { throw $e; }
            $this->retry($client, $records, $attempt, $e);
            return;
        }
        if (count($response['items'] ?? []) !== count($records)) {
            throw new \RuntimeException('OpenSearch returned an incomplete bulk response; the rebuild is not complete.');
        }
        $retry = [];
        foreach ($response['items'] as $position => $item) {
            $result = $item['index'] ?? [];
            $status = (int)($result['status'] ?? 0);
            if ($status >= 200 && $status < 300 && empty($result['error'])) { continue; }
            if (in_array($status, [429, 502, 503, 504], true)) { $retry[] = $records[$position]; continue; }
            throw new \RuntimeException('OpenSearch rejected document ' . ($result['_id'] ?? '?') . ': '
                . substr(json_encode($result['error'] ?? ['status' => $status]), 0, 1000));
        }
        if ($retry) {
            $this->retry($client, $retry, $attempt, new \RuntimeException('OpenSearch rejected bulk items with a transient status.'));
        }
    }

    private function retry(object $client, array $records, int $attempt, \Throwable $cause): void
    {
        if ($attempt >= $this->maxRetries) {
            throw new \RuntimeException('OpenSearch bulk still unavailable after bounded retries. The index must remain invalid; inspect cluster heap pressure and retry after recovery. ' . substr($cause->getMessage(), 0, 600), 0, $cause);
        }
        if ($this->retryDelayMicros) { usleep($this->retryDelayMicros * (2 ** $attempt)); }
        $chunks = count($records) > 1 ? array_chunk($records, (int)ceil(count($records) / 2)) : [$records];
        foreach ($chunks as $chunk) { $this->send($client, $chunk, $attempt + 1); }
    }
}
