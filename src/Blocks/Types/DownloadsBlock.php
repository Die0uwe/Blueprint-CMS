<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Blocks\Support\BlockHtml as H;
use CommunityFusion\Core\Database\Connection;

/**
 * Downloads-widget: nieuwste of populairste bestanden in drie lay-outs:
 * sidebar (compacte lijst), centered (gecentreerde kaarten) en slider.
 */
final class DownloadsBlock extends AbstractBlock
{
    public const LAYOUTS = ['sidebar', 'centered', 'slider'];
    public const SORTS   = ['latest', 'popular'];

    public function __construct(private readonly Connection $db) {}

    public function getSlug(): string { return 'downloads-latest'; }
    public function getName(): string { return 'Downloads'; }

    public function getConfigSchema(): array
    {
        return [
            'count'  => ['type' => 'integer', 'label' => 'Aantal downloads', 'default' => 5, 'min' => 1, 'max' => 12],
            'sort'   => ['type' => 'select',  'label' => 'Sortering (latest, popular)', 'default' => 'latest', 'options' => self::SORTS],
            'layout' => ['type' => 'select',  'label' => 'Lay-out (sidebar, centered, slider)', 'default' => 'sidebar', 'options' => self::LAYOUTS],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $count  = H::clampInt($config['count'] ?? 5, 1, 12, 5);
        $sort   = H::pick($config['sort'] ?? 'latest', self::SORTS, 'latest');
        $layout = H::pick($config['layout'] ?? 'sidebar', self::LAYOUTS, 'sidebar');
        $order  = $sort === 'popular' ? 'download_count DESC, created_at DESC' : 'created_at DESC';

        $items = $this->db->fetchAll(
            "SELECT slug, title, version, file_size, download_count FROM cf_downloads
             WHERE is_published = 1 AND deleted_at IS NULL
             ORDER BY {$order}
             LIMIT ?",
            [$count]
        );
        if (empty($items)) {
            return '<p class="cf-block-empty">Nog geen downloads.</p>';
        }

        if ($layout === 'sidebar') {
            $html = '<ul class="cf-block-news-list">';
            foreach ($items as $it) {
                $html .= '<li class="cf-block-news-item"><a href="/downloads/' . H::e($it['slug']) . '">' . H::e($it['title'])
                    . ($it['version'] ? ' <small>v' . H::e($it['version']) . '</small>' : '')
                    . '</a><span class="cf-block-news-date">⬇️ ' . (int) $it['download_count'] . '</span></li>';
            }
            return $html . '</ul><a href="/downloads" class="cf-block-more">Alle downloads →</a>';
        }

        $cards = '';
        foreach ($items as $it) {
            $card = '<div class="cf-dl-card"><strong>' . H::e($it['title']) . '</strong>'
                . '<small>' . ($it['version'] ? 'v' . H::e($it['version']) . ' · ' : '') . H::e(H::formatSize((int) $it['file_size'])) . ' · ⬇️ ' . (int) $it['download_count'] . '</small>'
                . '<span class="cf-dl-actions"><a class="cf-btn-sm" href="/downloads/' . H::e($it['slug']) . '">Details</a> '
                . '<a class="cf-btn-sm" href="/downloads/' . H::e($it['slug']) . '/bestand">⬇️ Download</a></span></div>';
            $cards .= $layout === 'slider' ? '<div class="cf-slider-item">' . $card . '</div>' : $card;
        }

        return $layout === 'slider'
            ? H::slider($cards, 'Downloads', 5000) . '<a href="/downloads" class="cf-block-more">Alle downloads →</a>'
            : '<div class="cf-dl-centered">' . $cards . '</div><a href="/downloads" class="cf-block-more" style="text-align:center;display:block;">Alle downloads →</a>';
    }

    public function getCacheTtl(): int { return 300; }
}
