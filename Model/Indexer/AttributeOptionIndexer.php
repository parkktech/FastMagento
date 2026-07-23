<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\Framework\Indexer\ActionInterface;
use Magento\Framework\Mview\ActionInterface as MviewActionInterface;
use ParkkTech\FastMagento\Model\OptionDictionary;

/**
 * Projects every option-bearing attribute's option→label set into the OpenSearch dictionary index
 * (see OptionDictionary). Options change rarely and are not per-product, so every trigger performs
 * the same idempotent full rebuild — cheap relative to the product index.
 *
 * Reindex: bin/magento indexer:reindex fastmagento_attribute_option
 */
class AttributeOptionIndexer implements ActionInterface, MviewActionInterface
{
    public function __construct(private readonly OptionDictionary $dictionary)
    {
    }

    /** Full reindex. */
    public function executeFull(): void
    {
        $this->dictionary->rebuild();
    }

    /** @param int[] $ids */
    public function executeList(array $ids): void
    {
        $this->dictionary->rebuild();
    }

    /** @param int $id */
    public function executeRow($id): void
    {
        $this->dictionary->rebuild();
    }

    /** Mview entry point (scheduled mode, on eav_attribute_option* changes). @param int[] $ids */
    public function execute($ids): void
    {
        $this->dictionary->rebuild();
    }
}
