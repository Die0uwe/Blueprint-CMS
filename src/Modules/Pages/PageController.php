<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Pages;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\News\NewsRepository;

final class PageController
{
    private const ADMIN_PER_PAGE = 20;

    /** cf_pages.status is een ENUM('draft','published') — zie schema.sql (geen 'archived', anders dan news). */
    private const STATUSES = ['draft', 'published'];

    /** themes/default/templates/pages/*.twig — uitgebreid als een thema meer varianten meelevert. */
    private const TEMPLATES = ['default', 'full'];

    public function __construct(
        private readonly PageRepository  $pages,
        private readonly ThemeManager    $theme,
        private readonly NewsRepository  $news,
        private readonly AuthManager     $auth,
    ) {}

    // ── Publiek ──────────────────────────────────────────────────────────

    /** Homepage */
    public function home(Request $request): Response
    {
        $latestNews = $this->news->getPublished(6);
        $menuPages  = $this->pages->getMenuPages();

        $html = $this->theme->render('home.twig', [
            'page_title'  => 'Home',
            'latest_news' => $latestNews,
            'menu_pages'  => $menuPages,
        ]);

        return Response::html($html);
    }

    /** Statische pagina */
    public function show(Request $request): Response
    {
        $slug = $request->param('slug');
        $page = $this->pages->findBySlug($slug);

        if ($page === null) {
            return Response::html('<h1>404 — Pagina niet gevonden</h1>', 404);
        }

        $page['content'] = $this->filterContent((string) $page['content'], 'page', (int) $page['id']);

        $template = $page['template'] ?? 'default';
        $html     = $this->theme->render("pages/{$template}.twig", [
            'page_title' => $page['title'],
            'page'       => $page,
        ]);

        return Response::html($html);
    }

    /** Plugins kunnen de uiteindelijke HTML nog aanpassen (filter content.after_render). Een fout in een plugin laat de pagina staan. */
    private function filterContent(string $html, string $type, int $id): string
    {
        try {
            $out = \CommunityFusion\Core\Application::getInstance()->getHooks()->applyFilters('content.after_render', $html, ['type' => $type, 'id' => $id]);
            return is_string($out) ? $out : $html;
        } catch (\Throwable) {
            return $html;
        }
    }

    // ── Admin (Wave 2 — /admin/pages gated door PermissionMiddleware:pages.manage) ──

    public function adminIndex(Request $request): Response
    {
        $page   = max(1, (int) $request->query('page', 1));
        $offset = ($page - 1) * self::ADMIN_PER_PAGE;
        $items  = $this->pages->getAll(self::ADMIN_PER_PAGE, $offset);
        $total  = $this->pages->countAll();
        $pages  = max(1, (int) ceil($total / self::ADMIN_PER_PAGE));
        $flash  = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function createForm(Request $request): Response
    {
        $item  = null;
        $error = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_form.php';
        return Response::html(ob_get_clean());
    }

    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();

        [$data, $error] = $this->fromRequest($request);
        if ($error !== null) {
            return Response::redirect('/admin/pages/create?error=' . urlencode($error));
        }

        $data['slug']      = $this->pages->uniqueSlug($data['title']);
        $data['author_id'] = $this->auth->id();

        $this->pages->create($data);

        return Response::redirect('/admin/pages?ok=aangemaakt');
    }

    public function editForm(Request $request): Response
    {
        $id    = (int) $request->param('id');
        $item  = $this->pages->findById($id);
        $error = $request->query('error');

        if ($item === null) {
            return Response::html('<h1>404 — Pagina niet gevonden</h1>', 404);
        }

        ob_start();
        include __DIR__ . '/views/admin_form.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $id   = (int) $request->param('id');
        $item = $this->pages->findById($id);
        if ($item === null) {
            return Response::html('<h1>404 — Pagina niet gevonden</h1>', 404);
        }

        [$data, $error] = $this->fromRequest($request);
        if ($error !== null) {
            return Response::redirect("/admin/pages/{$id}/bewerk?error=" . urlencode($error));
        }

        // Slug blijft bewust stabiel na aanmaken — zelfde reden als NewsController::update().
        $this->pages->update($id, $data);

        return Response::redirect('/admin/pages?ok=bijgewerkt');
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $id = (int) $request->param('id');
        $this->pages->delete($id);

        return Response::redirect('/admin/pages?ok=verwijderd');
    }

    /**
     * Gedeelde validatie/normalisatie voor store() en update().
     *
     * @return array{0: array<string, mixed>, 1: ?string} [data, foutmelding]
     */
    private function fromRequest(Request $request): array
    {
        $title    = trim((string) $request->input('title', ''));
        $content  = trim((string) $request->input('content', ''));
        $metaTitle = trim((string) $request->input('meta_title', ''));
        $metaDesc  = trim((string) $request->input('meta_desc', ''));
        $status    = (string) $request->input('status', 'draft');
        $template  = (string) $request->input('template', 'default');
        $menuRaw   = trim((string) $request->input('menu_position', ''));

        if ($title === '' || $content === '') {
            return [[], 'Titel en inhoud zijn verplicht.'];
        }
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }
        if (!in_array($template, self::TEMPLATES, true)) {
            $template = 'default';
        }

        // Alleen een geldig geheel getal wordt als menupositie opgeslagen;
        // leeg laten betekent "niet in het menu tonen" (kolom is NULL-able).
        $menuPosition = ($menuRaw !== '' && ctype_digit($menuRaw)) ? (int) $menuRaw : null;

        $data = [
            'title'         => $title,
            'content'       => $content,
            'template'      => $template,
            'meta_title'    => $metaTitle !== '' ? $metaTitle : null,
            'meta_desc'     => $metaDesc !== '' ? $metaDesc : null,
            'status'        => $status,
            'menu_position' => $menuPosition,
        ];

        return [$data, null];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: PageController.php | Role: Core | Version: 1.1.0             ║
// ║  Created: 2026-06-06 | Updated: 2026-09-28 — Wave 2 admin-CRUD       ║
// ╚══════════════════════════════════════════════════════════════════════╝
