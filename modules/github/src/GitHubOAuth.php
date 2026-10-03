<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\GitHub;

use CommunityFusion\Core\Auth\OAuth\OAuthClient;

/**
 * GitHub OAuth 2.0 client (OAuth App, authorization code flow).
 *
 * Scopes: read:user user:email
 * Auth:   https://github.com/login/oauth/authorize
 * Token:  https://github.com/login/oauth/access_token
 * User:   https://api.github.com/user  +  https://api.github.com/user/emails
 *
 * Het e-mailadres uit /user is het PUBLIEKE profieladres (vaak leeg en niet
 * gegarandeerd bevestigd). Daarom wordt het adres uitsluitend uit
 * /user/emails gehaald, en alleen het primaire adres dat GitHub als
 * bevestigd markeert.
 */
final class GitHubOAuth extends OAuthClient
{
    private const AUTH_ENDPOINT  = 'https://github.com/login/oauth/authorize';
    private const TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';
    private const API            = 'https://api.github.com';

    public function getProviderSlug(): string { return 'github'; }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope'        => implode(' ', $this->scopes ?: ['read:user', 'user:email']),
            'state'        => $state,
            'allow_signup' => 'true',
        ]);
    }

    protected function getTokenEndpoint(): string { return self::TOKEN_ENDPOINT; }

    protected function getUserEndpoint(): string { return self::API . '/user'; }

    protected function getGrantType(): string { return 'authorization_code'; }

    protected function extractUserId(array $user): string
    {
        return (string) $user['id'];
    }

    /**
     * Profiel + bevestigd primair e-mailadres.
     *
     * @return array<string,mixed> o.a. id, login, name, avatar_url, email (bevestigd of null), email_verified
     */
    protected function fetchUser(string $accessToken): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        $user = $this->get(self::API . '/user', $headers);
        // Het publieke profieladres nooit vertrouwen.
        $user['email']          = null;
        $user['email_verified'] = false;

        try {
            $emails = $this->get(self::API . '/user/emails', $headers);
            foreach ($emails as $row) {
                if (is_array($row) && !empty($row['primary']) && !empty($row['verified']) && !empty($row['email'])) {
                    $user['email']          = strtolower(trim((string) $row['email']));
                    $user['email_verified'] = true;
                    break;
                }
            }
        } catch (\RuntimeException) {
            // Geen toegang tot e-mailadressen: inloggen kan nog steeds, zonder adres.
        }

        return $user;
    }
}
