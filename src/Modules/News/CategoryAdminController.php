<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\News;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/news/categories — Categoriebeheer voor News (Wave 9).
 *
 * Tot deze wave was er GEEN manier om een News-categorie aan te maken,
 * hernoemen, herordenen of verwijderen anders dan rechtstreeks een rij in
 * cf_categories(type=news) invoegen — hetzelfde gat dat Wave 4 al dichtte
 * voor Forumborden (zie Forum\BoardAdminController, waarvan dit bestand het
 * patroon 1:1 hergebruikt). Permissie: news.create — dezelfde permissie die
 * de rest van het News-adminscherm al gebruikt (zie Router.php).
 *
 * Cascade-keuze bij verwijderen (zie ook NewsRepository::deleteCategory()):
 * anders dan bij Forumborden wordt een News-categorie NOOIT geblokkeerd op
 * basis van gekoppelde artikelen. cf_news.category_id staat in schema.sql op
 * ON DELETE SET NULL (in tegenstelling tot cf_forum_topics.board_id, dat op
 * ON DELETE CASCADE staat) — verwijderen is dus altijd non-destructief voor
 * de artikelen zelf, die worden alleen ontkoppeld. Deze controller blokkeert
 * daarom niet, maar toont wél hoeveel artikelen ontkoppeld raken (zowel vóór
 * het verwijderen, via het aantal in de tabel, als in de bevestigingsmelding
 * erna) zodat een beheerder niet voor een verrassing komt te staan.
 */
final class CategoryAdminController
{
    public function __construct(
        private readonly NewsRepository $repo,
        private readonly AuthManager    $auth,
        private readonly AuditLogger    $audit,
    ) {}

    public function index(Request $request): Response
    {
        $categories = $this->repo->getAllCategoriesForAdmin();
        $flash      = $request->query('ok');
        $unlinked   = (int) $request->query('unlinked', 0);
        $error      = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_categories_index.php';
        return Response::html(ob_get_clean());
    }

    public function createForm(Request $request): Response
    {
        $category   = null;
        $categories = $this->repo->getAllCategoriesForAdmin();
        $error      = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_category_form.php';
        return Response::html(ob_get_clean());
    }

    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();
        return $this->save($request, null);
    }

    public function editForm(Request $request): Response
    {
        $id       = (int) $request->param('id');
        $category = $this->repo->findCategoryById($id);

        if ($category === null) {
            return Response::html('<h1>404 — Categorie niet gevonden</h1>', 404);
        }

        $categories = $this->repo->getAllCategoriesForAdmin();
        $error      = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_category_form.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id = (int) $request->param('id');
        return $this->save($request, $id);
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id       = (int) $request->param('id');
        $category = $this->repo->findCategoryById($id);

        if ($category === null) {
            return Response::html('<h1>404 — Categorie niet gevonden</h1>', 404);
        }

        // Zie klasse-commentaar hierboven: verwijderen wordt bewust nooit
        // geblokkeerd (ON DELETE SET NULL), maar we tellen wel hoeveel
        // artikelen ontkoppeld raken voor de flash-melding.
        $unlinkedCount = $this->repo->countArticlesInCategory($id);

        $this->repo->deleteCategory($id);
        $this->logAction('news.category.delete', [
            'category_id'      => $id,
            'name'             => $category['name'],
            'unlinked_articles' => $unlinkedCount,
        ]);

        $redirect = '/admin/news/categories?ok=verwijderd';
        if ($unlinkedCount > 0) {
            $redirect .= '&unlinked=' . $unlinkedCount;
        }

        return Response::redirect($redirect);
    }

    private function logAction(string $action, array $context): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, $context);
    }

    private function save(Request $request, ?int $id): Response
    {
        $name        = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $position    = (int) $request->input('position', 0);
        $slugInput   = trim((string) $request->input('slug', ''));
        $parentInput = trim((string) $request->input('parent_id', ''));

        if ($name === '') {
            $target = $id !== null ? "/admin/news/categories/{$id}/bewerk" : '/admin/news/categories/nieuw';
            return Response::redirect($target . '?error=' . urlencode('Naam is verplicht.'));
        }

        // parent_id is optioneel — alleen een bestaande News-categorie-ID
        // toestaan, en nooit jezelf als eigen ouder (self-parent).
        $parentId = null;
        if ($parentInput !== '') {
            $candidateParentId = (int) $parentInput;
            $parent             = $this->repo->findCategoryById($candidateParentId);

            if ($parent === null) {
                $target = $id !== null ? "/admin/news/categories/{$id}/bewerk" : '/admin/news/categories/nieuw';
                return Response::redirect($target . '?error=' . urlencode('Ongeldige bovenliggende categorie.'));
            }
            if ($id !== null && $candidateParentId === $id) {
                return Response::redirect(
                    "/admin/news/categories/{$id}/bewerk?error=" . urlencode('Een categorie kan niet zijn eigen bovenliggende categorie zijn.')
                );
            }
            $parentId = $candidateParentId;
        }

        $slug = $this->slugify($slugInput !== '' ? $slugInput : $name);
        if ($slug === '') {
            $slug = 'categorie-' . bin2hex(random_bytes(3));
        }

        if ($this->repo->categorySlugTaken($slug, $id)) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }

        if ($id === null) {
            $newId = $this->repo->createCategory($slug, $name, $description, $position, $parentId);
            $this->logAction('news.category.create', ['category_id' => $newId, 'slug' => $slug, 'name' => $name]);
            return Response::redirect('/admin/news/categories?ok=aangemaakt');
        }

        $category = $this->repo->findCategoryById($id);
        if ($category === null) {
            return Response::html('<h1>404 — Categorie niet gevonden</h1>', 404);
        }

        $this->repo->updateCategory($id, $slug, $name, $description, $position, $parentId);
        $this->logAction('news.category.update', ['category_id' => $id, 'slug' => $slug, 'name' => $name]);
        return Response::redirect('/admin/news/categories?ok=bijgewerkt');
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: CategoryAdminController.php | Role: Core | Version: 1.0.0     ║
// ║  Created: 2026-09-29 — Wave 9 (admin/news/categories)                 ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
