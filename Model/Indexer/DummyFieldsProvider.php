<?php
namespace ParkkTech\FastMagento\Model\Indexer;

use Magento\Framework\DataObject;

/**
 * Minimal provider to satisfy <fieldset> provider reference in indexer.xml.
 */
class DummyFieldsProvider implements DummyFieldsProviderInterface
{
    public function getFields(DataObject $entity): array
    {
        return ['entity_id'];
    }
}
