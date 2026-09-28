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

namespace CommunityFusion\Modules\Forum;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * ForumController
 *
 * Publieke lezen: iedereen. Topic/reactie plaatsen: permissie `forum.post`
 * (member+ per de RBAC-seed in schema.sql). Pinnen/sluiten/verwijderen:
 * permissie `forum.moderate` (moderator+).
 */
final class ForumController
{
    public function __construct(
        private readonly ForumRepository $repo,
        private readonly AuthManager     $auth,
        private readonly ThemeManager    $theme,
    ) {}

    /** GET /forum — bordenlijst */
    public function index(Request $request): Response
    {
        $html = $this->theme->render('forum/index.twig', [
            'page_title' => 'Forum',
            'boards'     => $this->repo->getBoards(),
        ]);

        return Response::html($html);
    }

    /** GET /forum/{board} — topics binnen een bord */
    public function board(Request $request): Response
    {
        $board = $this->repo->findBoardBySlug((string) $request->param('board'));
        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        $perPage = $this->repo->topicsPerPage();
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;
        $total   = $this->repo->countTopics((int) $board['id']);

        $html = $this->theme->render('forum/board.twig', [
            'page_title' => $board['name'],
            'board'      => $board,
            'topics'     => $this->repo->getTopics((int) $board['id'], $perPage, $offset),
            'pagination' => ['current' => $page, 'total' => (int) ceil($total / $perPage)],
            'can_post'      => $this->auth->can('forum.post'),
            'can_moderate'  => $this->auth->can('forum.moderate'),
            'csrf'          => CsrfProtection::getToken(),
        ]);

        return Response::html($html);
    }

    /** GET /forum/{board}/nieuw — nieuw-topic formulier */
    public function newTopicForm(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }
        if (!$this->auth->can('forum.post')) {
            return Response::html('<h1>403 — Je mag hier geen topics plaatsen</h1>', 403);
        }

        $board = $this->repo->findBoardBySlug((string) $request->param('board'));
        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        $html = $this->theme->render('forum/new_topic.twig', [
            'page_title' => 'Nieuw topic — ' . $board['name'],
            'board'      => $board,
        ]);

        return Response::html($html);
    }

    /** POST /forum/{board}/nieuw — nieuw topic opslaan */
    public function storeTopic(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }
        if (!$this->auth->can('forum.post')) {
            return Response::html('<h1>403 — Je mag hier geen topics plaatsen</h1>', 403);
        }

        CsrfProtection::validateRequest();

        $board = $this->repo->findBoardBySlug((string) $request->param('board'));
        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        $title   = trim((string) $request->input('title', ''));
        $content = trim((string) $request->input('content', ''));

        if ($title === '' || $content === '') {
            return Response::redirect("/forum/{$board['slug']}/nieuw?error=leeg");
        }

        $topicId = $this->repo->createTopic((int) $board['id'], (int) $this->auth->id(), $title, $content);
        $topic   = $this->repo->findTopicById($topicId);

        return Response::redirect("/forum/{$board['slug']}/{$topic['slug']}");
    }

    /** GET /forum/{board}/{topic} — posts binnen een topic + reactieformulier */
    public function topic(Request $request): Response
    {
        $board = $this->repo->findBoardBySlug((string) $request->param('board'));
        if ($board === null) {
            return Response::html('<h1>404 — Bord niet gevonden</h1>', 404);
        }

        $topic = $this->repo->findTopicBySlug((int) $board['id'], (string) $request->param('topic'));
        if ($topic === null) {
            return Response::html('<h1>404 — Topic niet gevonden</h1>', 404);
        }

        $this->repo->incrementViews((int) $topic['id']);

        $perPage = $this->repo->postsPerPage();
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;
        $total   = $this->repo->countPosts((int) $topic['id']);

        $html = $this->theme->render('forum/topic.twig', [
            'page_title' => $topic['title'],
            'board'      => $board,
            'topic'      => $topic,
            'posts'      => $this->repo->getPosts((int) $topic['id'], $perPage, $offset),
            'pagination' => ['current' => $page, 'total' => max(1, (int) ceil($total / $perPage))],
            'can_post'      => $this->auth->can('forum.post'),
            'can_moderate'  => $this->auth->can('forum.moderate'),
            'csrf'          => CsrfProtection::getToken(),
        ]);

        return Response::html($html);
    }

    /** POST /forum/{board}/{topic}/reageer — reactie plaatsen */
    public function storePost(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }
        if (!$this->auth->can('forum.post')) {
            return Response::html('<h1>403 — Je mag hier niet reageren</h1>', 403);
        }

        CsrfProtection::validateRequest();

        $board = $this->repo->findBoardBySlug((string) $request->param('board'));
        $topic = $board !== null ? $this->repo->findTopicBySlug((int) $board['id'], (string) $request->param('topic')) : null;

        if ($board === null || $topic === null) {
            return Response::html('<h1>404 — Topic niet gevonden</h1>', 404);
        }

        if ((bool) $topic['is_locked'] && !$this->auth->can('forum.moderate')) {
            return Response::html('<h1>403 — Dit topic is gesloten</h1>', 403);
        }

        $content = trim((string) $request->input('content', ''));
        if ($content === '') {
            return Response::redirect("/forum/{$board['slug']}/{$topic['slug']}?error=leeg");
        }

        $this->repo->createPost((int) $topic['id'], (int) $this->auth->id(), $content);

        return Response::redirect("/forum/{$board['slug']}/{$topic['slug']}#reacties");
    }

    /** POST /forum/{board}/{topic}/pin — topic pinnen/losmaken (moderatie) */
    public function togglePin(Request $request): Response
    {
        $topic = $this->requireModeratableTopic($request);
        if ($topic instanceof Response) return $topic;

        $this->repo->setPinned((int) $topic['id'], !((bool) $topic['is_pinned']));

        return Response::redirect("/forum/{$request->param('board')}/{$topic['slug']}");
    }

    /** POST /forum/{board}/{topic}/lock — topic sluiten/heropenen (moderatie) */
    public function toggleLock(Request $request): Response
    {
        $topic = $this->requireModeratableTopic($request);
        if ($topic instanceof Response) return $topic;

        $this->repo->setLocked((int) $topic['id'], !((bool) $topic['is_locked']));

        return Response::redirect("/forum/{$request->param('board')}/{$topic['slug']}");
    }

    /** POST /forum/{board}/{topic}/verwijder — topic verwijderen (moderatie) */
    public function deleteTopic(Request $request): Response
    {
        $topic = $this->requireModeratableTopic($request);
        if ($topic instanceof Response) return $topic;

        $this->repo->deleteTopic((int) $topic['id']);

        return Response::redirect("/forum/{$request->param('board')}");
    }

    /**
     * Gedeelde guard voor de drie moderatie-acties hierboven: valideert
     * login + `forum.moderate`-permissie + CSRF + dat bord/topic bestaan.
     * Geeft het topic-array terug, of een klaar-om-te-returnen Response
     * bij elke afwijzing.
     */
    private function requireModeratableTopic(Request $request): array|Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }
        if (!$this->auth->can('forum.moderate')) {
            return Response::html('<h1>403 — Moderatierechten vereist</h1>', 403);
        }

        CsrfProtection::validateRequest();

        $board = $this->repo->findBoardBySlug((string) $request->param('board'));
        $topic = $board !== null ? $this->repo->findTopicBySlug((int) $board['id'], (string) $request->param('topic')) : null;

        if ($board === null || $topic === null) {
            return Response::html('<h1>404 — Topic niet gevonden</h1>', 404);
        }

        return $topic;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : ForumController.php                                  ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Forum core-module)                     ║
// ║  Notes        : forum.post / forum.moderate RBAC-permissies vereist  ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
