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
        $total   = $this->repo->countPublishedItems((int) $album['id']);

        $html = $this->theme->render('gallery/album.twig', [
            'page_title' => $album['name'],
            'album'      => $album,
            'items'      => $this->repo->getPublishedItems((int) $album['id'], $perPage, $offset),
            'pagination' => ['current' => $page, 'total' => max(1, (int) ceil($total / $perPage))],
            'can_manage' => $this->auth->can('gallery.manage'),
        ]);

        return Response::html($html);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryController.php | Role: Core | Version: 1.0.0          ║
// ║  Created: 2026-09-29 | Status: New — S11 (Media-galerij)            ║
// ╚══════════════════════════════════════════════════════════════════════╝
