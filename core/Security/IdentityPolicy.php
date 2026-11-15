<?php
declare(strict_types=1);
namespace Cajeer\Logs\Security;

use Cajeer\Logs\Config\ConfigRepository;

/** Configuration is evaluated on every request; a cookie never carries policy decisions. */
final class IdentityPolicy
{
    public function __construct(public readonly array $roles=[], public readonly array $permissions=[], public readonly int $idle=0, public readonly int $fresh=0) {
        if (array_diff($roles,['administrator','operator','viewer']) || array_diff($permissions,Rbac::permissions('administrator')) || $idle<0 || $idle>604800 || $fresh<0 || $fresh>3600) throw new \InvalidArgumentException('Invalid identity security policy.');
    }
    public static function fromConfig(ConfigRepository $c): self {
        $list=static fn(string $key): array => array_values(array_unique(array_filter(array_map('trim',explode(',',(string)$c->get($key,''))),static fn($v)=>$v!=='')));
        return new self($list('security.required_totp_roles'),$list('security.required_totp_permissions'),(int)$c->get('security.session_idle_seconds',0),(int)$c->get('security.reauth_seconds',0));
    }
    public function requires(string $role,array $permissions): bool { return in_array($role,$this->roles,true) || array_intersect($permissions,$this->permissions)!==[]; }
    public function requiresEnrollmentPolicy(): bool { return $this->roles!==[] || $this->permissions!==[]; }
}
