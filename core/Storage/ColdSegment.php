<?php
declare(strict_types=1);
namespace Cajeer\Logs\Storage;

/** Strongly validated, bounded, immutable S3 archive segment (not a metadata-only check). */
final class ColdSegment
{
    public static function sameManifest(array $left,array $right): bool
    {
        foreach (['id','project_id','month_start','object_key','sha256','bytes','event_count'] as $field) {
            if ((string)($left[$field]??'')!==(string)($right[$field]??'')) return false;
        }
        return true;
    }

    public static function decode(string $payload,array $meta,string $project): array
    {
        $project=ArchiveLifecycle::uuid($project);
        if (!preg_match('/^[a-f0-9]{64}$/D',(string)($meta['sha256']??''))
            || !hash_equals($meta['sha256'],hash('sha256',$payload))
            || strlen($payload)!==(int)($meta['bytes']??0)
            || (int)($meta['bytes']??0)<1 || strlen($payload)>16777216
            || (int)($meta['event_count']??0)<1 || (int)$meta['event_count']>500
            || ($meta['project_id']??null)!==$project) {
            throw new \RuntimeException('Cold segment manifest/bytes mismatch.');
        }
        $month=substr((string)($meta['month_start']??''),0,7);
        [$start,$end]=ArchiveLifecycle::month($month);
        $min=(new \DateTimeImmutable($start))->getTimestamp();
        $max=(new \DateTimeImmutable($end))->getTimestamp();
        $plain=@gzdecode($payload,16777217);
        if ($plain===false || strlen($plain)>16777216 || !str_ends_with($plain,"\n")) {
            throw new \RuntimeException('Invalid/oversized cold segment.');
        }
        $lines=explode("\n",substr($plain,0,-1));
        if (count($lines)!==(int)$meta['event_count']) throw new \RuntimeException('Cold segment count mismatch.');
        $rows=[];$seen=[];
        foreach ($lines as $line) {
            if (strlen($line)>8388608 || $line==='') throw new \RuntimeException('Invalid cold record length.');
            $row=json_decode($line,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($row) || !isset($row['id'],$row['project_id'],$row['source_id'],$row['received_at'],$row['snapshot'])
                || !is_string($row['id']) || !is_string($row['source_id']) || !is_string($row['received_at'])
                || ArchiveLifecycle::uuid((string)$row['project_id'])!==$project
                || ArchiveLifecycle::uuid($row['id'])!==$row['id']
                || ArchiveLifecycle::uuid($row['source_id'])!==$row['source_id']) {
                throw new \RuntimeException('Corrupt cold archive record.');
            }
            $at=new \DateTimeImmutable($row['received_at']);
            if ($at->getTimestamp()<$min || $at->getTimestamp()>=$max) {
                throw new \RuntimeException('Cold archive record is outside its partition.');
            }
            if (!is_string($row['snapshot']) || strlen($row['snapshot'])>8388608) {
                throw new \RuntimeException('Invalid cold record payload.');
            }
            $snapshot=json_decode($row['snapshot'],true,32,JSON_THROW_ON_ERROR);
            if (!is_array($snapshot) || ($snapshot['id']??null)!==$row['id']
                || ($snapshot['project_id']??null)!==$project
                || ($snapshot['source_id']??null)!==$row['source_id']
                || !isset($snapshot['received_at']) || !is_string($snapshot['received_at'])
                || (new \DateTimeImmutable($snapshot['received_at']))->format('U.u')!==$at->format('U.u')) {
                throw new \RuntimeException('Cold snapshot integrity mismatch.');
            }
            if (isset($seen[$row['id']])) throw new \RuntimeException('Duplicate cold archive event ID.');
            $seen[$row['id']]=true;
            $rows[]=$row;
        }
        return $rows;
    }
}
