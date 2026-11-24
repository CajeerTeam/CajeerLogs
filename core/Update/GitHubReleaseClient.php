<?php
declare(strict_types=1);
namespace Cajeer\Logs\Update;

/** Read-only GitHub REST client. Network destination is deliberately fixed (no SSRF input). */
final class GitHubReleaseClient
{
    public const REPOSITORY='CajeerTeam/CajeerLogs';
    private const API='https://api.github.com/repos/CajeerTeam/CajeerLogs/releases';
    private const PAGE_SIZE=30;
    private const MAX_PAGES=3;
    private const MAX_BODY_BYTES=524288;
    private readonly ?\Closure $transport;

    /** Optional transport is intended for deterministic tests only; production uses HTTPS. */
    public function __construct(?callable $transport=null)
    {
        $this->transport=$transport===null?null:\Closure::fromCallable($transport);
    }

    /** @return list<array<string,mixed>> */
    public function listPublished(): array
    {
        $releases=[];
        for ($page=1;$page<=self::MAX_PAGES;$page++) {
            $url=self::API.'?per_page='.self::PAGE_SIZE.'&page='.$page;
            [$status,$body]=$this->transport!==null?($this->transport)($url):self::httpsGet($url);
            if ($status!==200) {
                throw new \RuntimeException(match ($status) {
                    403,429=>'GitHub API rate limit or access restriction.',
                    404=>'GitHub releases repository is unavailable.',
                    default=>'GitHub Releases request failed (HTTP '.$status.').',
                });
            }
            if (!is_string($body) || strlen($body)>self::MAX_BODY_BYTES) throw new \RuntimeException('GitHub Releases response exceeds the size limit.');
            try { $items=json_decode($body,false,32,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \RuntimeException('GitHub Releases returned invalid JSON.'); }
            if (!is_array($items)||!array_is_list($items)) throw new \RuntimeException('GitHub Releases returned an invalid list.');
            if (count($items)>self::PAGE_SIZE) throw new \RuntimeException('GitHub Releases returned too many entries.');
            foreach ($items as $item) {
                if (!$item instanceof \stdClass) continue;
                $item=(array)$item;
                if (($item['draft']??true)!==false || !is_string($item['tag_name']??null)) continue;
                if (!is_string($item['published_at']??null) || strtotime($item['published_at'])===false) continue;
                try { $version=SemVer::normalize($item['tag_name']); } catch (\InvalidArgumentException) { continue; }
                $prerelease=$item['prerelease']??null;
                if (!is_bool($prerelease)) continue;
                // GitHub may mark a SemVer pre-release as stable by accident; fail closed for stable channel.
                $pre=(SemVer::parse($version))[1];
                $url='https://github.com/'.self::REPOSITORY.'/releases/tag/'.rawurlencode($item['tag_name']);
                $name=$item['name']??'';
                $body=$item['body']??'';
                $releases[]=[
                    'version'=>$version,'tag'=>$item['tag_name'],
                    'prerelease'=>$prerelease || $pre!==[],
                    'title'=>is_string($name)?self::limitUtf8($name,200):$version,
                    'changelog'=>is_string($body)?self::limitUtf8($body,16384):'',
                    'published_at'=>$item['published_at'], 'url'=>$url,
                ];
            }
            if (count($items)<self::PAGE_SIZE) break;
        }
        return $releases;
    }

    private static function limitUtf8(string $body,int $limit): string
    {
        // JSON decode enforces UTF-8; cut only at a character boundary, never return active markup.
        if (strlen($body)<=$limit) return $body;
        $cut=substr($body,0,$limit);
        while ($cut!=='' && !preg_match('//u',$cut)) $cut=substr($cut,0,-1);
        return $cut."\n\n[Changelog truncated. Open the GitHub release for the full text.]";
    }

    /** @return array{0:int,1:string} */
    private static function httpsGet(string $url): array
    {
        if (!str_starts_with($url,self::API.'?')) throw new \LogicException('Unexpected release API origin.');
        $context=stream_context_create([
            'http'=>[
                'method'=>'GET','timeout'=>5,'follow_location'=>0,'ignore_errors'=>true,
                'header'=>"Accept: application/vnd.github+json\r\nX-GitHub-Api-Version: 2022-11-28\r\nUser-Agent: CajeerLogs-UpdateDiscovery/1.0.4\r\nConnection: close\r\n",
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'SNI_enabled'=>true],
        ]);
        $fp=@fopen($url,'rb',false,$context);
        if ($fp===false) throw new \RuntimeException('Cannot connect to GitHub Releases over verified HTTPS.');
        try {
            $meta=stream_get_meta_data($fp);
            $headers=$meta['wrapper_data']??[];
            $status=0;
            foreach ($headers as $header) {
                if (preg_match('~^HTTP/[0-9.]+\s+([0-9]{3})(?:\s|$)~i',$header,$m)) $status=(int)$m[1];
            }
            if ($status<100 || $status>599) throw new \RuntimeException('GitHub returned an invalid HTTP status.');
            $body=stream_get_contents($fp,self::MAX_BODY_BYTES+1);
            if ($body===false) throw new \RuntimeException('GitHub Releases response could not be read.');
            if (strlen($body)>self::MAX_BODY_BYTES) throw new \RuntimeException('GitHub Releases response exceeds the size limit.');
            return [$status,$body];
        } finally { fclose($fp); }
    }
}
