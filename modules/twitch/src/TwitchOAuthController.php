<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Twitch;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;

/**
 * Twitch OAuth Controller
 *
 * Routes:
 *   GET  /auth/twitch              → redirect naar Twitch (koppelen aan ingelogd account)
 *   GET  /auth/twitch/login        → redirect naar Twitch (inloggen/registreren, geen account nodig)
 *   GET  /auth/twitch/callback     → verwerk callback (beide flows)
 *   POST /auth/twitch/disconnect   → ontkoppel Twitch account
 *
 * Golf 10: vóór deze golf bestond alleen de "koppelen"-flow (callback eiste
 * altijd al een ingelogde sessie) — er was geen manier om via Twitch in te
 * loggen of een nieuw account aan te maken. Dit volgt nu exact hetzelfde
 * link/login-intentiepatroon als DiscordOAuthController: de sessie onthoudt
 * welke van de twee bedoeld was (`oauth_intent_twitch`), zodat een bezoeker
 * nooit per ongeluk een Twitch-account aan het verkeerde CMS-account koppelt.
 */
final class TwitchOAuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection  $db,
    ) {}

    // ─── STAP 1a: Redirect naar Twitch — koppelen aan bestaand account ────

    public function redirect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/auth/twitch');
        }

        $_SESSION['oauth_intent_twitch'] = 'link';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 1b: Redirect naar Twitch — inloggen/registreren ──────────────

    public function loginRedirect(Request $request): Response
    {
        if ($this->auth->check()) {
            // Al ingelogd: "inloggen met Twitch" wordt dan gewoon koppelen.
            return $this->redirect($request);
        }

        $_SESSION['oauth_intent_twitch'] = 'login';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 2: Callback verwerken ────────────────────────────────────────

    public function callback(Request $request): Response
    {
        $code   = $request->query('code', '');
        $state  = $request->query('state', '');
        $intent = $_SESSION['oauth_intent_twitch'] ?? 'link';
        unset($_SESSION['oauth_intent_twitch']);

        if (empty($code)) {
            return Response::redirect('/?error=twitch_cancelled');
        }

        if ($intent === 'link' && !$this->auth->check()) {
            return Response::redirect('/login');
        }

        try {
            $oauth  = $this->makeOAuthClient();
            $result = $oauth->handleCallback($code, $state);

            $twitchUser = $result['user'];
            $tokens     = $result['tokens'];

            if ($intent === 'login') {
                $cmsUser = $this->auth->findOrCreateFromOAuth(
                    provider: 'twitch',
                    providerUserId: (string) $twitchUser['id'],
                    profile: [
                        'username'       => $twitchUser['login'] ?? ('twitch_' . $twitchUser['id']),
                        'email'          => $twitchUser['email'] ?? null,
                        'email_verified' => !empty($twitchUser['email']),
                        'avatar_url'     => $twitchUser['profile_image_url'] ?? null,
                    ],
                );
                $this->auth->login($cmsUser);
                $userId = (int) $cmsUser['id'];
            } else {
                $userId = (int) $this->auth->id();
            }

            $oauth->saveConnection($userId, $twitchUser, $tokens);

            // Avatar overnemen als de CMS-gebruiker er nog geen heeft.
            $cmsUser = $this->auth->user();
            if (empty($cmsUser['avatar_url']) && !empty($twitchUser['profile_image_url'])) {
                $this->db->execute(
                    "UPDATE cf_users SET avatar_url = ? WHERE id = ?",
                    [$twitchUser['profile_image_url'], $userId]
                );
            }

            return Response::redirect('/profiel?twitch=connected');
        } catch (\RuntimeException $e) {
            error_log("Twitch OAuth fout: " . $e->getMessage());
            return Response::redirect('/?error=twitch_failed');
        }
    }

    // ─── Ontkoppelen ────────────────────────────────────────────────────────

    public function disconnect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }

        // Nooit ontkoppelen als dit de enige manier is om in te loggen.
        if (!\CommunityFusion\Core\Auth\OAuth\AccountLinkPolicy::canDisconnect($this->db, (int) $this->auth->id(), 'twitch')) {
            if ($request->isJson() || $request->isAjax()) {
                return Response::json(['error' => 'Ontkoppelen niet mogelijk: je kunt daarna niet meer inloggen.'], 409);
            }
            return Response::redirect('/profiel?twitch=last_login');
        }

        $oauth = $this->makeOAuthClient();
        $oauth->disconnect((int) $this->auth->id());

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }

        return Response::redirect('/profiel?twitch=disconnected');
    }

    private function makeOAuthClient(): TwitchOAuth
    {
        return new TwitchOAuth(
            db:           $this->db,
            clientId:     $this->getSetting('client_id'),
            clientSecret: $this->getSetting('client_secret'),
            redirectUri:  $this->getSetting('redirect_uri',
                ($_ENV['APP_URL'] ?? '') . '/auth/twitch/callback'),
            scopes:       ['user:read:email', 'user:read:follows'],
        );
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne(
            "SELECT value, `type` FROM cf_settings WHERE `group` = 'twitch' AND `key` = ?",
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
// ║  File: TwitchOAuthController.php | Role: Core | Version: 1.1.0      ║
// ║  Created: 2026-06-06 | Updated: 2026-09-29 — Golf 10                ║
// ║  Notes: Login-flow + encrypted-setting support toegevoegd            ║
// ╚══════════════════════════════════════════════════════════════════════╝
