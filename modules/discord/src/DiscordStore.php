<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\Crypto;

/**
 * Databasetoegang van de Discord-module: instellingen (met ontsleuteling), rolkoppelingen en synclog.
 * Schrijven van instellingen gaat via SettingsRepository (die ook de cache leegt) — dit leest alleen.
 */
final class DiscordStore
{
    /** Rollen die nooit via Discord-koppeling/sync mogen worden toegekend of ingetrokken. */
    public const PROTECTED_ROLES = ['super_admin', 'admin'];

    public function __construct(private readonly Connection $db) {}

    public static function ensureSchema(Connection $db): void
    {
        $db->execute("
            CREATE TABLE IF NOT EXISTS `cf_discord_role_mapping` (
                `id`            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `discord_role_id` VARCHAR(30) NOT NULL COMMENT 'Discord Role ID (snowflake)',
                `cms_role_id`   SMALLINT UNSIGNED NOT NULL,
                `auto_remove`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Verwijder CMS-rol als Discord-rol weg is',
                `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_discord_role` (`discord_role_id`),
                CONSTRAINT `fk_drm_cms_role` FOREIGN KEY (`cms_role_id`) REFERENCES `cf_roles`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $db->execute("
            CREATE TABLE IF NOT EXISTS `cf_discord_sync_log` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`     INT UNSIGNED NOT NULL,
                `action`      VARCHAR(50) NOT NULL,
                `detail`      TEXT NULL,
                `synced_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // ─── Instellingen ─────────────────────────────────────────────────────

    public function get(string $key, string $default = ''): string
    {
        try {
            $row = $this->db->fetchOne(
                "SELECT `value`, `type` FROM cf_settings WHERE `group` = 'discord' AND `key` = ?",
                [$key]
            );
        } catch (\Throwable) {
            return $default;
        }
        if ($row === null || $row['value'] === null) {
            return $default;
        }
        if (($row['type'] ?? '') === 'encrypted') {
            return $row['value'] === '' ? $default : Crypto::decrypt((string) $row['value']);
        }
        return (string) $row['value'];
    }

    public function guildId(): string   { return trim($this->get('guild_id')); }
    public function clientId(): string  { return trim($this->get('client_id')); }
    public function botToken(): string  { return trim($this->get('bot_token')); }
    public function webhookUrl(): string { return trim($this->get('webhook_url')); }

    public function announceNews(): bool
    {
        return in_array(strtolower($this->get('announce_news', '0')), ['1', 'true', 'on'], true);
    }

    /** Is de module ingeschakeld (cf_modules.is_enabled)? */
    public function moduleEnabled(): bool
    {
        try {
            $r = $this->db->fetchOne("SELECT is_enabled FROM cf_modules WHERE slug = 'discord'");
            return $r !== null && (int) $r['is_enabled'] === 1;
        } catch (\Throwable) {
            return false;
        }
    }

    // ─── Rollen / koppelingen ─────────────────────────────────────────────

    /** @return list<array{id:int,name:string,display_name:string,priority:int,protected:bool}> */
    public function cmsRoles(): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT id, name, display_name, priority FROM cf_roles ORDER BY priority DESC, name ASC") as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'display_name' => (string) $r['display_name'],
                      'priority' => (int) $r['priority'], 'protected' => in_array($r['name'], self::PROTECTED_ROLES, true)];
        }
        return $out;
    }

    public function isProtectedRole(int $cmsRoleId): bool
    {
        $r = $this->db->fetchOne("SELECT name FROM cf_roles WHERE id = ?", [$cmsRoleId]);
        return $r !== null && in_array($r['name'], self::PROTECTED_ROLES, true);
    }

    /** @return list<array{id:int,discord_role_id:string,cms_role_id:int,cms_role_name:string,auto_remove:bool}> */
    public function mappings(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.id, m.discord_role_id, m.cms_role_id, m.auto_remove, r.name AS cms_name, r.display_name
             FROM cf_discord_role_mapping m JOIN cf_roles r ON r.id = m.cms_role_id ORDER BY m.id ASC"
        );
        return array_map(static fn($r) => [
            'id' => (int) $r['id'], 'discord_role_id' => (string) $r['discord_role_id'], 'cms_role_id' => (int) $r['cms_role_id'],
            'cms_role_name' => (string) ($r['display_name'] ?: $r['cms_name']), 'auto_remove' => (bool) $r['auto_remove'],
        ], $rows);
    }

    /** @return string|null foutmelding, null = gelukt */
    public function addMapping(string $discordRoleId, int $cmsRoleId, bool $autoRemove): ?string
    {
        $discordRoleId = trim($discordRoleId);
        if (!DiscordApi::isSnowflake($discordRoleId)) {
            return 'Ongeldig Discord-rol-ID (alleen cijfers, 15–25 lang).';
        }
        if ($err = $this->checkCmsRole($cmsRoleId)) {
            return $err;
        }
        if ($this->db->fetchOne("SELECT 1 x FROM cf_discord_role_mapping WHERE discord_role_id = ?", [$discordRoleId]) !== null) {
            return 'Deze Discord-rol is al gekoppeld. Bewerk of verwijder de bestaande koppeling.';
        }
        $this->db->execute(
            "INSERT INTO cf_discord_role_mapping (discord_role_id, cms_role_id, auto_remove) VALUES (?, ?, ?)",
            [$discordRoleId, $cmsRoleId, $autoRemove ? 1 : 0]
        );
        return null;
    }

    public function updateMapping(int $id, int $cmsRoleId, bool $autoRemove): ?string
    {
        if ($this->db->fetchOne("SELECT 1 x FROM cf_discord_role_mapping WHERE id = ?", [$id]) === null) {
            return 'Deze koppeling bestaat niet (meer).';
        }
        if ($err = $this->checkCmsRole($cmsRoleId)) {
            return $err;
        }
        $this->db->execute("UPDATE cf_discord_role_mapping SET cms_role_id = ?, auto_remove = ? WHERE id = ?", [$cmsRoleId, $autoRemove ? 1 : 0, $id]);
        return null;
    }

    public function deleteMapping(int $id): bool
    {
        return $this->db->execute("DELETE FROM cf_discord_role_mapping WHERE id = ?", [$id])->rowCount() > 0;
    }

    private function checkCmsRole(int $cmsRoleId): ?string
    {
        $r = $this->db->fetchOne("SELECT name FROM cf_roles WHERE id = ?", [$cmsRoleId]);
        if ($r === null) {
            return 'Die CMS-rol bestaat niet.';
        }
        if (in_array($r['name'], self::PROTECTED_ROLES, true)) {
            return "De rol '{$r['name']}' is beschermd en kan niet aan een Discord-rol worden gekoppeld.";
        }
        return null;
    }

    // ─── Synclog ──────────────────────────────────────────────────────────

    public function logSync(int $userId, string $action, ?string $detail): void
    {
        try {
            $this->db->execute("INSERT INTO cf_discord_sync_log (user_id, action, detail) VALUES (?, ?, ?)", [$userId, $action, $detail]);
        } catch (\Throwable) {
            // logging mag nooit de sync breken
        }
    }

    /** @return list<array<string,mixed>> */
    public function recentSyncLog(int $limit = 20): array
    {
        try {
            return $this->db->fetchAll(
                "SELECT l.user_id, l.action, l.detail, l.synced_at, u.username FROM cf_discord_sync_log l
                 LEFT JOIN cf_users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT " . max(1, min(100, $limit))
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
