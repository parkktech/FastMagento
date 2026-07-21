<?php
use Magento\Framework\App\Bootstrap; use Magento\Catalog\Model\Product;
require '/var/www/html/diyoffroad/app/bootstrap.php';
$b=Bootstrap::create(BP,$_SERVER); $om=$b->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$eav=$om->get(\Magento\Eav\Setup\EavSetupFactory::class)->create();
$conn=$om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$setRepo=$om->get(\Magento\Eav\Api\AttributeSetManagementInterface::class);
$setFactory=$om->get(\Magento\Eav\Api\Data\AttributeSetInterfaceFactory::class);
$etId=$eav->getEntityTypeId(Product::ENTITY); $defSet=$eav->getDefaultAttributeSetId(Product::ENTITY);
// distinct attribute compositions per set
$sets=[
 'Suspension'=>['link_style','shock_spacing','size','make','model','year','vehicle_type','weld_ready','compatible_platforms','install_notes'],
 'Fixtures & Tools'=>['size','material','revision_code','install_notes','hardware_included','included_formats'],
 'Exhaust'=>['size','material','file_format','weld_ready','included_formats','revision_code'],
];
$made=[];
foreach($sets as $name=>$attrs){
  $sid=$conn->fetchOne("SELECT attribute_set_id FROM eav_attribute_set WHERE attribute_set_name=? AND entity_type_id=?",[$name,$etId]);
  if(!$sid){ $s=$setFactory->create(); $s->setAttributeSetName($name)->setEntityTypeId($etId);
    $s=$setRepo->create(Product::ENTITY,$s,$defSet); $sid=$s->getAttributeSetId(); }
  $eav->addAttributeGroup(Product::ENTITY,$sid,'Fitment',90);
  foreach($attrs as $c) $eav->addAttributeToSet(Product::ENTITY,$sid,'Fitment',$c);
  $made[$name]=$sid; echo "set $name (#$sid): ".count($attrs)." attrs\n";
}
// reassign products by part_type -> set
$ptId=$eav->getAttributeId(Product::ENTITY,'part_type');
$optTxt=function($lbl)use($om){$a=$om->get(\Magento\Eav\Model\Config::class)->getAttribute(Product::ENTITY,'part_type');foreach($a->getSource()->getAllOptions() as $o)if($o['label']==$lbl)return $o['value'];return null;};
$map=['Exhaust'=>['Exhaust Component'],'Fixtures & Tools'=>['Fixture / Tool'],'Suspension'=>['Link Arm','Sway Bar Arm','Traction Bar']];
foreach($map as $set=>$labels){ $optIds=array_filter(array_map($optTxt,$labels)); if(!$optIds)continue;
  $in=implode(',',array_map('intval',$optIds));
  $pids=$conn->fetchCol("SELECT entity_id FROM catalog_product_entity_int WHERE attribute_id=$ptId AND store_id=0 AND value IN ($in)");
  if($pids){ $conn->update('catalog_product_entity',['attribute_set_id'=>$made[$set]],['entity_id IN (?)'=>$pids]); echo "moved ".count($pids)." products to '$set'\n"; }
}
echo "done\n";
