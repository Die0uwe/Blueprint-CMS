<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Blocks\Types;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Template\MarkupException;
use CommunityFusion\Core\Template\MarkupRenderer;

/**
 * Markup-blok: eigen HTML + Twig per blok-instantie (cf_block_content.content_markup).
 * Draait in de Twig-sandbox (vaste whitelist, config/markup-block.php); PHP wordt nooit uitgevoerd.
 * Bewerken kan alleen met het recht blocks.override_template (zie BlockController).
 */
final class MarkupBlock extends AbstractBlock
{
    public function __construct(private readonly Connection $db, private readonly MarkupRenderer $renderer) {}

    public function getSlug(): string { return 'markup'; }
    public function getName(): string { return 'Markup-blok (HTML + Twig)'; }

    public function getConfigSchema(): array { return []; }

    public function render(array $config, array $context = []): string
    {
        $id = (int)($context['block_id'] ?? 0);
        if ($id < 1) {
            return '';
        }
        $row = $this->db->fetchOne('SELECT content_markup FROM cf_block_content WHERE block_id = ?', [$id]);
        $markup = (string)($row['content_markup'] ?? '');
        if ($markup === '') {
            return '';
        }
        try {
            return $this->renderer->render($markup, ['title' => (string)($context['block_title'] ?? ''), 'today' => date('Y-m-d')]);
        } catch (MarkupException) {
            return '<!-- markup-blok: fout in de markup -->';
        }
    }

    public function getCacheTtl(): int { return 300; }
}
