<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\BattleNet;

use CommunityFusion\Core\Auth\OAuth\OAuthClient;
use CommunityFusion\Core\Database\Connection;

/**
 * Battle.net (Blizzard) OAuth2 Client
 *
 * OAuth bij Battle.net is regio-gebonden via subdomein: eu, us, kr, tw.
 * China is een aparte, losstaande markt (battlenet.com.cn) — als edge-case
 * meegenomen, maar geen focus.
 *
 * Standaard scope is leeg: puur inloggen met een BattleTag vereist geen
 * extra Blizzard-API scope (bv. 'wow.profile' is alleen nodig als je later
 * daadwerkelijk profielgegevens wilt uitlezen).
 *
 * Let op: Blizzard geeft via deze flow bewust GEEN e-mailadres terug.
 * De userinfo-response bevat alleen: {"sub": "...", "id": ..., "battletag": "Naam#1234"}.
 */
final class BattleNetOAuth extends OAuthClient
{
    public function __construct(
        Connection $db,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        protected readonly string $region,
        array $scopes = [],
    ) {
        parent::__construct($db, $clientId, $clientSecret, $redirectUri, $scopes);
    }

    public function getProviderSlug(): string { return 'battlenet'; }

    public function getAuthorizationUrl(string $state): string
    {
        return $this->baseUrl() . '/oauth/authorize?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $this->scopes),
            'state'         => $state,
        ]);
    }

    protected function getTokenEndpoint(): string
    {
        return $this->baseUrl() . '/oauth/token';
    }

    protected function getUserEndpoint(): string
    {
        return $this->baseUrl() . '/oauth/userinfo';
    }

    protected function getGrantType(): string
    {
        return 'authorization_code';
    }

    /**
     * Blizzard geeft afhankelijk van de API-versie soms 'sub' en soms 'id'
     * terug voor hetzelfde account — dek beide af.
     */
    protected function extractUserId(array $user): string
    {
        return (string) ($user['sub'] ?? $user['id']);
    }

    // ─── REGIO-ROUTERING ───────────────────────────────────────────────────

    /**
     * Battle.net OAuth-endpoints hangen af van de regio van het account:
     * https://{region}.battle.net/oauth/... voor eu/us/kr/tw, en een apart
     * domein voor de Chinese markt (battlenet.com.cn).
     */
    private function baseUrl(): string
    {
        return $this->region === 'cn'
            ? 'https://www.battlenet.com.cn'
            : "https://{$this->region}.battle.net";
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BattleNetOAuth.php | Role: Core | Version: 1.0.0             ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ║  Notes: Battle.net OAuth2 — regio-gebonden endpoints, geen e-mail   ║
// ╚══════════════════════════════════════════════════════════════════════╝
