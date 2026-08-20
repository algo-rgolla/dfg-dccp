<?php
declare(strict_types=1);

namespace App\Core;

use App\Shared\SessionHelper;

final class Rbac
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Load roles + permissions for a user into session (auth.*) */
    public function loadForUser(int $userId): void
    {
        $roles = $this->fetchRoles($userId);   // e.g. ['Admin','Strategy',...]
        $perms = $this->fetchPerms($userId);   // e.g. ['RATES_VIEW','RATES_EDIT',...]

        SessionHelper::set('auth.roles', $roles);
        SessionHelper::set('auth.perms', $perms);

        if ($userId > 0 && (empty($roles) || empty($perms))) {
            app_log('RBAC: user has no roles/perms', [
                'userId' => $userId,
                'rolesCount' => count($roles),
                'permsCount' => count($perms),
            ], 'warn');
        }
    }

    private function fetchRoles(int $uid): array
    {
        $sql = <<<SQL
            SELECT r.RoleName
            FROM dbo.tblUserRoles ur
            JOIN dbo.tblRoles r ON r.RoleID = ur.RoleID
            WHERE ur.UserID = :uid
              AND (r.Active = 1 OR r.Active IS NULL)
        SQL;

        $st = $this->pdo->prepare($sql);
        $st->execute([':uid' => $uid]);

        $rows  = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $roles = array_map(static fn($r) => (string)$r['RoleName'], $rows);

        // normalise + unique
        $roles = array_values(array_unique(array_map('trim', $roles)));
        return $roles;
    }

    private function fetchPerms(int $uid): array
    {
        $sql = <<<SQL
            SELECT DISTINCT p.PermissionCode
            FROM dbo.tblUserRoles ur
            JOIN dbo.tblRolePermissions rp ON rp.RoleID = ur.RoleID
            JOIN dbo.tblPermissions p      ON p.PermissionID = rp.PermissionID
            WHERE ur.UserID = :uid
              AND (p.Active = 1 OR p.Active IS NULL)
        SQL;

        $st = $this->pdo->prepare($sql);
        $st->execute([':uid' => $uid]);

        $rows  = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $perms = array_map(static fn($r) => strtoupper((string)$r['PermissionCode']), $rows);

        // unique
        $perms = array_values(array_unique($perms));
        return $perms;
    }

    // ---------- helpers that read from session ----------

    public static function roles(): array
    {
        $roles = SessionHelper::get('auth.roles', []);
        return is_array($roles) ? $roles : [];
    }

    public static function perms(): array
    {
        $perms = SessionHelper::get('auth.perms', []);
        return is_array($perms) ? $perms : [];
    }

    // --- Role checks (case-insensitive) ---
    public static function hasRole(string $role): bool
    {
        $role       = strtolower($role);
        $userRoles  = array_map('strtolower', self::roles());
        return in_array($role, $userRoles, true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        $userRoles = array_map('strtolower', self::roles());
        foreach ($roles as $r) {
            if (in_array(strtolower($r), $userRoles, true)) {
                return true;
            }
        }
        return false;
    }

    // --- Permission checks (already uppercased) ---
    public static function can(string $perm): bool
    {
        return in_array(strtoupper($perm), self::perms(), true);
    }

    public static function canAny(array $perms): bool
    {
        foreach ($perms as $p) {
            if (self::can($p)) return true;
        }
        return false;
    }

    public static function canAll(array $perms): bool
    {
        foreach ($perms as $p) {
            if (!self::can((string)$p)) {
                return false;
            }
        }
        return true;
    }
}
