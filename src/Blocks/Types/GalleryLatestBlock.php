<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Modules\Gallery\GalleryRepository;

/**
 * GalleryLatestBlock — toont de laatst geüploade galerij-AFBEELDINGEN als
 * miniaturenrooster (S11). Bewust alleen afbeeldingen, geen video's: een
 * video-item heeft geen miniatuur (zie GalleryThumbnailer) en een widget vol
 * afwisselend echte foto's en kale play-icoontjes oogt inconsistent op een
 * kleine sidebar-breedte — zie GalleryRepository::getLatestPublishedImages().
 */
final class GalleryLatestBlock extends AbstractBlock
{
    public function __construct(private readonly GalleryRepository $repo) {}

    public function getSlug(): string { return 'gallery-latest'; }
    public function getName(): string { return 'Laatste Galerij-foto\'s'; }

    public function getConfigSchema(): array
    {
        return [
            'count' => ['type' => 'integer', 'label' => 'Aantal foto\'s', 'default' => 6, 'min' => 1, 'max' => 12],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $count = max(1, min(12, (int) ($config['count'] ?? 6)));
        $items = $this->repo->getLatestPublishedImages($count);

        if (empty($items)) {
            return '<p class="cf-block-empty">Nog geen foto\'s in de galerij.</p>';
        }

        $html = '<div class="cf-block-gallery-grid">';
        foreach ($items as $item) {
            $thumb = htmlspecialchars($item['thumbnail_path']);
            $title = htmlspecialchars($item['title'] ?? '');
            $album = htmlspecialchars($item['album_slug']);
            $html .= <<<HTML
            <a href="/galerij/{$album}" class="cf-block-gallery-thumb" title="{$title}">
                <img src="/media/{$thumb}" alt="{$title}" loading="lazy">
            </a>
            HTML;
        }
        $html .= '</div>';
        $html .= '<a href="/galerij" class="cf-block-more">Volledige galerij →</a>';

        return $html;
    }

    public function getCacheTtl(): int { return 300; }
}
