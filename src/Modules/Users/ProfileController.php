<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\OAuth\AccountLinkPolicy;
use CommunityFusion\Core\Auth\OAuth\ProviderRegistry;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Storage\UploadException;
use CommunityFusion\Modules\Forum\ForumRepository;

/**
 * ProfileController — GET/POST /profiel (privé instellingen) + GET /leden/{username}
 * (publiek profiel).
 *
 * Bestond nog niet: elke succesvolle Discord/Twitch OAuth-koppeling
 * redirect al sinds Sprint 4 naar "/profiel?discord=connected", maar zonder
 * route erachter leidde dat tot een 404. Bevat meteen de eerste echte
 * consument van UploadManager voor `cf_users.avatar_url`.
 *
 * publicShow()/updateBio() toegevoegd voor het publieke ledenprofiel:
 * `cf_users.bio` bestond al sinds Sprint 1 in het schema maar werd nergens
 * getoond of ingesteld — /profiel liet alleen avatar + taal bewerken. Volgt
 * hetzelfde patroon als BlogController::author()/findUserByUsername()
 * (is_active=1 AND deleted_at IS NULL, alleen publiek-veilige kolommen).
 */
final class ProfileController
{
    public function __construct(
        private readonly AuthManager     $auth,
        private readonly Connection      $db,
        private readonly ThemeManager    $theme,
        private readonly UploadManager   $uploads,
        private readonly ForumRepository $forum,
        private readonly ProviderRegistry $providers,
    ) {}

    public function show(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/profiel');
        }

        $userId = (int) $this->auth->id();
        $html = $this->theme->render('users/profile.twig', [
            'page_title'      => 'Mijn profiel',
            'user'            => $this->auth->user(),
            'language'        => $request->query('language'),
            'bio_status'      => $request->query('bio'),
            // "Gekoppelde accounts": één rij per provider uit de ProviderRegistry.
            'linked_accounts' => $this->buildLinkedAccounts($userId),
            'oauth_flash'     => $this->buildOAuthFlash($request),
        ]);

        return Response::html($html);
    }

    /**
     * POST /profiel/koppelingen/{slug}/ontkoppelen — generiek voor alle
     * providers. Weigert als het account daarna niet meer kan inloggen
     * (zie AccountLinkPolicy). CSRF-beveiligd.
     */
    public function disconnectProvider(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/profiel');
        }

        CsrfProtection::validateRequest();

        $slug = (string) $request->param('slug');
        if (preg_match('/^[a-z0-9-]{1,40}$/D', $slug) !== 1) {
            return Response::redirect('/profiel');
        }

        $userId = (int) $this->auth->id();
        if (!AccountLinkPolicy::canDisconnect($this->db, $userId, $slug)) {
            return Response::redirect('/profiel?' . rawurlencode($slug) . '=last_login');
        }

        $this->db->execute(
            "DELETE FROM cf_user_oauth WHERE user_id = ? AND provider = ?",
            [$userId, $slug]
        );

        return Response::redirect('/profiel?' . rawurlencode($slug) . '=disconnected');
    }

    /**
     * Rijen voor het overzicht. Getoond wordt elke provider die nu
     * koppelbaar is (aan + geconfigureerd) of waaraan het account al
     * gekoppeld is (zodat je altijd kunt ontkoppelen, ook als de module
     * inmiddels uit staat). Koppelingen van providers die de registry niet
     * (meer) kent, worden ook getoond.
     */
    private function buildLinkedAccounts(int $userId): array
    {
        $links = [];
        foreach ($this->db->fetchAll(
            "SELECT provider, provider_data, created_at FROM cf_user_oauth WHERE user_id = ?",
            [$userId]
        ) as $row) {
            $links[$row['provider']] = $row;
        }

        $rows = [];
        $seen = [];
        foreach ($this->providers->all() as $p) {
            $link = $links[$p['slug']] ?? null;
            $seen[$p['slug']] = true;
            $canConnect = $p['enabled'] && $p['configured'];
            if ($link === null && !$canConnect) {
                continue; // niet koppelbaar en niet gekoppeld: niet tonen
            }
            $rows[] = $this->accountRow($p, $link, $canConnect, $userId);
        }

        foreach ($links as $slug => $link) {
            if (isset($seen[$slug])) continue;
            $rows[] = $this->accountRow([
                'slug' => $slug, 'label' => ucfirst($slug), 'icon' => '🔗',
                'color' => '#444444', 'text_color' => '#ffffff',
                'link_url' => "/auth/{$slug}", 'disconnect_url' => "/profiel/koppelingen/{$slug}/ontkoppelen",
            ], $link, false, $userId);
        }

        return $rows;
    }

    private function accountRow(array $p, ?array $link, bool $canConnect, int $userId): array
    {
        $data = $link !== null ? (json_decode($link['provider_data'] ?? '{}', true) ?: []) : [];
        $name = '';
        foreach (['username', 'login', 'battletag', 'global_name', 'name'] as $k) {
            if (!empty($data[$k]) && is_string($data[$k])) { $name = $data[$k]; break; }
        }

        return [
            'slug'           => $p['slug'],
            'label'          => $p['label'],
            'icon'           => $p['icon'],
            'color'          => $p['color'],
            'text_color'     => $p['text_color'],
            'linked'         => $link !== null,
            'name'           => $name,
            'since'          => $link['created_at'] ?? null,
            'can_connect'    => $canConnect,
            'can_disconnect' => $link !== null && AccountLinkPolicy::canDisconnect($this->db, $userId, $p['slug']),
            'link_url'       => $p['link_url'],
            'disconnect_url' => $p['disconnect_url'],
        ];
    }

    /** Statusmeldingen uit ?{provider}=connected|disconnected|already_linked|last_login. */
    private function buildOAuthFlash(Request $request): array
    {
        $flash = [];
        foreach ($this->providers->all() as $p) {
            $status = $request->query($p['slug']);
            if (in_array($status, ['connected', 'disconnected', 'already_linked', 'last_login'], true)) {
                $flash[] = ['label' => $p['label'], 'status' => $status];
            }
        }
        return $flash;
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

    /**
     * POST /profiel/bio — korte "over mij"-tekst, getoond op het publieke
     * ledenprofiel (publicShow() hieronder). Begrensd op 500 tekens: dit is
     * een korte introductie voor op een ledenkaart, geen tweede blogpost —
     * cf_blog_posts bestaat al voor lange content per gebruiker.
     */
    public function updateBio(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=/profiel');
        }

        CsrfProtection::validateRequest();

        $bio = trim((string) $request->input('bio', ''));
        if (mb_strlen($bio) > 500) {
            $bio = mb_substr($bio, 0, 500);
        }

        $this->db->execute("UPDATE cf_users SET bio = ? WHERE id = ?", [$bio, $this->auth->id()]);

        return Response::redirect('/profiel?bio=updated');
    }

    /**
     * GET /leden/{username} — het publieke ledenprofiel: avatar, weergavenaam,
     * bio, lid-sinds, en de laatste forumactiviteit (topics + reacties). Doelbewust
     * minimaal gehouden (v1.27.0 — beta-scope): geen prikbord/wall waar
     * andere bezoekers op kunnen reageren, geen volgen/vrienden — alleen wat
     * een bezoeker al ergens anders publiek van deze gebruiker kon zien
     * (forumbijdragen), nu samengevoegd op één kaart.
     */
    public function publicShow(Request $request): Response
    {
        $user = $this->findPublicUserByUsername((string) $request->param('username'));
        if ($user === null) {
            return Response::html('<h1>404 — Gebruiker niet gevonden</h1>', 404);
        }

        $html = $this->theme->render('users/public_profile.twig', [
            'page_title'    => ($user['display_name'] ?: $user['username']) . ' — Profiel',
            'member'        => $user,
            'recent_topics' => $this->forum->getTopicsByAuthor((int) $user['id'], 5),
            'recent_posts'  => $this->forum->getPostsByAuthor((int) $user['id'], 5),
        ]);

        return Response::html($html);
    }

    /**
     * Alleen publiek-veilige kolommen (geen e-mail, geen locale/timezone,
     * uiteraard geen password_hash) — zelfde is_active/deleted_at-gate als
     * BlogController::findUserByUsername(), zodat een gebande of
     * verwijderde gebruiker geen publiek profiel meer heeft.
     */
    private function findPublicUserByUsername(string $username): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, username, display_name, avatar_url, bio, created_at
             FROM cf_users
             WHERE username = ? AND is_active = 1 AND deleted_at IS NULL",
            [$username]
        );
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: ProfileController.php | Role: Core | Version: 1.0.0          ║
// ║  Created: 2026-09-28 | Status: New — Wave 0 gap-fix (404 op /profiel)║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
