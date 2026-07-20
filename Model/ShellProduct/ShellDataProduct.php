<?php

namespace ParkkTech\FastMagento\Model\ShellProduct;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductExtensionInterface;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductAttributeMediaGalleryEntryInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductTierPriceInterface;
use Magento\Catalog\Model\Product\Url as ProductUrlModel;
use Magento\Framework\Api\AttributeInterface;
use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Pricing\PriceInfoInterface;

/**
 * "ShellDataProduct" implements ProductInterface
 * WITHOUT extending \Magento\Catalog\Model\Product => no EAV load.
 *
 * - We store data from OpenSearch in $this->doc.
 * - We add getPriceInfo() returning a ShellPriceInfo so UI code won't crash.
 * - We also inject ProductUrlModel to support getUrlModel() and getUrl().
 */
class ShellDataProduct extends DataObject implements ProductInterface
{
    /**
     * Arbitrary data from OpenSearch (entity_id, sku, etc.)
     */
    protected array $doc = [];

    /**
     * A custom "price info" object (ShellPriceInfo).
     */
    protected ?PriceInfoInterface $priceInfo = null;

    /**
     * Extension attributes, if needed.
     */
    protected ?ProductExtensionInterface $extensionAttributes = null;

    /**
     * The Magento product URL model (for rewrites, getUrl(), etc.).
     */
    private ProductUrlModel $urlModel;

    /**
     * Constructor
     *
     * @param ProductUrlModel           $urlModel  Injected product URL model
     * @param array                     $doc       OpenSearch doc
     * @param PriceInfoInterface|null   $priceInfo e.g. ShellPriceInfo
     * @param array                     $data      Optional parent DataObject data
     */
    public function __construct(
        ProductUrlModel $urlModel,
        array $doc = [],
        ?PriceInfoInterface $priceInfo = null,
        array $data = []
    ) {
        parent::__construct($data);
        $this->urlModel  = $urlModel;
        $this->doc       = $doc;
        $this->priceInfo = $priceInfo;
    }

    /**
     * If code calls $product->getUrlModel(),
     * return the injected URL model (supports rewrites, SEO, etc.).
     */
    public function getUrlModel(): ProductUrlModel
    {
        return $this->urlModel;
    }

    /**
     * If code calls $product->getUrl($useSid), we return a rewritable URL
     * using the Magento URL model.
     * This is crucial for modules like Swissup/SeoCanonical.
     */
    public function getUrl($useSid = null): string
    {
        return $this->urlModel->getProductUrl($this, $useSid);
    }

    /**
     * A custom method so Magento collector can do:
     * $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue()
     */
    public function getPriceInfo(): PriceInfoInterface
    {
        // If not provided, create a default or a "blank" ShellPriceInfo
        if (!$this->priceInfo) {
            $this->priceInfo = new ShellPriceInfo(/* pass needed args if you want */);
        }
        return $this->priceInfo;
    }

    public function setPriceInfo(PriceInfoInterface $priceInfo)
    {
        $this->priceInfo = $priceInfo;
        return $this;
    }

    /**
     * --------------------------------------------------------------------
     * Implement all methods from ProductInterface:
     *   getId, setId, getSku, setSku, getName, setName, etc...
     * --------------------------------------------------------------------
     */

    public function getId()
    {
        return $this->doc['entity_id'] ?? null;
    }

    public function setId($id)
    {
        $this->doc['entity_id'] = $id;
        return $this;
    }

    public function getSku()
    {
        return $this->doc['sku'] ?? '';
    }

    public function setSku($sku)
    {
        $this->doc['sku'] = $sku;
        return $this;
    }

    public function getName()
    {
        return $this->doc['name'] ?? null;
    }

    public function setName($name)
    {
        $this->doc['name'] = $name;
        return $this;
    }

    public function getAttributeSetId()
    {
        return $this->doc['attribute_set_id'] ?? null;
    }

    public function setAttributeSetId($attributeSetId)
    {
        $this->doc['attribute_set_id'] = $attributeSetId;
        return $this;
    }

    public function getPrice()
    {
        return isset($this->doc['price']) ? (float)$this->doc['price'] : null;
    }

    public function setPrice($price)
    {
        $this->doc['price'] = $price;
        return $this;
    }

    public function getStatus()
    {
        return isset($this->doc['status']) ? (int)$this->doc['status'] : null;
    }

    public function setStatus($status)
    {
        $this->doc['status'] = $status;
        return $this;
    }

    public function getVisibility()
    {
        return isset($this->doc['visibility']) ? (int)$this->doc['visibility'] : null;
    }

    public function setVisibility($visibility)
    {
        $this->doc['visibility'] = $visibility;
        return $this;
    }

    public function getTypeId()
    {
        return $this->doc['type_id'] ?? null;
    }

    public function setTypeId($typeId)
    {
        $this->doc['type_id'] = $typeId;
        return $this;
    }

    public function getCreatedAt()
    {
        return $this->doc['created_at'] ?? null;
    }

    public function setCreatedAt($createdAt)
    {
        $this->doc['created_at'] = $createdAt;
        return $this;
    }

    public function getUpdatedAt()
    {
        return $this->doc['updated_at'] ?? null;
    }

    public function setUpdatedAt($updatedAt)
    {
        $this->doc['updated_at'] = $updatedAt;
        return $this;
    }

    public function getWeight()
    {
        return isset($this->doc['weight']) ? (float)$this->doc['weight'] : null;
    }

    public function setWeight($weight)
    {
        $this->doc['weight'] = $weight;
        return $this;
    }

    public function getExtensionAttributes()
    {
        return $this->extensionAttributes;
    }

    public function setExtensionAttributes(\Magento\Catalog\Api\Data\ProductExtensionInterface $extensionAttributes)
    {
        $this->extensionAttributes = $extensionAttributes;
        return $this;
    }

    public function getProductLinks()
    {
        return $this->doc['product_links'] ?? null;
    }

    public function setProductLinks(?array $links = null)
    {
        $this->doc['product_links'] = $links;
        return $this;
    }

    public function getOptions()
    {
        return $this->doc['options'] ?? null;
    }

    public function setOptions(?array $options = null)
    {
        $this->doc['options'] = $options;
        return $this;
    }

    public function getMediaGalleryEntries()
    {
        return $this->doc['media_gallery_entries'] ?? null;
    }

    public function setMediaGalleryEntries(?array $mediaGalleryEntries = null)
    {
        $this->doc['media_gallery_entries'] = $mediaGalleryEntries;
        return $this;
    }

    public function getTierPrices()
    {
        return $this->doc['tier_prices'] ?? null;
    }

    public function setTierPrices(?array $tierPrices = null)
    {
        $this->doc['tier_prices'] = $tierPrices;
        return $this;
    }

    /**
     * Inherited from \Magento\Framework\Api\CustomAttributesDataInterface
     * we must implement:
     *  - getCustomAttributes(), setCustomAttributes($attributes),
     *  - getCustomAttribute($code), setCustomAttribute($code, $value)
     */
    public function getCustomAttributes()
    {
        // Typically returns an array of \Magento\Framework\Api\AttributeInterface
        return [];
    }

    public function setCustomAttributes(array $attributes)
    {
        return $this;
    }

    public function getCustomAttribute($attributeCode)
    {
        return null;
    }

    public function setCustomAttribute($attributeCode, $attributeValue)
    {
        $this->doc[$attributeCode] = $attributeValue;
        return $this;
    }
}
