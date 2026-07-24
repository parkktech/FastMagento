<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\Efficiency;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Lock\LockManagerInterface;
use ParkkTech\FastMagento\Model\Efficiency\Profiler;

/**
 * Kicks off an efficiency scan in the background (the same `fastmagento:efficiency:scan` CLI
 * command) so the admin doesn't block on the profiling run, then redirects back to the monitor.
 * POST-only (it mutates state), and refuses to start a second run while one is in flight.
 */
class Scan extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'ParkkTech_FastMagento::efficiency';

    public function __construct(
        Context $context,
        private readonly DirectoryList $directoryList,
        private readonly LockManagerInterface $lockManager
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($this->lockManager->isLocked(Profiler::LOCK_NAME)) {
            // A run is already in flight — show the in-progress/auto-refresh state on the monitor.
            $resultRedirect->setPath('*/*/index', ['started' => 1]);
            return $resultRedirect;
        }

        if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
            $this->messageManager->addWarningMessage(
                __('Background scans are disabled on this host. Run "bin/magento fastmagento:efficiency:scan" from the CLI.')
            );
            $resultRedirect->setPath('*/*/index');
            return $resultRedirect;
        }

        $root = $this->directoryList->getRoot();
        $log = $root . '/var/log/fastmagento-efficiency-scan.log';
        // Single ">" truncates the log each run, so it never grows unbounded across scans.
        $cmd = sprintf(
            'nohup php %s fastmagento:efficiency:scan --sample=50 > %s 2>&1 &',
            escapeshellarg($root . '/bin/magento'),
            escapeshellarg($log)
        );
        @shell_exec($cmd);

        $this->messageManager->addSuccessMessage(
            __('Efficiency scan started in the background. This page will refresh automatically as it runs. Progress: var/log/fastmagento-efficiency-scan.log')
        );

        // started=1 shows the "scan in progress" state and begins auto-refresh, covering the brief
        // window before the background run acquires the profiler lock.
        $resultRedirect->setPath('*/*/index', ['started' => 1]);
        return $resultRedirect;
    }
}
