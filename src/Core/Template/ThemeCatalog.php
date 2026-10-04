<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Template;

/**
 * ThemeCatalog — leest alle theme.json-bestanden uit themes/ en maakt er één CSS-blok van.
 *
 * Alle thema's staan tegelijk in de pagina als `html[data-theme="slug"]`-regels
 * (+ `[data-mode="alt"]` voor de tegenovergestelde licht/donker-variant). Het
 * wisselen van thema of licht/donker is daarom puur client-side (data-attributen
 * op <html>), zonder serverrequest of flits bij laden.
 *
 * theme.json:
 *   "mode":       "dark" | "light"      — natuurlijke modus van `colors`
 *   "colors":     { bg, bg2, surface, surface2, border, accent, accent2, gold,
 *                   text, text_dim, muted, success, warning, error, on_accent,
 *                   link?, error_text?, success_text?, warning_text? }
 *   "colors_alt": zelfde sleutels, de tegenovergestelde modus (licht ↔ donker)
 *   "style":      { radius, radius_lg, font, font_heading, body_image,
 *                   body_size, body_repeat, body_attach }
 *
 * Een thema kan door een ZIP uit de marketplace komen en is dus niet
 * vertrouwd: elke waarde wordt strikt gevalideerd voordat ze in CSS belandt.
 */
final class ThemeCatalog
{
    private const COLOR_KEYS = [
        'bg' => '--bg', 'bg2' => '--bg2', 'surface' => '--surface', 'surface2' => '--surface2',
        'border' => '--border', 'accent' => '--accent', 'accent2' => '--accent2', 'gold' => '--gold',
        'text' => '--text', 'text_dim' => '--text-dim', 'muted' => '--muted',
        'success' => '--success', 'warning' => '--warning', 'error' => '--error',
        'on_accent' => '--on-accent', 'link' => '--link',
        'error_text' => '--error-text', 'success_text' => '--success-text', 'warning_text' => '--warning-text',
    ];

    /** Kleuren waarvan een rgb-triplet nodig is (rgba(var(--x-rgb), .2)). */
    private const RGB_TOKENS = ['accent' => 'accent', 'accent2' => 'accent2', 'surface' => 'surface',
                                'gold' => 'gold', 'success' => 'success', 'error' => 'error'];

    /** Standaard tekstkleuren voor meldingen, per modus (leesbaar op de eigen achtergrond). */
    private const TEXT_DEFAULTS = [
        'dark'  => ['error_text' => '#fca5a5', 'success_text' => '#6ee7b7', 'warning_text' => '#fcd34d'],
        'light' => ['error_text' => '#b91c1c', 'success_text' => '#047857', 'warning_text' => '#92400e'],
    ];

    /** @return array<string,array> slug => theme.json-inhoud (alleen geldige, ksort) */
    public static function scan(string $themesPath): array
    {
        $out = [];
        foreach (glob($themesPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $slug = basename($dir);
            if (!preg_match('/^[a-z0-9-]+$/', $slug) || !is_file($dir . '/theme.json')) {
                continue;
            }
            $data = json_decode((string) file_get_contents($dir . '/theme.json'), true);
            if (!is_array($data)) {
                continue;
            }
            $data['slug'] = $slug;
            $out[$slug] = $data;
        }
        ksort($out);
        return $out;
    }

    /** CSS voor alle thema's. Leeg als er niets geldigs is. */
    public static function css(array $themes): string
    {
        $css = '';
        foreach ($themes as $slug => $t) {
            if (!is_string($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
                continue;
            }
            $mode = ($t['mode'] ?? 'dark') === 'light' ? 'light' : 'dark';
            $alt  = $mode === 'dark' ? 'light' : 'dark';
            $style = self::styleVars(is_array($t['style'] ?? null) ? $t['style'] : []);

            if (is_array($t['colors'] ?? null)) {
                $css .= self::block("html[data-theme=\"{$slug}\"]", self::colorVars($t['colors'], $mode) . $style, $mode);
            }
            if (is_array($t['colors_alt'] ?? null)) {
                $css .= self::block("html[data-theme=\"{$slug}\"][data-mode=\"alt\"]", self::colorVars($t['colors_alt'], $alt) . $style, $alt);
            }
        }
        return $css;
    }

    /** Lijst voor de thema-kiezer: slug, naam, modus, staalkleuren. */
    public static function picker(array $themes): array
    {
        $list = [];
        foreach ($themes as $slug => $t) {
            if (!is_array($t['colors'] ?? null)) {
                continue;
            }
            $sw = static fn(array $c): array => array_values(array_filter([
                self::hex($c['bg'] ?? null), self::hex($c['accent'] ?? null), self::hex($c['accent2'] ?? null),
            ]));
            $list[] = [
                'slug'   => (string) $slug,
                'name'   => (string) ($t['name'] ?? $slug),
                'mode'   => ($t['mode'] ?? 'dark') === 'light' ? 'light' : 'dark',
                'hasAlt' => is_array($t['colors_alt'] ?? null),
                'swatch' => $sw($t['colors']),
            ];
        }
        return $list;
    }

    // ─── intern ──────────────────────────────────────────────────────────────

    private static function block(string $selector, string $vars, string $mode): string
    {
        return $selector . '{' . $vars . 'color-scheme:' . $mode . ";}\n";
    }

    private static function colorVars(array $colors, string $mode): string
    {
        $out = '';
        $clean = [];
        foreach (self::COLOR_KEYS as $key => $var) {
            $v = self::hex($colors[$key] ?? null) ?? ($key === 'link' ? null : (self::TEXT_DEFAULTS[$mode][$key] ?? null));
            if ($v === null) {
                continue;
            }
            $clean[$key] = $v;
            $out .= "{$var}:{$v};";
        }
        foreach (self::RGB_TOKENS as $key => $name) {
            if (isset($clean[$key]) && ($rgb = self::rgb($clean[$key])) !== null) {
                $out .= "--{$name}-rgb:{$rgb};";
            }
        }
        // Optioneel: effen logokleur voor thema's waarvan de accenten te licht zijn voor tekst.
        if (($logo = self::hex($colors['logo'] ?? null)) !== null) {
            $out .= "--logo-gradient:linear-gradient(135deg,{$logo},{$logo});";
        }
        $out .= $mode === 'light'
            ? '--fg-rgb:15,23,42;--shadow:0 8px 28px rgba(15,23,42,.12);'
            : '--fg-rgb:255,255,255;--shadow:0 8px 32px rgba(0,0,0,.4);';
        return $out;
    }

    private static function styleVars(array $s): string
    {
        $out = '';
        $len = '/^\d{1,3}(\.\d{1,2})?(px|rem)$/';
        foreach (['radius' => '--radius', 'radius_lg' => '--radius-lg'] as $k => $var) {
            if (isset($s[$k]) && is_string($s[$k]) && preg_match($len, $s[$k])) {
                $out .= "{$var}:{$s[$k]};";
            }
        }
        foreach (['font' => '--font', 'font_heading' => '--font-heading'] as $k => $var) {
            if (isset($s[$k]) && is_string($s[$k]) && preg_match('/^[A-Za-z0-9 ,\'"._-]{1,200}$/', $s[$k])) {
                $out .= "{$var}:{$s[$k]};";
            }
        }
        // Achtergrond: alleen gradients/kleuren — geen url(), @import of haakjes-trucs.
        $paint = '/^[A-Za-z0-9#%.,() \-]{1,1500}$/';
        foreach (['body_image' => '--body-image', 'body_size' => '--body-size',
                  'body_repeat' => '--body-repeat', 'body_attach' => '--body-attach'] as $k => $var) {
            if (isset($s[$k]) && is_string($s[$k]) && preg_match($paint, $s[$k])
                && !preg_match('/url|import|expression|script/i', $s[$k])) {
                $out .= "{$var}:{$s[$k]};";
            }
        }
        return $out;
    }

    private static function hex(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $v) ? strtolower($v) : null;
    }

    private static function rgb(string $hex): ?string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (!ctype_xdigit($h) || strlen($h) !== 6) {
            return null;
        }
        return hexdec(substr($h, 0, 2)) . ',' . hexdec(substr($h, 2, 2)) . ',' . hexdec(substr($h, 4, 2));
    }
}
