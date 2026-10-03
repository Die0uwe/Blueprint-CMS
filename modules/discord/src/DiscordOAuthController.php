<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

/**
 * Discord OAuth controller — dunne laag; de beslissingen (intentie, state,
 * geblokkeerde accounts, dubbele koppelingen, foutafhandeling) staan in
 * Core\Auth\OAuth\OAuthLoginFlow.
 *
 *   GET  /auth/discord             koppelen aan het ingelogde account
 *   GET  /auth/discord/login       inloggen/registreren
 *   GET  /auth/discord/callback    terugkeer van Discord (beide flows)
 *   POST /auth/discord/disconnect  ontkoppelen
 */
final class DiscordOAuthController
{
    public function __construct(
        private readonly Connection     $db,
        private readonly OAuthLoginFlow $flow,
        private readonly CacheManager   $cache,
    ) {}

    public function redirect(Request $request): Response
    {
        return $this->flow->begin('discord', 'link', $this->client(), $request);
    }

    public function loginRedirect(Request $request): Response
    {
        return $this->flow->begin('discord', 'login', $this->client(), $request);
    }

    public function callback(Request $request): Response
    {
        return $this->flow->complete(
            'discord',
            $request,
            $this->client(),
            static fn(array $u): array => [
                'id'      => (string) ($u['id'] ?? ''),
                'profile' => [
                    'username'       => (string) ($u['username'] ?? ('discord_' . ($u['id'] ?? ''))),
                    'email'          => $u['email'] ?? null,
                    'email_verified' => ($u['verified'] ?? false) === true,
                    'avatar_url'     => isset($u['avatar']) ? DiscordOAuth::avatarUrl($u) : null,
                ],
            ],
            // Na een geslaagde login/koppeling: Discord-rollen naar CMS-rollen synchroniseren.
            function (int $userId, array $discordUser, array $tokens): void {
                $this->logSync($userId, 'login_or_connect', 'Discord user: ' . ($discordUser['username'] ?? ''));
                $this->syncRoles($userId, (string) $tokens['access_token'], (string) $discordUser['id']);
            },
        );
    }

    public function disconnect(Request $request): Response
    {
        return $this->flow->disconnect('discord', $this->client(), $request);
    }

    private function client(): DiscordOAuth
    {
        return new DiscordOAuth(
            db:           $this->db,
            clientId:     OAuthProviders::setting($this->db, 'discord', 'client_id'),
            clientSecret: OAuthProviders::setting($this->db, 'discord', 'client_secret'),
            redirectUri:  OAuthProviders::setting($this->db, 'discord', 'redirect_uri',
                rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/discord/callback'),
            scopes:       ['identify', 'email', 'guilds.members.read'],
        );
    }

    // ─── Rol-synchronisatie ───────────────────────────────────────────────

    private function syncRoles(int $userId, string $accessToken, string $discordUserId): void
    {
        $guildId  = $this->setting('guild_id', '');
        $botToken = $this->setting('bot_token', '');

        if (empty($guildId)) return;

        try {
            $oauth  = $this->client();

            // Gebruik bot token voor betrouwbaardere member data
            $member = !empty($botToken)
                ? $oauth->getGuildMemberByBot($botToken, $guildId, $discordUserId)
                : $oauth->getGuildMember($accessToken, $guildId);

            if (empty($member)) return;

            $discordRoles = $member['roles'] ?? [];
            $mappings     = $this->db->fetchAll(
                "SELECT discord_role_id, cms_role_id, auto_remove FROM cf_discord_role_mapping"
            );

            foreach ($mappings as $mapping) {
                $hasDiscordRole = in_array($mapping['discord_role_id'], $discordRoles, true);
                $hasCmsRole     = (bool) $this->db->fetchOne(
                    "SELECT 1 FROM cf_user_roles WHERE user_id = ? AND role_id = ?",
                    [$userId, $mapping['cms_role_id']]
                );

                if ($hasDiscordRole && !$hasCmsRole) {
                    $this->db->execute(
                        "INSERT IGNORE INTO cf_user_roles (user_id, role_id) VALUES (?, ?)",
                        [$userId, $mapping['cms_role_id']]
                    );
                    $this->logSync($userId, 'role_added', "CMS role ID: {$mapping['cms_role_id']}");

                } elseif (!$hasDiscordRole && $hasCmsRole && $mapping['auto_remove']) {
                    $this->db->execute(
                        "DELETE FROM cf_user_roles WHERE user_id = ? AND role_id = ?",
                        [$userId, $mapping['cms_role_id']]
                    );
                    $this->logSync($userId, 'role_removed', "CMS role ID: {$mapping['cms_role_id']}");
                }
            }

            // Clear RBAC cache
            $this->cache->delete("rbac.user.{$userId}.permissions");
            $this->cache->delete("rbac.user.{$userId}.roles");

        } catch (\Throwable $e) {
            error_log("Discord rol sync fout voor user {$userId}: " . $e->getMessage());
        }
    }


    private function setting(string $key, string $default = ''): string
    {
        return OAuthProviders::setting($this->db, 'discord', $key, $default);
    }

    private function logSync(int $userId, string $action, ?string $detail): void
    {
        try {
            $this->db->execute(
                "INSERT INTO cf_discord_sync_log (user_id, action, detail) VALUES (?, ?, ?)",
                [$userId, $action, $detail]
            );
        } catch (\Throwable) {}
    }
}
