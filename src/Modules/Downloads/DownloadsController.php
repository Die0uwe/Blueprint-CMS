<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Downloads;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Storage\UploadException;

/**
 * DownloadsController — bestandsbeheer, curated door `downloads.manage`.
 *
 * Gebruikt een EIGEN UploadManager-instantie (UploadManager::forDownloads())
 * met een ruimere MIME-whitelist (zip/pdf/rar/7z/gz) dan de DI-singleton
 * die voor afbeeldingen (avatars/nieuws) is bedoeld, en een eigen map
 * (storage/downloads/, los van storage/uploads/) — vandaar geen constructor-
 * injectie hier: de container kent maar één `UploadManager`-binding.
 */
final class DownloadsController
{
    private const STORAGE_SUBDIR = 'downloads';
    private const MAX_BYTES      = 50 * 1024 * 1024; // 50MB

    private UploadManager $uploads;

    public function __construct(
        private readonly DownloadsRepository $repo,
        private readonly AuthManager         $auth,
        private readonly ThemeManager        $theme,
    ) {
        $this->uploads = UploadManager::forDownloads(
            CF_ROOT . '/storage/' . self::STORAGE_SUBDIR,
            self::MAX_BYTES,
        );
    }

    /** GET /downloads */
    public function index(Request $request): Response
    {
        $perPage = $this->repo->perPage();
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;
        $total   = $this->repo->countPublished();

        $html = $this->theme->render('downloads/index.twig', [
            'page_title'  => 'Downloads',
            'downloads'   => $this->repo->getPublished($perPage, $offset),
            'pagination'  => ['current' => $page, 'total' => max(1, (int) ceil($total / $perPage))],
            'can_manage'  => $this->auth->can('downloads.manage'),
        ]);

        return Response::html($html);
    }

    /** GET /downloads/nieuw */
    public function createForm(Request $request): Response
    {
        $guard = $this->requireManager($request);
        if ($guard !== null) return $guard;

        $html = $this->theme->render('downloads/edit.twig', [
            'page_title' => 'Nieuwe download',
            'download'   => null,
        ]);

        return Response::html($html);
    }

    /** POST /downloads/nieuw */
    public function store(Request $request): Response
    {
        $guard = $this->requireManager($request);
        if ($guard !== null) return $guard;

        $title       = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $file        = $request->files()['file'] ?? null;

        if ($title === '' || $file === null) {
            return Response::redirect('/downloads/nieuw?error=leeg');
        }

        try {
            $relative = $this->uploads->store($file, '');
        } catch (UploadException $e) {
            return Response::redirect('/downloads/nieuw?error=' . urlencode($e->getMessage()));
        }

        $id = $this->repo->create(
            authorId:         (int) $this->auth->id(),
            title:            $title,
            description:      $description,
            filePath:         $relative,
            originalFilename: (string) ($file['name'] ?? 'bestand'),
            fileSize:         (int) ($file['size'] ?? 0),
        );

        $download = $this->repo->findById($id);

        return Response::redirect('/downloads/' . $download['slug']);
    }

    /** GET /downloads/{slug} */
    public function show(Request $request): Response
    {
        $download = $this->repo->findBySlug((string) $request->param('slug'));
        if ($download === null) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }

        if (!$download['is_published'] && !$this->auth->can('downloads.manage')) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }

        $html = $this->theme->render('downloads/show.twig', [
            'page_title' => $download['title'],
            'download'   => $download,
            'can_manage' => $this->auth->can('downloads.manage'),
        ]);

        return Response::html($html);
    }

    /** GET /downloads/{slug}/bestand — serveert het bestand + telt de download */
    public function download(Request $request): Response
    {
        $download = $this->repo->findBySlug((string) $request->param('slug'));
        if ($download === null || (!$download['is_published'] && !$this->auth->can('downloads.manage'))) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }

        $full = $this->uploads->resolve($download['file_path']);
        if ($full === null || !is_file($full)) {
            return Response::html('<h1>404 — Bestand ontbreekt op de server</h1>', 404);
        }

        $this->repo->incrementDownloadCount((int) $download['id']);

        $filename = str_replace('"', '', $download['original_filename']);

        return new Response(file_get_contents($full), 200, [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length'      => (string) filesize($full),
        ]);
    }

    /** GET /downloads/{slug}/bewerk */
    public function editForm(Request $request): Response
    {
        $guard = $this->requireManager($request);
        if ($guard !== null) return $guard;

        $download = $this->repo->findBySlug((string) $request->param('slug'));
        if ($download === null) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }

        $html = $this->theme->render('downloads/edit.twig', [
            'page_title' => 'Download bewerken',
            'download'   => $download,
        ]);

        return Response::html($html);
    }

    /** POST /downloads/{slug}/bewerk — alleen titel/omschrijving/publicatiestatus, niet het bestand zelf */
    public function update(Request $request): Response
    {
        $guard = $this->requireManager($request);
        if ($guard !== null) return $guard;

        $download = $this->repo->findBySlug((string) $request->param('slug'));
        if ($download === null) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }

        $title       = trim((string) $request->input('title', ''));
        $description = trim((string) $request->input('description', ''));
        $isPublished = $request->input('is_published') !== null;

        if ($title === '') {
            return Response::redirect("/downloads/{$download['slug']}/bewerk?error=leeg");
        }

        $this->repo->updateDetails((int) $download['id'], $title, $description, $isPublished);

        return Response::redirect("/downloads/{$download['slug']}");
    }

    /** POST /downloads/{slug}/verwijder */
    public function delete(Request $request): Response
    {
        $guard = $this->requireManager($request);
        if ($guard !== null) return $guard;

        $download = $this->repo->findBySlug((string) $request->param('slug'));
        if ($download === null) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }

        $this->repo->delete((int) $download['id']);
        $this->uploads->delete($download['file_path']);

        return Response::redirect('/downloads');
    }

    private function requireManager(Request $request): ?Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }
        if (!$this->auth->can('downloads.manage')) {
            return Response::html('<h1>403 — Downloads-beheerrechten vereist</h1>', 403);
        }
        if ($request->getMethod() === 'POST') {
            CsrfProtection::validateRequest();
        }

        return null;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : DownloadsController.php                              ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Downloads core-module)                 ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
