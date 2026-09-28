<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Forum;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/forum/boards — Bordbeheer (Wave 4).
 *
 * Tot deze wave was er GEEN manier om een tweede forumbord aan te maken
 * anders dan rechtstreeks een rij in cf_categories(type=forum) invoegen —
 * schema.sql seedt alleen het standaardbord 'algemeen'. Dit scherm dicht
 * dat gat: aanmaken, hernoemen, herordenen en verwijderen van borden,
 * met bescherming tegen het per ongeluk cascade-verwijderen van topics
 * (zie ForumRepository::deleteBoard()). Permissie: forum.moderate.
 */
final class BoardAdminController
{
    public function __construct(
        private readonly ForumRepository $repo,
        private readonly AuthManager     $auth,
        private readonly AuditLogger     $audit,
    ) {}

    public function index(Request $request): Response
    {
        $boards = $this->repo->getAllBoardsForAdmin();
        $flash  = $request->query('ok');
        $error  = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_boards_index.php';
        return Response::html(ob_get_clean());
    }

    public function createForm(Request $request): Response
    {
        $board = null;
        $error = $request->query('error');
        ob_start();
        include __DIR__ . '/views/admin_board_form.php';
        return Response::html(ob_get_clean());
    }

    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();
        return $this->save($request, null);
    }

    public function editForm(Request $request): Response
    {
        $id    = (int) $request->param('id');
        $board = $this->repo->findBoardById($id);

        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        $error = $request->query('error');
        ob_start();
        include __DIR__ . '/views/admin_board_form.php';
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
        $id    = (int) $request->param('id');
        $board = $this->repo->findBoardById($id);

        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        if ($this->hasTopics($id)) {
            return Response::redirect('/admin/forum/boards?error=' . urlencode(
                "Bord \"{$board['name']}\" bevat nog topics — verplaats of verwijder die eerst."
            ));
        }

        $this->repo->deleteBoard($id);
        $this->logAction('forum.board.delete', ['board_id' => $id, 'name' => $board['name']]);
        return Response::redirect('/admin/forum/boards?ok=verwijderd');
    }

    private function logAction(string $action, array $context): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, $context);
    }

    private function hasTopics(int $boardId): bool
    {
        return $this->repo->countTopics($boardId) > 0;
    }

    private function save(Request $request, ?int $id): Response
    {
        $name        = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $position    = (int) $request->input('position', 0);
        $slugInput   = trim((string) $request->input('slug', ''));

        if ($name === '') {
            $target = $id !== null ? "/admin/forum/boards/{$id}/bewerk" : '/admin/forum/boards/nieuw';
            return Response::redirect($target . '?error=' . urlencode('Naam is verplicht.'));
        }

        $slug = $this->slugify($slugInput !== '' ? $slugInput : $name);
        if ($slug === '') {
            $slug = 'bord-' . bin2hex(random_bytes(3));
        }

        if ($this->repo->boardSlugTaken($slug, $id)) {
            $slug .= '-' . bin2hex(random_bytes(2));
        }

        if ($id === null) {
            $newId = $this->repo->createBoard($slug, $name, $description, $position);
            $this->logAction('forum.board.create', ['board_id' => $newId, 'slug' => $slug, 'name' => $name]);
            return Response::redirect('/admin/forum/boards?ok=aangemaakt');
        }

        $board = $this->repo->findBoardById($id);
        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        $this->repo->updateBoard($id, $slug, $name, $description, $position);
        $this->logAction('forum.board.update', ['board_id' => $id, 'slug' => $slug, 'name' => $name]);
        return Response::redirect('/admin/forum/boards?ok=bijgewerkt');
    }

    private function slugify(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BoardAdminController.php | Role: Core | Version: 1.0.0        ║
// ║  Created: 2026-09-29 — Wave 4 (admin/forum/boards)                    ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
