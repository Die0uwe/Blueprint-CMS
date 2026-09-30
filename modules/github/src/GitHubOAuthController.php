<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\GitHub;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\OAuth\AccountLinkPolicy;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Security\Crypto;

/**
 * GitHub OAuth Controller (zelfde opzet als GoogleOAuthController)
 *
 *   GET  /auth/github              → koppelen aan het ingelogde account
 *   GET  /auth/github/login        → inloggen/registreren met GitHub
 *   GET  /auth/github/callback     → callback voor beide flows
 *   POST /auth/github/disconnect   → ontkoppelen (alleen als inloggen daarna nog kan)
 *
 * Koppelen gebeurt uitsluitend op het numerieke GitHub-id. Een bestaand
 * account wordt nooit automatisch op e-mailadres overgenomen
 * (AuthManager::findOrCreateFromOAuth maakt bij een al bezet adres een nieuw
 * account met placeholder-adres); een niet-geverifieerd GitHub-adres wordt
 * door GitHubOAuth sowieso niet doorgegeven.
 */
final class GitHubOAuthController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection  $db,
    ) {}

    // ─── Stap 1a: koppelen ─────────────────────────────────────────────────

    public function redirect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/auth/github');
        }
        if (!$this->isConfigured()) {
            // Geen foutredirect: de knop hoort hier niet te bestaan (ProviderRegistry).
            return Response::redirect('/profiel');
        }

        $_SESSION['oauth_intent_github'] = 'link';

        return Response::redirect($this->makeOAuthClient()->buildRedirectUrl());
    }

    // ─── Stap 1b: inloggen/registreren ─────────────────────────────────────

    public function loginRedirect(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($request);
        }
        if (!$this->isConfigured()) {
            return Response::redirect('/login');
        }

        $_SESSION['oauth_intent_github'] = 'login';

        return Response::redirect($this->makeOAuthClient()->buildRedirectUrl());
    }

    // ─── Stap 2: callback ──────────────────────────────────────────────────

    public function callback(Request $request): Response
    {
        $code   = (string) $request->query('code', '');
        $state  = (string) $request->query('state', '');
        $intent = $_SESSION['oauth_intent_github'] ?? 'link';
        unset($_SESSION['oauth_intent_github']);

        // Geweigerd door de gebruiker (?error=access_denied) of geen code.
        if ($code === '' || $request->query('error', '') !== '') {
            return Response::redirect('/?error=github_cancelled');
        }

        if ($intent === 'link' && !$this->auth->check()) {
            return Response::redirect('/login');
        }
        if (!$this->isConfigured()) {
            return Response::redirect($intent === 'link' ? '/profiel' : '/login');
        }

        try {
            $oauth  = $this->makeOAuthClient();
            $result = $oauth->handleCallback($code, $state);

            $ghUser = $result['user'];
            $tokens = $result['tokens'];
            $ghId   = (string) $ghUser['id'];

            if ($intent === 'login') {
                $cmsUser = $this->auth->findOrCreateFromOAuth(
                    provider: 'github',
                    providerUserId: $ghId,
                    profile: [
                        'username'       => $ghUser['login'] ?? ('github_' . $ghId),
                        'email'          => $ghUser['email'] ?? null,
                        'email_verified' => (bool) ($ghUser['email_verified'] ?? false),
                        'avatar_url'     => $ghUser['avatar_url'] ?? null,
                    ],
                );
                $this->auth->login($cmsUser);
                $userId = (int) $cmsUser['id'];
            } else {
                $userId = (int) $this->auth->id();

                // Dit GitHub-account hoort al bij een ander CMS-account: niet
                // stilletjes "gekoppeld" melden terwijl er niets verandert.
                $owner = $this->db->fetchOne(
                    "SELECT user_id FROM cf_user_oauth WHERE provider = 'github' AND provider_user_id = ?",
                    [$ghId]
                );
                if ($owner !== null && (int) $owner['user_id'] !== $userId) {
                    return Response::redirect('/profiel?github=already_linked');
                }
            }

            $oauth->saveConnection($userId, $ghUser, $tokens);

            $cmsUser = $this->auth->user();
            if (empty($cmsUser['avatar_url']) && !empty($ghUser['avatar_url'])) {
                $this->db->execute(
                    "UPDATE cf_users SET avatar_url = ? WHERE id = ?",
                    [$ghUser['avatar_url'], $userId]
                );
            }

            return Response::redirect('/profiel?github=connected');

        } catch (\RuntimeException $e) {
            error_log('GitHub OAuth fout: ' . $e->getMessage());
            return Response::redirect('/?error=github_failed');
        }
    }

    // ─── Ontkoppelen ───────────────────────────────────────────────────────

    public function disconnect(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::json(['error' => 'Niet ingelogd.'], 401);
        }

        CsrfProtection::validateRequest();

        $userId = (int) $this->auth->id();
        if (!AccountLinkPolicy::canDisconnect($this->db, $userId, 'github')) {
            if ($request->isJson() || $request->isAjax()) {
                return Response::json(['error' => 'Ontkoppelen niet mogelijk: je kunt daarna niet meer inloggen.'], 409);
            }
            return Response::redirect('/profiel?github=last_login');
        }

        $this->makeOAuthClient()->disconnect($userId);

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }

        return Response::redirect('/profiel?github=disconnected');
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function isConfigured(): bool
    {
        return $this->getSetting('client_id') !== '' && $this->getSetting('client_secret') !== '';
    }

    private function makeOAuthClient(): GitHubOAuth
    {
        return new GitHubOAuth(
            db:           $this->db,
            clientId:     $this->getSetting('client_id'),
            clientSecret: $this->getSetting('client_secret'),
            redirectUri:  $this->getSetting('redirect_uri',
                rtrim($_ENV['APP_URL'] ?? '', '/') . '/auth/github/callback'),
            scopes:       ['read:user', 'user:email'],
        );
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne(
            "SELECT value, `type` FROM cf_settings WHERE `group` = 'github' AND `key` = ?",
            [$key]
        );
        if ($row === null || ($row['value'] ?? '') === '') return $default;
        if (($row['type'] ?? 'string') === 'encrypted') {
            return Crypto::decrypt($row['value']);
        }
        return (string) $row['value'];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GitHubOAuthController.php | Role: Module | Version: 1.0.0    ║
// ║  Notes: GitHub OAuth2 login/link/disconnect flow                    ║
// ╚══════════════════════════════════════════════════════════════════════╝
