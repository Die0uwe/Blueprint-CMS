<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Google;

use CommunityFusion\Core\Auth\OAuth\OAuthClient;

/**
 * Google OAuth2 / OpenID Connect Client
 *
 * Scopes: openid, email, profile
 * Auth:   https://accounts.google.com/o/oauth2/v2/auth
 * Token:  https://oauth2.googleapis.com/token
 * User:   https://openidconnect.googleapis.com/v1/userinfo
 */
final class GoogleOAuth extends OAuthClient
{
    private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USER_ENDPOINT  = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function getProviderSlug(): string { return 'google'; }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $this->scopes ?: ['openid', 'email', 'profile']),
            'state'         => $state,
            'access_type'   => 'offline',
            'prompt'        => 'select_account', // Altijd account-kiezer tonen, ook met 1 gekoppeld account
        ]);
    }

    protected function getTokenEndpoint(): string
    {
        return self::TOKEN_ENDPOINT;
    }

    protected function getUserEndpoint(): string
    {
        return self::USER_ENDPOINT;
    }

    protected function getGrantType(): string
    {
        return 'authorization_code';
    }

    protected function extractUserId(array $user): string
    {
        // Google's OpenID Connect user-id veld is 'sub', niet 'id'.
        return (string) $user['sub'];
    }

    // De basis fetchUser() uit OAuthClient (Bearer-GET naar getUserEndpoint())
    // levert userinfo al direct in de juiste vorm — sub, email, email_verified,
    // name, picture, given_name, family_name, locale — geen override nodig.

    /**
     * Google geeft altijd een directe CDN-URL terug in 'picture'.
     */
    public static function avatarUrl(array $user): string
    {
        return $user['picture'] ?? '';
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GoogleOAuth.php | Role: Core | Version: 1.0.0                ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ║  Notes: Google OAuth2 / OpenID Connect client                       ║
// ╚══════════════════════════════════════════════════════════════════════╝
