<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\GitHub;

use CommunityFusion\Core\Auth\OAuth\OAuthLoginFlow;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

/**
 * GitHub OAuth controller — dunne laag; de beslissingen staan in OAuthLoginFlow.
 *
 *   GET  /auth/github             koppelen aan het ingelogde account
 *   GET  /auth/github/login       inloggen/registreren
 *   GET  /auth/github/callback    terugkeer van GitHub (beide flows)
 *   POST /auth/github/disconnect  ontkoppelen
 */
final class GitHubOAuthController
{
    public function __construct(
        private readonly Connection     $db,
        private readonly OAuthLoginFlow $flow,
    ) {}

    public function redirect(Request $request): Response
    {
        return $this->flow->begin('github', 'link', $this->client(), $request);
    }

    public function loginRedirect(Request $request): Response
    {
        return $this->flow->begin('github', 'login', $this->client(), $request);
    }

    public function callback(Request $request): Response
    {
        return $this->flow->complete(
            'github',
            $request,
            $this->client(),
            static fn(array $u): array => [
                'id'      => (string) ($u['id'] ?? ''),
                'profile' => [
                    'username'       => (string) ($u['login'] ?? ('github_' . ($u['id'] ?? ''))),
                    'email'          => $u['email'] ?? null,
                    'email_verified' => (bool) ($u['email_verified'] ?? false),
                    'avatar_url'     => $u['avatar_url'] ?? null,
                ],
            ],
        );
    }

    public function disconnect(Request $request): Response
    {
        return $this->flow->disconnect('github', $this->client(), $request);
    }

    private function client(): GitHubOAuth
    {
        return new GitHubOAuth(
            db:           $this->db,
            clientId:     OAuthProviders::setting($this->db, 'github', 'client_id'),
            clientSecret: OAuthProviders::setting($this->db, 'github', 'client_secret'),
            redirectUri:  OAuthProviders::setting($this->db, 'github', 'redirect_uri',
                rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/auth/github/callback'),
            scopes:       ['read:user', 'user:email'],
        );
    }
}
