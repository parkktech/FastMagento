<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Pricing\Adjustment\AdjustmentInterface;
use Magento\Msrp\Model\Config;
use Magento\Msrp\Model\Msrp;
use Magento\Catalog\Model\Product\Configuration\Item\Option;

/**
 * Minimal PriceInfo that returns a ShellPrice for final_price, regular_price, special_price, etc.
 */
class ShellPriceInfo implements PriceInfoInterface
{
    private float $regularPrice;
    private float $finalPrice;
    private ?float $specialPrice = null;

    /** @var ShellPriceFactory */
    private ShellPriceFactory $shellPriceFactory;

    /** @var Config|null */
    private ?Config $msrpConfig;

    /** @var Msrp|null */
    private ?Msrp $msrp;

    /** @var Option|null */
    private ?Option $productOptions;

    private ?float $catalogRulePrice = null;

    /**
     * $shellPriceFactory is first, then numeric prices (regular, final, special), then optional msrp stuff.
     */
    public function __construct(
        ShellPriceFactory $shellPriceFactory,
        float $regularPrice = 0.0,
        float $finalPrice = 0.0,
        ?float $specialPrice = null,
        ?float $catalogRulePrice = null,
        ?Config $msrpConfig = null,
        ?Msrp $msrp = null,
        ?Option $productOptions = null
    ) {
        $this->shellPriceFactory = $shellPriceFactory;
        $this->regularPrice   = $regularPrice;
        $this->finalPrice     = $catalogRulePrice ?? $finalPrice;
        $this->specialPrice   = $specialPrice;
        $this->catalogRulePrice = $catalogRulePrice;
        $this->msrpConfig     = $msrpConfig;
        $this->msrp           = $msrp;
        $this->productOptions = $productOptions;
    }

    /**
     * Return a ShellPrice object for each code:
     */
    public function getPrice($code)
    {
        switch ($code) {
            case 'regular_price':
                return $this->shellPriceFactory->create([
                    'value' => $this->regularPrice,
                    'msrpConfig' => $this->msrpConfig,
                    'msrp' => $this->msrp,
                    'productOptions' => $this->productOptions
                ]);

            case 'special_price':
                return $this->shellPriceFactory->create([
                    'value' => $this->specialPrice,
                    'msrpConfig' => $this->msrpConfig,
                    'msrp' => $this->msrp,
                    'productOptions' => $this->productOptions
                ]);

            case 'tier_price':
                // Return a ShellPrice with 0.0 or some "tier" logic
                return $this->shellPriceFactory->create([
                    'value' => 0.0,
                    'msrpConfig' => $this->msrpConfig,
                    'msrp' => $this->msrp,
                    'productOptions' => $this->productOptions
                ]);

            case 'final_price':
            case 'catalog_rule_price':
                return $this->shellPriceFactory->create([
                    'value' => $this->catalogRulePrice ?? $this->finalPrice,
                    'msrpConfig' => $this->msrpConfig,
                    'msrp' => $this->msrp,
                    'productOptions' => $this->productOptions
                ]);

            default:
                // fallback
                return $this->shellPriceFactory->create([
                    'value' => 0.0,
                    'msrpConfig' => $this->msrpConfig,
                    'msrp' => $this->msrp,
                    'productOptions' => $this->productOptions
                ]);
        }
    }

    public function getPrices()
    {
        // Return an array of your main "price" objects if needed
        return [];
    }

    public function getAdjustments()
    {
        return [];
    }

    public function hasAdjustment($code)
    {
        return false;
    }

    public function setAdjustment(AdjustmentInterface $adjustment)
    {
        return $this;
    }

    public function getAdjustment($code)
    {
        return null;
    }

    public function removeAdjustment($code)
    {
        // no-op
    }
}
