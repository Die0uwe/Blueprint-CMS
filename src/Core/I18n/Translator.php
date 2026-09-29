<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\I18n;

/**
 * Translator — S13 (Multi-language / i18n).
 *
 * ONTWERPKEUZE: platte PHP-array-bestanden onder `lang/{locale}.php`, geen
 * database-tabel en geen .po/.mo-bestanden. Onderbouwing:
 *  - Geen admin-UI nodig om duizenden losse strings te beheren (zou een héle
 *    eigen CRUD-module vergen, buiten scope van deze wave).
 *  - `require` van een PHP-array is net zo snel als elke andere config-load
 *    in dit project (zie `config/config.php`, ook een `return [...]`) en
 *    profiteert van OPcache zonder extra parser.
 *  - Git-diffable en direct te bewerken door een vertaler zonder database-
 *    toegang — consistent met hoe dit project andere statische config
 *    beheert.
 *
 * Sleutels zijn dot-notation ('admin.sidebar.dashboard') tegen een geneste
 * array — zelfde idee als Laravel/Symfony, maar zonder die frameworks nodig
 * te hebben. Ontbrekende sleutels vallen terug op `$fallbackLocale` (altijd
 * 'nl', de taal waarin dit project native geschreven is), en als zelfs dat
 * mist wordt de sleutel zelf teruggegeven (nooit een lege string of fatale
 * fout — een ontbrekende vertaling mag de pagina nooit breken).
 */
final class Translator
{
    private array $strings = [];
    private array $fallbackStrings = [];

    /** @var string[] Locales waarvoor een lang/-bestand bestaat en die de installer/admin aanbieden */
    public const SUPPORTED = ['nl', 'en', 'de'];

    public function __construct(
        private readonly string $locale,
        private readonly string $langPath = CF_ROOT . '/lang',
        private readonly string $fallbackLocale = 'nl',
    ) {
        $this->strings = $this->load($this->locale);
        if ($this->locale !== $this->fallbackLocale) {
            $this->fallbackStrings = $this->load($this->fallbackLocale);
        } else {
            $this->fallbackStrings = $this->strings;
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * Vertaal een dot-notation sleutel, met optionele `:placeholder`-
     * vervangingen (bv. `trans('gallery.item_count', ['count' => 3])` met
     * de string "{count} item(s)" → ":count item(s)").
     *
     * Geeft de sleutel zelf terug als noch de actieve taal, noch de
     * fallback ('nl') de sleutel kent — nooit een lege of "MISSING"-string,
     * zodat een ontbrekende vertaling zichtbaar maar nooit breed genoeg is
     * om de pagina onbruikbaar te maken.
     */
    public function trans(string $key, array $replace = []): string
    {
        $value = $this->lookup($this->strings, $key) ?? $this->lookup($this->fallbackStrings, $key) ?? $key;

        foreach ($replace as $placeholder => $replacement) {
            $value = str_replace(':' . $placeholder, (string) $replacement, $value);
        }

        return $value;
    }

    /** Alias, korter voor gebruik in Twig/raw-PHP views. */
    public function t(string $key, array $replace = []): string
    {
        return $this->trans($key, $replace);
    }

    public function has(string $key): bool
    {
        return $this->lookup($this->strings, $key) !== null || $this->lookup($this->fallbackStrings, $key) !== null;
    }

    private function lookup(array $tree, string $key): ?string
    {
        $segments = explode('.', $key);
        $node     = $tree;

        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : null;
    }

    private function load(string $locale): array
    {
        $locale = preg_replace('/[^a-z]/', '', strtolower($locale)) ?: 'nl';
        $file   = $this->langPath . '/' . $locale . '.php';

        if (!is_file($file)) {
            return [];
        }

        $data = require $file;
        return is_array($data) ? $data : [];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: Translator.php | Role: Core | Version: 1.0.0                 ║
// ║  Created: 2026-09-29 | Status: New — S13 (Multi-language/i18n)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
