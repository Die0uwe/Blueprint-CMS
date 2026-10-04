<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Template;

/**
 * Thema-instellingen die ónafhankelijk van het gekozen thema gelden
 * (cf_settings, groep 'theme'): layout (Wide/Boxed + presets + breedtes),
 * branding (logo, headerbanner) en kleur-overrides.
 *
 * Alles wat in CSS terechtkomt wordt hier gevalideerd (hex-kleuren, gehele
 * getallen met grenzen, /media/theme/-paden), zodat css() nooit door
 * gebruikersinvoer te misbruiken is.
 */
final class ThemeSettings
{
    public const GROUP = 'theme';

    /** @var array<string, array{label:string, mode:string, width:int, sidebar:int}> */
    public const PRESETS = [
        'standaard' => ['label' => 'Standaard',          'mode' => 'wide',  'width' => 1280, 'sidebar' => 260],
        'ruim'      => ['label' => 'Ruim (breedbeeld)',  'mode' => 'wide',  'width' => 1520, 'sidebar' => 280],
        'compact'   => ['label' => 'Compact (boxed)',    'mode' => 'boxed', 'width' => 1100, 'sidebar' => 240],
        'magazine'  => ['label' => 'Magazine (boxed)',   'mode' => 'boxed', 'width' => 1320, 'sidebar' => 300],
    ];

    /** Instelling → CSS-variabele die het thema al gebruikt. */
    public const COLOR_FIELDS = [
        'color_primary'    => ['var' => '--accent',  'rgb' => '--accent-rgb',  'label' => 'Primair'],
        'color_secondary'  => ['var' => '--accent2', 'rgb' => '--accent2-rgb', 'label' => 'Secundair'],
        'color_background' => ['var' => '--bg',      'rgb' => null,            'label' => 'Achtergrond'],
        'color_accent'     => ['var' => '--gold',    'rgb' => '--gold-rgb',    'label' => 'Accent'],
    ];

    private const MEDIA_PATH = '#^/media/theme/[A-Za-z0-9_.-]+$#';

    /** @return array<string, string|int> */
    public static function defaults(): array
    {
        return [
            'layout_mode'      => 'wide',
            'layout_preset'    => 'standaard',
            'layout_width'     => 1280,
            'sidebar_width'    => 260,
            'logo'             => '',
            'logo_show_name'   => '0',
            'banner'           => '',
            'banner_height'    => 220,
            'color_primary'    => '',
            'color_secondary'  => '',
            'color_background' => '',
            'color_accent'     => '',
        ];
    }

    /**
     * Valideert ruwe waarden (uit cf_settings of een formulier) en geeft een
     * volledige, veilige set terug; ongeldige waarden vallen terug op de default.
     *
     * @param  array<string, mixed> $raw
     * @return array<string, string|int>
     */
    public static function load(array $raw): array
    {
        $d = self::defaults();

        $mode = (string) ($raw['layout_mode'] ?? $d['layout_mode']);
        $d['layout_mode'] = $mode === 'boxed' ? 'boxed' : 'wide';

        $preset = (string) ($raw['layout_preset'] ?? '');
        $d['layout_preset'] = ($preset === 'aangepast' || isset(self::PRESETS[$preset])) ? $preset : 'standaard';

        $d['layout_width']  = self::intIn($raw['layout_width']  ?? null, 900, 1800, (int) $d['layout_width']);
        $d['sidebar_width'] = self::intIn($raw['sidebar_width'] ?? null, 180, 360, (int) $d['sidebar_width']);
        $d['banner_height'] = self::intIn($raw['banner_height'] ?? null, 80, 600, (int) $d['banner_height']);

        foreach (['logo', 'banner'] as $k) {
            $v = (string) ($raw[$k] ?? '');
            $d[$k] = preg_match(self::MEDIA_PATH, $v) === 1 ? $v : '';
        }
        $d['logo_show_name'] = ((string) ($raw['logo_show_name'] ?? '0')) === '1' ? '1' : '0';

        foreach (array_keys(self::COLOR_FIELDS) as $k) {
            $d[$k] = self::hex($raw[$k] ?? null) ?? '';
        }
        return $d;
    }

    /**
     * Past een preset toe op een (al gevalideerde) set: mode/breedtes volgen de preset.
     *
     * @param  array<string, string|int> $settings
     * @return array<string, string|int>
     */
    public static function applyPreset(array $settings, string $preset): array
    {
        if (!isset(self::PRESETS[$preset])) {
            $settings['layout_preset'] = 'aangepast';
            return $settings;
        }
        $p = self::PRESETS[$preset];
        $settings['layout_preset'] = $preset;
        $settings['layout_mode']   = $p['mode'];
        $settings['layout_width']  = $p['width'];
        $settings['sidebar_width'] = $p['sidebar'];
        return $settings;
    }

    /**
     * CSS-overrides voor in <style id="cf-theme-css"> (ná de thema-CSS).
     *
     * @param array<string, mixed> $raw
     */
    public static function css(array $raw): string
    {
        $s = self::load($raw);

        $out = sprintf(
            ':root{--cf-max-w:%dpx;--cf-sidebar-w:%dpx;--cf-banner-h:%dpx;}',
            $s['layout_width'], $s['sidebar_width'], $s['banner_height']
        );

        $vars = '';
        foreach (self::COLOR_FIELDS as $key => $f) {
            $hex = (string) $s[$key];
            if ($hex === '') {
                continue;
            }
            $vars .= $f['var'] . ':' . $hex . ';';
            if ($f['rgb'] !== null) {
                $vars .= $f['rgb'] . ':' . self::rgb($hex) . ';';
            }
            if ($key === 'color_primary') {
                $vars .= '--on-accent:' . (self::luminance($hex) > 0.5 ? '#0b0b0f' : '#ffffff') . ';';
            }
        }
        if ($vars !== '') {
            // Drie keer het attribuut = specificiteit boven elk thema-/modus-blok.
            $out .= 'html[data-theme][data-theme][data-theme]{' . $vars . '}';
        }
        return $out;
    }

    /** '#abc' / '#aabbcc' → '#aabbcc' (lowercase), anders null. */
    public static function hex(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = strtolower(trim($v));
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }
        return preg_match('/^#[0-9a-f]{6}$/', $v) === 1 ? $v : null;
    }

    /** '#aabbcc' → '170,187,204' */
    public static function rgb(string $hex): string
    {
        return hexdec(substr($hex, 1, 2)) . ',' . hexdec(substr($hex, 3, 2)) . ',' . hexdec(substr($hex, 5, 2));
    }

    /** Relatieve luminantie 0..1 (WCAG) — bepaalt zwarte of witte tekst op de primaire kleur. */
    public static function luminance(string $hex): float
    {
        $ch = static function (int $v): float {
            $c = $v / 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        return 0.2126 * $ch((int) hexdec(substr($hex, 1, 2)))
             + 0.7152 * $ch((int) hexdec(substr($hex, 3, 2)))
             + 0.0722 * $ch((int) hexdec(substr($hex, 5, 2)));
    }

    private static function intIn(mixed $v, int $min, int $max, int $default): int
    {
        if (!is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (int) $v));
    }
}
