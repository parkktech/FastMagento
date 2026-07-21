-- Fix: multiselect product attributes must use backend_type='text'
-- (values stored in catalog_product_entity_text), NOT 'varchar'.
--
-- Why: Magento's core EAV Source indexer
-- (Magento\Catalog\Model\ResourceModel\Product\Indexer\Eav\Source::_getIndexableAttributes)
-- only indexes multiselect attributes whose backend_type = 'text', reading their
-- values from catalog_product_entity_text. A multiselect stored as 'varchar' is
-- silently skipped -> 0 rows in catalog_product_index_eav -> the attribute never
-- appears as a layered-navigation facet (even though it renders fine on the PDP
-- and is present in the OpenSearch doc).
--
-- This migrates any user-defined multiselect attribute that was created as
-- 'varchar' over to 'text': it flips backend_type and moves existing values from
-- catalog_product_entity_varchar to catalog_product_entity_text. Idempotent.
--
-- After running:  bin/magento indexer:reindex catalog_product_attribute
--
-- Usage: mysql <db> < fix-multiselect-backend-type.sql

START TRANSACTION;

-- 1. Move values varchar -> text for every multiselect currently on 'varchar'.
INSERT INTO catalog_product_entity_text (attribute_id, store_id, entity_id, value)
SELECT v.attribute_id, v.store_id, v.entity_id, v.value
FROM catalog_product_entity_varchar v
JOIN eav_attribute ea ON ea.attribute_id = v.attribute_id
WHERE ea.frontend_input = 'multiselect'
  AND ea.backend_type   = 'varchar'
  AND ea.entity_type_id = 4;

DELETE v FROM catalog_product_entity_varchar v
JOIN eav_attribute ea ON ea.attribute_id = v.attribute_id
WHERE ea.frontend_input = 'multiselect'
  AND ea.backend_type   = 'varchar'
  AND ea.entity_type_id = 4;

-- 2. Flip the attribute definition to the correct backend type.
UPDATE eav_attribute
SET backend_type = 'text'
WHERE frontend_input = 'multiselect'
  AND backend_type   = 'varchar'
  AND entity_type_id = 4;

COMMIT;
