<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Msrp\Model\Config;
use Magento\Msrp\Model\Msrp;
use Magento\Catalog\Model\Product\Configuration\Item\Option;
use ParkkTech\FastMagento\Model\ShellProduct\ShellAmountFactory;
use Magento\Msrp\Model\Product\Attribute\Source\Type as MsrpType;

/**
 * ShellPrice implementing PriceInterface,
 * using a factory for ShellAmount (no direct "new").
 * Has optional Msrp logic.
 */
class ShellPrice implements PriceInterface
{
    /** @var ShellAmountFactory */
    private ShellAmountFactory $shellAmountFactory;

    /** @var float */
    private float $value = 0.0;

    /** @var Config|null */
    private ?Config $msrpConfig;

    /** @var Msrp|null */
    private ?Msrp $msrp;

    /** @var Option|null */
    private ?Option $productOptions;

    /**
     * @param ShellAmountFactory $shellAmountFactory [REQUIRED]
     * @param float              $value              [OPTIONAL price amount]
     * @param Config|null        $msrpConfig         [OPTIONAL Msrp config]
     * @param Msrp|null          $msrp               [OPTIONAL Msrp model]
     * @param Option|null        $productOptions     [OPTIONAL for composite logic]
     */
    public function __construct(
        ShellAmountFactory $shellAmountFactory,
        float $value = 0.0,
        ?Config $msrpConfig = null,
        ?Msrp $msrp = null,
        ?Option $productOptions = null
    ) {
        $this->shellAmountFactory = $shellAmountFactory;
        $this->value              = $value;
        $this->msrpConfig         = $msrpConfig;
        $this->msrp               = $msrp;
        $this->productOptions     = $productOptions;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getMinimalValue()
    {
        return $this->value;
    }

    public function getMaximalValue()
    {
        return $this->value;
    }

    public function getPriceCode()
    {
        return 'shell_price';
    }

    public function getCustomAmount($amount = null, $exclude = null, $context = [])
    {
        return $this->value;
    }

    public function getAmount()
    {
        // create a ShellAmount object for the price
        return $this->shellAmountFactory->create(['value' => $this->value]);
    }

    /**
     * If code calls getMinimalPrice() => MUST return a PriceInterface object, not float.
     */
    public function getMinimalPrice()
    {
        // simplest approach: return $this (the same price object).
        // Or if you store a distinct minimalValue, build a new ShellPrice with that.
        return $this;
    }

    /**
     * If code calls getMaximalPrice() => MUST return a PriceInterface object, not float.
     */
    public function getMaximalPrice()
    {
        // likewise, return $this or a new ShellPrice for the "max" scenario.
        return $this;
    }

    /**
     * If code calls canApplyMsrp($product, $visibility),
     * check Msrp config before calling isEnabled().
     */
    public function canApplyMsrp($product, $visibility = null)
    {
        if (!$this->msrpConfig || !$this->msrpConfig->isEnabled() || !$this->msrp) {
            return false;
        }

        $result = $this->msrp->canApplyToProduct($product);
        if ($result && $visibility !== null) {
            $priceVisibility = $product->getMsrpDisplayActualPriceType();
            if ($priceVisibility == \Magento\Msrp\Model\Product\Attribute\Source\Type\Price::TYPE_USE_CONFIG) {
                $priceVisibility = $this->msrpConfig->getDisplayActualPriceType();
            }
            $result = ($priceVisibility == $visibility);
        }

        return $result;
    }

    /**
     * Stub required by MinimalTierPriceCalculator and configurable product templates.
     */
    public function getTierPriceList()
    {
        return [];
    }

    /**
     * Needed for final_price.phtml templates.
     */
    public function getProduct()
    {
        return $this->productOptions ? $this->productOptions->getProduct() : null;
    }

}
