<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Efficiency;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Reads and writes the extension-efficiency report as a single JSON file under
 * var/fastmagento/. The CLI scan writes it; the admin dashboard reads it. Keeping the
 * heavy profiling out of the web request means the admin page is always instant.
 */
class ReportStorage
{
    private const REPORT_PATH = 'fastmagento/efficiency-report.json';

    private readonly WriteInterface $varDir;

    public function __construct(
        Filesystem $filesystem,
        private readonly Json $json
    ) {
        $this->varDir = $filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    public function save(array $report): void
    {
        $this->varDir->writeFile(self::REPORT_PATH, $this->json->serialize($report));
    }

    public function load(): ?array
    {
        if (!$this->varDir->isExist(self::REPORT_PATH)) {
            return null;
        }
        try {
            $data = $this->json->unserialize($this->varDir->readFile(self::REPORT_PATH));
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function getAbsolutePath(): string
    {
        return $this->varDir->getAbsolutePath(self::REPORT_PATH);
    }
}
