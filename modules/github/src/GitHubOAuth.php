<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\GitHub;

use CommunityFusion\Core\Auth\OAuth\OAuthClient;
use CommunityFusion\Core\Database\Connection;

/**
 * GitHub OAuth2 client.
 *
 * Authorize: https://github.com/login/oauth/authorize
 * Token:     https://github.com/login/oauth/access_token   (POST)
 * User:      https://api.github.com/user
 * E-mail:    https://api.github.com/user/emails            (scope user:email)
 *
 * Afwijkingen van de OAuthClient-basis:
 *  - GitHub geeft fouten op het token-endpoint terug als HTTP 200 met een
 *    `error`-veld in de body; dat wordt hier een RuntimeException.
 *  - Token-/API-requests hebben eigen headers nodig (Accept, User-Agent,
 *    X-GitHub-Api-Version).
 *  - Het e-mailadres staat niet (betrouwbaar) in /user maar komt uit
 *    /user/emails: alleen een primair, GEVERIFIEERD adres wordt gebruikt.
 *
 * De HTTP-laag is injecteerbaar ($http) zodat tests geen netwerk nodig hebben:
 *   callable(string $method, string $url, array $headers, ?array $form): array{0:int,1:string}
 * geeft [HTTP-status, body] terug.
 */
final class GitHubOAuth extends OAuthClient
{
    private const AUTH_ENDPOINT   = 'https://github.com/login/oauth/authorize';
    private const TOKEN_ENDPOINT  = 'https://github.com/login/oauth/access_token';
    private const USER_ENDPOINT   = 'https://api.github.com/user';
    private const EMAIL_ENDPOINT  = 'https://api.github.com/user/emails';
    private const API_VERSION     = '2022-11-28';
    private const USER_AGENT      = 'Blueprint-CMS';

    /** @var callable|null */
    private $http;

    public function __construct(
        Connection $db,
        string     $clientId,
        string     $clientSecret,
        string     $redirectUri,
        array      $scopes = [],
        ?callable  $http = null,
    ) {
        parent::__construct($db, $clientId, $clientSecret, $redirectUri, $scopes);
        $this->http = $http;
    }

    public function getProviderSlug(): string { return 'github'; }

    public function getAuthorizationUrl(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id'    => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope'        => implode(' ', $this->scopes ?: ['read:user', 'user:email']),
            'state'        => $state,
        ]);
    }

    protected function getTokenEndpoint(): string { return self::TOKEN_ENDPOINT; }
    protected function getUserEndpoint(): string  { return self::USER_ENDPOINT; }
    protected function getGrantType(): string     { return 'authorization_code'; }

    protected function extractUserId(array $user): string
    {
        // Stabiel numeriek GitHub-id — nooit de (wijzigbare) login gebruiken.
        return (string) $user['id'];
    }

    // ─── Token exchange ───────────────────────────────────────────────────

    protected function exchangeCode(string $code): array
    {
        $tokens = $this->request('POST', self::TOKEN_ENDPOINT, [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . self::USER_AGENT,
        ], [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
        ]);

        // GitHub meldt fouten (verlopen/ongeldige code, verkeerd secret) met HTTP 200.
        if (isset($tokens['error'])) {
            $desc = (string) ($tokens['error_description'] ?? '');
            throw new \RuntimeException('GitHub token-fout: ' . (string) $tokens['error'] . ($desc !== '' ? " — {$desc}" : ''));
        }
        if (empty($tokens['access_token']) || !is_string($tokens['access_token'])) {
            throw new \RuntimeException('GitHub gaf geen access_token terug.');
        }

        return $tokens;
    }

    // ─── Gebruiker + e-mail ───────────────────────────────────────────────

    protected function fetchUser(string $accessToken): array
    {
        $headers = $this->apiHeaders($accessToken);

        $user = $this->request('GET', self::USER_ENDPOINT, $headers);
        if (!isset($user['id'])) {
            throw new \RuntimeException('GitHub-gebruiker kon niet worden opgehaald (geen id in antwoord).');
        }

        // Mislukt /user/emails (bv. scope geweigerd), dan loggen we gewoon in
        // zonder e-mailadres: het account wordt aan het GitHub-id gekoppeld.
        $emails = [];
        try {
            $emails = $this->request('GET', self::EMAIL_ENDPOINT, $headers);
        } catch (\RuntimeException) {
            $emails = [];
        }

        $user['email']          = self::pickVerifiedEmail($emails);
        $user['email_verified'] = $user['email'] !== null;

        return $user;
    }

    /**
     * Kies het primaire, geverifieerde e-mailadres uit het /user/emails-antwoord.
     * Valt terug op een ander geverifieerd adres; een niet-geverifieerd adres
     * wordt NOOIT teruggegeven (anders zou iemand een willekeurig adres
     * kunnen claimen).
     */
    public static function pickVerifiedEmail(array $emails): ?string
    {
        $fallback = null;
        foreach ($emails as $e) {
            if (!is_array($e) || empty($e['verified']) || !is_string($e['email'] ?? null) || $e['email'] === '') {
                continue;
            }
            if (!empty($e['primary'])) {
                return $e['email'];
            }
            $fallback ??= $e['email'];
        }
        return $fallback;
    }

    public static function avatarUrl(array $user): string
    {
        return $user['avatar_url'] ?? '';
    }

    // ─── HTTP ─────────────────────────────────────────────────────────────

    private function apiHeaders(string $accessToken): array
    {
        return [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/vnd.github+json',
            'User-Agent: ' . self::USER_AGENT,
            'X-GitHub-Api-Version: ' . self::API_VERSION,
        ];
    }

    /** Voert het request uit (of de geïnjecteerde nep-HTTP) en decodeert de JSON-body. */
    private function request(string $method, string $url, array $headers, ?array $form = null): array
    {
        [$status, $body] = $this->http !== null
            ? ($this->http)($method, $url, $headers, $form)
            : $this->curl($method, $url, $headers, $form);

        if ($status !== 200) {
            throw new \RuntimeException(
                "GitHub {$method} {$url} mislukt: HTTP {$status} — " . substr((string) $body, 0, 200)
            );
        }

        try {
            $data = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('GitHub gaf geen geldige JSON terug.', 0, $e);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub gaf een onverwacht antwoord terug.');
        }

        return $data;
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, ?array $form): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($form ?? []);
        }
        curl_setopt_array($ch, $opts);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $body === false ? '' : (string) $body];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GitHubOAuth.php | Role: Module | Version: 1.0.0              ║
// ║  Notes: GitHub OAuth2 client met injecteerbare HTTP-laag            ║
// ╚══════════════════════════════════════════════════════════════════════╝
