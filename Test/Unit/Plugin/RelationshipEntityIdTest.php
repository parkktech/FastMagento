<?php
declare(strict_types=1);

namespace ParkkTech\FastMagento\Test\Unit\Plugin;

use ParkkTech\FastMagento\Plugin\Mview\RelationshipEntityId;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\ViewInterface;
use ParkkTech\FastMagento\Model\Db\EntityLink;
use PHPUnit\Framework\TestCase;

class RelationshipEntityIdTest extends TestCase
{
    /** @dataProvider subscriptions */
    #[\PHPUnit\Framework\Attributes\DataProvider('subscriptions')]
    public function testOnlyCommerceFastMagentoParentLinksAreTranslated(
        bool $staged,
        string $viewId,
        string $table,
        string $prefix,
        string $original,
        string $expected
    ): void {
        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('quoteIdentifier')->willReturnCallback(static fn ($name) => '`' . $name . '`');
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnCallback(static fn ($name) => 'demo_' . $name);
        $link = $this->createMock(EntityLink::class);
        $link->method('isProductStaged')->willReturn($staged);
        $subject = $this->createMock(Subscription::class);
        $subject->method('getTableName')->willReturn('demo_' . $table);
        $view = $this->createMock(ViewInterface::class);
        $view->method('getId')->willReturn($viewId);

        self::assertSame($expected, (new RelationshipEntityId($resource, $link))
            ->afterGetEntityColumn($subject, $original, $prefix, $view));
    }

    public static function subscriptions(): array
    {
        return [
            'Commerce linked parent insert' => [true, 'fastmagento_product', 'catalog_product_link', 'NEW.', 'NEW.`product_id`', '(SELECT entity_id FROM `demo_catalog_product_entity` WHERE row_id = NEW.`product_id`)'],
            'Commerce bundle parent delete' => [true, 'fastmagento_product', 'catalog_product_bundle_selection', 'OLD.', 'OLD.`parent_product_id`', '(SELECT entity_id FROM `demo_catalog_product_entity` WHERE row_id = OLD.`parent_product_id`)'],
            'Open Source' => [false, 'fastmagento_product', 'catalog_product_link', 'NEW.', 'NEW.`product_id`', 'NEW.`product_id`'],
            'Native indexer unchanged' => [true, 'catalog_product_price', 'catalog_product_link', 'NEW.', 'NEW.`product_id`', 'NEW.`product_id`'],
            'Configurable child already entity id' => [true, 'fastmagento_product', 'catalog_product_super_link', 'NEW.', 'NEW.`product_id`', 'NEW.`product_id`'],
            'Native staged EAV translation preserved' => [true, 'fastmagento_product', 'catalog_product_entity_int', 'NEW.', '@entity_id', '@entity_id'],
        ];
    }
}
