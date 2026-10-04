<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Blocks\Support;

/** Kleine, gedeelde HTML-helpers voor block-render()-methodes (alles geëscaped). */
final class BlockHtml
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    }

    /** 'd M' → ["05", "OKT"]-achtige stub-onderdelen; leeg bij ontbrekende/ongeldige datum. */
    public static function dayMonth(?string $datetime): array
    {
        $ts = $datetime ? strtotime($datetime) : false;
        return $ts === false ? ['', ''] : [date('d', $ts), date('M', $ts)];
    }

    /** Platte tekst-samenvatting uit (rijke) HTML. */
    public static function excerpt(?string $html, int $length = 110): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $html)));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length - 1)) . '…';
    }

    /** Kies uit een whitelist; onbekende waarde → default. */
    public static function pick(mixed $value, array $allowed, string $default): string
    {
        $v = is_scalar($value) ? (string) $value : '';
        return in_array($v, $allowed, true) ? $v : $default;
    }

    public static function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1073741824) { return number_format($bytes / 1073741824, 1, ',', '.') . ' GB'; }
        if ($bytes >= 1048576)    { return number_format($bytes / 1048576, 1, ',', '.') . ' MB'; }
        return number_format(max(0, $bytes) / 1024, 0, ',', '.') . ' KB';
    }

    /**
     * Horizontale slider (CSS scroll-snap + knoppen; gedrag in blueprint.js via
     * data-cf-slider). $itemsHtml = reeds geëscaped, elk item in .cf-slider-item.
     */
    public static function slider(string $itemsHtml, string $label, int $autoplayMs = 0): string
    {
        $auto = $autoplayMs >= 2000 ? ' data-autoplay="' . min(20000, $autoplayMs) . '"' : '';
        return '<div class="cf-slider" data-cf-slider' . $auto . ' role="region" aria-label="' . self::e($label) . '">'
            . '<button type="button" class="cf-slider-btn cf-slider-prev" aria-label="Vorige">‹</button>'
            . '<div class="cf-slider-track" tabindex="0">' . $itemsHtml . '</div>'
            . '<button type="button" class="cf-slider-btn cf-slider-next" aria-label="Volgende">›</button>'
            . '</div>';
    }
}
