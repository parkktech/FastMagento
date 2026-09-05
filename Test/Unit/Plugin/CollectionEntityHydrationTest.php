<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Test\Unit\Plugin;
use ParkkTech\FastMagento\Plugin\CollectionEntityHydration;
use ParkkTech\FastMagento\Model\Plp\ListingHydrator;
use Magento\Framework\App\State;
use Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection;
use PHPUnit\Framework\TestCase;
class ThirdPartyCollectionFixture extends Collection { public function getThirdPartyFacet(): string { return 'preserved'; } }
class CollectionEntityHydrationTest extends TestCase
{
    public function testSameThirdPartyCollectionWithExistingHydratorAndNoNativeLoad(): void {
        $state=$this->createMock(State::class);$state->method('getAreaCode')->willReturn('frontend');
        $subject=(new \ReflectionClass(ThirdPartyCollectionFixture::class))->newInstanceWithoutConstructor();
        $hydrator=$this->createMock(ListingHydrator::class);$hydrator->method('isEnabled')->willReturn(true);
        $hydrator->expects(self::once())->method('hydrate')->with(self::identicalTo($subject))->willReturn(true);
        $result=(new CollectionEntityHydration($state,$hydrator))->around_loadEntities($subject,fn()=>self::fail('Native entity SQL must not execute on an index hit'));
        self::assertSame($subject,$result);self::assertSame('preserved',$result->getThirdPartyFacet());
    }
    public function testAdminDoesNotUseFrontendHydration(): void {
        $state=$this->createMock(State::class);$state->method('getAreaCode')->willReturn('adminhtml');
        $hydrator=$this->createMock(ListingHydrator::class);$hydrator->expects(self::never())->method('hydrate');
        $subject=$this->createMock(Collection::class);$calls=0;
        (new CollectionEntityHydration($state,$hydrator))->around_loadEntities($subject,function($print,$log)use(&$calls,$subject){$calls++;self::assertTrue($print);return $subject;},true,false);
        self::assertSame(1,$calls);
    }
}
