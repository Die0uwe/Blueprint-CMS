<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Auth\OAuth\OAuthClient;

/**
 * Discord OAuth2 Client
 *
 * Scopes: identify, email, guilds.members.read
 * API: https://discord.com/api/v10
 */
final class DiscordOAuth extends OAuthClient
{
    private const API = 'https://discord.com/api/v10';

    public function getProviderSlug(): string { return 'discord'; }

    public function getAuthorizationUrl(string $state): string
    {
        return 'https://discord.com/oauth2/authorize?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $this->scopes ?: ['identify', 'email', 'guilds.members.read']),
            'state'         => $state,
            // Geen prompt=none: dat slaat het toestemmingsscherm over en laat een nieuwe gebruiker
            // bij Discord op een fout uitkomen (Discord OAuth2-docs). Standaardgedrag is correct.
        ]);
    }

    protected function getTokenEndpoint(): string
    {
        return self::API . '/oauth2/token';
    }

    protected function getUserEndpoint(): string
    {
        return self::API . '/users/@me';
    }

    protected function getGrantType(): string
    {
        return 'authorization_code';
    }

    protected function extractUserId(array $user): string
    {
        return $user['id'];
    }

    // ─── DISCORD-SPECIFIEKE API CALLS ─────────────────────────────────────

    /**
     * Haal alle guilds op waar de user lid van is.
     */
    public function getUserGuilds(string $accessToken): array
    {
        return $this->get(self::API . '/users/@me/guilds', [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    /**
     * Haal de guild-member info op (inclusief rollen).
     * Vereist: guilds.members.read scope + user is lid van de guild.
     */
    public function getGuildMember(string $accessToken, string $guildId): array
    {
        try {
            return $this->get(
                self::API . "/users/@me/guilds/{$guildId}/member",
                ['Authorization: Bearer ' . $accessToken]
            );
        } catch (\RuntimeException) {
            return []; // Niet lid van de guild
        }
    }

    /**
     * Haal guild-member info op via Bot Token (voor server-side sync).
     */
    public function getGuildMemberByBot(string $botToken, string $guildId, string $discordUserId): array
    {
        try {
            return $this->get(
                self::API . "/guilds/{$guildId}/members/{$discordUserId}",
                ['Authorization: Bot ' . $botToken]
            );
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * Haal guild info op (naam, icon, leden count) via Bot Token.
     */
    public function getGuildInfo(string $botToken, string $guildId): array
    {
        try {
            return $this->get(
                self::API . "/guilds/{$guildId}?with_counts=true",
                ['Authorization: Bot ' . $botToken]
            );
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * Haal online leden op via guild widget (publiek, geen token nodig).
     * Widget moet ingeschakeld zijn in de Discord server-instellingen.
     */
    public function getWidgetData(string $guildId): array
    {
        try {
            $ch = curl_init("https://discord.com/api/guilds/{$guildId}/widget.json");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
            $body = curl_exec($ch);
            curl_close($ch);
            return json_decode($body ?: '{}', true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Genereer een Discord-avatar URL.
     */
    public static function avatarUrl(array $user): string
    {
        if (empty($user['avatar'])) {
            $discrim = (int) ($user['discriminator'] ?? 0) % 5;
            return "https://cdn.discordapp.com/embed/avatars/{$discrim}.png";
        }
        $ext = str_starts_with($user['avatar'], 'a_') ? 'gif' : 'png';
        return "https://cdn.discordapp.com/avatars/{$user['id']}/{$user['avatar']}.{$ext}?size=128";
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: DiscordOAuth.php | Role: Core | Version: 1.0.0               ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Discord OAuth2 + guild member API + widget data             ║
// ╚══════════════════════════════════════════════════════════════════════╝
