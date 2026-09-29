<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\I18n;

use CommunityFusion\Core\Application;

/**
 * Trans — statische facade voor `Translator` in raw-PHP admin views (S13).
 *
 * De publieke Twig-kant gebruikt `{{ trans('key') }}` (ThemeManager::
 * setTranslator()), maar de ~20 admin-schermen zijn plain PHP `include`s
 * zonder Twig — die hebben geen `$this`-scope naar de DI-container. Naar
 * hetzelfde statische-facade-patroon als `CsrfProtection::field()` (dat óók
 * vanuit raw-PHP views wordt aangeroepen zonder container-toegang): haalt
 * de per-request opgeloste `Translator`-singleton op via
 * `Application::getInstance()`, dus geen tweede locale-resolutie — dezelfde
 * instantie als Twig gebruikt.
 *
 * Gebruik in een admin-view: `<?= Trans::get('admin.sidebar.dashboard') ?>`.
 */
final class Trans
{
    public static function get(string $key, array $replace = []): string
    {
        try {
            return Application::getInstance()->make(Translator::class)->trans($key, $replace);
        } catch (\Throwable) {
            // Vóór installatie, of buiten een HTTP-request (CLI) bestaat er
            // geen db/sessie om een taal uit op te lossen — geef de kale
            // sleutel terug i.p.v. een fatale fout, zelfde ontwerp als
            // Translator::trans() zelf bij een ontbrekende vertaling.
            return $key;
        }
    }

    /** Kort alias, voor leesbaarheid in dichtbevolkte views. */
    public static function t(string $key, array $replace = []): string
    {
        return self::get($key, $replace);
    }

    /**
     * De opgeloste locale voor dit request ('nl'/'en'/'de'), voor gebruik in
     * `<html lang="<?= Trans::locale() ?>">` door de raw-PHP admin-views —
     * dezelfde per-request Translator-instantie als get()/t() hierboven
     * gebruiken, dus geen tweede resolutie. Valt terug op 'nl' buiten een
     * HTTP-request (CLI) of vóór installatie, net als get().
     */
    public static function locale(): string
    {
        try {
            return Application::getInstance()->make(Translator::class)->locale();
        } catch (\Throwable) {
            return 'nl';
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: Trans.php | Role: Core | Version: 1.0.0                      ║
// ║  Created: 2026-09-29 | Status: New — S13 (Multi-language/i18n)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
