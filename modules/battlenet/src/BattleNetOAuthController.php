<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\BattleNet;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;

/**
 * Battle.net OAuth Controller
 *
 * Routes:
 *   GET  /auth/battlenet              → redirect naar Battle.net (koppelen aan ingelogd account)
 *   GET  /auth/battlenet/login        → redirect naar Battle.net (inloggen/registreren, geen account nodig)
 *   GET  /auth/battlenet/callback     → verwerk callback (beide flows)
 *   POST /auth/battlenet/disconnect   → ontkoppel Battle.net account
 *
 * Zelfde link/login-intentiepatroon als Discord: "inloggen met Battle.net"
 * (/auth/battlenet/login) is een aparte entrypoint van "Battle.net koppelen"
 * (/auth/battlenet), zodat een niet-ingelogde bezoeker nooit per ongeluk een
 * Battle.net-account aan het account van een ander koppelt. De sessie
 * onthoudt welke van de twee intenties gestart is (`oauth_intent_battlenet`).
 */
final class BattleNetOAuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection  $db,
    ) {}

    // ─── STAP 1a: Redirect naar Battle.net — koppelen aan bestaand account ─

    public function redirect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/auth/battlenet');
        }

        $_SESSION['oauth_intent_battlenet'] = 'link';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 1b: Redirect naar Battle.net — inloggen/registreren ──────────

    public function loginRedirect(Request $request): Response
    {
        if ($this->auth->check()) {
            // Al ingelogd: "inloggen met Battle.net" wordt dan gewoon koppelen.
            return $this->redirect($request);
        }

        $_SESSION['oauth_intent_battlenet'] = 'login';

        $oauth = $this->makeOAuthClient();
        return Response::redirect($oauth->buildRedirectUrl());
    }

    // ─── STAP 2: Callback verwerken ─────────────────────────────────────────

    public function callback(Request $request): Response
    {
        $code   = $request->query('code', '');
        $state  = $request->query('state', '');
        $intent = $_SESSION['oauth_intent_battlenet'] ?? 'link';
        unset($_SESSION['oauth_intent_battlenet']);

        if (empty($code)) {
            return Response::redirect('/?error=battlenet_cancelled');
        }

        // "link" vereist een ingelogd account; als de sessie ondertussen
        // verlopen is, valt dit netjes terug op de login-pagina i.p.v. een
        // OAuth-koppeling zonder eigenaar te maken.
        if ($intent === 'link' && !$this->auth->check()) {
            return Response::redirect('/login');
        }

        try {
            $oauth = $this->makeOAuthClient();
            $result = $oauth->handleCallback($code, $state);

            $bnetUser = $result['user'];
            $tokens   = $result['tokens'];

            if ($intent === 'login') {
                // Nieuwe of bestaande CMS-gebruiker vinden/aanmaken puur op
                // basis van de Battle.net-koppeling — geen wachtwoord nodig.
                // Blizzard levert geen e-mail: AuthManager::findOrCreateFromOAuth
                // genereert dan zelf al een unieke placeholder-e-mail (zelfde
                // pad als Discord zonder e-mail-scope), dus geen extra werk hier.
                $cmsUser = $this->auth->findOrCreateFromOAuth(
                    provider: 'battlenet',
                    providerUserId: (string) ($bnetUser['sub'] ?? $bnetUser['id']),
                    profile: [
                        'username'       => $bnetUser['battletag'] ?? ('battlenet_' . ($bnetUser['sub'] ?? $bnetUser['id'])),
                        'email'          => null,
                        'email_verified' => false,
                        'avatar_url'     => null,
                    ],
                );
                $this->auth->login($cmsUser);
                $userId = (int) $cmsUser['id'];
            } else {
                $userId = (int) $this->auth->id();
            }

            // Sla OAuth koppeling op (idempotent: ON DUPLICATE KEY UPDATE)
            $oauth->saveConnection($userId, $bnetUser, $tokens);

            // Vul display_name aan met de BattleTag als die nog niet gezet is
            // — zelfde "aanvullen als leeg" patroon als Discord's avatar-update.
            $cmsUser = $this->auth->user();
            if (empty($cmsUser['display_name']) && !empty($bnetUser['battletag'])) {
                $this->db->execute(
                    "UPDATE cf_users SET display_name = ? WHERE id = ?",
                    [$bnetUser['battletag'], $userId]
                );
            }

            return Response::redirect('/profiel?battlenet=connected');

        } catch (\RuntimeException $e) {
            error_log("Battle.net OAuth fout: " . $e->getMessage());
            return Response::redirect('/?error=battlenet_failed');
        }
    }

    // ─── Ontkoppelen ────────────────────────────────────────────────────────

    public function disconnect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }

        // Nooit ontkoppelen als dit de enige manier is om in te loggen.
        if (!\CommunityFusion\Core\Auth\OAuth\AccountLinkPolicy::canDisconnect($this->db, (int) $this->auth->id(), 'battlenet')) {
            if ($request->isJson() || $request->isAjax()) {
                return Response::json(['error' => 'Ontkoppelen niet mogelijk: je kunt daarna niet meer inloggen.'], 409);
            }
            return Response::redirect('/profiel?battlenet=last_login');
        }

        $oauth = $this->makeOAuthClient();
        $oauth->disconnect((int) $this->auth->id());

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }

        return Response::redirect('/profiel?battlenet=disconnected');
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function makeOAuthClient(): BattleNetOAuth
    {
        return new BattleNetOAuth(
            db:           $this->db,
            clientId:     $this->getSetting('client_id', ''),
            clientSecret: $this->getSetting('client_secret', ''),
            redirectUri:  $this->getSetting('redirect_uri',
                ($_ENV['APP_URL'] ?? '') . '/auth/battlenet/callback'),
            region:       $this->getSetting('region', 'eu'),
            scopes:       [],
        );
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne(
            "SELECT value, `type` FROM cf_settings WHERE `group` = 'battlenet' AND `key` = ?",
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
// ║  File: BattleNetOAuthController.php | Role: Core | Version: 1.0.0   ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
