<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Remembers which reported N+1 hotspots the developer has dismissed ("cleared"), so once a loop is
 * fixed (or accepted) it drops off the monitor and the list shows only what's left to work on.
 * Dismissals are keyed by a stable hash of the finding (extension · class::method · page · context),
 * so a fixed hotspot stays hidden across re-scans while any NEW loop still surfaces.
 */
class DismissStorage
{
    private const PATH = 'fastmagento/efficiency-dismissed.json';

    private readonly WriteInterface $varDir;

    public function __construct(
        Filesystem $filesystem,
        private readonly Json $json
    ) {
        $this->varDir = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /** Stable key for a finding — unchanged across scans so a dismissal sticks. */
    public static function key(array $finding): string
    {
        return substr(md5(implode('|', [
            $finding['vendor'] ?? '',
            $finding['class'] ?? '',
            $finding['method'] ?? '',
            $finding['page_key'] ?? '',
            $finding['context'] ?? 'guest',
        ])), 0, 16);
    }

    /** @return array<string,bool> */
    public function all(): array
    {
        if (!$this->varDir->isExist(self::PATH)) {
            return [];
        }
        try {
            $data = $this->json->unserialize($this->varDir->readFile(self::PATH));
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function isDismissed(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function dismiss(string $key): void
    {
        $key = preg_replace('/[^a-f0-9]/', '', $key);
        if ($key === '') {
            return;
        }
        $all = $this->all();
        $all[$key] = true;
        $this->varDir->writeFile(self::PATH, $this->json->serialize($all));
    }

    public function clearAll(): void
    {
        $this->varDir->writeFile(self::PATH, $this->json->serialize([]));
    }
}
