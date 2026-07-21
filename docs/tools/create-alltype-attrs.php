<?php
use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;

require '/var/www/html/diyoffroad/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$eavSetup = $om->get(\Magento\Eav\Setup\EavSetupFactory::class)->create();

// --- 1. Create attributes covering every input type ---
$defs = [
  // boolean (yes/no) - filterable
  'weld_ready'          => ['boolean','int','Weld Ready', null, true],
  'hardware_included'   => ['boolean','int','Hardware Included', null, true],
  // text - product level (not a facet)
  'revision_code'       => ['text','varchar','Revision Code', null, false],
  // textarea - product level
  'install_notes'       => ['textarea','text','Install Notes', null, false],
  // multiselect - filterable. backend_type MUST be 'text' (values in
  // catalog_product_entity_text): the core EAV Source indexer only indexes
  // multiselect attrs where backend_type='text' (Source.php _getIndexableAttributes),
  // so 'varchar' silently yields 0 rows in catalog_product_index_eav (no facet).
  'compatible_platforms'=> ['multiselect','text','Compatible Platforms', ['Jeep','Ford','Chevrolet','Toyota','Polaris','Can-Am','Universal'], true],
  'included_formats'    => ['multiselect','text','Included Formats', ['DXF','DWG','STEP','PDF','STL'], true],
];
foreach ($defs as $code => [$input,$type,$label,$options,$filterable]) {
    if ($eavSetup->getAttributeId(Product::ENTITY, $code)) { echo "SKIP $code\n"; continue; }
    $data = [
        'type'=>$type, 'input'=>$input, 'label'=>$label,
        'global'=>ScopedAttributeInterface::SCOPE_GLOBAL,
        'required'=>false, 'user_defined'=>true, 'default'=>'',
        'visible'=>true, 'visible_on_front'=>true,
        'is_filterable'=> $filterable ? 2 : 0,
        'is_filterable_in_search'=> $filterable,
        'used_in_product_listing'=>true, 'searchable'=>true,
        'group'=>'Fitment',
    ];
    if ($input==='boolean') $data['source']=\Magento\Eav\Model\Entity\Attribute\Source\Boolean::class;
    if ($input==='multiselect') { $data['source']=\Magento\Eav\Model\Entity\Attribute\Source\Table::class; $data['backend']=\Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend::class; $data['option']=['values'=>$options]; }
    $eavSetup->addAttribute(Product::ENTITY, $code, $data);
    echo "CREATE $code ($input)\n";
}

// --- 2. Add ALL new attributes to the sets where products actually live ---
$allNew = ['size','vehicle_type','make','model','year','material','file_format','part_type','link_style','shock_spacing',
           'weld_ready','hardware_included','revision_code','install_notes','compatible_platforms','included_formats'];
foreach (['Default','C2c'] as $setName) {
    $setId = $eavSetup->getAttributeSetId(Product::ENTITY, $setName);
    if (!$setId) { echo "no set $setName\n"; continue; }
    $eavSetup->addAttributeGroup(Product::ENTITY, $setId, 'Fitment', 100);
    foreach ($allNew as $code) {
        $eavSetup->addAttributeToSet(Product::ENTITY, $setId, 'Fitment', $code);
    }
    echo "added ".count($allNew)." attrs to set '$setName'\n";
}
echo "done\n";
