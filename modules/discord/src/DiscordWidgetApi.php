<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Cache\CacheManager;

/**
 * Haalt de publieke Discord Guild Widget-data op (widget.json): servernaam,
 * online leden, spraakkamers en de invite-link. Eén minuut gecachet en gedeeld
 * door DiscordOnlineBlock en DiscordWidgetBlock (zelfde cache-sleutel).
 *
 * Vereist dat de widget aanstaat in de Discord-serverinstellingen.
 */
final class DiscordWidgetApi
{
    /**
     * @return array<string, mixed>|null  null als de widget niet bereikbaar/uitgeschakeld is
     */
    public static function fetch(CacheManager $cache, string $serverId): ?array
    {
        // Een server-ID is een numerieke snowflake; zo kan er niets anders in het pad komen.
        if (preg_match('/^\d{5,25}$/', $serverId) !== 1) {
            return null;
        }

        $data = $cache->remember("discord.widget.{$serverId}", 60, static function () use ($serverId) {
            $ch = curl_init("https://discord.com/api/guilds/{$serverId}/widget.json");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 200 && is_string($body) ? json_decode($body, true) : null;
        });

        return is_array($data) && $data !== [] ? $data : null;
    }
}
