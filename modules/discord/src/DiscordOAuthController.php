<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * Discord OAuth Controller
 *
 * Routes:
 *   GET  /auth/discord              → redirect naar Discord (koppelen aan ingelogd account)
 *   GET  /auth/discord/login        → redirect naar Discord (inloggen/registreren, geen account nodig)
 *   GET  /auth/discord/callback     → verwerk callback (beide flows)
 *   POST /auth/discord/disconnect   → ontkoppel Discord account
 *
 * "Inloggen met Discord" (/auth/discord/login) is bewust een aparte entrypoint
 * van "Discord koppelen" (/auth/discord): een bezoeker die niet is ingelogd
 * mag via Discord een nieuw account krijgen of op een al gekoppeld account
 * inloggen, maar mag NOOIT per ongeluk een Discord-account aan het account van
 * een ander koppelen. De sessie onthoudt welke van de twee intenties gestart is
 * (`oauth_intent_discord`), zodat de callback weet wat te doen.
 */
final class DiscordOAuthController
{
    public function __construct(
        private readonly AuthManager  $auth,
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    // ─── STAP 1a: Redirect naar Discord — koppelen aan bestaand account ───

    public function redirect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/auth/discord');
        }

        $_SESSION['oauth_intent_discord'] = 'link';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 1b: Redirect naar Discord — inloggen/registreren ─────────────

    public function loginRedirect(Request $request): Response
    {
        if ($this->auth->check()) {
            // Al ingelogd: "inloggen met Discord" wordt dan gewoon koppelen.
            return $this->redirect($request);
        }

        $_SESSION['oauth_intent_discord'] = 'login';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 2: Callback verwerken ───────────────────────────────────────

    public function callback(Request $request): Response
    {
        $code   = $request->query('code', '');
        $state  = $request->query('state', '');
        $intent = $_SESSION['oauth_intent_discord'] ?? 'link';
        unset($_SESSION['oauth_intent_discord']);

        if (empty($code)) {
            return Response::redirect('/?error=discord_cancelled');
        }

        // "link" vereist een ingelogd account; als de sessie ondertussen
        // verlopen is, valt dit netjes terug op de login-pagina i.p.v. een
        // OAuth-koppeling zonder eigenaar te maken.
        if ($intent === 'link' && !$this->auth->check()) {
            return Response::redirect('/login');
        }

        try {
            $oauth  = $this->makeOAuthClient();
            $result = $oauth->handleCallback($code, $state);

            $discordUser = $result['user'];
            $tokens      = $result['tokens'];

            if ($intent === 'login') {
                // Nieuwe of bestaande CMS-gebruiker vinden/aanmaken puur op
                // basis van de Discord-koppeling — geen wachtwoord nodig.
                $cmsUser = $this->auth->findOrCreateFromOAuth(
                    provider: 'discord',
                    providerUserId: (string) $discordUser['id'],
                    profile: [
                        'username'       => $discordUser['username'] ?? ('discord_' . $discordUser['id']),
                        'email'          => $discordUser['email'] ?? null,
                        'email_verified' => (bool) ($discordUser['verified'] ?? false),
                        'avatar_url'     => isset($discordUser['avatar']) ? DiscordOAuth::avatarUrl($discordUser) : null,
                    ],
                );
                $this->auth->login($cmsUser);
                $userId = (int) $cmsUser['id'];
                $this->logSync($userId, 'registered_or_login', "Discord user: {$discordUser['username']}");
            } else {
                $userId = (int) $this->auth->id();
                $this->logSync($userId, 'connected', "Discord user: {$discordUser['username']}");
            }

            // Sla OAuth koppeling op (idempotent: ON DUPLICATE KEY UPDATE)
            $oauth->saveConnection($userId, $discordUser, $tokens);

            // Update CMS user met Discord avatar als er nog geen is
            $cmsUser = $this->auth->user();
            if (empty($cmsUser['avatar_url']) && isset($discordUser['avatar'])) {
                $avatarUrl = DiscordOAuth::avatarUrl($discordUser);
                $this->db->execute(
                    "UPDATE cf_users SET avatar_url = ? WHERE id = ?",
                    [$avatarUrl, $userId]
                );
            }

            // Sync Discord rollen
            $this->syncRoles($userId, $tokens['access_token'], $discordUser['id']);

            return Response::redirect('/profiel?discord=connected');

        } catch (\RuntimeException $e) {
            error_log("Discord OAuth fout: " . $e->getMessage());
            return Response::redirect('/?error=discord_failed');
        }
    }

    // ─── Ontkoppelen ──────────────────────────────────────────────────────

    public function disconnect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }

        $oauth = $this->makeOAuthClient();
        $oauth->disconnect((int) $this->auth->id());

        $this->logSync((int) $this->auth->id(), 'disconnected', null);

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }

        return Response::redirect('/profiel?discord=disconnected');
    }

    // ─── Rol Synchronisatie ───────────────────────────────────────────────

    private function syncRoles(int $userId, string $accessToken, string $discordUserId): void
    {
        $guildId  = $this->getSetting('guild_id', '');
        $botToken = $this->getSetting('bot_token', '');

        if (empty($guildId)) return;

        try {
            $oauth  = $this->makeOAuthClient();

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

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function makeOAuthClient(): DiscordOAuth
    {
        return new DiscordOAuth(
            db:           $this->db,
            clientId:     $this->getSetting('client_id', ''),
            clientSecret: $this->getSetting('client_secret', ''),
            redirectUri:  $this->getSetting('redirect_uri',
                ($_ENV['APP_URL'] ?? '') . '/auth/discord/callback'),
            scopes:       ['identify', 'email', 'guilds.members.read'],
        );
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne(
            "SELECT value FROM cf_settings WHERE `group` = 'discord' AND `key` = ?",
            [$key]
        );
        return $row['value'] ?? $default;
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

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: DiscordOAuthController.php | Role: Core | Version: 1.0.0     ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝
