<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Google;

use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

/**
 * Google OAuth controller — dunne laag; de beslissingen (intentie, state,
 * geblokkeerde accounts, dubbele koppelingen, foutafhandeling) staan in
 * Core\Auth\OAuth\OAuthLoginFlow.
 *
 *   GET  /auth/google             koppelen aan het ingelogde account
 *   GET  /auth/google/login       inloggen/registreren
 *   GET  /auth/google/callback    terugkeer van Google (beide flows)
 *   POST /auth/google/disconnect  ontkoppelen
 */
final class GoogleOAuthController
{
    public function __construct(
        private readonly Connection     $db,
        private readonly OAuthLoginFlow $flow,
    ) {}

    public function redirect(Request $request): Response
    {
        return $this->flow->begin('google', 'link', $this->client(), $request);
    }

    public function loginRedirect(Request $request): Response
    {
        return $this->flow->begin('google', 'login', $this->client(), $request);
    }

    public function callback(Request $request): Response
    {
        return $this->flow->complete(
            'google',
            $request,
            $this->client(),
            static fn(array $u): array => [
                'id'      => (string) ($u['sub'] ?? ''),
                'profile' => [
                    'username'       => (string) ($u['name'] ?? ('google_' . ($u['sub'] ?? ''))),
                    'email'          => $u['email'] ?? null,
                    'email_verified' => ($u['email_verified'] ?? false) === true,
                    'avatar_url'     => $u['picture'] ?? null,
                ],
            ],
        );
    }

    public function disconnect(Request $request): Response
    {
        return $this->flow->disconnect('google', $this->client(), $request);
    }

    private function client(): GoogleOAuth
    {
        return new GoogleOAuth(
            db:           $this->db,
            clientId:     OAuthProviders::setting($this->db, 'google', 'client_id'),
            clientSecret: OAuthProviders::setting($this->db, 'google', 'client_secret'),
            redirectUri:  OAuthProviders::setting($this->db, 'google', 'redirect_uri',
                rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/google/callback'),
            scopes:       ['openid', 'email', 'profile'],
        );
    }
}
