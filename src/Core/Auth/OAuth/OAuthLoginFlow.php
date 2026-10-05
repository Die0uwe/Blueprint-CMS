<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth\OAuth;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Security\SafeRedirect;

/**
 * OAuthLoginFlow — de gedeelde "inloggen / koppelen met provider X"-afhandeling.
 *
 * Elke provider-module (GitHub, Google, Discord, Twitch, Battle.net) heeft een
 * eigen OAuthClient (endpoints, scopes) en een eigen dunne controller, maar
 * ALLE beslissingen die ertoe doen staan hier, op één plek:
 *
 *   - twee bedoelingen ("intent"): `login` (inloggen/registreren, geen account
 *     nodig) en `link` (aan het ingelogde account koppelen). Een bezoeker die
 *     niet is ingelogd kan nooit een provider-account aan het account van een
 *     ander koppelen;
 *   - state-controle (CSRF) via OAuthClient::handleCallback();
 *   - een geblokkeerd/verwijderd account kan niet inloggen en krijgt ook geen
 *     nieuw account;
 *   - een provider-account dat al aan een ánder account hangt, wordt nooit
 *     stilzwijgend overgenomen;
 *   - er wordt NIET op e-mailadres aan een bestaand account gekoppeld: de
 *     registratie controleert e-mailadressen niet, dus een aanvaller kan het
 *     adres van een ander met een eigen wachtwoord hebben geregistreerd.
 *     Bestaande leden koppelen een provider bewust via hun profiel;
 *   - elke fout (netwerk, ongeldige JSON, geweigerd) eindigt in een nette
 *     redirect met een foutcode, nooit in een 500;
 *   - de terugkeer-URL wordt gecontroleerd (SafeRedirect).
 *
 * Foutcodes (altijd uit deze vaste lijst, nooit vrije tekst in de URL):
 *   cancelled, state, failed, disabled, not_configured, not_enabled,
 *   already_linked, other_linked, last_method, merge_unknown, merge_same
 *
 * Derde intentie `merge` (?intent=merge op /auth/{provider}): bewijst dat het
 * provider-account bij een ánder account van dezelfde persoon hoort. Er wordt
 * niets gekoppeld of samengevoegd; alleen een kortlevend bewijs in de sessie
 * gezet (zie AccountService) waarna /profiel/samenvoegen om bevestiging vraagt.
 */
final class OAuthLoginFlow
{
    public const ERRORS = [
        'cancelled', 'state', 'failed', 'disabled', 'not_configured',
        'not_enabled', 'already_linked', 'other_linked', 'last_method',
        'merge_unknown', 'merge_same',
    ];

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection  $db,
        private readonly AuditLogger $audit,
    ) {
    }

    // ─── STAP 1: naar de provider ────────────────────────────────────────

    /**
     * @param string $intent 'login' of 'link'
     */
    public function begin(string $provider, string $intent, OAuthClient $client, Request $request): Response
    {
        if ($request->query('intent', '') === 'merge') {
            if (!$this->auth->check()) {
                return Response::redirect('/login?redirect=' . rawurlencode('/profiel/samenvoegen'));
            }
            $intent = 'merge';
        } elseif ($intent === 'link' || $this->auth->check()) {
            // Al ingelogd: "inloggen met X" is dan gewoon koppelen.
            $intent = 'link';
            if (!$this->auth->check()) {
                return Response::redirect('/login?redirect=' . rawurlencode('/auth/' . $provider));
            }
        }

        if (!$client->isConfigured()) {
            return $this->fail($provider, $intent, 'not_configured');
        }

        $_SESSION['oauth_intent_' . $provider]   = $intent;
        $_SESSION['oauth_redirect_' . $provider] = SafeRedirect::target($request->query('redirect', '/'));

        return Response::redirect($client->buildRedirectUrl());
    }

    // ─── STAP 2: terug van de provider ───────────────────────────────────

    /**
     * @param callable(array<string,mixed>):array{id:string,profile:array<string,mixed>} $normalize
     *        zet het ruwe provider-profiel om naar [id => string, profile => [...]]
     *        (profile: username, email, email_verified, avatar_url)
     * @param (callable(int,array<string,mixed>,array<string,mixed>):void)|null $after
     *        optioneel, na een geslaagde login/koppeling: ($userId, $providerUser, $tokens)
     */
    public function complete(
        string $provider,
        Request $request,
        OAuthClient $client,
        callable $normalize,
        ?callable $after = null,
    ): Response {
        // Geen intentie in de sessie = deze callback hoort niet bij een door ons gestarte
        // inlogpoging (verlopen sessie, herhaalde of ongevraagde aanroep).
        if (!isset($_SESSION['oauth_intent_' . $provider])) {
            return $this->fail($provider, 'login', 'state');
        }
        $intent   = (string) $_SESSION['oauth_intent_' . $provider];
        $redirect = SafeRedirect::target($_SESSION['oauth_redirect_' . $provider] ?? '/');
        unset($_SESSION['oauth_intent_' . $provider], $_SESSION['oauth_redirect_' . $provider]);
        $intent = in_array($intent, ['login', 'merge'], true) ? $intent : 'link';

        $code = (string) $request->query('code', '');
        if ((string) $request->query('error', '') !== '' || $code === '') {
            return $this->fail($provider, $intent, 'cancelled');
        }

        // Koppelen kan alleen met een geldige sessie. Is die intussen verlopen,
        // dan terug naar de loginpagina in plaats van een koppeling zonder eigenaar.
        if ($intent !== 'login' && !$this->auth->check()) {
            return Response::redirect('/login');
        }

        try {
            $result = $client->handleCallback($code, (string) $request->query('state', ''));
        } catch (\Throwable $e) {
            error_log("OAuth ({$provider}) callback mislukt: " . $e->getMessage());
            $state = str_contains($e->getMessage(), 'state mismatch');
            return $this->fail($provider, $intent, $state ? 'state' : 'failed');
        }

        $providerUser = $result['user'];
        $tokens       = $result['tokens'];

        try {
            $n          = $normalize($providerUser);
            $providerId = trim((string) ($n['id'] ?? ''));
            $profile    = (array) ($n['profile'] ?? []);
        } catch (\Throwable $e) {
            error_log("OAuth ({$provider}) profiel onleesbaar: " . $e->getMessage());
            return $this->fail($provider, $intent, 'failed');
        }
        if ($providerId === '') {
            return $this->fail($provider, $intent, 'failed');
        }

        if ($intent === 'merge') {
            $me    = (int) $this->auth->id();
            $owner = $this->db->fetchOne(
                "SELECT user_id FROM cf_user_oauth WHERE provider = ? AND provider_user_id = ?",
                [$provider, $providerId]
            );
            if ($owner === null) {
                return $this->fail($provider, $intent, 'merge_unknown');
            }
            if ((int) $owner['user_id'] === $me) {
                return $this->fail($provider, $intent, 'merge_same');
            }
            $_SESSION['merge_proof'] = ['keep' => $me, 'drop' => (int) $owner['user_id'], 'at' => time()];
            $this->audit->log('auth.merge_proof', $me, null, ['provider' => $provider]);
            return Response::redirect('/profiel/samenvoegen');
        }

        try {
            if ($intent === 'login') {
                try {
                    $user = $this->auth->findOrCreateFromOAuth($provider, $providerId, $profile);
                } catch (OAuthAccountDisabledException) {
                    $this->audit->log('auth.oauth_login_blocked', null, null, ['provider' => $provider]);
                    return $this->fail($provider, $intent, 'disabled');
                }
                $isNew  = $this->isNewlyCreated($user);
                $userId = (int) $user['id'];
                $this->auth->login($user);
                $this->audit->log($isNew ? 'auth.oauth_register' : 'auth.oauth_login', $userId, (string) $user['username'], ['provider' => $provider]);
            } else {
                $userId = (int) $this->auth->id();
                $error  = $this->linkConflict($userId, $provider, $providerId);
                if ($error !== null) {
                    return $this->fail($provider, $intent, $error);
                }
                $this->audit->log('auth.oauth_link', $userId, (string) ($this->auth->user()['username'] ?? ''), ['provider' => $provider]);
            }

            $client->saveConnection($userId, $providerUser, $tokens);
            $this->fillAvatar($userId, (string) ($profile['avatar_url'] ?? ''));

            if ($after !== null) {
                try {
                    $after($userId, $providerUser, $tokens);
                } catch (\Throwable $e) {
                    // Bijwerk (rol-sync e.d.) mag een geslaagde login nooit breken.
                    error_log("OAuth ({$provider}) na-stap mislukt: " . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log("OAuth ({$provider}) afronden mislukt: " . $e->getMessage());
            return $this->fail($provider, $intent, 'failed');
        }

        return Response::redirect($intent === 'login' ? $redirect : "/profiel?{$provider}=connected");
    }

    // ─── Ontkoppelen ─────────────────────────────────────────────────────

    /** POST /auth/{provider}/disconnect */
    public function disconnect(string $provider, OAuthClient $client, Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }
        CsrfProtection::validateRequest();

        $userId = (int) $this->auth->id();
        $wantsJson = $request->isJson() || $request->isAjax();

        if (!$this->canDisconnect($userId, $provider)) {
            // Dit is de enige manier waarop dit account nog kan inloggen.
            return $wantsJson
                ? Response::json(['error' => 'Dit is je enige inlogmethode.'], 409)
                : Response::redirect("/profiel?oauth_error=last_method&provider={$provider}");
        }

        $client->disconnect($userId);
        $this->audit->log('auth.oauth_unlink', $userId, (string) ($this->auth->user()['username'] ?? ''), ['provider' => $provider]);

        return $wantsJson
            ? Response::json(['success' => true])
            : Response::redirect("/profiel?{$provider}=disconnected");
    }


    /**
     * Mag het ingelogde account deze provider ontkoppelen zonder buitengesloten te raken?
     * Een account dat alleen via deze provider kan inloggen én geen echt e-mailadres
     * heeft (placeholder), zou nergens meer een herstellink kunnen krijgen.
     */
    public function canDisconnect(int $userId, string $provider): bool
    {
        $user = $this->db->fetchOne("SELECT email FROM cf_users WHERE id = ?", [$userId]);
        if ($user === null) {
            return false;
        }
        if (!str_ends_with((string) $user['email'], '.invalid')) {
            return true; // echt adres: wachtwoord vergeten-flow is altijd een vangnet
        }
        $others = $this->db->fetchOne(
            "SELECT COUNT(*) AS n FROM cf_user_oauth WHERE user_id = ? AND provider <> ?",
            [$userId, $provider]
        );
        return (int) ($others['n'] ?? 0) > 0;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** null = koppelen mag; anders de foutcode. */
    private function linkConflict(int $userId, string $provider, string $providerId): ?string
    {
        $owner = $this->db->fetchOne(
            "SELECT user_id FROM cf_user_oauth WHERE provider = ? AND provider_user_id = ?",
            [$provider, $providerId]
        );
        if ($owner !== null) {
            return (int) $owner['user_id'] === $userId ? null : 'already_linked';
        }
        $mine = $this->db->fetchOne(
            "SELECT 1 AS x FROM cf_user_oauth WHERE user_id = ? AND provider = ?",
            [$userId, $provider]
        );
        return $mine !== null ? 'other_linked' : null;
    }

    /** Is dit account net in deze request aangemaakt (geen eerdere login)? */
    /** @param array<string,mixed> $user */
    private function isNewlyCreated(array $user): bool
    {
        return ($user['last_login_at'] ?? null) === null;
    }

    private function fillAvatar(int $userId, string $url): void
    {
        if ($url === '' || !preg_match('#^https://#i', $url) || strlen($url) > 500) {
            return;
        }
        $this->db->execute(
            "UPDATE cf_users SET avatar_url = ? WHERE id = ? AND (avatar_url IS NULL OR avatar_url = '')",
            [$url, $userId]
        );
    }

    private function fail(string $provider, string $intent, string $code): Response
    {
        $code = in_array($code, self::ERRORS, true) ? $code : 'failed';
        // Wie ingelogd is (koppelen), ziet de melding op het profiel; anders op de loginpagina.
        $base = $intent === 'merge' ? '/profiel/samenvoegen' : ($this->auth->check() ? '/profiel' : '/login');
        return Response::redirect($base . '?oauth_error=' . $code . '&provider=' . rawurlencode($provider));
    }
}
