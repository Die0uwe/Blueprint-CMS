<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Downloads;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadManager;

/**
 * Beheeroverzicht van álle downloads (permissie `downloads.manage`, afgedwongen
 * door de route). Bewerken/uploaden via de bestaande formulieren onder /downloads.
 */
final class DownloadsAdminController
{
    private const PER_PAGE = 20;

    private UploadManager $uploads;

    public function __construct(
        private readonly DownloadsRepository $repo,
        private readonly AuthManager         $auth,
        private readonly AuditLogger         $audit,
    ) {
        $this->uploads = UploadManager::forDownloads(CF_ROOT . '/storage/downloads', 50 * 1024 * 1024);
    }

    public function index(Request $request): Response
    {
        $page  = max(1, (int) $request->query('page', 1));
        $items = $this->repo->getAllForAdmin(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total = $this->repo->countAll();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $flash = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html((string) ob_get_clean());
    }

    public function toggle(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $dl = $this->repo->findById((int) $request->param('id'));
        if ($dl === null) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }
        $publish = (int) $dl['is_published'] !== 1;
        $this->repo->setPublished((int) $dl['id'], $publish);
        $this->log($publish ? 'downloads.publish' : 'downloads.unpublish', $dl);

        return Response::redirect('/admin/downloads?ok=' . ($publish ? 'gepubliceerd' : 'verborgen'));
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $dl = $this->repo->findById((int) $request->param('id'));
        if ($dl === null) {
            return Response::html('<h1>404 — Download niet gevonden</h1>', 404);
        }
        $this->repo->delete((int) $dl['id']);
        $this->uploads->delete($dl['file_path']);
        $this->log('downloads.delete', $dl);

        return Response::redirect('/admin/downloads?ok=verwijderd');
    }

    private function log(string $action, array $dl): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'download_id' => (int) $dl['id'],
            'title'       => mb_substr((string) $dl['title'], 0, 100),
        ]);
    }
}
