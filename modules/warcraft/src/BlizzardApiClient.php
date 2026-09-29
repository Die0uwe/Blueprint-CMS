<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Warcraft;

use CommunityFusion\Core\Cache\CacheManager;

/**
 * Blizzard Battle.net API Client
 * Ondersteunt WoW Game Data + Profile API (OAuth2 Client Credentials)
 * API Docs: https://develop.battle.net/documentation/world-of-warcraft
 */
final class BlizzardApiClient
{
    private const TOKEN_URL = 'https://{region}.battle.net/oauth/token';
    private const API_BASE  = 'https://{region}.api.blizzard.com';

    private ?string $accessToken = null;

    public function __construct(
        private readonly CacheManager $cache,
        private readonly string       $clientId,
        private readonly string       $clientSecret,
        private readonly string       $region   = 'eu',
        private readonly string       $locale   = 'en_GB',
    ) {}

    // ─── TOKEN ───────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        if ($this->accessToken) return $this->accessToken;

        $cacheKey = "wow.token.{$this->region}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached) { $this->accessToken = $cached; return $cached; }

        $url = str_replace('{region}', $this->region, self::TOKEN_URL);
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERPWD        => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);

        // curl_exec() geeft `false` terug bij een netwerkfout (Battle.net
        // onbereikbaar, timeout, ...). json_decode(false, ...) knalt onder
        // strict_types=1 met een TypeError (verwacht string) i.p.v. netjes
        // null terug te geven — elke WoW-functie in deze module crashte
        // hierdoor met een 500 in plaats van gewoon te degraderen, in
        // tegenstelling tot get() hieronder die zijn HTTP-status al wél
        // checkt. Gevonden tijdens de S13-inventarisatiepas.
        $body    = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $token   = $body['access_token'] ?? '';
        $expires = (int)($body['expires_in'] ?? 86400) - 60;

        if ($token) {
            $this->cache->set($cacheKey, $token, $expires);
            $this->accessToken = $token;
        }

        return $token;
    }

    // ─── API CALLS ────────────────────────────────────────────────────────

    private function get(string $path, array $params = [], int $cacheTtl = 300): ?array
    {
        $cacheKey = 'wow.api.' . md5($path . json_encode($params));
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $token    = $this->getAccessToken();
        if (empty($token)) return null;

        $base   = str_replace('{region}', $this->region, self::API_BASE);
        $params = array_merge(['locale' => $this->locale], $params);
        $url    = $base . $path . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) return null;

        $data = json_decode($body, true);
        if ($data) $this->cache->set($cacheKey, $data, $cacheTtl);
        return $data;
    }

    // ─── GUILD ENDPOINTS ─────────────────────────────────────────────────

    /**
     * Haal guild roster op.
     * Vereist namespace: profile-{region}
     */
    public function getGuildRoster(string $realm, string $guildName): ?array
    {
        $realm = strtolower(str_replace(' ', '-', $realm));
        $guild = strtolower(str_replace(' ', '-', $guildName));
        return $this->get(
            "/data/wow/guild/{$realm}/{$guild}/roster",
            ['namespace' => "profile-{$this->region}"],
            600
        );
    }

    /**
     * Haal guild info op (naam, faction, achievement points).
     */
    public function getGuildInfo(string $realm, string $guildName): ?array
    {
        $realm = strtolower(str_replace(' ', '-', $realm));
        $guild = strtolower(str_replace(' ', '-', $guildName));
        return $this->get(
            "/data/wow/guild/{$realm}/{$guild}",
            ['namespace' => "profile-{$this->region}"],
            1800
        );
    }

    /**
     * Haal guild activity op (achievements, member joins).
     */
    public function getGuildActivity(string $realm, string $guildName): ?array
    {
        $realm = strtolower(str_replace(' ', '-', $realm));
        $guild = strtolower(str_replace(' ', '-', $guildName));
        return $this->get(
            "/data/wow/guild/{$realm}/{$guild}/activity",
            ['namespace' => "profile-{$this->region}"],
            300
        );
    }

    // ─── CHARACTER ENDPOINTS ─────────────────────────────────────────────

    /**
     * Haal character profiel op.
     */
    public function getCharacter(string $realm, string $charName): ?array
    {
        $realm = strtolower(str_replace(' ', '-', $realm));
        $char  = strtolower($charName);
        return $this->get(
            "/profile/wow/character/{$realm}/{$char}",
            ['namespace' => "profile-{$this->region}"],
            300
        );
    }

    /**
     * Haal character equipment op (item levels per slot).
     */
    public function getCharacterEquipment(string $realm, string $charName): ?array
    {
        $realm = strtolower(str_replace(' ', '-', $realm));
        $char  = strtolower($charName);
        return $this->get(
            "/profile/wow/character/{$realm}/{$char}/equipment",
            ['namespace' => "profile-{$this->region}"],
            300
        );
    }

    /**
     * Haal Mythic+ seizoenscore op (Raider.IO via externe API).
     * Blizzard API heeft geen directe M+ score — gebruik Raider.IO.
     */
    public function getMythicPlusScore(string $realm, string $charName): ?array
    {
        $cacheKey = "wow.rio.{$realm}.{$charName}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $url = 'https://raider.io/api/v1/characters/profile?' . http_build_query([
            'region'  => $this->region,
            'realm'   => $realm,
            'name'    => $charName,
            'fields'  => 'mythic_plus_scores_by_season:current,mythic_plus_best_runs',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) return null;
        $data = json_decode($body, true);
        if ($data) $this->cache->set($cacheKey, $data, 600);
        return $data;
    }

    /**
     * Haal raid progress op via Raider.IO guild endpoint.
     */
    public function getRaidProgress(string $realm, string $guildName): ?array
    {
        $cacheKey = "wow.rio.guild.{$realm}.{$guildName}";
        $cached   = $this->cache->get($cacheKey);
        if ($cached !== null) return $cached;

        $url = 'https://raider.io/api/v1/guilds/profile?' . http_build_query([
            'region'  => $this->region,
            'realm'   => $realm,
            'name'    => $guildName,
            'fields'  => 'raid_progression',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status !== 200) return null;
        $data = json_decode($body, true);
        if ($data) $this->cache->set($cacheKey, $data, 1800);
        return $data;
    }

    // ─── GAME DATA ────────────────────────────────────────────────────────

    /**
     * Haal realm status op.
     */
    public function getRealmStatus(string $realm): ?array
    {
        $slug = strtolower(str_replace(' ', '-', $realm));
        return $this->get(
            "/data/wow/connected-realm/search",
            ['namespace' => "dynamic-{$this->region}", 'realms.slug' => $slug, 'status.type' => 'UP'],
            120
        );
    }

    public function getRegion(): string   { return $this->region; }
    public function getLocale(): string   { return $this->locale; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BlizzardApiClient.php | Role: Core | Version: 1.0.0          ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Blizzard OAuth2 CC + Raider.IO integratie                   ║
// ║  Created by Dieouwe — www.slayeralliance.com                         ║
// ╚══════════════════════════════════════════════════════════════════════╝
