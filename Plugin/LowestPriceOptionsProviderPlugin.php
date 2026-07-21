<?php

declare(strict_types=1);

namespace ParkkTech\FastMagento\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Pricing\Price\LowestPriceOptionsProvider;
use Magento\Framework\Registry;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;

/**
 * Feed the configurable price resolver the OpenSearch child SHELLS instead of a native
 * child collection.
 *
 * Core ConfigurablePriceResolver::resolvePrice() iterates
 * LowestPriceOptionsProvider::getProducts(), which builds a native product collection
 * (collectionFactory->create()->addIdFilter(...)). Those native children bypass the shell
 * price path, so each one resolves its catalog-rule + tier price from MySQL — one
 * catalogrule_product_price + one catalog_product_entity_tier_price query PER child (the
 * ~660-pair N+1 on a large configurable PDP).
 *
 * When the parent is an OS-hydrated shell, its children are already built as shells (in the
 * `child_products` registry) carrying indexed catalog_rule_price + tier_prices. Returning
 * those shells makes CatalogRulePrice/TierPrice serve from the doc — zero price SQL. We only
 * substitute when the registry shells match THIS product's own child ids, otherwise we defer
 * to native (correct prices over fast prices).
 */
class LowestPriceOptionsProviderPlugin
{
    public function __construct(private Registry $registry)
    {
    }

    /**
     * @param LowestPriceOptionsProvider $subject
     * @param callable $proceed
     * @param ProductInterface $product
     * @return ProductInterface[]
     */
    public function aroundGetProducts(
        LowestPriceOptionsProvider $subject,
        callable $proceed,
        ProductInterface $product
    ) {
        if ($product instanceof ShellNoEavProduct) {
            $shells = $this->registry->registry('child_products');
            $docChildren = $product->getChildProducts();

            if (is_array($shells) && $shells && is_array($docChildren) && $docChildren) {
                $docIds = [];
                foreach ($docChildren as $child) {
                    $cid = (int)($child['entity_id'] ?? 0);
                    if ($cid) {
                        $docIds[$cid] = true;
                    }
                }

                // Only substitute when every registry shell belongs to this product's doc —
                // guards against a different configurable's children lingering in the registry.
                $matches = $docIds !== [];
                foreach ($shells as $shell) {
                    if (!isset($docIds[(int)$shell->getId()])) {
                        $matches = false;
                        break;
                    }
                }

                if ($matches) {
                    return $shells;
                }
            }
        }

        return $proceed($product);
    }
}
