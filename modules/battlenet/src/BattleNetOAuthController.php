<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\BattleNet;

use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

/**
 * Battle.net OAuth controller — dunne laag; de beslissingen (intentie, state,
 * geblokkeerde accounts, dubbele koppelingen, foutafhandeling) staan in
 * Core\Auth\OAuth\OAuthLoginFlow.
 *
 *   GET  /auth/battlenet             koppelen aan het ingelogde account
 *   GET  /auth/battlenet/login       inloggen/registreren
 *   GET  /auth/battlenet/callback    terugkeer van Battle.net (beide flows)
 *   POST /auth/battlenet/disconnect  ontkoppelen
 */
final class BattleNetOAuthController
{
    public function __construct(
        private readonly Connection     $db,
        private readonly OAuthLoginFlow $flow,
    ) {}

    public function redirect(Request $request): Response
    {
        return $this->flow->begin('battlenet', 'link', $this->client(), $request);
    }

    public function loginRedirect(Request $request): Response
    {
        return $this->flow->begin('battlenet', 'login', $this->client(), $request);
    }

    public function callback(Request $request): Response
    {
        return $this->flow->complete(
            'battlenet',
            $request,
            $this->client(),
            // Blizzard levert geen e-mailadres: AuthManager maakt dan zelf een unieke
            // placeholder aan. De BattleTag wordt de weergavenaam.
            static fn(array $u): array => [
                'id'      => (string) ($u['sub'] ?? $u['id'] ?? ''),
                'profile' => [
                    'username'       => (string) ($u['battletag'] ?? ('battlenet_' . ($u['sub'] ?? $u['id'] ?? ''))),
                    'email'          => null,
                    'email_verified' => false,
                    'avatar_url'     => null,
                ],
            ],
        );
    }

    public function disconnect(Request $request): Response
    {
        return $this->flow->disconnect('battlenet', $this->client(), $request);
    }

    private function client(): BattleNetOAuth
    {
        return new BattleNetOAuth(
            db:           $this->db,
            clientId:     OAuthProviders::setting($this->db, 'battlenet', 'client_id'),
            clientSecret: OAuthProviders::setting($this->db, 'battlenet', 'client_secret'),
            redirectUri:  OAuthProviders::setting($this->db, 'battlenet', 'redirect_uri',
                rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/battlenet/callback'),
            region:       $this->region(),
            scopes:       [],
        );
    }

    /** Alleen bekende regio's: de waarde wordt onderdeel van een hostnaam. */
    private function region(): string
    {
        $region = strtolower(trim(OAuthProviders::setting($this->db, 'battlenet', 'region', 'eu')));
        return in_array($region, ['eu', 'us', 'kr', 'tw', 'cn'], true) ? $region : 'eu';
    }
}
