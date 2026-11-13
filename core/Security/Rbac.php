<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

final class Rbac
{
    public function allows(array $permissions, string $permission): bool { return in_array($permission,$permissions,true); }
    public function roleAllows(string $role, string $permission): bool
    {
        return $this->allows(match($role) {
            'administrator'=>['users:manage','projects:manage','projects:read','sources:manage','tokens:manage','logs:read','logs:write','system:read'],
            'operator'=>['projects:read','sources:manage','tokens:manage','logs:read','logs:write'],
            'viewer'=>['projects:read','logs:read'],
            default=>[],
        },$permission);
    }
}
