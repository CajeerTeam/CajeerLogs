<?php
declare(strict_types=1);
namespace Cajeer\Logs\Log;

use Cajeer\Logs\Http\HttpException;

/** Stored queries are validated by exactly the same parser as the Explorer. */
final class SavedView
{
    public const COLUMNS=['timestamp','level','source','service','message','received_at','host','trace_id','request_id','tags','id','source_id'];
    public static function validate(array $data,string $project): array
    {
        $name=$data['name']??null;
        if (!is_string($name)||trim($name)===''||strlen($name)>120||str_contains($name,"\0")||!preg_match('//u',$name)) self::invalid('Invalid view name.');
        $filters=$data['filters']??null;
        if (!is_array($filters)||array_is_list($filters)&&$filters!==[]||array_intersect(array_keys($filters),['project_id','cursor','format','interval'])) self::invalid('Invalid saved filters.');
        $query=LogQuery::parse(['project_id'=>$project]+$filters);
        $filters=$query->filters; unset($filters['project_id']);
        $filters['limit']=(string)$query->limit; $filters['consistency']=$query->consistency;
        $layout=$data['layout']??null;
        if (!is_array($layout)||array_diff(array_keys($layout),['order','visible','widths','density','wrap','zone'])) self::invalid('Invalid table layout.');
        foreach (['order','visible'] as $key) {
            $list=$layout[$key]??null;
            if (!is_array($list)||!array_is_list($list)||!$list||count($list)>count(self::COLUMNS)) self::invalid('Invalid table columns.');
            foreach ($list as $column) if (!is_string($column)||!in_array($column,self::COLUMNS,true)) self::invalid('Unknown table column.');
            if (count(array_unique($list))!==count($list)) self::invalid('Duplicate table column.');
        }
        if (count($layout['order'])!==count(self::COLUMNS)) self::invalid('Incomplete column order.');
        $widths=$layout['widths']??null;
        if (!is_array($widths)||array_diff(array_keys($widths),self::COLUMNS)) self::invalid('Invalid column widths.');
        foreach ($widths as $width) if (!is_int($width)||$width<90||$width>800) self::invalid('Invalid column width.');
        if (!in_array($layout['density']??null,['normal','compact'],true)||!in_array($layout['zone']??null,['utc','local'],true)||!is_bool($layout['wrap']??null)) self::invalid('Invalid table settings.');
        $layout['widths']=(object)$widths;
        return ['name'=>trim($name),'filters'=>$filters,'layout'=>$layout];
    }
    private static function invalid(string $message): never { throw new HttpException(422,'invalid_payload',$message); }
}
