<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Template;

/** Volledig HTML-document voor <iframe sandbox="" srcdoc>: eigen CSS inline en een strikte CSP. */
final class PreviewDocument
{
    public static function wrap(string $bodyHtml): string
    {
        $cssFile = (defined('CF_ROOT') ? CF_ROOT : dirname(__DIR__, 3)) . '/public/assets/css/cf-prose.css';
        $css = is_file($cssFile) ? (string)file_get_contents($cssFile) : '';
        $css = str_replace('</style', '<\/style', $css);
        return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src https: data:; font-src https: data:; media-src https: data:">'
            . '<base target="_blank"><style>' . $css . '</style></head><body class="cf-prose">' . $bodyHtml . '</body></html>';
    }
}
