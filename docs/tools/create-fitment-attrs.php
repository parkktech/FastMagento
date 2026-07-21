<?php
use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;

require '/var/www/html/diyoffroad/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$eavSetup = $om->get(\Magento\Eav\Setup\EavSetupFactory::class)->create();
$eavConfig = $om->get(\Magento\Eav\Model\Config::class);

// code => [label, [options...]]
$attrs = [
  'size'          => ['Size',           ['1"','1.25"','1.5"','1.75"','2.0"','40"','55"','60"','84"']],
  'vehicle_type'  => ['Vehicle Type',   ['Jeep','Truck','UTV / SxS','Buggy','Rock Crawler','Desert / Pre-Runner','Trophy Truck','Rock Bouncer','Universal']],
  'make'          => ['Make',           ['Jeep','Ford','Chevrolet','GMC','Toyota','Ram / Dodge','Nissan','Polaris','Can-Am','Universal']],
  'model'         => ['Model',          ['Wrangler','Gladiator','Bronco','Tacoma','4Runner','Tundra','F-150','Silverado','RZR','Universal']],
  'year'          => ['Year',           ['1995-1999','2000-2005','2006-2010','2011-2015','2016-2020','2021-2025','Universal']],
  'material'      => ['Material',        ['Steel (HREW)','Steel (DOM)','Chromoly 4130','Aluminum']],
  'file_format'   => ['File Format',     ['DXF','DWG','STEP / STP','PDF','3D Model (STL/STEP)']],
  'part_type'     => ['Part Type',       ['Link Arm','Sway Bar Arm','Chassis Mount','Bumper / Frame Horn','Fixture / Tool','Traction Bar','Leaf Spring Component','Tab / Gusset / Bracket','Chassis Assembly','Exhaust Component']],
  'link_style'    => ['Link Style',      ['Boxed','Clevis','Tube']],
  'shock_spacing' => ['Shock Spacing',   ['Standard','Offset']],
];

$setId = $eavSetup->getDefaultAttributeSetId(Product::ENTITY);
$created = 0; $skipped = 0;
foreach ($attrs as $code => $spec) {
    [$label, $options] = $spec;
    if ($eavSetup->getAttributeId(Product::ENTITY, $code)) {
        echo "SKIP  $code (exists)\n"; $skipped++; continue;
    }
    $eavSetup->addAttribute(Product::ENTITY, $code, [
        'type'                     => 'int',
        'input'                    => 'select',
        'label'                    => $label,
        'source'                   => \Magento\Eav\Model\Entity\Attribute\Source\Table::class,
        'global'                   => ScopedAttributeInterface::SCOPE_GLOBAL,
        'required'                 => false,
        'user_defined'             => true,
        'default'                  => '',
        'visible'                  => true,
        'visible_on_front'         => true,
        'is_filterable'            => 2,      // filterable (with results)
        'is_filterable_in_search'  => true,
        'used_in_product_listing'  => true,
        'searchable'               => true,
        'comparable'               => false,
        'unique'                   => false,
        'group'                    => 'Fitment',
        'option'                   => ['values' => $options],
    ]);
    echo "CREATE $code -> ".count($options)." options\n"; $created++;
}
echo "\ndone: $created created, $skipped skipped\n";
