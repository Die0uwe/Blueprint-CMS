<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Themes;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Settings\SettingsRepository;

/**
 * /admin/themes — Thema's (Wave 5).
 *
 * Het actieve thema kwam tot deze wave alleen uit config/config.php,
 * geschreven door de installer en verder alleen handmatig te wijzigen.
 * Dit scherm scant themes/ voor theme.json-bestanden en schrijft de keuze
 * naar cf_settings('core','active_theme') — zie Application.php voor de
 * DB-overschrijft-config.php-wiring. Permissie: themes.manage.
 *
 * Eerlijkheidshalve: theme.json's `colors`-blok en de per-thema
 * assets/-map worden nergens door de templates gebruikt (asset() in
 * ThemeManager wijst altijd naar public/assets/, thema-onafhankelijk) —
 * wisselen van thema wisselt dus de Twig-TEMPLATES (bewezen via het
 * data-theme-slug/-name attribuut dat dit scherm ook toevoegde), niet
 * (nog) een kleurenschema. Zie CHANGELOG v1.15.0.
 */
final class ThemeAdminController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuthManager        $auth,
        private readonly AuditLogger        $audit,
    ) {}

    public function index(Request $request): Response
    {
        $themes       = $this->scanThemes();
        $activeTheme  = $this->settings->get('core', 'active_theme') ?: (require CF_ROOT . '/config/config.php')['app']['theme'] ?? 'default';
        $error        = $request->query('error');
        $flash        = $request->query('ok');
        $visitorChoice = (string) $this->settings->get('core', 'visitor_theme_choice', '1') !== '0';

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function activate(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $slug = (string) $request->param('slug');

        $themes = $this->scanThemes();
        if (!isset($themes[$slug])) {
            return Response::redirect('/admin/themes?error=' . urlencode("Thema \"{$slug}\" niet gevonden."));
        }

        $this->settings->set('core', 'active_theme', $slug);
        $this->audit->log('themes.activate', $this->auth->id(), $this->auth->user()['username'] ?? null, ['theme' => $slug]);

        return Response::redirect('/admin/themes?ok=geactiveerd');
    }

    /**
     * @return array<string, array> slug => theme.json-inhoud
     */
    private function scanThemes(): array
    {
        return \CommunityFusion\Core\Template\ThemeCatalog::scan(CF_ROOT . '/themes');
    }

    /** POST /admin/themes/bezoekerskeuze — mogen bezoekers zelf thema/licht-donker kiezen? */
    public function visitorChoice(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $on = (string) $request->input('visitor_theme_choice', '0') === '1';
        $this->settings->set('core', 'visitor_theme_choice', $on ? '1' : '0');
        $this->audit->log('themes.visitor_choice', $this->auth->id(), $this->auth->user()['username'] ?? null, ['enabled' => $on]);
        return Response::redirect('/admin/themes?ok=' . ($on ? 'keuze_aan' : 'keuze_uit'));
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: ThemeAdminController.php | Role: Core | Version: 1.0.0        ║
// ║  Created: 2026-09-29 — Wave 5 (admin/themes)                         ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
