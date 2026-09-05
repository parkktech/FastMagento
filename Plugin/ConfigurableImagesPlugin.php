<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\ConfigurableProduct\Block\Product\View\Type\Configurable;
use Magento\Framework\Serialize\Serializer\Json;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/** Restore the standard inline swatch contract using the already-fetched product document. */
class ConfigurableImagesPlugin
{
    public function __construct(private readonly UrlBuilder $urls, private readonly Json $json)
    {
    }

    public function afterGetJsonConfig(Configurable $subject, $result)
    {
        // Listing renderers deliberately defer galleries: do not inflate every product card.
        if ($subject instanceof \Magento\Swatches\Block\Product\Renderer\Listing\Configurable) {
            return $result;
        }
        $product = $subject->getProduct();
        if (!$product instanceof ShellNoEavProduct || !$result) {
            return $result;
        }
        $config = $this->json->unserialize($result);
        if (!is_array($config) || !empty($config['images']) || empty($config['index'])) {
            return $result;
        }

        $images = [];
        $urlCache = [];
        foreach ($product->getData('child_products') ?: [] as $child) {
            $id = $child['entity_id'] ?? null;
            if (!$id || !isset($config['index'][$id])) {
                continue;
            }
            $gallery = $child['media_gallery']['images'] ?? [];
            // Older projections contain the variant's base image but no gallery snapshot.
            // Use that indexed image without fetching the child or inventing extra views.
            if (!$gallery && !empty($child['image']) && $child['image'] !== 'no_selection') {
                $gallery = [['file' => $child['image'], 'position' => 0]];
            }
            foreach ($gallery as $image) {
                $file = $image['file'] ?? '';
                if (!$file || $file === 'no_selection' || !empty($image['disabled']) || !empty($image['removed'])) {
                    continue;
                }
                if (!isset($urlCache[$file])) {
                    $external = preg_match('#^(https?:)?//#i', $file);
                    $urlCache[$file] = [
                        'thumb' => $external ? $file : $this->urls->getUrl($file, 'product_page_image_small'),
                        'img' => $external ? $file : $this->urls->getUrl($file, 'product_page_image_medium'),
                        'full' => $external ? $file : $this->urls->getUrl($file, 'product_page_image_large'),
                    ];
                }
                $images[$id][] = $urlCache[$file] + [
                    'caption' => (string)($image['label'] ?? $child['name'] ?? ''),
                    'position' => (int)($image['position'] ?? 0),
                    'isMain' => $file === ($child['image'] ?? null),
                    'type' => str_replace('external-', '', $image['media_type'] ?? 'image'),
                    'videoUrl' => $image['video_url'] ?? null,
                ];
            }
        }
        if (!$images) {
            return $result;
        }
        $config['images'] = $images;
        return $this->json->serialize($config);
    }
}
