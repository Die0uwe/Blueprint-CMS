<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;

/**
 * Referral-/partnerlinks (bv. AI-generators als Kling en Suno). Eén regel per link:
 *
 *   Naam | https://link | Omschrijving | Badge
 *
 * Omschrijving en badge zijn optioneel (bv. badge "Code: DIEOUWE" of "-20%").
 * Alleen http(s)-links; externe links krijgen rel="sponsored nofollow noopener".
 * Onder de lijst staat een korte affiliate-melding (uit te zetten of aan te passen).
 */
final class ReferralBlock extends AbstractBlock
{
    public const LAYOUTS  = ['list', 'buttons', 'cards'];
    public const MAX_LINKS = 20;

    public function getSlug(): string { return 'referral-links'; }
    public function getName(): string { return 'Referral / Partner-links'; }

    public function getConfigSchema(): array
    {
        return [
            'heading'    => ['type' => 'string', 'label' => 'Kop boven de links', 'default' => 'Mijn favoriete AI-tools'],
            'links'      => [
                'type' => 'textarea', 'label' => 'Links — één per regel: Naam | URL | Omschrijving | Badge',
                'default' => "Kling AI | https://klingai.com | AI-video genereren | \nSuno | https://suno.com | AI-muziek maken | ",
                'help' => 'Vervang de voorbeeld-URL door jouw eigen referral-link. Omschrijving en badge (bv. "Code: DIEOUWE") mag je weglaten.',
            ],
            'layout'     => ['type' => 'select', 'label' => 'Weergave (list / buttons / cards)', 'options' => self::LAYOUTS, 'default' => 'cards'],
            'open_new'   => ['type' => 'boolean', 'label' => 'Openen in nieuw tabblad', 'default' => true],
            'disclosure' => ['type' => 'boolean', 'label' => 'Affiliate-melding tonen', 'default' => true],
            'disclosure_text' => ['type' => 'string', 'label' => 'Tekst van de melding', 'default' => 'Dit zijn referral-links: jij betaalt niets extra, ik kan er een beloning voor krijgen.'],
        ];
    }

    /** @return list<array{name:string,url:string,note:string,badge:string}> */
    public static function parseLinks(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            $p = array_map('trim', explode('|', $line));
            $name = $p[0] ?? '';
            $url  = $p[1] ?? '';
            if ($name === '' || preg_match('#^https?://[^\s<>"\']+$#i', $url) !== 1) { continue; }
            $out[] = ['name' => $name, 'url' => $url, 'note' => $p[2] ?? '', 'badge' => $p[3] ?? ''];
            if (count($out) >= self::MAX_LINKS) { break; }
        }
        return $out;
    }

    public function render(array $config, array $context = []): string
    {
        $links = self::parseLinks((string) ($config['links'] ?? ''));
        if ($links === []) { return '<!-- Referral-links: geen geldige links ingesteld -->'; }

        $e      = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $layout = in_array($config['layout'] ?? '', self::LAYOUTS, true) ? $config['layout'] : 'cards';
        $new    = !array_key_exists('open_new', $config) || !empty($config['open_new']);
        $rel    = 'sponsored nofollow noopener noreferrer';
        $tgt    = $new ? ' target="_blank"' : '';

        $html = '<div class="cf-ref cf-ref--' . $layout . '">';
        $heading = trim((string) ($config['heading'] ?? ''));
        if ($heading !== '') { $html .= '<div class="cf-ref-heading">' . $e($heading) . '</div>'; }
        $html .= '<ul class="cf-ref-list">';
        foreach ($links as $l) {
            $html .= '<li class="cf-ref-item"><a class="cf-ref-link" href="' . $e($l['url']) . '"' . $tgt . ' rel="' . $rel . '">'
                . '<span class="cf-ref-name">' . $e($l['name']) . '</span>'
                . ($l['badge'] !== '' ? '<span class="cf-ref-badge">' . $e($l['badge']) . '</span>' : '')
                . ($l['note'] !== '' ? '<span class="cf-ref-note">' . $e($l['note']) . '</span>' : '')
                . '</a></li>';
        }
        $html .= '</ul>';
        $disc = trim((string) ($config['disclosure_text'] ?? 'Dit zijn referral-links: jij betaalt niets extra, ik kan er een beloning voor krijgen.'));
        if ((!array_key_exists('disclosure', $config) || !empty($config['disclosure'])) && $disc !== '') {
            $html .= '<div class="cf-ref-disclosure">' . $e($disc) . '</div>';
        }
        return $html . '</div>';
    }

    public function getCacheTtl(): int { return 3600; }
}
