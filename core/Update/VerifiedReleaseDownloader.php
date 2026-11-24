<?php
declare(strict_types=1);
namespace Cajeer\Logs\Update;

/** Fetch an immutable GitHub GA asset and authenticate it with Sigstore provenance.
 * This is *download and verification only*: it never extracts or executes the ZIP.
 */
final class VerifiedReleaseDownloader
{
    public const REPOSITORY = 'CajeerTeam/CajeerLogs';
    private const WORKFLOW = self::REPOSITORY.'/.github/workflows/runtime.yml';
    private const MAX_ZIP = 41943040;
    private const MAX_UNPACKED = 104857600;
    private readonly ?\Closure $runner;
    private readonly ?\Closure $databaseVersion;

    /** Injected runner / DB probe are for integration tests; production executes gh and queries PostgreSQL. */
    public function __construct(private readonly string $directory, ?callable $runner=null, ?callable $databaseVersion=null)
    {
        $this->runner=$runner===null?null:\Closure::fromCallable($runner);
        $this->databaseVersion=$databaseVersion===null?null:\Closure::fromCallable($databaseVersion);
    }

    private static function version(string $version): string
    {
        $version=SemVer::normalize($version);
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?$/D',$version)) {
            throw new \InvalidArgumentException('Update version must be a release SemVer without build metadata.');
        }
        return $version;
    }

    private function command(array $args): string
    {
        if ($this->runner!==null) {
            $result=($this->runner)($args);
            if (!is_string($result)) throw new \RuntimeException('GitHub command returned an invalid response.');
            return $result;
        }
        $argv=['gh',...$args];
        // Docker runs read-only as www-data; gh's TUF/Sigstore cache must live
        // in private writable storage, never /var/www or the immutable image.
        $cache=$this->base().'/.gh-cache';
        if (is_link($cache) || (!is_dir($cache) && !@mkdir($cache,0700))) throw new \RuntimeException('Cannot initialize private GitHub verification cache.');
        $config=$cache.'/config';
        if (is_link($config) || (!is_dir($config) && !@mkdir($config,0700))) throw new \RuntimeException('Cannot initialize private GitHub configuration directory.');
        $environment=array_replace(getenv(),[
            'GH_HOST'=>'github.com','GH_PROMPT_DISABLED'=>'1','GH_NO_UPDATE_NOTIFIER'=>'1',
            'GIT_TERMINAL_PROMPT'=>'0','XDG_CACHE_HOME'=>$cache,'GH_CONFIG_DIR'=>$config,
        ]);
        $pipes=[];
        $process=@proc_open($argv,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$environment,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new \RuntimeException('GitHub CLI is unavailable; install gh and configure read-only authentication.');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $out='';$err='';$deadline=microtime(true)+120;$lastExit=-1;
        try {
            while (true) {
                $state=proc_get_status($process);
                if (!$state['running'] && $state['exitcode']>=0) $lastExit=$state['exitcode'];
                $read=[];if(!feof($pipes[1]))$read[]=$pipes[1];if(!feof($pipes[2]))$read[]=$pipes[2];
                if (!$state['running'] && !$read) break;
                if (microtime(true)>$deadline) throw new \RuntimeException('GitHub operation timed out.');
                if ($read) {
                    $write=null;$except=null;
                    if (@stream_select($read,$write,$except,1,0)===false) throw new \RuntimeException('GitHub command I/O error.');
                    foreach ($read as $stream) {
                        $chunk=fread($stream,8192);
                        if ($chunk===false) throw new \RuntimeException('GitHub command I/O error.');
                        if ($stream===$pipes[1]) $out.=$chunk; else $err.=$chunk;
                        if (strlen($out)>524288 || strlen($err)>524288) throw new \RuntimeException('GitHub command output exceeds safety bound.');
                    }
                } else usleep(25000);
            }
        } catch (\Throwable $e) {
            proc_terminate($process);
            throw $e;
        } finally {fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);}
        if ($lastExit<0) $lastExit=$closed;
        if ($lastExit!==0) throw new \RuntimeException('GitHub command failed. Verify gh authentication, release availability and signed attestations.');
        return $out;
    }

    /** @return array<string,mixed> */
    private function api(string $path): array
    {
        $raw=$this->command(['api','--method','GET','repos/'.self::REPOSITORY.'/'.$path,'--header','Accept: application/vnd.github+json']);
        if (strlen($raw)>524288) throw new \RuntimeException('GitHub API response is too large.');
        try {$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new \RuntimeException('GitHub API returned malformed JSON.');}
        if (!is_array($data) || array_is_list($data)) throw new \RuntimeException('GitHub API returned an unexpected object.');
        return $data;
    }

    private static function artifactName(string $version): string {return 'CajeerLogs-'.$version.'.zip';}

    private function requirePublished(string $version): array
    {
        $tag='v'.$version;
        $release=$this->api('releases/tags/'.$tag);
        if (($release['tag_name']??null)!==$tag || ($release['draft']??true)!==false || !is_string($release['published_at']??null)) {
            throw new \RuntimeException('Target is not a published CajeerLogs release.');
        }
        $isPre=(SemVer::parse($version))[1]!==[];
        if (($release['prerelease']??null)!== $isPre) throw new \RuntimeException('Release channel is inconsistent with signed tag.');
        $name=self::artifactName($version);$assets=$release['assets']??null;
        if (!is_array($assets) || !array_is_list($assets)) throw new \RuntimeException('GitHub release assets are missing.');
        $matched=[];
        foreach ($assets as $asset) {
            if (!is_array($asset)||!is_string($asset['name']??null)) continue;
            if (in_array($asset['name'],[$name,$name.'.sha256'],true)) {
                if (isset($matched[$asset['name']])) throw new \RuntimeException('Duplicate release asset name.');
                $matched[$asset['name']]=$asset;
            }
        }
        if (count($matched)!==2) throw new \RuntimeException('Published ZIP and its SHA-256 sidecar are required.');
        foreach ($matched as $asset) {
            if (($asset['state']??null)!=='uploaded' || !is_int($asset['size']??null) || $asset['size']<1) throw new \RuntimeException('Release asset is not uploaded.');
        }
        if ($matched[$name]['size']>self::MAX_ZIP || $matched[$name.'.sha256']['size']>256) throw new \RuntimeException('Release asset exceeds allowed size.');
        $ref=$this->api('git/ref/tags/'.$tag);
        $object=$ref['object']??null;
        if (($ref['ref']??null)!=='refs/tags/'.$tag || !is_array($object) || ($object['type']??null)!=='commit'
            || !is_string($object['sha']??null) || !preg_match('/^[a-f0-9]{40}$/D',$object['sha'])) {
            throw new \RuntimeException('Expected a lightweight tag pointing to an immutable source commit.');
        }
        return [$matched,$object['sha']];
    }

    private static function safeFile(string $file,int $maximum): int
    {
        clearstatcache(true,$file);
        if (is_link($file)||!is_file($file)) throw new \RuntimeException('Release asset is missing or unsafe.');
        $size=filesize($file);
        if (!is_int($size)||$size<1||$size>$maximum) throw new \RuntimeException('Release asset size is invalid.');
        return $size;
    }

    private static function checksum(string $file,string $sidecar,string $name,?string $githubDigest): string
    {
        self::safeFile($sidecar,256);
        $raw=file_get_contents($sidecar);
        $actual=hash_file('sha256',$file);
        if (!is_string($actual) || $raw!==$actual.'  '.$name."\n") throw new \RuntimeException('GitHub ZIP SHA-256 sidecar does not match downloaded bytes.');
        if ($githubDigest!==null && (!preg_match('/^sha256:([a-f0-9]{64})$/D',$githubDigest,$m) || !hash_equals($m[1],$actual))) {
            throw new \RuntimeException('GitHub asset digest does not match downloaded bytes.');
        }
        return $actual;
    }

    private static function validatePath(string $path): void
    {
        if ($path===''||strlen($path)>512||str_contains($path,"\\")||str_starts_with($path,'/')||preg_match('/[\x00-\x1f\x7f]/',$path)
            || str_contains('/'.$path.'/','/../')||str_contains('/'.$path.'/','/./')||str_contains($path,'//') || str_ends_with($path,'/')) {
            throw new \RuntimeException('Release ZIP contains an unsafe entry.');
        }
    }

    /** Verify every byte of the official release manifest and enforce runtime and DB compatibility. */
    public function inspectZip(string $file,string $version): array
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('PHP ext-zip is required for archive verification.');
        self::safeFile($file,self::MAX_ZIP);
        $zip=new \ZipArchive();
        if ($zip->open($file)!==true) throw new \RuntimeException('Downloaded release ZIP is not readable.');
        try {
            if ($zip->numFiles<10||$zip->numFiles>4096) throw new \RuntimeException('Unexpected release ZIP entry count.');
            $entries=[];$bytes=0;
            for ($i=0;$i<$zip->numFiles;$i++) {
                $stat=$zip->statIndex($i);
                if (!is_array($stat)) throw new \RuntimeException('Invalid release entry.');
                $name=$stat['name'];self::validatePath($name);
                if (isset($entries[$name])) throw new \RuntimeException('Duplicate release ZIP entry.');
                $size=$stat['size'];
                if (!is_int($size)||$size>16777216||($bytes+=$size)>self::MAX_UNPACKED) throw new \RuntimeException('Release ZIP expansion limit exceeded.');
                $attrs=0;$opsys=0;$zip->getExternalAttributesIndex($i,$opsys,$attrs);
                $fileType=($attrs>>16)&0170000;
                if (!in_array($fileType,[0,0100000],true)) throw new \RuntimeException('Non-file or symlink in release ZIP.');
                $entries[$name]=$i;
            }
            if (!isset($entries['RELEASE-MANIFEST.json'],$entries['release.json'])) throw new \RuntimeException('Release manifest or metadata missing.');
            $manifest=json_decode((string)$zip->getFromIndex($entries['RELEASE-MANIFEST.json']),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($manifest)||($manifest['format']??null)!==1||($manifest['name']??null)!=='CajeerLogs'
                ||($manifest['version']??null)!==$version||!is_array($manifest['files']??null)||array_is_list($manifest['files'])) {
                throw new \RuntimeException('Invalid CajeerLogs release manifest.');
            }
            if (count($manifest['files'])+1!==count($entries)) throw new \RuntimeException('Manifest does not cover every release file.');
            foreach ($manifest['files'] as $name=>$info) {
                self::validatePath((string)$name);
                if (!isset($entries[$name]) || !is_array($info)||!is_int($info['bytes']??null)
                    ||!is_string($info['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$info['sha256'])) {
                    throw new \RuntimeException('Invalid release manifest entry.');
                }
                $actual=$zip->getFromIndex($entries[$name]);
                if (!is_string($actual)||strlen($actual)!==$info['bytes']||!hash_equals(hash('sha256',$actual),$info['sha256'])) {
                    throw new \RuntimeException('Release ZIP file integrity mismatch.');
                }
            }
            $metadata=json_decode((string)$zip->getFromIndex($entries['release.json']),true,16,JSON_THROW_ON_ERROR);
            if (!is_array($metadata)||($metadata['name']??null)!=='CajeerLogs'||($metadata['version']??null)!==$version
                ||($metadata['api']??null)!=='v1') throw new \RuntimeException('Release identity/version/API incompatible.');
            $php=$metadata['php']??null;
            if (!is_string($php)||!preg_match('/^>=([0-9]+)\.([0-9]+)$/D',$php,$m)) throw new \RuntimeException('Unknown minimum PHP compatibility requirement.');
            $phpMin=(int)$m[1]*10000+(int)$m[2]*100;
            if (PHP_VERSION_ID<$phpMin) throw new \RuntimeException('PHP runtime is older than release requirement.');
            $target=$metadata['compatibilityTarget']??null;
            if (!is_string($target)||!preg_match('/PostgreSQL\s+([0-9]+)\+/',$target,$m)) throw new \RuntimeException('Unknown PostgreSQL compatibility requirement.');
            $postgresMin=(int)$m[1];
            $actualDatabase=($this->databaseVersion!==null) ? ($this->databaseVersion)() : null;
            if (!is_int($actualDatabase)||$actualDatabase<10000) throw new \RuntimeException('PostgreSQL server version could not be verified.');
            $postgresMajor=intdiv($actualDatabase,10000);
            if ($postgresMajor<$postgresMin) throw new \RuntimeException('PostgreSQL version is older than release requirement.');
            return ['php_min'=>$php,'php_actual'=>PHP_VERSION,'postgres_min'=>$postgresMin,'postgres_major'=>$postgresMajor,'api'=>'v1'];
        } finally {$zip->close();}
    }

    private function base(): string
    {
        if (is_link($this->directory)) throw new \RuntimeException('Unsafe update storage directory.');
        if (!is_dir($this->directory) && !@mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new \RuntimeException('Cannot create private update archive directory.');
        $real=realpath($this->directory);
        if ($real===false || !is_writable($real)) throw new \RuntimeException('Private update storage directory is not writable.');
        return $real;
    }

    private static function removeStage(string $path): void
    {
        if (is_link($path)) {@unlink($path);return;}
        if (!is_dir($path)) return;
        foreach (scandir($path)?:[] as $child) {
            if ($child==='.'||$child==='..')continue;
            $entry=$path.'/'.$child;
            if (is_dir($entry) && !is_link($entry)) self::removeStage($entry);
            else @unlink($entry);
        }
        @rmdir($path);
    }

    private static function proof(string $directory,string $version): ?array
    {
        $name=self::artifactName($version);
        $file=$directory.'/'.$name;$proofPath=$directory.'/verified.json';
        if (!is_file($proofPath)||is_link($proofPath)||filesize($proofPath)>4096) return null;
        $data=json_decode((string)file_get_contents($proofPath),true,16);
        if (!is_array($data)||($data['status']??null)!=='verified'||($data['version']??null)!==$version
            ||($data['tag']??null)!=='v'.$version||($data['file']??null)!==$name
            ||($data['repository']??null)!==self::REPOSITORY||($data['signer_workflow']??null)!==self::WORKFLOW
            ||($data['provenance']??null)!=='github_oidc_sigstore'
            ||!is_string($data['source_commit']??null)||!preg_match('/^[a-f0-9]{40}$/D',$data['source_commit'])
            ||!is_string($data['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$data['sha256'])
            ||!is_int($data['bytes']??null)) return null;
        try {$size=self::safeFile($file,self::MAX_ZIP);}catch (\RuntimeException) {return null;}
        if ($data['bytes']!==$size) return null;
        $actual=hash_file('sha256',$file);
        return is_string($actual)&&hash_equals($actual,$data['sha256'])?$data:null;
    }

    public function status(string $version): ?array
    {
        $version=self::version($version);
        $base=$this->base().'/v'.$version;
        if (is_link($base)) throw new \RuntimeException('Unsafe verified release directory.');
        return is_dir($base)?self::proof($base,$version):null;
    }

    public function archive(string $version): array
    {
        $data=$this->status($version);
        if ($data===null) throw new \RuntimeException('Version is not downloaded and verified.');
        return [$this->base().'/v'.$version.'/'.self::artifactName($version),$data];
    }

    /** Revalidate both saved bytes and the live signed provenance; never trust a local receipt alone. */
    public function verify(string $version): array
    {
        $version=self::version($version);
        [$file,$proof]=$this->archive($version);
        [$assets,$commit]=$this->requirePublished($version);
        if (!hash_equals((string)($proof['source_commit']??''),$commit)) throw new \RuntimeException('Verified source commit has changed.');
        $name=self::artifactName($version);
        if (filesize($file)!==$assets[$name]['size']) throw new \RuntimeException('Saved artifact size changed.');
        $digest=hash_file('sha256',$file);
        if ($digest===false || !hash_equals($digest,$proof['sha256'])) throw new \RuntimeException('Saved release checksum mismatch.');
        $result=$this->command(['attestation','verify',$file,'--repo',self::REPOSITORY,
            '--source-ref','refs/tags/v'.$version,'--source-digest',$commit,
            '--signer-workflow',self::WORKFLOW,'--deny-self-hosted-runners',
            '--predicate-type','https://slsa.dev/provenance/v1','--format','json']);
        $attestations=json_decode($result,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($attestations)||!array_is_list($attestations)||count($attestations)===0) throw new \RuntimeException('No signed provenance on revalidation.');
        return array_replace($proof,['reverified_at'=>gmdate('Y-m-d\TH:i:s\Z'),'compatibility'=>$this->inspectZip($file,$version)]);
    }

    /** Download directly from a published GitHub release, verify digest, signed provenance, manifest and runtime. */
    public function download(string $version,string $installed): array
    {
        $version=self::version($version);
        if (SemVer::compare($version,SemVer::normalize($installed))<=0) throw new \RuntimeException('Only a newer published release can be downloaded.');
        $root=$this->base();$lock=$root.'/.download.lock';
        if (is_link($lock)) throw new \RuntimeException('Unsafe download lock.');
        $guard=fopen($lock,'c+b');if ($guard===false||!flock($guard,LOCK_EX)) throw new \RuntimeException('Cannot lock update download.');
        try {
            $existing=$this->status($version);
            if ($existing!==null) return array_replace($this->verify($version),['cached'=>true]);
            if (file_exists($root.'/v'.$version) || is_link($root.'/v'.$version)) throw new \RuntimeException('Unverified archive directory exists; operator intervention is required.');
            [$assets,$commit]=$this->requirePublished($version);
            $name=self::artifactName($version);
            $stage=$root.'/.download-'.bin2hex(random_bytes(12));
            if (!mkdir($stage,0700)) throw new \RuntimeException('Cannot create private download staging area.');
            try {
                foreach ([$name,$name.'.sha256'] as $asset) {
                    $this->command(['release','download','v'.$version,'--repo',self::REPOSITORY,'--dir',$stage,'--pattern',$asset]);
                    if (self::safeFile($stage.'/'.$asset,$asset===$name?self::MAX_ZIP:256)!==$assets[$asset]['size']) throw new \RuntimeException('Published asset size mismatch.');
                }
                $digest=self::checksum($stage.'/'.$name,$stage.'/'.$name.'.sha256',$name,$assets[$name]['digest']??null);
                $verified=$this->command(['attestation','verify',$stage.'/'.$name,'--repo',self::REPOSITORY,
                    '--source-ref','refs/tags/v'.$version,'--source-digest',$commit,
                    '--signer-workflow',self::WORKFLOW,'--deny-self-hosted-runners',
                    '--predicate-type','https://slsa.dev/provenance/v1','--format','json']);
                try {$statements=json_decode($verified,true,32,JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new \RuntimeException('Authenticated provenance result is invalid.');}
                if (!is_array($statements)||!array_is_list($statements)||count($statements)===0) throw new \RuntimeException('No valid signed GitHub build provenance was found.');
                $compat=$this->inspectZip($stage.'/'.$name,$version);
                $proof=['status'=>'verified','version'=>$version,'tag'=>'v'.$version,'repository'=>self::REPOSITORY,
                    'file'=>$name,'sha256'=>$digest,'source_commit'=>$commit,'signer_workflow'=>self::WORKFLOW,
                    'verified_at'=>gmdate('Y-m-d\TH:i:s\Z'),'bytes'=>filesize($stage.'/'.$name),
                    'compatibility'=>$compat,'provenance'=>'github_oidc_sigstore','cached'=>false];
                if (file_put_contents($stage.'/verified.json',json_encode($proof,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX)===false) throw new \RuntimeException('Unable to save verification record.');
                @chmod($stage.'/verified.json',0600);
                foreach ([$name,$name.'.sha256'] as $asset) @chmod($stage.'/'.$asset,0600);
                if (!@rename($stage,$root.'/v'.$version)) throw new \RuntimeException('Cannot commit verified archive.');
                return $proof;
            } finally {self::removeStage($stage);}
        } finally {flock($guard,LOCK_UN);fclose($guard);}
    }
}
