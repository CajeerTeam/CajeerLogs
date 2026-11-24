<?php
declare(strict_types=1);
namespace Cajeer\Logs\Update;

/** SemVer 2.0 ordering, including numeric pre-release identifiers and build metadata. */
final class SemVer
{
    /** @return array{0:list<string>,1:list<string>} */
    public static function parse(string $version): array
    {
        if (strlen($version)>128 || !preg_match('/^(?:v)?(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D', $version, $m)) {
            throw new \InvalidArgumentException('Invalid SemVer version.');
        }
        $pre=isset($m[4]) && $m[4]!=='' ? explode('.', $m[4]) : [];
        foreach ($pre as $part) {
            if (ctype_digit($part) && strlen($part)>1 && $part[0]==='0') throw new \InvalidArgumentException('Invalid numeric SemVer pre-release identifier.');
        }
        return [[ $m[1],$m[2],$m[3] ],$pre];
    }

    public static function normalize(string $version): string
    {
        self::parse($version);
        return ltrim($version, 'v');
    }

    /** -1, 0, 1; comparison ignores build metadata. */
    public static function compare(string $left,string $right): int
    {
        [$a,$ap]=self::parse($left); [$b,$bp]=self::parse($right);
        for ($i=0;$i<3;$i++) {
            $cmp=self::numeric($a[$i],$b[$i]);
            if ($cmp!==0) return $cmp;
        }
        if (!$ap && !$bp) return 0;
        if (!$ap) return 1;
        if (!$bp) return -1;
        for ($i=0;$i<min(count($ap),count($bp));$i++) {
            $x=$ap[$i];$y=$bp[$i];
            $xn=ctype_digit($x);$yn=ctype_digit($y);
            if ($xn!==$yn) return $xn?-1:1;
            $cmp=$xn?self::numeric($x,$y):($x<=>$y);
            if ($cmp!==0) return $cmp<=>0;
        }
        return count($ap)<=>count($bp);
    }

    private static function numeric(string $a,string $b): int
    {
        return (strlen($a)<=>strlen($b)) ?: (strcmp($a,$b)<=>0);
    }
}
