<?php
declare(strict_types=1);
namespace Cajeer\Logs\Update;

/** Resolves real published releases and retains a short private, process-shared cache. */
final class UpdateResolver
{
    private const TTL=300;
    private readonly GitHubReleaseClient $client;

    public function __construct(private readonly ?string $cacheDirectory=null,?GitHubReleaseClient $client=null)
    {
        $this->client=$client??new GitHubReleaseClient();
    }

    public function channel(): string { return getenv('UPDATE_CHANNEL')?:'stable'; }

    /** @return array<string,mixed> */
    public function check(string $installed,?string $channel=null,bool $refresh=false): array
    {
        $current=SemVer::normalize($installed);
        $channel=$channel??$this->channel();
        if (!in_array($channel,['stable','preview'],true)) throw new \InvalidArgumentException('Invalid update channel.');
        if ($this->cacheDirectory===null || !is_dir($this->cacheDirectory)) return $this->resolve($current,$channel);

        $lock=$this->cacheDirectory.'/github-releases.lock';
        $path=$this->cacheDirectory.'/github-releases.json';
        if (is_link($lock) || is_link($path)) throw new \RuntimeException('Unsafe release cache path.');
        $handle=@fopen($lock,'c+b');
        if ($handle===false || !flock($handle,LOCK_EX)) throw new \RuntimeException('Release cache lock unavailable.');
        try {
            if (!$refresh && is_file($path) && filesize($path)<=65536) {
                $raw=@file_get_contents($path);
                $cached=is_string($raw)?json_decode($raw,true,16):null;
                $fresh=is_array($cached) && ($cached['current']??null)===$current
                    && ($cached['channel']??null)===$channel
                    && is_int($cached['checked_unix']??null)
                    && $cached['checked_unix']>=time()-self::TTL
                    && $cached['checked_unix']<=time()
                    && ( ($cached['status']??null)==='no_releases' && ($cached['release']??null)===null
                        || ($cached['status']??null)==='ok' && is_array($cached['release']??null) );
                if ($fresh) {
                    $cached['cached']=true;
                    return $cached;
                }
            }
            $data=$this->resolve($current,$channel);
            $data['checked_unix']=time();
            $encoded=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (strlen($encoded)<=65536) {
                $temporary=@tempnam($this->cacheDirectory,'.github-releases-');
                if ($temporary!==false) {
                    try {
                        chmod($temporary,0600);
                        if (file_put_contents($temporary,$encoded,LOCK_EX)!==false) @rename($temporary,$path);
                    } finally { if (is_file($temporary)) @unlink($temporary); }
                }
            }
            return $data;
        } finally { flock($handle,LOCK_UN);fclose($handle); }
    }

    /** @return array<string,mixed> */
    private function resolve(string $installed,string $channel): array
    {
        $best=null;
        foreach ($this->client->listPublished() as $item) {
            if ($channel==='stable' && $item['prerelease']) continue;
            if ($best===null || SemVer::compare($item['version'],$best['version'])>0) $best=$item;
        }
        return [
            'status'=>$best===null?'no_releases':'ok',
            'source'=>'github_releases', 'repository'=>GitHubReleaseClient::REPOSITORY,
            'channel'=>$channel,'current'=>$installed,
            'candidate'=>$best['version']??null,
            'update_available'=>$best!==null && SemVer::compare($best['version'],$installed)>0,
            'release'=>$best,
            'checked_at'=>gmdate('Y-m-d\TH:i:s\Z'),
            'cached'=>false,
        ];
    }
}
