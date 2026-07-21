<?php

namespace ParkkTech\FastMagento\Model;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\Data\StockInterface;
use Magento\CatalogInventory\Api\StockItemCriteriaInterfaceFactory;
use Magento\CatalogInventory\Model\Spi\StockRegistryProviderInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Registry;
use Magento\Catalog\Model\Product\Type as ProductType;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;



class OpenSearchStockRegistry implements StockRegistryInterface
{

    private StoreManagerInterface $storeManager;
    private StockConfigurationInterface $stockConfiguration;
    private StockRegistryProviderInterface $stockRegistryProvider;
    private StockItemRepositoryInterface $stockItemRepository;
    private StockItemCriteriaInterfaceFactory $criteriaFactory;
    private ProductFactory $productFactory;

    private Registry $registry;
    private string $indexName;

    private ProductType $productType;

    public function __construct(
        StoreManagerInterface $storeManager,
        StockConfigurationInterface $stockConfiguration,
        StockRegistryProviderInterface $stockRegistryProvider,
        StockItemRepositoryInterface $stockItemRepository,
        StockItemCriteriaInterfaceFactory $criteriaFactory,
        ProductFactory $productFactory,
        ScopeConfigInterface $scopeConfig,
        Registry $registry,
        ProductType $productType
    ) {
        $this->storeManager = $storeManager;
        $this->stockConfiguration = $stockConfiguration;
        $this->stockRegistryProvider = $stockRegistryProvider;
        $this->stockItemRepository = $stockItemRepository;
        $this->criteriaFactory = $criteriaFactory;
        $this->productFactory = $productFactory;
        $this->registry = $registry;
        $this->productType = $productType;
    }


    public function getStockItem($productId, $scopeId = null): StockItemInterface
    {
        // ✅ Check if product exists in registry
        $product = $this->registry->registry('current_product');

        if ($product && (int)$product->getId() === (int)$productId) {
            return $this->buildStockItemFromRegistry($product);
        }

        // ❌ If not found in registry, fallback to Magento Core stock management
        return $this->stockRegistryProvider->getStockItem(
            $productId,
            $scopeId ?? $this->stockConfiguration->getDefaultScopeId()
        );
    }




    public function getStockStatus($productId, $scopeId = null): StockStatusInterface
    {
        $product = $this->registry->registry('current_product');

        if ($product && (int)$product->getId() === (int)$productId) {
            return $this->buildStockStatusFromRegistry($product);
        }

        return $this->stockRegistryProvider->getStockStatus(
            $productId,
            $scopeId ?? $this->stockConfiguration->getDefaultScopeId()
        );
    }

    public function getStockItemBySku($productSku, $scopeId = null): StockItemInterface
    {
        $productId = $this->resolveProductId($productSku);
        return $this->getStockItem($productId, $scopeId);
    }



    public function getStockStatusBySku($productSku, $scopeId = null): ?StockStatusInterface
    {
        $productId = $this->resolveProductId($productSku);
        return $this->getStockStatus($productId, $scopeId);
    }


    public function getProductStockStatus($productId, $scopeId = null): ?bool
    {
        $stockStatus = $this->getStockStatus($productId, $scopeId);
        return $stockStatus ? $stockStatus->getStockStatus() : null;
    }


    public function getProductStockStatusBySku($productSku, $scopeId = null): ?bool
    {
        $productId = $this->resolveProductId($productSku);
        return $this->getProductStockStatus($productId, $scopeId);
    }

    public function getLowStockItems($scopeId, $qty, $currentPage = 1, $pageSize = 0)
    {
        $criteria = $this->criteriaFactory->create();
        $criteria->setLimit($currentPage, $pageSize);
        $criteria->setScopeFilter($scopeId);
        $criteria->setQtyFilter('<=', $qty);
        $criteria->addField('qty');
        return $this->stockItemRepository->getList($criteria);
    }


    public function updateStockItemBySku($productSku, StockItemInterface $stockItem)
    {
        $productId = $this->resolveProductId($productSku);
        $websiteId = $stockItem->getWebsiteId() ?: null;
        $origStockItem = $this->getStockItem($productId, $websiteId);
        $data = $stockItem->getData();
        if ($origStockItem->getItemId()) {
            unset($data['item_id']);
        }
        $origStockItem->addData($data);
        $origStockItem->setProductId($productId);
        return $this->stockItemRepository->save($origStockItem)->getItemId();
    }


    public function getStock($scopeId = null): StockInterface
    {
        return $this->stockRegistryProvider->getStock(
            $scopeId ?? $this->stockConfiguration->getDefaultScopeId()
        );
    }


    private function resolveProductId($productSku): ?int
    {
        $product = $this->productFactory->create();
        return $product->getIdBySku($productSku);
    }



    private function createStockItem($productId, ?array $stockData): StockItemInterface
    {
        $stockItem = $this->stockItemRepository->get($productId);
        $stockItem->setProductId($productId);
        $stockItem->setIsInStock($stockData['is_in_stock'] ?? false);
        $stockItem->setQty($stockData['qty'] ?? 0);
        return $stockItem;
    }


    /**
     * ✅ Retrieve stock for different product types (Simple, Configurable, Grouped, Bundle).
     */
    private function buildStockItemFromRegistry($product): StockItemInterface
    {
        /** @var StockItemInterface $stockItem */
        $stockItem = $this->stockItemRepository->get($product->getId());

        // ✅ Simple Products: Use direct stock data
        if ($product->getTypeId() === $this->productType::TYPE_SIMPLE) {
            $stockItem->setIsInStock($product->getData('is_in_stock') ?? false);
            $stockItem->setQty($product->getData('stock_qty') ?? 0);
        }

        // ✅ Configurable Products: Check if any child is in stock
        elseif ($product->getTypeId() === Configurable::TYPE_CODE) {
            $childProducts = $product->getChildProducts();
            $isInStock = false;
            $stockQty = 0;

            foreach ($childProducts as $child) {
                if ($child['is_in_stock']) {
                    $isInStock = true;
                }
                $stockQty += (int) $child['stock_qty'];
            }

            $stockItem->setIsInStock($isInStock);
            $stockItem->setQty($stockQty);
        }

        // ✅ Grouped Products: Check if any grouped product is in stock
        elseif ($product->getTypeId() === Grouped::TYPE_CODE) {
            $associatedProducts = $product->getAssociatedProducts();
            $isInStock = false;
            $stockQty = 0;

            foreach ($associatedProducts as $associated) {
                if ($associated['is_in_stock']) {
                    $isInStock = true;
                }
                $stockQty += (int) $associated['stock_qty'];
            }

            $stockItem->setIsInStock($isInStock);
            $stockItem->setQty($stockQty);
        }

        // ✅ Bundle Products: Check if any bundle item is in stock
        elseif ($product->getTypeId() === ProductType::TYPE_BUNDLE) {
            $bundleItems = $product->getBundleChildren();
            $isInStock = false;
            $stockQty = 0;

            foreach ($bundleItems as $bundleItem) {
                if ($bundleItem['is_in_stock']) {
                    $isInStock = true;
                }
                $stockQty += (int) $bundleItem['stock_qty'];
            }

            $stockItem->setIsInStock($isInStock);
            $stockItem->setQty($stockQty);
        }

        return $stockItem;
    }
    /**
     * ✅ Build Stock Status from Registry Data.
     */
    private function buildStockStatusFromRegistry($product): StockStatusInterface
    {
        /** @var StockStatusInterface $stockStatus */
        $stockStatus = $this->stockRegistryProvider->getStockStatus($product->getId(), null);

        $stockStatus->setStockStatus($product->getData('is_in_stock') ?? false);
        return $stockStatus;
    }

}
