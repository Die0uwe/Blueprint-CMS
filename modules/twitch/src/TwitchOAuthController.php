<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Twitch;

use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

/**
 * Twitch OAuth controller — dunne laag; de beslissingen (intentie, state,
 * geblokkeerde accounts, dubbele koppelingen, foutafhandeling) staan in
 * Core\Auth\OAuth\OAuthLoginFlow.
 *
 *   GET  /auth/twitch             koppelen aan het ingelogde account
 *   GET  /auth/twitch/login       inloggen/registreren
 *   GET  /auth/twitch/callback    terugkeer van Twitch (beide flows)
 *   POST /auth/twitch/disconnect  ontkoppelen
 */
final class TwitchOAuthController
{
    public function __construct(
        private readonly Connection     $db,
        private readonly OAuthLoginFlow $flow,
    ) {}

    public function redirect(Request $request): Response
    {
        return $this->flow->begin('twitch', 'link', $this->client(), $request);
    }

    public function loginRedirect(Request $request): Response
    {
        return $this->flow->begin('twitch', 'login', $this->client(), $request);
    }

    public function callback(Request $request): Response
    {
        return $this->flow->complete(
            'twitch',
            $request,
            $this->client(),
            static fn(array $u): array => [
                'id'      => (string) ($u['id'] ?? ''),
                'profile' => [
                    'username'       => (string) ($u['login'] ?? ('twitch_' . ($u['id'] ?? ''))),
                    'email'          => $u['email'] ?? null,
                    'email_verified' => !empty($u['email']),
                    'avatar_url'     => $u['profile_image_url'] ?? null,
                ],
            ],
        );
    }

    public function disconnect(Request $request): Response
    {
        return $this->flow->disconnect('twitch', $this->client(), $request);
    }

    private function client(): TwitchOAuth
    {
        return new TwitchOAuth(
            db:           $this->db,
            clientId:     OAuthProviders::setting($this->db, 'twitch', 'client_id'),
            clientSecret: OAuthProviders::setting($this->db, 'twitch', 'client_secret'),
            redirectUri:  OAuthProviders::setting($this->db, 'twitch', 'redirect_uri',
                rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/twitch/callback'),
            scopes:       ['user:read:email', 'user:read:follows'],
        );
    }
}
