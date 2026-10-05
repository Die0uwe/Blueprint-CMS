<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;

/**
 * Klok / datum: digitaal, analoog, beide of alleen datum. Tijdzone naar keuze
 * (of die van de bezoeker). De tijd loopt in de browser (assets/js/cf-clock.js);
 * de server levert een beginwaarde zodat de klok ook zonder JavaScript klopt.
 */
final class ClockBlock extends AbstractBlock
{
    public const STYLES = ['digital', 'analog', 'both', 'date'];
    public const ZONES  = [
        'visitor', 'Europe/Amsterdam', 'Europe/London', 'Europe/Berlin', 'Europe/Paris', 'Europe/Madrid',
        'Europe/Moscow', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
        'America/Sao_Paulo', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Shanghai', 'Asia/Tokyo', 'Australia/Sydney', 'UTC',
    ];

    public function getSlug(): string { return 'clock'; }
    public function getName(): string { return 'Klok / Datum'; }

    public function getConfigSchema(): array
    {
        return [
            'style'        => ['type' => 'select', 'label' => 'Weergave (digital / analog / both / date)', 'options' => self::STYLES, 'default' => 'digital'],
            'timezone'     => ['type' => 'select', 'label' => 'Tijdzone (visitor = die van de bezoeker)', 'options' => self::ZONES, 'default' => 'Europe/Amsterdam'],
            'format'       => ['type' => 'select', 'label' => 'Uren (24 of 12)', 'options' => ['24', '12'], 'default' => '24'],
            'show_seconds' => ['type' => 'boolean', 'label' => 'Seconden tonen', 'default' => true],
            'show_date'    => ['type' => 'boolean', 'label' => 'Datum tonen', 'default' => true],
            'date_style'   => ['type' => 'select', 'label' => 'Datumnotatie (long / short / numeric)', 'options' => ['long', 'short', 'numeric'], 'default' => 'long'],
            'label'        => ['type' => 'string', 'label' => 'Label (bv. "Server tijd")', 'default' => ''],
            'size'         => ['type' => 'integer', 'label' => 'Grootte analoge klok (px, 80–300)', 'default' => 160, 'min' => 80, 'max' => 300],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $style = in_array($config['style'] ?? '', self::STYLES, true) ? $config['style'] : 'digital';
        $tz    = in_array($config['timezone'] ?? '', self::ZONES, true) ? $config['timezone'] : 'Europe/Amsterdam';
        $h12   = ($config['format'] ?? '24') === '12';
        $sec   = !empty($config['show_seconds']);
        $date  = !empty($config['show_date']) || $style === 'date';
        $ds    = in_array($config['date_style'] ?? '', ['long', 'short', 'numeric'], true) ? $config['date_style'] : 'long';
        $size  = max(80, min(300, (int) ($config['size'] ?? 160)));
        $label = htmlspecialchars((string) ($config['label'] ?? ''), ENT_QUOTES);

        // Beginwaarde (server): alleen voor een vaste tijdzone
        $initTime = '--:--';
        $initDate = '';
        if ($tz !== 'visitor') {
            $now      = new \DateTimeImmutable('now', new \DateTimeZone($tz));
            $initTime = $now->format($h12 ? ($sec ? 'g:i:s A' : 'g:i A') : ($sec ? 'H:i:s' : 'H:i'));
            $initDate = $now->format($ds === 'numeric' ? 'd-m-Y' : 'Y-m-d');
        }

        $attrs = sprintf(
            'data-style="%s" data-tz="%s" data-h12="%d" data-sec="%d" data-date="%d" data-datestyle="%s"',
            $style, htmlspecialchars($tz, ENT_QUOTES), $h12 ? 1 : 0, $sec ? 1 : 0, $date ? 1 : 0, $ds
        );

        $html = '<div class="cf-clock cf-clock--' . $style . '" ' . $attrs . '>';
        if ($label !== '') {
            $html .= '<div class="cf-clock-label">' . $label . '</div>';
        }
        if ($style === 'analog' || $style === 'both') {
            $html .= '<svg class="cf-clock-face" viewBox="-50 -50 100 100" width="' . $size . '" height="' . $size . '" role="img" aria-label="Analoge klok">'
                . '<circle r="48" class="cf-clock-ring"/>';
            for ($i = 0; $i < 12; $i++) {
                $html .= '<line x1="0" y1="-42" x2="0" y2="' . ($i % 3 === 0 ? '-36' : '-39') . '" class="cf-clock-tick" transform="rotate(' . ($i * 30) . ')"/>';
            }
            $html .= '<line class="cf-clock-hand cf-clock-h" x1="0" y1="4" x2="0" y2="-24"/>'
                . '<line class="cf-clock-hand cf-clock-m" x1="0" y1="6" x2="0" y2="-34"/>'
                . ($sec ? '<line class="cf-clock-hand cf-clock-s" x1="0" y1="8" x2="0" y2="-38"/>' : '')
                . '<circle r="2.2" class="cf-clock-pin"/></svg>';
        }
        if ($style === 'digital' || $style === 'both') {
            $html .= '<div class="cf-clock-time" aria-live="off">' . htmlspecialchars($initTime) . '</div>';
        }
        if ($date) {
            $html .= '<div class="cf-clock-date">' . htmlspecialchars($initDate) . '</div>';
        }
        $html .= '</div>';

        // Het script één keer per pagina laden; het initialiseert elke klok maar één keer.
        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $html .= '<script src="/assets/js/cf-clock.js" defer></script>';
        }
        return $html;
    }

    public function getCacheTtl(): int { return 3600; }
}
