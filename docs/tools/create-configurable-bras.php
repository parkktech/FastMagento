<?php
/**
 * FastMagento test-bed: generate robust configurable "bra" products with two swatch
 * super-attributes (color = visual swatch, size = text swatch), one child simple per
 * color x size combo, each child with its own SKU, price, stock and image.
 *
 * Modeled on real band+cup bra catalogs (e.g. Goddess/Elomi): band 34..46 x cups
 * DD,DDD,G..O, ~15 colors, ~$59 base with a per-cup price bump.
 *
 * Usage:
 *   php create-configurable-bras.php [numProducts] [numColors] [numSizes]
 *   php create-configurable-bras.php 3 6 10     # quick validation set
 *   php create-configurable-bras.php 1 15 44    # one "monster" (660 children)
 *
 * Idempotent per SKU: existing SKUs are skipped.
 */

use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Api\Data\ProductInterface;

require '/var/www/html/diyoffroad/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$numProducts = (int)($argv[1] ?? 3);
$numColors   = (int)($argv[2] ?? 6);
$numSizes    = (int)($argv[3] ?? 10);

$COLOR_ATTR = 'color';
$SIZE_ATTR  = 'size';
$ATTR_SET   = 'Default';

// ---- realistic option universes (trimmed to requested counts) -----------------
$ALL_COLORS = [
    'Black' => '#000000', 'White' => '#FFFFFF', 'Nude' => '#E3BC9A', 'Toast' => '#B98C6A',
    'Espresso' => '#4A3123', 'Navy' => '#1F2A44', 'Red' => '#B3122B', 'Pink' => '#F4B6C2',
    'Champagne' => '#F0DFC0', 'Sand' => '#D9C6A5', 'Cocoa' => '#6B4A2B', 'Charcoal' => '#36393B',
    'Ivory' => '#FFFFF0', 'Blush' => '#DE9E9E', 'Merlot' => '#5E1F2E',
];
$BANDS = [34, 36, 38, 40, 42, 44, 46];
$CUPS  = ['DD', 'DDD', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O']; // ascending cup => price bump
$ALL_SIZES = [];
foreach ($BANDS as $b) {
    foreach ($CUPS as $ci => $c) {
        $ALL_SIZES[$b . $c] = $ci; // label => cup index (for price bump)
    }
}

$colors = array_slice($ALL_COLORS, 0, min($numColors, count($ALL_COLORS)), true);
$sizes  = array_slice($ALL_SIZES, 0, min($numSizes, count($ALL_SIZES)), true);

$BASE_MODELS = ['Keira', 'Matilda', 'Cate', 'Elomi Sachi', 'Goddess Adelaide', 'Envisage', 'Morgan', 'Smoothing'];

// ---- helpers ------------------------------------------------------------------
$eavSetup   = $om->get(\Magento\Eav\Setup\EavSetupFactory::class)->create();
$attrRepo   = $om->get(\Magento\Catalog\Api\ProductAttributeRepositoryInterface::class);
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn       = $resource->getConnection();
$swatchType = ['visual_color' => 1, 'visual_image' => 2, 'textual' => 0];

/** Ensure an attribute is a swatch of the given input type and has the given options+swatch values. */
function ensureSwatchOptions($conn, $eavSetup, $attrRepo, string $code, string $inputType, array $labelToSwatch): array
{
    $attrId = (int)$eavSetup->getAttributeId(Product::ENTITY, $code);
    // Mark the attribute as a swatch. swatch_input_type lives in
    // catalog_eav_attribute.additional_data (NOT eav_attribute); color/size are
    // already select/int, so frontend_input/backend_type need no change.
    $additional = json_encode([
        'swatch_input_type' => $inputType,           // 'visual' | 'text'
        'update_product_preview_image' => $inputType === 'visual' ? 1 : 0,
        'use_product_image_for_swatch' => 0,
    ]);
    $conn->update(
        $conn->getTableName('catalog_eav_attribute'),
        ['additional_data' => $additional],
        ['attribute_id = ?' => $attrId]
    );

    // existing options label=>id
    $existing = [];
    $rows = $conn->fetchAll(
        "SELECT o.option_id, v.value AS label FROM {$conn->getTableName('eav_attribute_option')} o " .
        "JOIN {$conn->getTableName('eav_attribute_option_value')} v ON v.option_id=o.option_id AND v.store_id=0 " .
        "WHERE o.attribute_id=?",
        [$attrId]
    );
    foreach ($rows as $r) {
        $existing[$r['label']] = (int)$r['option_id'];
    }

    $type = ($inputType === 'visual') ? 1 : 0; // 1=visual color(hex), 0=textual
    $labelToId = [];
    $sortOrder = 0;
    foreach ($labelToSwatch as $label => $swatchValue) {
        $sortOrder++;
        if (isset($existing[$label])) {
            $optionId = $existing[$label];
        } else {
            // create option + admin store value
            $conn->insert($conn->getTableName('eav_attribute_option'), [
                'attribute_id' => $attrId, 'sort_order' => $sortOrder,
            ]);
            $optionId = (int)$conn->lastInsertId();
            $conn->insert($conn->getTableName('eav_attribute_option_value'), [
                'option_id' => $optionId, 'store_id' => 0, 'value' => $label,
            ]);
        }
        // upsert swatch value (admin store 0)
        $swatchStored = ($inputType === 'visual') ? $swatchValue : $label; // text swatch shows the label
        $exists = $conn->fetchOne(
            "SELECT swatch_id FROM {$conn->getTableName('eav_attribute_option_swatch')} WHERE option_id=? AND store_id=0",
            [$optionId]
        );
        if ($exists) {
            $conn->update($conn->getTableName('eav_attribute_option_swatch'),
                ['type' => $type, 'value' => $swatchStored], ['swatch_id = ?' => (int)$exists]);
        } else {
            $conn->insert($conn->getTableName('eav_attribute_option_swatch'), [
                'option_id' => $optionId, 'store_id' => 0, 'type' => $type, 'value' => $swatchStored,
            ]);
        }
        $labelToId[$label] = $optionId;
    }
    return $labelToId;
}

echo "Ensuring swatch options: {$numColors} colors (visual), " . count($sizes) . " sizes (text)...\n";
$colorIds = ensureSwatchOptions($conn, $eavSetup, $attrRepo, $COLOR_ATTR, 'visual', $colors);
$sizeSwatch = [];
foreach ($sizes as $label => $_) { $sizeSwatch[$label] = $label; }
$sizeIds  = ensureSwatchOptions($conn, $eavSetup, $attrRepo, $SIZE_ATTR, 'text', $sizeSwatch);

$colorAttrId = (int)$eavSetup->getAttributeId(Product::ENTITY, $COLOR_ATTR);
$sizeAttrId  = (int)$eavSetup->getAttributeId(Product::ENTITY, $SIZE_ATTR);
$setId       = (int)$eavSetup->getAttributeSetId(Product::ENTITY, $ATTR_SET);

// make sure color+size are in the attribute set
foreach ([$COLOR_ATTR, $SIZE_ATTR] as $c) {
    try { $eavSetup->addAttributeToSet(Product::ENTITY, $setId, 'General', $c); } catch (\Throwable $e) {}
}

$productRepo = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$productFactory = $om->get(\Magento\Catalog\Model\ProductFactory::class);
$stockRegistry = $om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class);
$optionsFactory = $om->get(\Magento\ConfigurableProduct\Helper\Product\Options\Factory::class);
$sampleImages = $conn->fetchCol("SELECT DISTINCT value FROM {$conn->getTableName('catalog_product_entity_media_gallery')} LIMIT 30");
if (!$sampleImages) { $sampleImages = ['/placeholder/default/no_selection']; }

function makeSimpleChild($productFactory, $productRepo, $stockRegistry, int $setId, string $sku, string $name,
                         float $price, int $colorId, int $sizeId, string $colorAttr, string $sizeAttr, string $image): int
{
    try {
        $existing = $productRepo->get($sku);
        return (int)$existing->getId();
    } catch (\Throwable $e) { /* not found -> create */ }

    /** @var Product $p */
    $p = $productFactory->create();
    $p->setSku($sku)->setName($name)->setAttributeSetId($setId)->setStatus(1)
      ->setVisibility(1) // not individually visible
      ->setTypeId('simple')->setPrice($price)->setWebsiteIds([1])
      ->setStoreId(0)
      ->setData($colorAttr, $colorId)->setData($sizeAttr, $sizeId)
      ->setImage($image)->setSmallImage($image)->setThumbnail($image);
    $p = $productRepo->save($p);
    $item = $stockRegistry->getStockItem($p->getId());
    $item->setQty(100)->setIsInStock(true);
    $stockRegistry->updateStockItemBySku($sku, $item);
    return (int)$p->getId();
}

$colorList = array_keys($colors);
$sizeList  = array_keys($sizes);
$made = 0; $childCount = 0;
for ($n = 1; $n <= $numProducts; $n++) {
    $model = $BASE_MODELS[($n - 1) % count($BASE_MODELS)];
    $parentSku = sprintf('HER-%s-%03d', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $model)), $n);
    $parentName = sprintf('%s Banded Underwire Bra (%d)', $model, $n);
    $basePrice = 49.0 + ($n % 5) * 5; // 49..69 base per product

    try { $productRepo->get($parentSku); echo "SKIP existing $parentSku\n"; continue; } catch (\Throwable $e) {}

    // 1) children
    $childIds = [];
    $ci = 0;
    foreach ($colorList as $color) {
        $img = $sampleImages[$ci % count($sampleImages)]; $ci++;
        foreach ($sizeList as $size) {
            $cupIdx = $sizes[$size];
            $price = $basePrice + $cupIdx * 3.0; // bigger cup => +$3/step
            $childSku = sprintf('%s-%s-%s', $parentSku,
                strtoupper(substr($color, 0, 3)), strtoupper($size));
            $cid = makeSimpleChild($productFactory, $productRepo, $stockRegistry, $setId,
                $childSku, $parentName . " - $color $size", $price,
                $colorIds[$color], $sizeIds[$size], $COLOR_ATTR, $SIZE_ATTR, $img);
            $childIds[] = $cid;
            $childCount++;
        }
    }

    // 2) configurable parent
    /** @var Product $conf */
    $conf = $productFactory->create();
    $conf->setSku($parentSku)->setName($parentName)->setAttributeSetId($setId)
         ->setStatus(1)->setVisibility(4)->setTypeId('configurable')
         ->setPrice($basePrice)->setWebsiteIds([1])->setStoreId(0);

    // super-attribute config for color + size
    $configurableAttributesData = [];
    foreach ([['id' => $colorAttrId, 'code' => $COLOR_ATTR, 'ids' => $colorIds],
              ['id' => $sizeAttrId, 'code' => $SIZE_ATTR, 'ids' => $sizeIds]] as $pos => $sa) {
        $values = [];
        foreach ($sa['ids'] as $optId) {
            $values[] = ['value_index' => $optId];
        }
        $configurableAttributesData[] = [
            'attribute_id' => $sa['id'],
            'code' => $sa['code'],
            'label' => $sa['code'],
            'position' => $pos,
            'values' => $values,
        ];
    }
    $options = $optionsFactory->create($configurableAttributesData);
    $extension = $conf->getExtensionAttributes();
    $extension->setConfigurableProductOptions($options);
    $extension->setConfigurableProductLinks($childIds);
    $conf->setExtensionAttributes($extension);

    $productRepo->save($conf);
    $made++;
    echo "CREATED $parentSku  ({$parentName})  children=" . count($childIds) . "\n";
}

echo "done: {$made} configurable products, {$childCount} children created.\n";
echo "Reindex: bin/magento indexer:reindex catalog_product_attribute fastmagento_product\n";
