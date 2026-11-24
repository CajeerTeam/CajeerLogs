<?php
declare(strict_types=1);
namespace Cajeer\Logs\Update;

/** Read the bounded display projection only; this data cannot authorize updates. */
final class AutomationStatus
{
    private const MODES=['off','check','download','install'];
    private const STATUSES=['idle','off','available','up_to_date','downloaded','installing','installed','check_or_download_failed','installation_failed','interrupted','recovery_required','installer_unavailable','database_pool_exhausted'];
    private const NOTICE=['available','downloaded','installing','installed','check_or_download_failed','installation_failed','interrupted','recovery_required','installer_unavailable','database_pool_exhausted','resumed'];
    private static function version(mixed $value): ?string
    {
        if (!is_string($value)||strlen($value)>128) return null;
        try {return SemVer::normalize($value);} catch (\InvalidArgumentException) {return null;}
    }
    private static function timestamp(mixed $value): ?int {return is_int($value)&&$value>0&&$value<=time()+604800? $value:null;}
    public static function read(string $directory): array
    {
        $path=$directory.'/update-automation.json';
        $fallback=['status'=>'not_configured','mode'=>'off','notices'=>[]];
        if (is_link($path)||!is_file($path)||!is_readable($path)) return $fallback;
        $raw=@file_get_contents($path,false,null,0,32769);
        if (!is_string($raw)||strlen($raw)>32768) return $fallback;
        try {$data=json_decode($raw,true,12,JSON_THROW_ON_ERROR);} catch (\JsonException) {return $fallback;}
        if (!is_array($data)||!in_array($data['mode']??null,self::MODES,true)||!in_array($data['status']??null,self::STATUSES,true)||!in_array($data['channel']??null,['stable','preview'],true)||!is_bool($data['blocked']??null)) return $fallback;
        if (!is_int($data['interval_minutes']??null)||$data['interval_minutes']<5||$data['interval_minutes']>10080||!is_int($data['window_minutes']??null)||$data['window_minutes']<5||$data['window_minutes']>240||!is_string($data['window_start']??null)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$data['window_start'])) return $fallback;
        $notices=[];
        if (is_array($data['notices']??null)) foreach (array_slice($data['notices'],-20) as $entry) {
            if (!is_array($entry)||!in_array($entry['kind']??null,self::NOTICE,true)||($at=self::timestamp($entry['at']??null))===null) continue;
            $notices[]=['kind'=>$entry['kind'],'version'=>self::version($entry['version']??null),'at'=>$at];
        }
        $updated=self::timestamp($data['updated_at']??null);
        return ['status'=>$data['status'],'mode'=>$data['mode'],'channel'=>$data['channel'],'blocked'=>$data['blocked'],
            'interval_minutes'=>$data['interval_minutes'],'window_start'=>$data['window_start'],'window_minutes'=>$data['window_minutes'],
            'candidate'=>self::version($data['candidate']??null),'downloaded'=>self::version($data['downloaded']??null),
            'last_check'=>self::timestamp($data['last_check']??null),'next_check'=>self::timestamp($data['next_check']??null),
            'updated_at'=>$updated,'stale'=>$data['mode']!=='off' && ($updated===null||$updated<time()-180),
            'remaining_databases'=>is_int($data['remaining_databases']??null)?max(0,min(32,$data['remaining_databases'])):0,'notices'=>$notices];
    }
}
