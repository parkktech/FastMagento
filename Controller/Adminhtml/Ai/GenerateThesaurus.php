<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Controller\Adminhtml\Ai;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use ParkkTech\FastMagento\Model\Ai\ThesaurusGenerator;

/**
 * Admin AJAX endpoint (fastmagento/ai/generateThesaurus): builds a synonym thesaurus from the
 * store's own catalogue vocabulary via the Claude API and merges it into the Search > Synonyms
 * setting. Returns JSON for the button on the FastMagento config page.
 *
 * POST-only (state-changing + calls a paid API); form-key + ACL enforced by the admin router.
 */
class GenerateThesaurus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'ParkkTech_FastMagento::config';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ThesaurusGenerator $generator
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        // The external call can run tens of seconds; keep a constrained FPM
        // max_execution_time from killing it mid-request (front proxy read
        // timeouts are environment-specific — see docs).
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            $stats = $this->generator->generateAndImport();
            $message = sprintf(
                'Scanned %d catalogue terms. Added %d new synonym group(s), expanded %d existing, %d total. '
                . 'Review and Save the Synonyms field, then reindex if you want them live in search immediately.',
                $stats['terms_scanned'],
                $stats['added'],
                $stats['merged'],
                $stats['total']
            );
            return $result->setData([
                'success' => true,
                'message' => $message,
                'preview' => $stats['preview'],
            ]);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
