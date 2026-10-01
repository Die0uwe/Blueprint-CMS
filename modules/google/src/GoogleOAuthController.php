<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Google;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;

/**
 * Google OAuth Controller
 *
 * Routes:
 *   GET  /auth/google              → redirect naar Google (koppelen aan ingelogd account)
 *   GET  /auth/google/login        → redirect naar Google (inloggen/registreren, geen account nodig)
 *   GET  /auth/google/callback     → verwerk callback (beide flows)
 *   POST /auth/google/disconnect   → ontkoppel Google account
 *
 * "Inloggen met Google" (/auth/google/login) is bewust een aparte entrypoint
 * van "Google koppelen" (/auth/google): een bezoeker die niet is ingelogd mag
 * via Google een nieuw account krijgen of op een al gekoppeld account inloggen,
 * maar mag NOOIT per ongeluk een Google-account aan het account van een ander
 * koppelen. De sessie onthoudt welke van de twee intenties gestart is
 * (`oauth_intent_google`), zodat de callback weet wat te doen.
 */
final class GoogleOAuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection  $db,
    ) {}

    // ─── STAP 1a: Redirect naar Google — koppelen aan bestaand account ────

    public function redirect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/auth/google');
        }

        $_SESSION['oauth_intent_google'] = 'link';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 1b: Redirect naar Google — inloggen/registreren ──────────────

    public function loginRedirect(Request $request): Response
    {
        if ($this->auth->check()) {
            // Al ingelogd: "inloggen met Google" wordt dan gewoon koppelen.
            return $this->redirect($request);
        }

        $_SESSION['oauth_intent_google'] = 'login';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 2: Callback verwerken ─────────────────────────────────────────

    public function callback(Request $request): Response
    {
        $code   = $request->query('code', '');
        $state  = $request->query('state', '');
        $intent = $_SESSION['oauth_intent_google'] ?? 'link';
        unset($_SESSION['oauth_intent_google']);

        if (empty($code)) {
            return Response::redirect('/?error=google_cancelled');
        }

        // "link" vereist een ingelogd account; als de sessie ondertussen
        // verlopen is, valt dit netjes terug op de login-pagina i.p.v. een
        // OAuth-koppeling zonder eigenaar te maken.
        if ($intent === 'link' && !$this->auth->check()) {
            return Response::redirect('/login');
        }

        try {
            $oauth  = $this->makeOAuthClient();
            $result = $oauth->handleCallback($code, $state);

            $googleUser = $result['user'];
            $tokens     = $result['tokens'];

            if ($intent === 'login') {
                // Nieuwe of bestaande CMS-gebruiker vinden/aanmaken puur op
                // basis van de Google-koppeling — geen wachtwoord nodig.
                $cmsUser = $this->auth->findOrCreateFromOAuth(
                    provider: 'google',
                    providerUserId: (string) $googleUser['sub'],
                    profile: [
                        'username'       => $googleUser['name'] ?? ('google_' . $googleUser['sub']),
                        'email'          => $googleUser['email'] ?? null,
                        'email_verified' => (bool) ($googleUser['email_verified'] ?? false),
                        'avatar_url'     => $googleUser['picture'] ?? null,
                    ],
                );
                $this->auth->login($cmsUser);
                $userId = (int) $cmsUser['id'];
            } else {
                $userId = (int) $this->auth->id();
            }

            // Sla OAuth koppeling op (idempotent: ON DUPLICATE KEY UPDATE)
            $oauth->saveConnection($userId, $googleUser, $tokens);

            // Update CMS user met Google avatar als er nog geen is
            $cmsUser = $this->auth->user();
            if (empty($cmsUser['avatar_url']) && !empty($googleUser['picture'])) {
                $this->db->execute(
                    "UPDATE cf_users SET avatar_url = ? WHERE id = ?",
                    [$googleUser['picture'], $userId]
                );
            }

            return Response::redirect('/profiel?google=connected');

        } catch (\RuntimeException $e) {
            error_log("Google OAuth fout: " . $e->getMessage());
            return Response::redirect('/?error=google_failed');
        }
    }

    // ─── Ontkoppelen ────────────────────────────────────────────────────────

    public function disconnect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }

        \CommunityFusion\Core\Security\CsrfProtection::validateRequest();

        // Nooit ontkoppelen als dit de enige manier is om in te loggen.
        if (!\CommunityFusion\Core\Auth\OAuth\AccountLinkPolicy::canDisconnect($this->db, (int) $this->auth->id(), 'google')) {
            if ($request->isJson() || $request->isAjax()) {
                return Response::json(['error' => 'Ontkoppelen niet mogelijk: je kunt daarna niet meer inloggen.'], 409);
            }
            return Response::redirect('/profiel?google=last_login');
        }

        $oauth = $this->makeOAuthClient();
        $oauth->disconnect((int) $this->auth->id());

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }

        return Response::redirect('/profiel?google=disconnected');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function makeOAuthClient(): GoogleOAuth
    {
        return new GoogleOAuth(
            db:           $this->db,
            clientId:     $this->getSetting('client_id', ''),
            clientSecret: $this->getSetting('client_secret', ''),
            redirectUri:  $this->getSetting('redirect_uri',
                ($_ENV['APP_URL'] ?? '') . '/auth/google/callback'),
            scopes:       ['openid', 'email', 'profile'],
        );
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne(
            "SELECT value, `type` FROM cf_settings WHERE `group` = 'google' AND `key` = ?",
            [$key]
        );
        if ($row === null) return $default;
        if (($row['type'] ?? 'string') === 'encrypted' && $row['value'] !== '') {
            return \CommunityFusion\Core\Security\Crypto::decrypt($row['value']);
        }
        return $row['value'] ?? $default;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GoogleOAuthController.php | Role: Core | Version: 1.0.0      ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ║  Notes: Google OAuth2 login/link/disconnect flow                    ║
// ╚══════════════════════════════════════════════════════════════════════╝
