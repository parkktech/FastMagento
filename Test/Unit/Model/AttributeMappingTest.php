<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Test\Unit\Model;
use ParkkTech\FastMagento\Model\Indexer\ProductIndexer;
use Magento\Eav\Api\Data\AttributeInterface;
use PHPUnit\Framework\TestCase;
class AttributeMappingTest extends TestCase
{
    /** @dataProvider cases */
    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function testMapping(string $input,string $backend,string $type): void {
        $a=$this->createMock(AttributeInterface::class);$a->method('getFrontendInput')->willReturn($input);$a->method('getBackendType')->willReturn($backend);
        $indexer=(new \ReflectionClass(ProductIndexer::class))->newInstanceWithoutConstructor();
        $mapping=(new \ReflectionMethod(ProductIndexer::class,'attributeFieldMapping'))->invoke($indexer,$a);
        self::assertSame($type,$mapping['type']);if($type==='keyword'){self::assertLessThanOrEqual(8191,$mapping['ignore_above']);}
    }
    public static function cases():array{return [['textarea','text','text'],['texteditor','text','text'],['pagebuilder','text','text'],['text','text','text'],['select','int','keyword'],['multiselect','text','keyword']];}
}
