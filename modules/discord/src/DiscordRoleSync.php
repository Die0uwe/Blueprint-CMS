<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;

/**
 * Synchroniseert de Discord-rollen van één gebruiker naar CMS-rollen volgens cf_discord_role_mapping.
 *
 * Regels:
 *  - De rollen super_admin en admin worden NOOIT via de sync toegekend of ingetrokken (ook niet als
 *    iemand er een koppeling voor in de database zet): dat wordt geweigerd en gelogd.
 *  - Is de gebruiker geen lid van de server (404), dan blijven de rollen ongewijzigd; alleen bij een
 *    positief antwoord van Discord (de rollijst) wordt iets toegevoegd of (auto_remove) verwijderd.
 *  - Een fout bij Discord laat de rollen ongemoeid en wordt gelogd.
 */
final class DiscordRoleSync
{
    private readonly DiscordStore $store;

    public function __construct(
        private readonly Connection $db,
        private readonly ?CacheManager $cache = null,
        private readonly ?DiscordApi $api = null,
    ) {
        $this->store = new DiscordStore($db);
    }

    /**
     * Volledige sync voor één CMS-gebruiker (gebruikt door de queue-job).
     * @return array{status:string,added:list<int>,removed:list<int>,blocked:list<int>,retryable?:bool}
     */
    public function syncUser(int $userId): array
    {
        $none = ['status' => '', 'added' => [], 'removed' => [], 'blocked' => []];
        if ($userId < 1) {
            return ['status' => 'invalid_user'] + $none;
        }
        $guildId = $this->store->guildId();
        $token   = $this->store->botToken();
        if (!DiscordApi::isSnowflake($guildId) || $token === '') {
            return ['status' => 'not_configured'] + $none;
        }

        $link = $this->db->fetchOne(
            "SELECT provider_user_id FROM cf_user_oauth WHERE user_id = ? AND provider = 'discord'",
            [$userId]
        );
        if ($link === null || !DiscordApi::isSnowflake((string) $link['provider_user_id'])) {
            return ['status' => 'no_link'] + $none; // gebruiker heeft geen Discord gekoppeld: niets te doen, niet loggen
        }

        $api = $this->api ?? new DiscordApi($token);
        try {
            $member = $api->getGuildMember($guildId, (string) $link['provider_user_id']);
        } catch (DiscordApiException $e) {
            $this->store->logSync($userId, 'sync_error', $e->getMessage());
            // 429/5xx/netwerk zijn tijdelijk: de queue-job mag het opnieuw proberen.
            return ['status' => 'error', 'retryable' => $e->status === 0 || $e->status === 429 || $e->status >= 500] + $none;
        }
        if ($member === null) {
            $this->store->logSync($userId, 'not_in_guild', 'Geen lid van de Discord-server; rollen ongewijzigd.');
            return ['status' => 'not_in_guild'] + $none;
        }

        $roles = array_values(array_filter(array_map('strval', (array) ($member['roles'] ?? [])), static fn($r) => $r !== ''));
        return ['status' => 'ok'] + $this->applyRoles($userId, $roles);
    }

    /**
     * Past de mapping toe op een bekende lijst Discord-rol-ID's van de gebruiker.
     * @param list<string> $discordRoleIds
     * @return array{added:list<int>,removed:list<int>,blocked:list<int>}
     */
    public function applyRoles(int $userId, array $discordRoleIds): array
    {
        $added = $removed = $blocked = [];
        $protected = $this->protectedRoleIds();

        // Eerst per CMS-rol samenvatten: meerdere Discord-rollen mogen naar dezelfde CMS-rol wijzen
        // (gewenst = minstens één ervan aanwezig; verwijderen alleen als ALLE koppelingen auto_remove hebben).
        $byRole = [];
        foreach ($this->db->fetchAll("SELECT discord_role_id, cms_role_id, auto_remove FROM cf_discord_role_mapping") as $m) {
            $cmsRole = (int) $m['cms_role_id'];
            $byRole[$cmsRole] ??= ['wanted' => false, 'auto_remove' => true];
            $byRole[$cmsRole]['wanted'] = $byRole[$cmsRole]['wanted'] || in_array((string) $m['discord_role_id'], $discordRoleIds, true);
            $byRole[$cmsRole]['auto_remove'] = $byRole[$cmsRole]['auto_remove'] && (int) $m['auto_remove'] === 1;
        }

        foreach ($byRole as $cmsRole => $info) {
            if (isset($protected[$cmsRole])) {
                $blocked[] = $cmsRole;
                $this->store->logSync($userId, 'role_blocked', "CMS role ID: {$cmsRole} ({$protected[$cmsRole]}) is beschermd en wordt niet via Discord beheerd.");
                continue;
            }
            $current = $this->db->fetchOne("SELECT assigned_by FROM cf_user_roles WHERE user_id = ? AND role_id = ?", [$userId, $cmsRole]);
            $hasCms  = $current !== null;

            if ($info['wanted'] && !$hasCms) {
                $this->db->execute("INSERT IGNORE INTO cf_user_roles (user_id, role_id) VALUES (?, ?)", [$userId, $cmsRole]);
                $added[] = $cmsRole;
                $this->store->logSync($userId, 'role_added', "CMS role ID: {$cmsRole}");
            } elseif (!$info['wanted'] && $hasCms && $info['auto_remove'] && $current['assigned_by'] === null) {
                // Alleen rollen die de sync zelf gaf (assigned_by leeg); handmatig toegekende rollen blijven staan
                $this->db->execute("DELETE FROM cf_user_roles WHERE user_id = ? AND role_id = ? AND assigned_by IS NULL", [$userId, $cmsRole]);
                $removed[] = $cmsRole;
                $this->store->logSync($userId, 'role_removed', "CMS role ID: {$cmsRole}");
            }
        }

        if (($added !== [] || $removed !== []) && $this->cache !== null) {
            $this->cache->delete("rbac.user.{$userId}.permissions");
            $this->cache->delete("rbac.user.{$userId}.roles");
        }
        return ['added' => $added, 'removed' => $removed, 'blocked' => $blocked];
    }

    /** @return array<int,string> cms_role_id → naam */
    private function protectedRoleIds(): array
    {
        $in  = implode(',', array_fill(0, count(DiscordStore::PROTECTED_ROLES), '?'));
        $out = [];
        foreach ($this->db->fetchAll("SELECT id, name FROM cf_roles WHERE name IN ({$in})", DiscordStore::PROTECTED_ROLES) as $r) {
            $out[(int) $r['id']] = (string) $r['name'];
        }
        return $out;
    }
}
