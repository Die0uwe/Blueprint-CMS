<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Template\ThemeManager;

/**
 * GalleryController — publieke kant van de Media-galerij (S11).
 * Admin-CRUD (albums aanmaken/bewerken/verwijderen, items uploaden/
 * verwijderen) zit in GalleryAdminController.
 */
final class GalleryController
{
    public function __construct(
        private readonly GalleryRepository $repo,
        private readonly AuthManager       $auth,
        private readonly ThemeManager      $theme,
    ) {}

    /** GET /galerij */
    public function index(Request $request): Response
    {
        $html = $this->theme->render('gallery/index.twig', [
            'page_title' => 'Galerij',
            'albums'     => $this->repo->getAlbums(),
            'can_manage' => $this->auth->can('gallery.manage'),
        ]);

        return Response::html($html);
    }

    /** GET /galerij/{slug} */
    public function album(Request $request): Response
    {
        $album = $this->repo->findAlbumBySlug((string) $request->param('slug'));
        if ($album === null) {
            return Response::html('<h1>404 — Album niet gevonden</h1>', 404);
        }

        $perPage = $this->repo->itemsPerPage();
        $page    = $request->page();
        $offset  = ($page - 1) * $perPage;
        $style   = GalleryTaxonomy::slugify((string) $request->query('stijl', ''));
        $tag     = GalleryTaxonomy::slugify((string) $request->query('tag', ''));
        $total   = $this->repo->countPublishedItems((int) $album['id'], $style, $tag);

        $parent = $album['parent_id'] !== null ? $this->repo->findAlbumById((int) $album['parent_id']) : null;
        $labels = GalleryTaxonomy::stylesFor((string) ($parent['slug'] ?? $album['slug']));

        // Filterchips: alleen stijlen die in dit album voorkomen, met het nette label uit de taxonomie.
        $styles = [];
        foreach ($this->repo->getStyleCounts((int) $album['id']) as $slug => $count) {
            $styles[] = ['slug' => $slug, 'label' => $labels[$slug] ?? ucfirst(str_replace('-', ' ', $slug)), 'count' => $count];
        }

        $items = array_map(static function (array $i): array {
            $i['tag_list'] = GalleryTaxonomy::unpackTags($i['tags'] ?? null);
            return $i;
        }, $this->repo->getPublishedItems((int) $album['id'], $perPage, $offset, $style, $tag));

        $html = $this->theme->render('gallery/album.twig', [
            'page_title'   => $album['name'],
            'album'        => $album,
            'parent'       => $parent,
            'subalbums'    => $this->repo->getSubalbums((int) $album['id']),
            'items'        => $items,
            'styles'       => $styles,
            'active_style' => $style,
            'active_tag'   => $tag,
            'pagination'   => ['current' => $page, 'total' => max(1, (int) ceil($total / $perPage))],
            'can_manage'   => $this->auth->can('gallery.manage'),
        ]);

        return Response::html($html);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryController.php | Role: Core | Version: 1.1.0          ║
// ║  Created: 2026-09-29 | Status: Updated — Galerij-taxonomie 1.35.0   ║
// ╚══════════════════════════════════════════════════════════════════════╝
