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

use CommunityFusion\Core\Security\ContentSanitizer;
use CommunityFusion\Core\Request;
use CommunityFusion\Modules\Media\MediaController;
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
        private readonly DownloadStats        $stats,
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
        $page    = $request->page();
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
        $description = ContentSanitizer::cleanForStorage((string) $request->input('description', ''));
        $version     = self::cleanVersion((string) $request->input('version', ''));
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
            version:          $version,
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

        $size  = (int) filesize($full);
        $range = MediaController::parseRange((string) $request->header('Range', ''), $size);
        if ($range === false) {
            return new Response('', 416, ['Content-Range' => 'bytes */' . $size]);
        }

        // Tellen/loggen alleen bij het begin van een download (niet bij elk hervat-verzoek).
        if ($range === null || $range[0] === 0) {
            $this->repo->incrementDownloadCount((int) $download['id']);
            $this->stats->record($download, $this->auth->id(), $request->ip(), $size);
        }

        $headers = [
            'Content-Type'        => 'application/octet-stream',
            'Content-Disposition' => self::contentDisposition((string) $download['original_filename']),
            'Accept-Ranges'       => 'bytes',
        ];

        if ($range !== null) {
            [$start, $end] = $range;
            return Response::stream($full, $start, $end - $start + 1, 206, $headers + [
                'Content-Range' => "bytes {$start}-{$end}/{$size}",
            ]);
        }

        return Response::stream($full, 0, $size, 200, $headers);
    }

    /**
     * Veilige Content-Disposition: ASCII-fallback + RFC 5987 `filename*` voor Unicode;
     * geen quotes, backslashes of stuurtekens (header-injectie) in de naam.
     */
    public static function contentDisposition(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\"]+/u', '', $name) ?? '';
        $name = trim($name) !== '' ? trim($name) : 'bestand';
        $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'bestand';

        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name);
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
        $description = ContentSanitizer::cleanForStorage((string) $request->input('description', ''));
        $isPublished = $request->input('is_published') !== null;

        if ($title === '') {
            return Response::redirect("/downloads/{$download['slug']}/bewerk?error=leeg");
        }

        $this->repo->updateDetails(
            (int) $download['id'], $title, $description, $isPublished,
            self::cleanVersion((string) $request->input('version', '')),
        );

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

    /** Versielabel: alleen cijfers, letters en . _ + - (max. 30 tekens). */
    private static function cleanVersion(string $v): string
    {
        return substr((string) preg_replace('/[^0-9A-Za-z._+\-]/', '', trim($v)), 0, 30);
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
