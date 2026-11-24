<?php
declare(strict_types=1);
namespace Cajeer\Logs\AI;
use Cajeer\Logs\Http\HttpException;

final class OpenAIClient
{
    public const URL='https://api.openai.com/v1/responses';
    private const MAX_RESPONSE=1048576;
    public function __construct(private readonly string $key,private readonly ?\Closure $transport=null) {
        if ($key===''||strlen($key)>512||preg_match('/[^\x21-\x7e]/',$key)) throw new HttpException(503,'ai_unavailable','OpenAI is not configured.');
    }
    public function answer(array $payload): array
    {
        $body=json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (strlen($body)>100000) throw new HttpException(422,'invalid_payload','Assistant context too large.');
        $transport=$this->transport??$this->send(...);
        [$status,$raw]=$transport(self::URL,$body,$this->key);
        if ($status===429) throw new HttpException(429,'ai_rate_limited','OpenAI request limit reached. Retry later.');
        if ($status!==200||!is_string($raw)||strlen($raw)>self::MAX_RESPONSE) self::unavailable();
        try { $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR); } catch (\JsonException) { self::unavailable(); }
        if (!is_array($data)||($data['status']??null)!=='completed'||!is_array($data['output']??null)) self::unavailable();
        $parts=[];
        foreach ($data['output'] as $item) {
            if (($item['type']??null)!=='message'||($item['role']??null)!=='assistant'||!is_array($item['content']??null)) continue;
            foreach ($item['content'] as $part) {
                $text=match($part['type']??null){'output_text'=>$part['text']??null,'refusal'=>$part['refusal']??null,default=>null};
                if (is_string($text)&&trim($text)!=='') $parts[]=$text;
            }
        }
        $answer=implode("\n",$parts);
        if ($answer===''||strlen($answer)>24000) self::unavailable();
        $usage=[]; foreach(['input_tokens','output_tokens','total_tokens'] as $field) if(is_int($data['usage'][$field]??null)&&$data['usage'][$field]>=0) $usage[$field]=$data['usage'][$field];
        return ['answer'=>Assistant::mask($answer),'usage'=>(object)$usage];
    }
    private function send(string $url,string $body,string $key): array
    {
        if (!function_exists('curl_init')) self::unavailable();
        $handle=curl_init($url); if ($handle===false) self::unavailable();
        $raw='';
        try {
            curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>40,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk) use (&$raw): int { if (strlen($raw)+strlen($chunk)>self::MAX_RESPONSE) return 0; $raw.=$chunk; return strlen($chunk); }]);
            if (curl_exec($handle)===false) self::unavailable();
            return [(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE),$raw];
        } finally { curl_close($handle); }
    }
    private static function unavailable(): never { throw new HttpException(503,'ai_unavailable','OpenAI response unavailable.'); }
}
