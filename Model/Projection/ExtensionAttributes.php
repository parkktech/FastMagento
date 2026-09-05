<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Model\Projection;
use Magento\Catalog\Api\Data\{ProductInterface,ProductExtensionFactory};
use Magento\Framework\Api\{ExtensionAttribute\Config,DataObjectHelper,SimpleDataObjectConverter};
use Magento\Framework\EntityManager\Operation\Read\ReadExtensions;
use Magento\Framework\Reflection\DataObjectProcessor;
use Magento\Framework\{DataObject,ObjectManagerInterface};

/** Project registered extension data at indexing time; reconstruct typed objects without reads. */
class ExtensionAttributes
{
    private array $memo = [];
    private const EXISTING_PROJECTIONS = ['stock_item','category_links','configurable_product_links','configurable_product_options'];
    public function __construct(
        private readonly Config $config,
        private readonly ReadExtensions $reader,
        private readonly DataObjectProcessor $processor,
        private readonly DataObjectHelper $helper,
        private readonly ProductExtensionFactory $factory,
        private readonly ObjectManagerInterface $objects,
        private readonly \Magento\Framework\App\State $state
    ) {}

    public function project(ProductInterface $product): array
    {
        try { $storefront = in_array($this->state->getAreaCode(), ['frontend', 'webapi_rest', 'graphql'], true); }
        catch (\Throwable $e) { $storefront = false; }
        if ($storefront && is_array($product->getData('fm_extension_attributes'))) {
            return $product->getData('fm_extension_attributes');
        }
        $key = $product->getId() . ':' . $product->getStoreId() . ':' . $product->getData('row_id');
        if (isset($this->memo[$key])) {
            // A different collection instance can reuse this product's extension snapshot.
            // Reuse the gallery read by that same operation instead of another SQL read.
            if (!$product->hasData('media_gallery') && $this->memo[$key]['media_gallery'] !== null) {
                $product->setData('media_gallery', $this->memo[$key]['media_gallery']);
            }
            return $this->memo[$key]['extension_attributes'];
        }
        // Collections do not execute EntityManager extension readers. Run them HERE, never
        // on the storefront. Repository-loaded objects already have their extension data.
        $definitions = $this->definitions();
        $extension = $product->getExtensionAttributes();
        foreach ($definitions as $code => $definition) {
            $getter = 'get' . SimpleDataObjectConverter::snakeCaseToUpperCamelCase($code);
            if (!$storefront && ($extension === null || $extension->$getter() === null)) {
                $product = $this->reader->execute($product);
                $extension = $product->getExtensionAttributes();
                break;
            }
        }
        $out = [];
        foreach ($definitions as $code => $definition) {
            $getter = 'get' . SimpleDataObjectConverter::snakeCaseToUpperCamelCase($code);
            $value = $extension ? $extension->$getter() : null;
            $out[$code] = $this->encode($value, $definition['type']);
        }
        if (count($this->memo) >= 1000) { $this->memo = []; }
        $this->memo[$key] = [
            'extension_attributes' => $out,
            'media_gallery' => $product->getData('media_gallery'),
        ];
        return $out;
    }

    public function hydrate(ProductInterface $product, array $snapshot): void
    {
        if (!$snapshot) { return; }
        $extension = $product->getExtensionAttributes() ?? $this->factory->create();
        foreach ($this->definitions() as $code => $definition) {
            if (!array_key_exists($code, $snapshot)) { continue; }
            $setter = 'set' . SimpleDataObjectConverter::snakeCaseToUpperCamelCase($code);
            $extension->$setter($this->decode($snapshot[$code], $definition['type']));
        }
        $product->setExtensionAttributes($extension);
    }

    public function reset(): void { $this->memo = []; }

    private function definitions(): array
    {
        return array_diff_key($this->config->get(ProductInterface::class) ?? [], array_flip(self::EXISTING_PROJECTIONS));
    }

    private function encode($value, string $type)
    {
        if ($value === null || is_scalar($value)) { return $value; }
        if (str_ends_with($type, '[]')) {
            return array_map(fn($item) => $this->encode($item, substr($type, 0, -2)), $value);
        }
        if (is_array($value)) { return $value; }
        if ($value instanceof DataObject) {
            // Preserve raw data: calling transformed image/price getters would bake in a
            // request-specific transformation and apply it twice when plugins run later.
            $data = $value->getData();
            json_encode($data, JSON_THROW_ON_ERROR);
            return ['encoding' => 'data', 'value' => $data];
        }
        return ['encoding' => 'api', 'value' => $this->processor->buildOutputDataArray($value, $type)];
    }

    private function decode($value, string $type)
    {
        if ($value === null || is_scalar($value)) { return $value; }
        if (str_ends_with($type, '[]')) {
            return array_map(fn($item) => $this->decode($item, substr($type, 0, -2)), $value);
        }
        if (!isset($value['encoding'])) { return $value; }
        // The installed extension declaration controls the class, never an indexed classname.
        $object = $this->objects->create($type);
        if ($value['encoding'] === 'data' && $object instanceof DataObject) {
            $object->setData($value['value']);
        } else {
            $this->helper->populateWithArray($object, $value['value'], $type);
        }
        return $object;
    }
}
