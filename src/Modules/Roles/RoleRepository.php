<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Roles;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Auth\RBAC\RBACManager;

/**
 * RoleRepository — beheert cf_roles/cf_permissions/cf_role_permissions.
 *
 * De vijf geseede rollen (super_admin, admin, moderator, member, guest —
 * zie schema.sql) zijn "beschermd": hun machine-`name` mag nooit wijzigen
 * (harde string-checks elders, o.a. RBACManager's '*'-wildcard-lookup op
 * super_admin en AuthManager::register()'s is_default-lookup) en ze kunnen
 * niet verwijderd worden. Zelfgemaakte rollen mogen wel verwijderd worden,
 * maar alleen zolang er geen gebruiker meer aan hangt.
 */
final class RoleRepository
{
    public const PROTECTED_NAMES = ['super_admin', 'admin', 'moderator', 'member', 'guest'];

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
        private readonly RBACManager  $rbac,
    ) {}

    public function getAllWithCounts(): array
    {
        return $this->db->fetchAll(
            "SELECT r.*,
                    (SELECT COUNT(*) FROM cf_user_roles ur WHERE ur.role_id = r.id) AS user_count,
                    (SELECT COUNT(*) FROM cf_role_permissions rp WHERE rp.role_id = r.id) AS permission_count
             FROM cf_roles r
             ORDER BY r.priority DESC, r.name ASC"
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_roles WHERE id = ?", [$id]);
    }

    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $row = $this->db->fetchOne("SELECT id FROM cf_roles WHERE name = ? AND id != ?", [$name, $exceptId]);
        } else {
            $row = $this->db->fetchOne("SELECT id FROM cf_roles WHERE name = ?", [$name]);
        }
        return $row !== null;
    }

    /**
     * Alle permissies, gegroepeerd op hun `group`-kolom — voor de
     * checkbox-matrix in het bewerk-scherm.
     *
     * @return array<string, array> group => permissie-rijen
     */
    public function getAllPermissionsGrouped(): array
    {
        $rows    = $this->db->fetchAll("SELECT * FROM cf_permissions ORDER BY `group` ASC, name ASC");
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group']][] = $row;
        }
        return $grouped;
    }

    public function getRolePermissionIds(int $roleId): array
    {
        $rows = $this->db->fetchAll("SELECT permission_id FROM cf_role_permissions WHERE role_id = ?", [$roleId]);
        return array_map(static fn($r) => (int) $r['permission_id'], $rows);
    }

    public function createRole(string $name, string $displayName, ?string $description, ?string $color, int $priority): int
    {
        $id = $this->db->insert('roles', [
            'name'         => $name,
            'display_name' => $displayName,
            'description'  => $description,
            'color'        => $color,
            'priority'     => $priority,
        ]);
        return (int) $id;
    }

    public function updateRole(int $id, string $displayName, ?string $description, ?string $color, int $priority): void
    {
        $this->db->update('roles', [
            'display_name' => $displayName,
            'description'  => $description,
            'color'        => $color,
            'priority'     => $priority,
        ], 'id = ?', [$id]);
    }

    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $this->db->transaction(function (Connection $db) use ($roleId, $permissionIds) {
            $db->delete('role_permissions', 'role_id = ?', [$roleId]);
            foreach ($permissionIds as $permId) {
                $db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permId]);
            }
        });
        $this->clearAuthCacheForRole($roleId);
    }

    /**
     * Zet deze rol als standaardrol voor nieuwe registraties, en alle
     * andere rollen expliciet uit — is_default is bedoeld als uniek per
     * tabel (AuthManager::register() pakt "LIMIT 1" zonder verdere
     * garantie welke dat is als er per ongeluk meerdere op 1 staan).
     */
    public function setDefault(int $id): void
    {
        $this->db->transaction(function (Connection $db) use ($id) {
            $db->execute("UPDATE cf_roles SET is_default = 0");
            $db->execute("UPDATE cf_roles SET is_default = 1 WHERE id = ?", [$id]);
        });
    }

    public function userCount(int $id): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_user_roles WHERE role_id = ?", [$id]);
        return (int) ($row['c'] ?? 0);
    }

    public function deleteRole(int $id): void
    {
        $this->db->delete('roles', 'id = ?', [$id]);
    }

    /**
     * RBACManager cachet permissies/rollen per user_id, 300s TTL (zie
     * RBACManager::getUserPermissions()/getUserRoles()). Zonder ingreep zou
     * een permissiewijziging in dit scherm dus tot 5 minuten geen effect
     * hebben voor een al ingelogde gebruiker. CacheManager kent geen
     * pattern-delete, dus vegen we gericht: elke gebruiker die deze rol op
     * dit moment heeft, via RBACManager::clearUserCache(). Het bouwen van
     * dit scherm legde bloot dat UserAdminController::update() hetzelfde
     * gat had (rol toewijzen via /admin/users cachete ook niet-vers) — is
     * in dezelfde wave meegefixt, zie CHANGELOG v1.15.0.
     */
    private function clearAuthCacheForRole(int $roleId): void
    {
        $userIds = $this->db->fetchAll("SELECT user_id FROM cf_user_roles WHERE role_id = ?", [$roleId]);
        foreach ($userIds as $row) {
            $this->rbac->clearUserCache((int) $row['user_id']);
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: RoleRepository.php | Role: Data | Version: 1.0.0              ║
// ║  Created: 2026-09-29 — Wave 5 (admin/roles)                          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
