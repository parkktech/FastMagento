<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Test\Unit\Plugin;

use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\ConfigurableProduct\Block\Product\View\Type\Configurable;
use Magento\Framework\Serialize\Serializer\Json;
use ParkkTech\FastMagento\Model\ShellProduct\ShellNoEavProduct;
use ParkkTech\FastMagento\Plugin\ConfigurableImagesPlugin;
use PHPUnit\Framework\TestCase;

class ConfigurableImagesPluginTest extends TestCase
{
    private function block(array $children): Configurable
    {
        $product = $this->createMock(ShellNoEavProduct::class);
        $product->method('getData')->with('child_products')->willReturn($children);
        $block = $this->createMock(Configurable::class);
        $block->method('getProduct')->willReturn($product);
        // Never re-load allowed children to recover image data.
        $block->expects(self::never())->method('getAllowProducts');
        return $block;
    }

    public function testEmptyThirdPartyImagesUseIndexedVariantsAndDeduplicateUrlWork(): void
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->expects(self::exactly(6))->method('getUrl')
            ->willReturnCallback(fn($file, $role) => '/' . $role . $file);
        $block = $this->block([
            ['entity_id'=>245, 'image'=>'/blue.jpg'],
            ['entity_id'=>246, 'image'=>'/green.jpg'],
            ['entity_id'=>248, 'image'=>'/blue.jpg'],
            ['entity_id'=>999, 'image'=>'/unavailable.jpg'],
        ]);
        $json = new Json();
        $result = $json->unserialize((new ConfigurableImagesPlugin($urls, $json))->afterGetJsonConfig(
            $block, $json->serialize(['images'=>[], 'index'=>[245=>[],246=>[],248=>[]], 'third_party'=>'retained'])
        ));
        self::assertSame([245,246,248], array_keys($result['images']));
        self::assertSame('/product_page_image_medium/green.jpg', $result['images'][246][0]['img']);
        self::assertSame('retained', $result['third_party']);
    }

    public function testExistingImageConfigurationIsReturnedUnchanged(): void
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->expects(self::never())->method('getUrl');
        $input = '{"images":{"245":[{"img":"custom-image"}]},"index":{"245":[]}}';
        self::assertSame($input, (new ConfigurableImagesPlugin($urls,new Json()))
            ->afterGetJsonConfig($this->block([]), $input));
    }

    public function testListingRendererDoesNotExpandGalleryPayload(): void
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->expects(self::never())->method('getUrl');
        $block = $this->createMock(\Magento\Swatches\Block\Product\Renderer\Listing\Configurable::class);
        $block->expects(self::never())->method('getProduct');
        $input = '{"images":[],"index":{"245":[]}}';
        self::assertSame($input,(new ConfigurableImagesPlugin($urls,new Json()))->afterGetJsonConfig($block,$input));
    }

    public function testIndexedGalleryPreservesVideoAndOmitsDisabledImages(): void
    {
        $urls = $this->createMock(UrlBuilder::class);
        $urls->expects(self::never())->method('getUrl');
        $block = $this->block([['entity_id'=>245, 'image'=>'https://cdn.example/main.jpg', 'media_gallery'=>['images'=>[
            ['file'=>'https://cdn.example/main.jpg', 'label'=>'Main', 'position'=>2],
            ['file'=>'/hidden.jpg', 'disabled'=>1],
            ['file'=>'https://cdn.example/video.jpg', 'media_type'=>'external-video', 'video_url'=>'https://video.example/watch'],
        ]]]]);
        $json = new Json();
        $result = $json->unserialize((new ConfigurableImagesPlugin($urls,$json))->afterGetJsonConfig(
            $block, '{"images":[],"index":{"245":[]}}'
        ));
        self::assertCount(2,$result['images'][245]);
        self::assertTrue($result['images'][245][0]['isMain']);
        self::assertSame('video',$result['images'][245][1]['type']);
        self::assertSame('https://video.example/watch',$result['images'][245][1]['videoUrl']);
    }
}
