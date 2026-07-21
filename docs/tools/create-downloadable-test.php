<?php
/**
 * Create a downloadable test product that exercises the FULL downloadable render path:
 * multiple links (with per-link samples) AND product-level samples — because the live
 * catalog only has single-link, no-sample downloadables.
 *
 * Usage:  php app/code/ParkkTech/FastMagento/docs/tools/create-downloadable-test.php
 * Re-runnable: deletes + recreates SKU FASTMAG-DL-TEST, then indexes it into OpenSearch.
 */
require __DIR__ . '/../../../../../../app/bootstrap.php';

use Magento\Framework\App\Bootstrap;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$sku = 'FASTMAG-DL-TEST';
$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

// Clean slate.
try {
    $productRepository->deleteById($sku);
    echo "deleted existing $sku\n";
} catch (\Throwable $e) {
    // not present
}

$linkFactory = $om->get(\Magento\Downloadable\Api\Data\LinkInterfaceFactory::class);
$sampleFactory = $om->get(\Magento\Downloadable\Api\Data\SampleInterfaceFactory::class);

/** @var \Magento\Catalog\Model\Product $product */
$product = $om->create(\Magento\Catalog\Model\Product::class);
$product->setSku($sku)
    ->setName('FastMagento Downloadable Test (links + samples)')
    ->setAttributeSetId(4)
    ->setTypeId(\Magento\Downloadable\Model\Product\Type::TYPE_DOWNLOADABLE)
    ->setPrice(9.99)
    ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
    ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
    ->setWebsiteIds([1])
    ->setCategoryIds([3])
    ->setLinksPurchasedSeparately(true)
    ->setLinksTitle('Download Files')
    ->setSamplesTitle('Free Samples')
    ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1, 'qty' => 999]);

// Two purchasable links, each with its own sample URL.
$links = [];
$linkDefs = [
    ['title' => 'Full CAD Bundle (.zip)', 'price' => 9.99, 'sort' => 1, 'sample' => 'https://example.com/sample-cad.pdf'],
    ['title' => 'DXF Cut File (.dxf)',    'price' => 4.99, 'sort' => 2, 'sample' => 'https://example.com/sample-dxf.pdf'],
];
foreach ($linkDefs as $def) {
    $link = $linkFactory->create();
    $link->setTitle($def['title'])
        ->setPrice($def['price'])
        ->setSortOrder($def['sort'])
        ->setNumberOfDownloads(0)   // unlimited
        ->setIsShareable(\Magento\Downloadable\Model\Link::LINK_SHAREABLE_CONFIG)
        ->setLinkType(\Magento\Downloadable\Helper\Download::LINK_TYPE_URL)
        ->setLinkUrl('https://example.com/download/' . urlencode($def['title']) . '.zip')
        ->setSampleType(\Magento\Downloadable\Helper\Download::LINK_TYPE_URL)
        ->setSampleUrl($def['sample']);
    $links[] = $link;
}

// Two product-level samples.
$samples = [];
$sampleDefs = [
    ['title' => 'Preview Render (.png)', 'sort' => 1, 'url' => 'https://example.com/preview.png'],
    ['title' => 'Spec Sheet (.pdf)',     'sort' => 2, 'url' => 'https://example.com/spec.pdf'],
];
foreach ($sampleDefs as $def) {
    $sample = $sampleFactory->create();
    $sample->setTitle($def['title'])
        ->setSortOrder($def['sort'])
        ->setSampleType(\Magento\Downloadable\Helper\Download::LINK_TYPE_URL)
        ->setSampleUrl($def['url']);
    $samples[] = $sample;
}

$extension = $product->getExtensionAttributes();
$extension->setDownloadableProductLinks($links);
$extension->setDownloadableProductSamples($samples);
$product->setExtensionAttributes($extension);

$product = $productRepository->save($product);
echo "created {$product->getSku()} id={$product->getId()} price={$product->getPrice()}\n";

// Index straight into OpenSearch.
$om->get(\ParkkTech\FastMagento\Model\Indexer\ProductIndexer::class)->executeRow((int) $product->getId());
echo "indexed id={$product->getId()} into OpenSearch\n";
echo "url_key: " . $product->getUrlKey() . "\n";
