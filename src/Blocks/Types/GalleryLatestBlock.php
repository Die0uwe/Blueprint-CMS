<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;

use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Blocks\Support\BlockHtml as H;
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
    public const LAYOUTS = ['grid', 'centered', 'slider'];

    public function __construct(private readonly GalleryRepository $repo) {}

    public function getSlug(): string { return 'gallery-latest'; }
    public function getName(): string { return 'Laatste Galerij-foto\'s'; }

    public function getConfigSchema(): array
    {
        return [
            'count'  => ['type' => 'integer', 'label' => 'Aantal foto\'s', 'default' => 6, 'min' => 1, 'max' => 12],
            'layout' => ['type' => 'select',  'label' => 'Lay-out (grid, centered, slider)', 'default' => 'grid', 'options' => self::LAYOUTS],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $count  = H::clampInt($config['count'] ?? 6, 1, 12, 6);
        $layout = H::pick($config['layout'] ?? 'grid', self::LAYOUTS, 'grid');
        $items  = $this->repo->getLatestPublishedImages($count);

        if (empty($items)) {
            return '<p class="cf-block-empty">Nog geen foto\'s in de galerij.</p>';
        }

        $tiles = '';
        foreach ($items as $item) {
            $thumb = H::e($item['thumbnail_path']);
            $title = H::e($item['title'] ?? '');
            $album = H::e($item['album_slug']);
            $a = '<a href="/galerij/' . $album . '" class="cf-block-gallery-thumb" title="' . $title . '">'
               . '<img src="/media/' . $thumb . '" alt="' . $title . '" loading="lazy"></a>';
            $tiles .= $layout === 'slider' ? '<div class="cf-slider-item cf-slider-photo">' . $a . '</div>' : $a;
        }

        $more = '<a href="/galerij" class="cf-block-more">Volledige galerij →</a>';
        return match ($layout) {
            'slider'   => H::slider($tiles, 'Galerij', 4000) . $more,
            'centered' => '<div class="cf-block-gallery-grid cf-block-gallery-centered">' . $tiles . '</div>' . $more,
            default    => '<div class="cf-block-gallery-grid">' . $tiles . '</div>' . $more,
        };
    }

    public function getCacheTtl(): int { return 300; }
}
