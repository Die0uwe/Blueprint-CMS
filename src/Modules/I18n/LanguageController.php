<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\I18n;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\I18n\Translator;

/**
 * LanguageController — S13 (Multi-language/i18n).
 *
 * Publieke taalwisselaar: `GET /taal/{locale}`. Werkt voor gasten (sessie-
 * override, `$_SESSION['locale']` — zie Application::boot()'s
 * Translator-resolutievolgorde) én voor ingelogde leden, die daarnaast hun
 * keuze persistent op `cf_users.locale` opslaan zodat een volgende login op
 * een ander apparaat dezelfde taal oplevert (Translator's stap 2, vóór de
 * sitestandaard).
 *
 * Geen eigen module — geen `module.json`, geen `cf_modules`-rij, geen
 * `boot()`-lifecycle — naar hetzelfde patroon als Media/Settings: een klein,
 * altijd-geladen core-scherm dat toevallig onder `src/Modules/` staat omdat
 * dit project geen aparte `src/Core/Controllers/`-laag heeft (zie Router.php:
 * élke controller-klasse in dit project leeft onder `CommunityFusion\Modules\*`).
 */
final class LanguageController
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection  $db,
    ) {}

    public function switch(Request $request): Response
    {
        $locale = (string) $request->param('locale');

        if (in_array($locale, Translator::SUPPORTED, true)) {
            if (session_status() === PHP_SESSION_NONE) {
                // AuthManager start de sessie normaal al bij constructie
                // (zie AuthManager::startSecureSession()) — dit is puur een
                // vangnet mocht de DI-resolutievolgorde ooit veranderen.
                session_start();
            }
            $_SESSION['locale'] = $locale;

            if ($this->auth->check()) {
                $this->db->execute("UPDATE cf_users SET locale = ? WHERE id = ?", [$locale, $this->auth->id()]);
            }
        }

        return Response::redirect($this->safeRedirectTarget($request));
    }

    /**
     * Stuur terug naar de pagina waar de bezoeker vandaan kwam (Referer),
     * maar alleen als dat een relatief, eigen-site-pad is — een Referer-
     * header komt van de client en is dus geen vertrouwde open-redirect-vrije
     * bron. Bij twijfel (geen Referer, of een externe/absolute URL): terug
     * naar de homepage.
     */
    private function safeRedirectTarget(Request $request): string
    {
        $referer = (string) $request->header('Referer', '');
        if ($referer === '') {
            return '/';
        }

        $path = parse_url($referer, PHP_URL_PATH) ?: '/';
        $query = parse_url($referer, PHP_URL_QUERY);

        // Alleen een pad (nooit een schema/host uit de Referer overnemen) en
        // nooit terug naar de wissel-route zelf (voorkomt een redirect-lus
        // als de switcher ooit zonder Referer-strip opnieuw gelinkt wordt).
        if (!str_starts_with($path, '/') || str_starts_with($path, '/taal/')) {
            return '/';
        }

        return $path . ($query !== null ? '?' . $query : '');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: LanguageController.php | Role: Core | Version: 1.0.0         ║
// ║  Created: 2026-09-29 | Status: New — S13 (Multi-language/i18n)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
