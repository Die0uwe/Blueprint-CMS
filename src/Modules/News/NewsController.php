<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\News;

use CommunityFusion\Core\Security\ContentSanitizer;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Security\CsrfProtection;

final class NewsController
{
    private const PER_PAGE       = 12;
    private const ADMIN_PER_PAGE = 20;

    /** cf_news.status is een ENUM('draft','published','archived') — zie schema.sql. */
    private const STATUSES = ['draft', 'published', 'archived'];

    public function __construct(
        private readonly NewsRepository $repo,
        private readonly ThemeManager   $theme,
        private readonly AuthManager    $auth,
    ) {}

    // ── Publiek ──────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $page    = $request->page();
        $offset  = ($page - 1) * self::PER_PAGE;
        $items   = $this->repo->getPublished(self::PER_PAGE, $offset);
        $total   = $this->repo->countPublished();
        $pages   = (int) ceil($total / self::PER_PAGE);

        $html = $this->theme->render('news/index.twig', [
            'page_title' => 'Nieuws',
            'news'       => $items,
            'pagination' => ['current' => $page, 'total' => $pages],
        ]);

        return Response::html($html);
    }

    public function show(Request $request): Response
    {
        $slug = $request->param('slug');
        $item = $this->repo->findBySlug($slug);

        if ($item === null) {
            return Response::html('<h1>404 — Artikel niet gevonden</h1>', 404);
        }

        $this->repo->incrementViews((int) $item['id']);

        $html = $this->theme->render('news/show.twig', [
            'page_title' => $item['title'],
            'article'    => $item,
        ]);

        return Response::html($html);
    }

    // ── Admin (Wave 2 — /admin/news gated door PermissionMiddleware:news.create,
    //    zie Router.php; de checks hieronder zijn defense-in-depth voor het geval
    //    een route ooit los van die middleware wordt aangeroepen) ──────────────

    public function adminIndex(Request $request): Response
    {
        $page    = $request->page();
        $offset  = ($page - 1) * self::ADMIN_PER_PAGE;
        $items   = $this->repo->getAll(self::ADMIN_PER_PAGE, $offset);
        $total   = $this->repo->countAll();
        $pages   = max(1, (int) ceil($total / self::ADMIN_PER_PAGE));
        $flash   = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function createForm(Request $request): Response
    {
        $article = null;
        $error   = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_form.php';
        return Response::html(ob_get_clean());
    }

    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();

        [$data, $error] = $this->fromRequest($request);
        if ($error !== null) {
            return Response::redirect('/admin/news/create?error=' . urlencode($error));
        }

        $data['slug']      = $this->repo->uniqueSlug($data['title']);
        $data['author_id'] = $this->auth->id();

        $this->repo->create($data);

        return Response::redirect('/admin/news?ok=aangemaakt');
    }

    public function editForm(Request $request): Response
    {
        $id      = (int) $request->param('id');
        $article = $this->repo->findById($id);
        $error   = $request->query('error');

        if ($article === null) {
            return Response::html('<h1>404 — Artikel niet gevonden</h1>', 404);
        }

        ob_start();
        include __DIR__ . '/views/admin_form.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $id      = (int) $request->param('id');
        $article = $this->repo->findById($id);
        if ($article === null) {
            return Response::html('<h1>404 — Artikel niet gevonden</h1>', 404);
        }

        [$data, $error] = $this->fromRequest($request);
        if ($error !== null) {
            return Response::redirect("/admin/news/{$id}/bewerk?error=" . urlencode($error));
        }

        // Slug blijft bewust stabiel na aanmaken (bestaande permalinks/SEO
        // blijven zo werken) — alleen titel/inhoud/status zijn hier wijzigbaar.
        $this->repo->update($id, $data);

        return Response::redirect('/admin/news?ok=bijgewerkt');
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $id = (int) $request->param('id');
        $this->repo->delete($id);

        return Response::redirect('/admin/news?ok=verwijderd');
    }

    /**
     * Gedeelde validatie/normalisatie voor store() en update().
     *
     * @return array{0: array<string, mixed>, 1: ?string} [data, foutmelding]
     */
    private function fromRequest(Request $request): array
    {
        $title    = trim((string) $request->input('title', ''));
        $summary  = trim((string) $request->input('summary', ''));
        $content  = ContentSanitizer::cleanForStorage((string) $request->input('content', ''));
        $image    = trim((string) $request->input('featured_image', ''));
        $status   = (string) $request->input('status', 'draft');
        $isSticky = $request->input('is_sticky') !== null ? 1 : 0;

        if ($title === '' || $content === '') {
            return [[], 'Titel en inhoud zijn verplicht.'];
        }
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }
        if ($image !== '' && !filter_var($image, FILTER_VALIDATE_URL)) {
            return [[], 'De afbeeldings-URL is ongeldig.'];
        }

        $data = [
            'title'          => $title,
            'summary'        => $summary !== '' ? $summary : null,
            'content'        => $content,
            'featured_image' => $image !== '' ? $image : null,
            'status'         => $status,
            'is_sticky'      => $isSticky,
        ];

        // published_at wordt alleen gezet zodra een artikel voor het eerst
        // gepubliceerd wordt — zo blijft de publicatiedatum stabiel bij een
        // latere bewerking i.p.v. steeds "nu" te worden.
        if ($status === 'published') {
            $existingPublishedAt = $request->input('_current_published_at', '');
            if ($existingPublishedAt === '') {
                $data['published_at'] = date('Y-m-d H:i:s');
            }
        }

        return [$data, null];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: NewsController.php | Role: Core | Version: 1.1.0             ║
// ║  Created: 2026-06-06 | Updated: 2026-09-28 — Wave 2 admin-CRUD       ║
// ╚══════════════════════════════════════════════════════════════════════╝
