<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Helper;

use Psr\Log\LoggerInterface;

readonly class WriteLog
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * @param string $message
     * @return void
     */
    public function writeInfoLog(string $message): void {
        $this->logger->info('ParkkTech_FastMagento:: ' . $message);
    }

    /**
     * @param string $message
     * @return void
     */
    public function writeErrorLog(string $message): void {
        $this->logger->error('ParkkTech_FastMagento:: ' . $message);
    }
}
