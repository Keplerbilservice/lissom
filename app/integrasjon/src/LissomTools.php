<?php
declare(strict_types=1);
namespace Kepler\Integrasjon;
use Mcp\Server;
use Mcp\Schema\ToolAnnotations;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Content\TextContent;

final class LissomTools
{
    public static function server(\PDO $db,OAuth $oauth,?int $actor,string $internalKey): Server
    {
        $builder = Server::builder()->setServerInfo('lissom-courses-readonly','0.2.0')
            ->setSession(new SqlSessionStore($oauth->state),gcProbability:1,gcDivisor:100);
        $read = static function(string $operation,array $args) use ($db,$actor,$internalKey): CallToolResult {
            if ($actor === null) return CallToolResult::error([new TextContent('Innlogging kreves.')]);
            try {
                $alias = 'member-'.$actor;
                $raw = json_encode(['actor' => $alias,'operation' => $operation,'requestId' => self::uuid(),...$args],JSON_THROW_ON_ERROR);
                $time = (string)time(); $nonce = bin2hex(random_bytes(16));
                $reader = new \ChatgptKurser($db,$internalKey,[$alias => $actor]);
                $data = $reader->les($raw,['timestamp' => $time,'nonce' => $nonce,
                    'signature' => hash_hmac('sha256',"lissom-courses-read-v1\n$time\n$nonce\n$raw",$internalKey)],time());
                return new CallToolResult([new TextContent(json_encode($data,JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))],structuredContent:$data);
            } catch (\Throwable) { return CallToolResult::error([new TextContent('Opplysningene kunne ikke leses. Prøv igjen senere.')]); }
        };
        $annotation = new ToolAnnotations(readOnlyHint:true,destructiveHint:false,idempotentHint:true,openWorldHint:false);
        $meta = ['securitySchemes' => [['type' => 'oauth2','scopes' => [$oauth->scope]]]];
        $builder->addTool(static fn(int $afterId=0,int $limit=20) => $read('list',['afterId'=>$afterId,'limit'=>$limit]),
            name:'lissom_list_courses',title:'List Lissom-kurs',description:'Les kurs. Bare ID, tittel, slug, status og bilde.',
            annotations:$annotation,meta:$meta,inputSchema:['type'=>'object','properties'=>[
                'afterId'=>['type'=>'integer','minimum'=>0,'maximum'=>9007199254740991,'default'=>0],
                'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>50,'default'=>20]],'additionalProperties'=>false]);
        $builder->addTool(static fn(int $id) => $read('get',['id'=>$id]),
            name:'lissom_get_course',title:'Hent ett Lissom-kurs',description:'Les ett kurs uten å endre det.',
            annotations:$annotation,meta:$meta,inputSchema:['type'=>'object','properties'=>[
                'id'=>['type'=>'integer','minimum'=>1,'maximum'=>9007199254740991]],'required'=>['id'],'additionalProperties'=>false]);
        return $builder->build();
    }
    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex,0,8).'-'.substr($hex,8,4).'-4'.substr($hex,13,3).'-8'.substr($hex,17,3).'-'.substr($hex,20);
    }
}
