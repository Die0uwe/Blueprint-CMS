<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth\OAuth;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\Crypto;

/**
 * OAuthClient — Abstract base voor OAuth2 providers.
 * Concrete implementaties: DiscordOAuth, TwitchOAuth.
 *
 * Verantwoordelijkheden:
 * - Bouw de authorization URL op
 * - Wissel de auth code in voor tokens
 * - Haal user-data op via de provider API
 * - Sla tokens encrypted op in cf_user_oauth
 * - Refresh tokens als ze bijna verlopen zijn
 */
abstract class OAuthClient
{
    abstract public function getProviderSlug(): string;
    abstract public function getAuthorizationUrl(string $state): string;
    abstract protected function getTokenEndpoint(): string;
    abstract protected function getUserEndpoint(): string;
    abstract protected function getGrantType(): string;

    public function __construct(
        protected readonly Connection $db,
        protected readonly string     $clientId,
        protected readonly string     $clientSecret,
        protected readonly string     $redirectUri,
        protected readonly array      $scopes = [],
    ) {}

    // ─── STAP 1: Genereer Authorization URL ───────────────────────────────

    /**
     * Sla de state op in de sessie en geef de authorization URL terug.
     */
    public function buildRedirectUrl(): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state_' . $this->getProviderSlug()] = $state;

        return $this->getAuthorizationUrl($state);
    }

    // ─── STAP 2: Verwerk de Callback ──────────────────────────────────────

    /**
     * Valideer state, wissel code in, haal user op, sla op in DB.
     * Geeft de provider user-data terug.
     *
     * @throws \RuntimeException bij CSRF of API fouten
     */
    public function handleCallback(string $code, string $state): array
    {
        // CSRF validatie
        // Een lege verwachte state (sessie kwijt/verlopen) mag NOOIT matchen: hash_equals('', '')
        // is true, dus een callback zonder state-parameter kwam er voorheen doorheen.
        $expectedState = (string) ($_SESSION['oauth_state_' . $this->getProviderSlug()] ?? '');
        unset($_SESSION['oauth_state_' . $this->getProviderSlug()]);
        if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
            throw new \RuntimeException('OAuth state mismatch — mogelijke CSRF aanval.');
        }

        // Token ophalen
        $tokens = $this->exchangeCode($code);
        if (empty($tokens['access_token']) || !is_string($tokens['access_token'])) {
            throw new \RuntimeException('OAuth: de provider gaf geen access_token terug.');
        }

        // User ophalen
        $user = $this->fetchUser($tokens['access_token']);

        return [
            'user'   => $user,
            'tokens' => $tokens,
        ];
    }

    // ─── STAP 3: DB Opslag ────────────────────────────────────────────────

    /**
     * Sla de OAuth koppeling op in cf_user_oauth.
     * Tokens worden geëncrypteerd opgeslagen.
     */
    public function saveConnection(int $userId, array $user, array $tokens): void
    {
        $expiresAt = isset($tokens['expires_in'])
            ? date('Y-m-d H:i:s', time() + (int) $tokens['expires_in'])
            : null;

        $this->db->execute(
            "INSERT INTO cf_user_oauth
             (user_id, provider, provider_user_id, access_token, refresh_token,
              token_expires_at, scope, provider_data, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
               access_token     = VALUES(access_token),
               refresh_token    = VALUES(refresh_token),
               token_expires_at = VALUES(token_expires_at),
               scope            = VALUES(scope),
               provider_data    = VALUES(provider_data),
               updated_at       = NOW()",
            [
                $userId,
                $this->getProviderSlug(),
                (string) $this->extractUserId($user),
                $this->encrypt($tokens['access_token']),
                isset($tokens['refresh_token']) ? $this->encrypt($tokens['refresh_token']) : null,
                $expiresAt,
                $tokens['scope'] ?? implode(' ', $this->scopes),
                json_encode($user),
            ]
        );
    }

    /**
     * Haal de opgeslagen OAuth koppeling op voor een user.
     */
    public function getConnection(int $userId): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_user_oauth WHERE user_id = ? AND provider = ?",
            [$userId, $this->getProviderSlug()]
        );
    }

    /**
     * Verwijder een OAuth koppeling.
     */
    public function disconnect(int $userId): void
    {
        $this->db->execute(
            "DELETE FROM cf_user_oauth WHERE user_id = ? AND provider = ?",
            [$userId, $this->getProviderSlug()]
        );
    }

    // ─── TOKEN EXCHANGE ───────────────────────────────────────────────────

    protected function exchangeCode(string $code): array
    {
        $params = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => $this->getGrantType(),
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri,
        ];

        return $this->post($this->getTokenEndpoint(), $params, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);
    }

    /**
     * Refresh een verlopen access token.
     */
    public function refreshToken(string $encryptedRefreshToken): array
    {
        $refreshToken = $this->decrypt($encryptedRefreshToken);

        return $this->post($this->getTokenEndpoint(), [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ], ['Content-Type: application/x-www-form-urlencoded']);
    }

    // ─── USER FETCH ───────────────────────────────────────────────────────

    protected function fetchUser(string $accessToken): array
    {
        return $this->get($this->getUserEndpoint(), [
            'Authorization: Bearer ' . $accessToken,
        ]);
    }

    // ─── ENCRYPTIE ────────────────────────────────────────────────────────

    // Golf 10: gedelegeerd naar Core\Security\Crypto (nu ook gebruikt door
    // SettingsRepository voor 'encrypted'-type instellingen zoals een OAuth
    // client_secret) — was hier voorheen private, ongedupliceerde logica.
    protected function encrypt(string $value): string
    {
        return Crypto::encrypt($value);
    }

    protected function decrypt(string $encrypted): string
    {
        return Crypto::decrypt($encrypted);
    }

    // ─── HTTP HELPERS ─────────────────────────────────────────────────────

    protected function post(string $url, array $data, array $headers = []): array
    {
        return $this->request($url, $headers, $data);
    }

    protected function get(string $url, array $headers = []): array
    {
        return $this->request($url, $headers, null);
    }

    /**
     * Eén HTTP-aanroep naar de provider. Gooit ALTIJD een \RuntimeException bij
     * een netwerkfout, een niet-200 antwoord of onleesbare JSON (zodat de
     * aanroeper één type fout hoeft af te vangen en de bezoeker nooit een 500 ziet).
     */
    private function request(string $url, array $headers, ?array $post): array
    {
        $original = $url;
        $url      = self::rewriteForTests($url);
        $mock     = $url !== $original;
        $ch       = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('OAuth: cURL kon niet worden gestart.');
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => false,
            // Alleen https naar echte providers; http alleen naar de lokale testserver.
            CURLOPT_PROTOCOLS      => $mock ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
            // GitHub eist een User-Agent en geeft zonder Accept: application/json
            // het token-antwoord als formulier-tekst terug.
            CURLOPT_HTTPHEADER     => array_merge(
                ['Accept: application/json', 'User-Agent: BlueprintCMS'],
                $headers
            ),
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $opts);

        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        $label = $post !== null ? 'POST' : 'GET';
        if ($body === false) {
            throw new \RuntimeException("OAuth {$label} naar {$url} mislukt: {$error}");
        }
        if ($status !== 200) {
            throw new \RuntimeException(
                "OAuth {$label} naar {$url} mislukt: HTTP {$status} — " . substr((string) $body, 0, 200)
            );
        }

        try {
            $json = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("OAuth {$label} naar {$url}: ongeldig JSON-antwoord.", 0, $e);
        }
        if (!is_array($json)) {
            throw new \RuntimeException("OAuth {$label} naar {$url}: onverwacht antwoord.");
        }

        return $json;
    }

    /**
     * Testnaad: met APP_ENV=testing en OAUTH_MOCK_BASE gezet gaan de server-naar-server
     * aanroepen naar een lokale nep-provider ("https://host/pad" wordt
     * "{BASE}/host/pad"). Op productie (APP_ENV=production) heeft dit nooit effect.
     */
    public static function rewriteForTests(string $url): string
    {
        $base = (string) ($_ENV['OAUTH_MOCK_BASE'] ?? getenv('OAUTH_MOCK_BASE') ?: '');
        $env  = (string) ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: '');
        if ($base === '' || $env !== 'testing' || !str_starts_with($url, 'https://')) {
            return $url;
        }
        return rtrim($base, '/') . '/' . substr($url, strlen('https://'));
    }

    /** Zijn client-ID en -secret ingevuld? */
    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    // ─── ABSTRACT HELPER ─────────────────────────────────────────────────

    /** Extraheer het provider user ID uit de user-data array */
    abstract protected function extractUserId(array $user): string|int;
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: OAuthClient.php | Role: Core | Version: 1.0.0                ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Abstract OAuth2 base — Discord + Twitch extenden dit        ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
