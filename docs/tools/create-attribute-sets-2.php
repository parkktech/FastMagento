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
// each set: distinct attribute composition; mapped to a part_type
$sets=[
 'Chassis Mounts'=>[['size','material','weld_ready','hardware_included','make','model','compatible_platforms'],['Chassis Mount']],
 'Bumpers & Frame Horns'=>[['size','material','file_format','vehicle_type','weld_ready','install_notes'],['Bumper / Frame Horn']],
 'Chassis Assemblies'=>[['size','material','make','model','year','vehicle_type','compatible_platforms','revision_code'],['Chassis Assembly']],
 'Tabs & Brackets'=>[['material','file_format','included_formats','hardware_included'],['Tab / Gusset / Bracket']],
 'Leaf Spring'=>[['size','material','make','model','year','shock_spacing'],['Leaf Spring Component']],
];
$made=[];
foreach($sets as $name=>$spec){ [$attrs,$labels]=$spec;
  $sid=$conn->fetchOne("SELECT attribute_set_id FROM eav_attribute_set WHERE attribute_set_name=? AND entity_type_id=?",[$name,$etId]);
  if(!$sid){ $s=$setFactory->create(); $s->setAttributeSetName($name)->setEntityTypeId($etId); $s=$setRepo->create(Product::ENTITY,$s,$defSet); $sid=$s->getAttributeSetId(); }
  $eav->addAttributeGroup(Product::ENTITY,$sid,'Fitment',90);
  foreach($attrs as $c) $eav->addAttributeToSet(Product::ENTITY,$sid,'Fitment',$c);
  $made[$name]=[$sid,$labels]; echo "set $name (#$sid): ".count($attrs)." attrs\n";
}
$optTxt=function($lbl)use($om){$a=$om->get(\Magento\Eav\Model\Config::class)->getAttribute(Product::ENTITY,'part_type');foreach($a->getSource()->getAllOptions() as $o)if($o['label']==$lbl)return $o['value'];return null;};
$ptId=$eav->getAttributeId(Product::ENTITY,'part_type');
foreach($made as $name=>[$sid,$labels]){ $optIds=array_filter(array_map($optTxt,$labels)); if(!$optIds)continue;
  $in=implode(',',array_map('intval',$optIds));
  $pids=$conn->fetchCol("SELECT entity_id FROM catalog_product_entity_int WHERE attribute_id=$ptId AND store_id=0 AND value IN ($in)");
  if($pids){ $conn->update('catalog_product_entity',['attribute_set_id'=>$sid],['entity_id IN (?)'=>$pids]); echo "moved ".count($pids)." -> '$name'\n"; }
}
echo "done\n";
