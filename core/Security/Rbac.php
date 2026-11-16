<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Http\HttpException;

/** Built-in grants are immutable ceilings. Custom roles only remove privileges. */
final class Rbac
{
    public const PROJECT = ['projects:read','logs:read','logs:export','logs:write','sources:create','sources:update','sources:state',
        'tokens:read','tokens:create','tokens:update','tokens:rotate','tokens:revoke','tokens:signing','services:read','services:manage','audit:read','queue:read','queue:retry','queue:discard'];
    private const LEGACY = ['sources:manage','tokens:manage'];
    public const ADMIN = ['users:manage','projects:manage','system:read','roles:manage'];
    public function allows(array $permissions, string $permission): bool { return in_array($permission,$permissions,true); }
    public static function permissions(string $role): array
    {
        return match($role) {
            'administrator'=>array_merge(self::PROJECT,self::ADMIN,self::LEGACY),
            'operator'=>array_merge(self::PROJECT,self::LEGACY),
            'viewer'=>['projects:read','logs:read','logs:export'],
            default=>[],
        };
    }
    public function roleAllows(string $role, string $permission): bool { return $this->allows(self::permissions($role),$permission); }
    public static function validate(mixed $permissions): array
    {
        if (!is_array($permissions)||!array_is_list($permissions)||count($permissions)>count(self::PROJECT)) self::invalid();
        foreach ($permissions as $permission) if (!is_string($permission)||!in_array($permission,self::PROJECT,true)) self::invalid();
        if (count(array_unique($permissions))!==count($permissions)||!in_array('projects:read',$permissions,true)) self::invalid();
        if (in_array('logs:export',$permissions,true)&&!in_array('logs:read',$permissions,true)) self::invalid();
        foreach (['tokens:create','tokens:update','tokens:rotate','tokens:revoke','tokens:signing'] as $action) {
            if (in_array($action,$permissions,true)&&!in_array('tokens:read',$permissions,true)) self::invalid();
        }
        if (in_array('services:manage',$permissions,true)&&!in_array('services:read',$permissions,true)) self::invalid();
        foreach (['queue:retry','queue:discard'] as $action) if (in_array($action,$permissions,true)&&!in_array('queue:read',$permissions,true)) self::invalid();
        return array_values(array_intersect(self::PROJECT,$permissions));
    }
    private static function invalid(): never { throw new HttpException(422,'invalid_permissions','Use unique supported project permissions. Project read is required; export requires log read, token mutations require token read, and service mutations require service read, and queue mutations require queue read.'); }
    /** Fail closed if stored data is malformed or assigned in the wrong scope. */
    public static function restrict(array $grants, ?string $id, ?string $scope, mixed $json, string $expected): array
    {
        if ($id===null) return $grants;
        if ($scope!==$expected || !is_string($json)) return [];
        try { $allowed=self::validate(json_decode($json,true,16,JSON_THROW_ON_ERROR)); }
        catch (\JsonException|HttpException) { return []; }
        return array_values(array_intersect($grants,$allowed));
    }
}
