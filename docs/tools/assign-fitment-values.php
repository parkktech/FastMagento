<?php
use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Model\Product;

require '/var/www/html/diyoffroad/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$action    = $om->get(\Magento\Catalog\Model\Product\Action::class);
$eavConfig = $om->get(\Magento\Eav\Model\Config::class);
$conn      = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();

// option label -> option id, per attribute
function optMap($eavConfig, $code) {
    $attr = $eavConfig->getAttribute(Product::ENTITY, $code);
    $m = [];
    foreach ($attr->getSource()->getAllOptions() as $o) {
        if ($o['value'] !== '' && $o['value'] !== null) $m[strtolower($o['label'])] = $o['value'];
    }
    return $m;
}
$optLink  = optMap($eavConfig, 'link_style');
$optShock = optMap($eavConfig, 'shock_spacing');
$optPart  = optMap($eavConfig, 'part_type');

// product id -> lowercased name
$nameAttr = $eavConfig->getAttribute(Product::ENTITY, 'name')->getId();
$rows = $conn->fetchPairs("SELECT entity_id, value FROM catalog_product_entity_varchar WHERE attribute_id=$nameAttr AND store_id=0");

$g = ['link_style'=>[], 'shock_spacing'=>[], 'part_type'=>[]];
foreach ($rows as $id => $name) {
    $n = strtolower($name);
    // link_style
    if (strpos($n,'clevis')!==false)        $g['link_style'][$optLink['clevis']][] = $id;
    elseif (strpos($n,'boxed')!==false)      $g['link_style'][$optLink['boxed']][] = $id;
    elseif (strpos($n,'tube')!==false)       $g['link_style'][$optLink['tube']][] = $id;
    // shock_spacing
    if (strpos($n,'off-set')!==false || strpos($n,'offset')!==false) $g['shock_spacing'][$optShock['offset']][] = $id;
    elseif (strpos($n,'standard shock')!==false)                     $g['shock_spacing'][$optShock['standard']][] = $id;
    // part_type
    $pt = null;
    if (strpos($n,'sway bar')!==false)                     $pt='sway bar arm';
    elseif (strpos($n,'link arm')!==false||strpos($n,'link arms')!==false) $pt='link arm';
    elseif (strpos($n,'traction bar')!==false)             $pt='traction bar';
    elseif (strpos($n,'muffler')!==false||strpos($n,'exhaust')!==false)    $pt='exhaust component';
    elseif (strpos($n,'table')!==false||strpos($n,'fixture')!==false||strpos($n,'rotisserie')!==false) $pt='fixture / tool';
    elseif (strpos($n,'bumper')!==false||strpos($n,'frame horn')!==false)  $pt='bumper / frame horn';
    elseif (strpos($n,'tab')!==false||strpos($n,'gusset')!==false||strpos($n,'bracket')!==false) $pt='tab / gusset / bracket';
    elseif (strpos($n,'assembl')!==false||strpos($n,'bulkhead')!==false||strpos($n,'chassis')!==false||strpos($n,'roller')!==false) $pt='chassis assembly';
    elseif (strpos($n,'mount')!==false)                    $pt='chassis mount';
    elseif (strpos($n,'leaf spring')!==false)              $pt='leaf spring component';
    if ($pt && isset($optPart[$pt])) $g['part_type'][$optPart[$pt]][] = $id;
}

foreach ($g as $code => $byVal) {
    $tot = 0;
    foreach ($byVal as $optId => $ids) {
        $action->updateAttributes($ids, [$code => $optId], 0);
        $tot += count($ids);
    }
    echo "$code: assigned to $tot products across ".count($byVal)." values\n";
}
echo "done\n";
