<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin\Inventory;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\Registry;

/**
 * Keep a composite parent (configurable/grouped/bundle) sellable at the cart's stock gate
 * whenever the OpenSearch layer says it is, overriding a stale native/MSI parent salability.
 *
 * Why: a composite parent holds no stock of its own — its salability is "any child in stock".
 * The native cataloginventory_stock_status / MSI parent salability is maintained by an mview
 * indexer that can lag (StockSyncer keeps only the OpenSearch doc live in real time). When that
 * parent row is a stale 0, Magento\CatalogInventory\Model\Quote\Item\QuantityValidator reads it
 * during add-to-cart and rejects the item with "This product is out of stock." even though every
 * child is in stock — the exact 2nd-configurable add failure. The PDP already tolerates this via
 * our Configurable::isSalable() override that trusts the OS/child-derived flag; this applies the
 * same trust to the cart's stock gate.
 *
 * Ordering: MSI's Magento\InventoryCatalog\Plugin\CatalogInventory\Api\StockRegistry\
 * AdaptGetStockStatusPlugin recomputes the status from MSI in its own afterGetStockStatus and
 * would otherwise win. After-plugins apply last-wins in ascending sortOrder, and MSI declares no
 * sortOrder (PHP_INT_MIN), so this plugin's positive sortOrder makes it run last and correct the
 * result. NB: this last-wins guarantee holds only while no module registers an aroundGetStockStatus
 * on StockRegistryInterface — an around splits the after-chain at its boundary and can invert the
 * order. None exists today; revisit this sortOrder if one is ever added.
 *
 * Safety (no overselling): this lifts only the COARSE parent-level gate. The child actually being
 * purchased is validated independently by QuantityValidator (its qtyOptions / option initializer
 * against the child's own MSI salable qty), so a genuinely out-of-stock child is still blocked.
 * It fires only for a composite parent whose children were hydrated from OpenSearch this request
 * (the per-parent child_products registry the shell build populates on the cart/checkout path);
 * simple products and any unresolved id keep the native/MSI status untouched.
 */
class CompositeParentStockStatusPlugin
{
    public function __construct(private readonly Registry $registry)
    {
    }

    /**
     * @param StockRegistryInterface $subject
     * @param StockStatusInterface $result
     * @param int $productId
     * @param int|null $scopeId
     * @return StockStatusInterface
     */
    public function afterGetStockStatus(
        StockRegistryInterface $subject,
        StockStatusInterface $result,
        $productId,
        $scopeId = null
    ): StockStatusInterface {
        // Already sellable — nothing to correct.
        if ((int) $result->getStockStatus() === Stock::STOCK_IN_STOCK) {
            return $result;
        }

        // Only act on a composite parent whose OS children are hydrated this request. A miss
        // (simple product, or a parent not loaded as a shell) keeps the native/MSI status.
        $children = $this->registry->registry('child_products_' . (int) $productId);
        if (!is_array($children) || empty($children)) {
            return $result;
        }

        foreach ($children as $child) {
            // Registry children are OS-hydrated shell products; tolerate raw doc arrays too.
            $childInStock = is_array($child)
                ? ($child['is_in_stock'] ?? false)
                : $child->getData('is_in_stock');
            if ($childInStock) {
                $result->setStockStatus(Stock::STOCK_IN_STOCK);
                break;
            }
        }

        return $result;
    }
}
