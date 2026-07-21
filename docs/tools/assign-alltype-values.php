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

function optMap($eavConfig,$code){ $a=$eavConfig->getAttribute(Product::ENTITY,$code); $m=[];
  foreach($a->getSource()->getAllOptions() as $o){ if($o['value']!==''&&$o['value']!==null) $m[$o['label']]=$o['value']; } return $m; }
$plat = optMap($eavConfig,'compatible_platforms');
$fmt  = optMap($eavConfig,'included_formats');

$ids = $conn->fetchCol("SELECT entity_id FROM catalog_product_entity");

// buckets
$b = ['weld_ready'=>[1=>[],0=>[]], 'hardware_included'=>[1=>[],0=>[]],
      'compatible_platforms'=>[], 'included_formats'=>[], 'revision_code'=>[], 'install_notes'=>[]];

// multiselect combos (value = comma-joined option ids)
$platCombos = [
  implode(',',array_filter([$plat['Universal']??null])),
  implode(',',array_filter([$plat['Jeep']??null,$plat['Universal']??null])),
  implode(',',array_filter([$plat['Ford']??null,$plat['Toyota']??null])),
  implode(',',array_filter([$plat['Polaris']??null,$plat['Can-Am']??null,$plat['Universal']??null])),
];
$fmtCombos = [
  implode(',',array_filter([$fmt['DXF']??null])),
  implode(',',array_filter([$fmt['DXF']??null,$fmt['DWG']??null])),
  implode(',',array_filter([$fmt['DXF']??null,$fmt['STEP']??null,$fmt['PDF']??null])),
  implode(',',array_filter([$fmt['STL']??null,$fmt['STEP']??null])),
];
$revs = ['REV-A','REV-B','REV-C'];
$notes = [
  'Recommended: TIG weld, back-purge all chromoly joints. Fixture before final weld.',
  'Plasma/laser ready DXF. Deburr and test-fit before welding.',
  'Includes weld-prep bevels. Verify fitment against your chassis before cutting.',
];

foreach ($ids as $i => $id) {
    $b['weld_ready'][$id % 2 === 0 ? 1 : 0][] = $id;
    $b['hardware_included'][$id % 3 === 0 ? 1 : 0][] = $id;
    $b['compatible_platforms'][$platCombos[$id % count($platCombos)]][] = $id;
    $b['included_formats'][$fmtCombos[$id % count($fmtCombos)]][] = $id;
    $b['revision_code'][$revs[$id % count($revs)]][] = $id;
    $b['install_notes'][$notes[$id % count($notes)]][] = $id;
}

foreach ($b as $code => $byVal) {
    $tot = 0;
    foreach ($byVal as $val => $pids) {
        if ($val === '' || empty($pids)) continue;
        $action->updateAttributes($pids, [$code => $val], 0);
        $tot += count($pids);
    }
    echo "$code: set on $tot products\n";
}
echo "done\n";
