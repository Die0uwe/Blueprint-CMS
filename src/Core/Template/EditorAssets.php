<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);

namespace CommunityFusion\Core\Template;

/**
 * Script-tags voor de gedeelde editor (TinyMCE self-hosted + cf-editor.js).
 *
 * Eén bron voor admin-views (PHP) én thema-templates (Twig `editor_assets()`),
 * zodat elk formulier met een textarea[data-editor] dezelfde editor krijgt.
 * Het versienummer (filemtime) zorgt voor cache-busting na een update.
 */
final class EditorAssets
{
    public static function tags(): string
    {
        $root = defined('CF_ROOT') ? CF_ROOT : dirname(__DIR__, 3);
        $v    = static fn(string $rel): string => (string) (@filemtime($root . '/public' . $rel) ?: 1);

        return '<script src="/assets/vendor/tinymce/tinymce.min.js?v=' . $v('/assets/vendor/tinymce/tinymce.min.js') . '" referrerpolicy="origin"></script>' . "\n"
             . '<script src="/assets/js/cf-editor.js?v=' . $v('/assets/js/cf-editor.js') . '"></script>' . "\n";
    }
}
