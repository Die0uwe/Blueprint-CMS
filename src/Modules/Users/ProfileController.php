<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Storage\UploadException;

/**
 * ProfileController — GET/POST /profiel
 *
 * Bestond nog niet: elke succesvolle Discord/Twitch OAuth-koppeling
 * redirect al sinds Sprint 4 naar "/profiel?discord=connected", maar zonder
 * route erachter leidde dat tot een 404. Bevat meteen de eerste echte
 * consument van UploadManager voor `cf_users.avatar_url`.
 */
final class ProfileController
{
    public function __construct(
        private readonly AuthManager   $auth,
        private readonly Connection    $db,
        private readonly ThemeManager  $theme,
        private readonly UploadManager $uploads,
    ) {}

    public function show(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/profiel');
        }

        $connections = $this->db->fetchAll(
            "SELECT provider, provider_data, created_at FROM cf_user_oauth WHERE user_id = ?",
            [$this->auth->id()]
        );

        $html = $this->theme->render('users/profile.twig', [
            'page_title'  => 'Mijn profiel',
            'user'        => $this->auth->user(),
            'language'    => $request->query('language'),
            'connections' => array_map(
                fn(array $c) => [
                    'provider' => $c['provider'],
                    'data'     => json_decode($c['provider_data'] ?? '{}', true) ?? [],
                    'since'    => $c['created_at'],
                ],
                $connections
            ),
            // Golf 10: generiek gemaakt voor alle vier OAuth-providers i.p.v.
            // hardcoded discord_status/twitch_status — profile.twig loopt nu
            // over 'oauth_providers' i.p.v. losse if-blokken per provider.
            'oauth_providers' => [
                ['slug' => 'discord',   'label' => 'Discord',    'color' => '#5865F2', 'status' => $request->query('discord')],
                ['slug' => 'twitch',    'label' => 'Twitch',     'color' => '#9146FF', 'status' => $request->query('twitch')],
                ['slug' => 'google',    'label' => 'Google',     'color' => '#4285F4', 'status' => $request->query('google')],
                ['slug' => 'battlenet', 'label' => 'Battle.net', 'color' => '#148eff', 'status' => $request->query('battlenet')],
            ],
        ]);

        return Response::html($html);
    }

    public function updateAvatar(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/profiel');
        }

        CsrfProtection::validateRequest();

        $userId = (int) $this->auth->id();
        $file   = $request->files()['avatar'] ?? null;

        if ($file === null) {
            return Response::redirect('/profiel?error=geen_bestand');
        }

        try {
            $stored = $this->uploads->store($file, 'avatars');
        } catch (UploadException $e) {
            return Response::redirect('/profiel?error=' . urlencode($e->getMessage()));
        }

        $previous = $this->db->fetchOne("SELECT avatar_url FROM cf_users WHERE id = ?", [$userId]);

        $this->db->execute(
            "UPDATE cf_users SET avatar_url = ? WHERE id = ?",
            ['/media/' . $stored, $userId]
        );

        // Oude eigen upload opruimen (nooit een externe Discord/Twitch-avatar-URL verwijderen)
        $oldUrl = $previous['avatar_url'] ?? null;
        if (is_string($oldUrl) && str_starts_with($oldUrl, '/media/avatars/')) {
            $this->uploads->delete(substr($oldUrl, strlen('/media/')));
        }

        return Response::redirect('/profiel?avatar=updated');
    }

    /**
     * POST /profiel/taal — S13 (Multi-language/i18n). Persistent, per-
     * gebruiker taalvoorkeur, los van de publieke gast-taalwisselaar
     * (`GET /taal/{locale}`, die alleen de sessie zet). Schrijft ook meteen
     * naar `$_SESSION['locale']` zodat de wijziging direct op déze pagina-
     * load al zichtbaar is, zonder te wachten op de volgende Translator-
     * resolutie (die `cf_users.locale` pas bij de eerstvolgende request zou
     * hebben gelezen — zie Application::boot()'s resolutievolgorde).
     */
    public function updateLanguage(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/profiel');
        }

        CsrfProtection::validateRequest();

        $locale = (string) $request->input('locale', '');
        if (!in_array($locale, \CommunityFusion\Core\I18n\Translator::SUPPORTED, true)) {
            return Response::redirect('/profiel?error=' . urlencode('Onbekende taal.'));
        }

        $this->db->execute("UPDATE cf_users SET locale = ? WHERE id = ?", [$locale, $this->auth->id()]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['locale'] = $locale;

        return Response::redirect('/profiel?language=updated');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: ProfileController.php | Role: Core | Version: 1.0.0          ║
// ║  Created: 2026-09-28 | Status: New — Wave 0 gap-fix (404 op /profiel)║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
