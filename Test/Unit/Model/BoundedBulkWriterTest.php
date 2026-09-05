<?php
declare(strict_types=1);
namespace ParkkTech\FastMagento\Test\Unit\Model;
use ParkkTech\FastMagento\Model\OpenSearch\BoundedBulkWriter;
use PHPUnit\Framework\TestCase;
class BoundedBulkWriterTest extends TestCase
{
    private function documents(int $count): array {
        return array_map(fn($id)=>['id'=>$id,'body'=>['value'=>str_repeat('日',180)]],range(1,$count));
    }
    private function client(?callable $behavior = null): object {
        return new class($behavior) {
            public array $calls=[];
            public function __construct(private $behavior) {}
            public function bulk(array $request): array {
                $this->calls[]=$request['body'];
                $lines=explode("\n",trim($request['body']));$items=[];
                for($i=0;$i<count($lines);$i+=2){$id=json_decode($lines[$i],true)['index']['_id'];$items[]=['index'=>['_id'=>$id,'status'=>201]];}
                return $this->behavior ? ($this->behavior)(count($this->calls),$items) : ['items'=>$items];
            }
        };
    }
    public function testRequestsBoundUtf8Bytes(): void {
        $client=$this->client();self::assertSame(5,(new BoundedBulkWriter(1024,3,0))->write($client,'test',$this->documents(5)));
        self::assertCount(5,$client->calls);foreach($client->calls as $body){self::assertLessThanOrEqual(1024,strlen($body));}
    }
    public function testRetriesOnlyRejectedItems(): void {
        $client=$this->client(function($call,$items){if($call===1){$items[1]['index']['status']=429;}return ['items'=>$items];});
        (new BoundedBulkWriter(10000,3,0))->write($client,'test',$this->documents(3));
        self::assertCount(2,$client->calls);self::assertStringContainsString('"_id":"2"',$client->calls[1]);self::assertStringNotContainsString('"_id":"1"',$client->calls[1]);
    }
    public function testCircuitBreakerSplitsAndRecovers(): void {
        $client=$this->client(function($call,$items){if($call===1){throw new \RuntimeException('circuit_breaking_exception',429);}return ['items'=>$items];});
        self::assertSame(4,(new BoundedBulkWriter(10000,3,0))->write($client,'test',$this->documents(4)));self::assertCount(3,$client->calls);
    }
    public function testPersistentRejectionFails(): void {
        $client=$this->client(function(){throw new \RuntimeException('circuit_breaking_exception',429);});
        $this->expectExceptionMessage('after bounded retries');
        try{(new BoundedBulkWriter(10000,2,0))->write($client,'test',$this->documents(1));}finally{self::assertCount(3,$client->calls);}
    }
    public function testFatalMappingErrorIsNotRetried(): void {
        $client=$this->client(fn($call,$items)=>['items'=>[['index'=>['_id'=>'1','status'=>400,'error'=>['reason'=>'immense term']]]]]);
        $this->expectExceptionMessage('immense term');try{(new BoundedBulkWriter(10000,3,0))->write($client,'test',$this->documents(1));}finally{self::assertCount(1,$client->calls);}
    }
    public function testIncompleteResponseFails(): void {
        $this->expectExceptionMessage('incomplete bulk response');(new BoundedBulkWriter(10000,3,0))->write($this->client(fn()=>['items'=>[]]),'test',$this->documents(1));
    }
    public function testOversizedSingleDocumentFailsBeforeSending(): void {
        $client=$this->client();$this->expectExceptionMessage('above the 1024-byte bulk limit');
        try{(new BoundedBulkWriter(1024,3,0))->write($client,'test',[['id'=>1,'body'=>['html'=>str_repeat('x',2000)]]]);}finally{self::assertCount(0,$client->calls);}
    }
}
