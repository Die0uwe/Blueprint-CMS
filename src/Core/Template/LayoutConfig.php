<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Template;

/**
 * Header- en footer-indeling (grid-builder, thema-instellingen tab 4).
 *
 * Wordt als één JSON-waarde opgeslagen (cf_settings theme.layout_json) en bij
 * elk gebruik opnieuw gevalideerd, zodat een kapotte of kwaadaardige waarde
 * nooit tot HTML/CSS-injectie kan leiden: alleen vaste opties, geheel
 * getallen binnen grenzen en platte tekst (de template escapet bij uitvoer).
 */
final class LayoutConfig
{
    public const KEY = 'layout_json';

    public const ALIGN        = ['left', 'center', 'right'];
    public const CELL_TYPES   = ['text', 'links', 'siteinfo', 'blocks', 'copyright'];
    public const MAX_CELLS    = 8;
    public const MAX_COLUMNS  = 4;

    /** @return array{header: array<string, mixed>, footer: array<string, mixed>} */
    public static function defaults(): array
    {
        return [
            'header' => [
                'logo_align' => 'left',
                'nav_align'  => 'left',
                'show_motd'  => true,
                'sticky'     => true,
            ],
            'footer' => [
                'columns' => 3,
                'cells'   => [],   // leeg = klassieke footer (blokken + copyright)
            ],
        ];
    }

    /**
     * @param  mixed $raw JSON-string of array
     * @return array{header: array<string, mixed>, footer: array<string, mixed>}
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            $raw = [];
        }
        $d = self::defaults();

        $h = is_array($raw['header'] ?? null) ? $raw['header'] : [];
        foreach (['logo_align', 'nav_align'] as $k) {
            $v = is_string($h[$k] ?? null) ? $h[$k] : '';
            $d['header'][$k] = in_array($v, self::ALIGN, true) ? $v : 'left';
        }
        $d['header']['show_motd'] = self::bool($h['show_motd'] ?? true);
        $d['header']['sticky']    = self::bool($h['sticky'] ?? true);

        $f = is_array($raw['footer'] ?? null) ? $raw['footer'] : [];
        $d['footer']['columns'] = max(1, min(self::MAX_COLUMNS, (int) ($f['columns'] ?? 3)));

        $cells     = [];
        $hasBlocks = false;
        foreach (is_array($f['cells'] ?? null) ? array_values($f['cells']) : [] as $cell) {
            if (!is_array($cell) || count($cells) >= self::MAX_CELLS) {
                continue;
            }
            $type = is_string($cell['type'] ?? null) ? $cell['type'] : '';
            if (!in_array($type, self::CELL_TYPES, true)) {
                continue;
            }
            // De blokken-cel rendert de footer-zone met vaste element-id's: maximaal één per footer.
            if ($type === 'blocks') {
                if ($hasBlocks) {
                    continue;
                }
                $hasBlocks = true;
            }
            $cells[] = [
                'type'  => $type,
                'title' => self::text($cell['title'] ?? '', 80),
                'text'  => $type === 'text' ? self::text($cell['text'] ?? '', 1000, true) : '',
                'links' => $type === 'links' ? self::links($cell['links'] ?? []) : [],
            ];
        }
        $d['footer']['cells'] = $cells;

        return $d;
    }

    /**
     * Formulier-invoer (parallelle arrays cell_type[], cell_title[], cell_text[], cell_links[])
     * → genormaliseerde configuratie. De volgorde van de arrays is de volgorde in de grid.
     *
     * @param  array<string, mixed> $in
     */
    public static function fromForm(array $in): array
    {
        $types  = is_array($in['cell_type'] ?? null)  ? array_values($in['cell_type'])  : [];
        $titles = is_array($in['cell_title'] ?? null) ? array_values($in['cell_title']) : [];
        $texts  = is_array($in['cell_text'] ?? null)  ? array_values($in['cell_text'])  : [];
        $links  = is_array($in['cell_links'] ?? null) ? array_values($in['cell_links']) : [];

        $cells = [];
        foreach ($types as $i => $type) {
            $cells[] = [
                'type'  => $type,
                'title' => $titles[$i] ?? '',
                'text'  => $texts[$i] ?? '',
                'links' => is_string($links[$i] ?? null) ? self::parseLinkLines($links[$i]) : [],
            ];
        }

        return self::normalize([
            'header' => [
                'logo_align' => $in['logo_align'] ?? 'left',
                'nav_align'  => $in['nav_align'] ?? 'left',
                'show_motd'  => isset($in['show_motd']),
                'sticky'     => isset($in['sticky']),
            ],
            'footer' => [
                'columns' => $in['footer_columns'] ?? 3,
                'cells'   => $cells,
            ],
        ]);
    }

    public static function toJson(array $config): string
    {
        return (string) json_encode(self::normalize($config), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** CSS voor de header; alleen afwijkingen van de standaard, alleen op desktop. */
    public static function css(array $config): string
    {
        $h   = self::normalize($config)['header'];
        $out = '';

        if ($h['logo_align'] === 'center') {
            $out .= '#cf-header .cf-container{flex-wrap:wrap;justify-content:center}'
                  . '#cf-header .cf-logo-wrap{flex:0 0 100%;align-items:center}'
                  . '#cf-header .cf-logo-row{justify-content:center}'
                  . '#cf-header .cf-header-panel{flex:0 0 100%}';
        } elseif ($h['logo_align'] === 'right') {
            $out .= '#cf-header .cf-logo-wrap{order:2;margin-left:auto}'
                  . '#cf-header .cf-header-panel{order:1}';
        }
        if ($h['nav_align'] !== 'left') {
            $out .= '#cf-header .cf-nav{justify-content:' . ($h['nav_align'] === 'center' ? 'center' : 'flex-end') . '}';
        }
        $css = $out !== '' ? '@media(min-width:769px){' . $out . '}' : '';
        if (!$h['sticky']) {
            $css .= '#cf-header{position:static}';
        }
        return $css;
    }

    /**
     * "Label|/pad" per regel → [{label,url}]; alleen /pad en http(s):// zijn toegestaan.
     *
     * @return list<array{label: string, url: string}>
     */
    public static function parseLinkLines(string $lines): array
    {
        $out = [];
        foreach (preg_split('/\R/', $lines) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($url === '' && preg_match('#^(/|https?://)#i', $label) === 1) {
                $url = $label;
            }
            $out[] = ['label' => $label, 'url' => $url];
        }
        return self::links($out);
    }

    /**
     * @param  mixed $links
     * @return list<array{label: string, url: string}>
     */
    public static function links(mixed $links): array
    {
        $out = [];
        foreach (is_array($links) ? $links : [] as $l) {
            if (count($out) >= 12 || !is_array($l)) {
                continue;
            }
            $url   = is_string($l['url'] ?? null) ? trim($l['url']) : '';
            $label = self::text($l['label'] ?? '', 60);
            $ok = preg_match('#^/(?!/)[^\s<>"\'\\\\]*$#', $url) === 1
               || preg_match('#^https?://[^\s<>"\'\\\\]+$#i', $url) === 1;
            if (!$ok) {
                continue;
            }
            $out[] = ['label' => $label !== '' ? $label : $url, 'url' => $url];
        }
        return $out;
    }

    /** Platte tekst: geen tags, geen stuurtekens, begrensd. */
    private static function text(mixed $v, int $max, bool $multiline = false): string
    {
        if (!is_string($v)) {
            return '';
        }
        $v = strip_tags($v);
        $v = preg_replace($multiline ? '/[^\P{C}\n]+/u' : '/\p{C}+/u', '', $v) ?? '';
        $v = trim($v);
        return mb_substr($v, 0, $max);
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }
}
