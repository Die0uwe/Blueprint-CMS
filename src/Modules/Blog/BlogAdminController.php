<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Blog;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * Beheeroverzicht van álle blogposts (permissie `blog.moderate`, afgedwongen
 * door de route). Bewerken gebeurt via het bestaande formulier op
 * /blog/{user}/{slug}/bewerk (met editor); hier: overzicht, publiceren en verwijderen.
 */
final class BlogAdminController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly BlogRepository $repo,
        private readonly AuthManager    $auth,
        private readonly AuditLogger    $audit,
    ) {}

    public function index(Request $request): Response
    {
        $page   = $request->page();
        $items  = $this->repo->getAllForAdmin(self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total  = $this->repo->countAll();
        $pages  = max(1, (int) ceil($total / self::PER_PAGE));
        $flash  = $request->query('ok');
        $meUsername = (string) ($this->auth->user()['username'] ?? '');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html((string) ob_get_clean());
    }

    public function toggle(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $post = $this->repo->findById((int) $request->param('id'));
        if ($post === null || $post['deleted_at'] !== null) {
            return Response::html('<h1>404 — Post niet gevonden</h1>', 404);
        }
        $new = $post['status'] === 'published' ? 'draft' : 'published';
        $this->repo->setStatus((int) $post['id'], $new);
        $this->log('blog.' . ($new === 'published' ? 'publish' : 'unpublish'), $post);

        return Response::redirect('/admin/blog?ok=' . ($new === 'published' ? 'gepubliceerd' : 'verborgen'));
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $post = $this->repo->findById((int) $request->param('id'));
        if ($post === null || $post['deleted_at'] !== null) {
            return Response::html('<h1>404 — Post niet gevonden</h1>', 404);
        }
        $this->repo->delete((int) $post['id']);
        $this->log('blog.delete', $post);

        return Response::redirect('/admin/blog?ok=verwijderd');
    }

    private function log(string $action, array $post): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'post_id' => (int) $post['id'],
            'title'   => mb_substr((string) $post['title'], 0, 100),
        ]);
    }
}
