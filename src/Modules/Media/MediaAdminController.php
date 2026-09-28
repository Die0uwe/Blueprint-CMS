<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Media;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Storage\UploadManager;

/**
 * /admin/media — Mediabeheer (Wave 5).
 *
 * Bestanden landen op twee plekken, geschreven door twee onafhankelijke
 * UploadManager-instanties (zie UploadManager.php's docblok en
 * DownloadsController): storage/uploads/ (avatars, via de DI-singleton)
 * en storage/downloads/ (het Downloads-bestandsbeheer, met zijn eigen
 * instantie en ruimere MIME-whitelist). Dit scherm scant beide mappen
 * rechtstreeks op de schijf — er was nog geen centrale registry van
 * geüploade bestanden — en kruist elk bestand tegen de tabellen die
 * ernaar kunnen verwijzen (cf_users.avatar_url, cf_downloads.file_path)
 * zodat een admin nooit per ongeluk een bestand verwijdert dat nog in
 * gebruik is. Permissie: media.manage.
 */
final class MediaAdminController
{
    public function __construct(
        private readonly UploadManager $uploads,   // storage/uploads/ (DI-singleton, zie Application.php)
        private readonly Connection    $db,
        private readonly AuthManager   $auth,
        private readonly AuditLogger   $audit,
    ) {}

    public function index(Request $request): Response
    {
        // avatar_url wordt met een '/media/'-URL-prefix opgeslagen (zie
        // ProfileController::uploadAvatar()), file_path van Downloads NIET
        // (die geeft UploadManager::resolve() het pad rechtstreeks door) —
        // dus alleen de eerste heeft de prefix nodig om te stripped te
        // worden vóór vergelijking met de kale relatieve paden die
        // scanArea() teruggeeft.
        $usedAvatars   = $this->db->fetchAll("SELECT DISTINCT avatar_url FROM cf_users WHERE avatar_url IS NOT NULL");
        $usedAvatars   = array_map(
            static fn($u) => preg_replace('#^/media/#', '', (string) $u),
            array_column($usedAvatars, 'avatar_url')
        );
        $usedDownloads = $this->db->fetchAll("SELECT DISTINCT file_path FROM cf_downloads WHERE deleted_at IS NULL");
        $usedDownloads = array_column($usedDownloads, 'file_path');

        $uploadsFiles   = $this->scanArea(CF_ROOT . '/storage/uploads', $usedAvatars);
        $downloadsFiles = $this->scanArea(CF_ROOT . '/storage/downloads', $usedDownloads);

        $totalBytes = array_sum(array_column($uploadsFiles, 'size')) + array_sum(array_column($downloadsFiles, 'size'));
        $flash      = $request->query('ok');
        $error      = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $area = (string) $request->input('area', '');
        $path = (string) $request->input('path', '');

        $manager = $this->managerFor($area);
        if ($manager === null) {
            return Response::redirect('/admin/media?error=' . urlencode('Onbekend media-gebied.'));
        }

        if ($this->isReferenced($area, $path)) {
            return Response::redirect('/admin/media?error=' . urlencode(
                "\"{$path}\" is nog in gebruik (avatar of download) — kan niet verwijderd worden."
            ));
        }

        $manager->delete($path);
        $this->audit->log('media.delete', $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'area' => $area, 'path' => $path,
        ]);

        return Response::redirect('/admin/media?ok=verwijderd');
    }

    private function managerFor(string $area): ?UploadManager
    {
        return match ($area) {
            'uploads'   => $this->uploads,
            'downloads' => UploadManager::forDownloads(CF_ROOT . '/storage/downloads', 50 * 1024 * 1024),
            default     => null,
        };
    }

    private function isReferenced(string $area, string $path): bool
    {
        if ($area === 'uploads') {
            $row = $this->db->fetchOne("SELECT id FROM cf_users WHERE avatar_url = ?", ['/media/' . $path]);
            return $row !== null;
        }
        if ($area === 'downloads') {
            $row = $this->db->fetchOne("SELECT id FROM cf_downloads WHERE file_path = ? AND deleted_at IS NULL", [$path]);
            return $row !== null;
        }
        return false;
    }

    /**
     * @param string[] $usedPaths Relatieve paden die ergens in de database staan
     * @return array<int, array{path: string, size: int, mtime: int, in_use: bool}>
     */
    private function scanArea(string $root, array $usedPaths): array
    {
        if (!is_dir($root)) return [];

        $used  = array_flip($usedPaths);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            if ($fileInfo->getFilename() === '.gitkeep') continue;

            $relative = ltrim(str_replace($root, '', $fileInfo->getPathname()), '/');
            $files[] = [
                'path'   => $relative,
                'size'   => $fileInfo->getSize(),
                'mtime'  => $fileInfo->getMTime(),
                'in_use' => isset($used[$relative]),
            ];
        }

        usort($files, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $files;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: MediaAdminController.php | Role: Core | Version: 1.0.0        ║
// ║  Created: 2026-09-29 — Wave 5 (admin/media)                          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
