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

namespace CommunityFusion\Modules\Blog;

use CommunityFusion\Core\Security\ContentSanitizer;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Template\ThemeManager;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * BlogController
 *
 * Iedereen mag lezen. Aanmaken/bewerken/verwijderen: ingelogd + eigenaar
 * van de post, OF `blog.moderate` (voor andermans posts).
 */
final class BlogController
{
    public function __construct(
        private readonly BlogRepository $repo,
        private readonly AuthManager    $auth,
        private readonly Connection     $db,
        private readonly ThemeManager   $theme,
    ) {}

    /** GET /blog — alle gepubliceerde posts, van alle leden */
    public function index(Request $request): Response
    {
        $perPage = $this->repo->perPage();
        $page    = max(1, (int) $request->query('page', 1));
        $offset  = ($page - 1) * $perPage;
        $total   = $this->repo->countPublished();

        $html = $this->theme->render('blog/index.twig', [
            'page_title' => 'Blog',
            'posts'      => $this->repo->getPublished($perPage, $offset),
            'pagination' => ['current' => $page, 'total' => max(1, (int) ceil($total / $perPage))],
        ]);

        return Response::html($html);
    }

    /** GET /blog/{username} — het "profiel"-overzicht van één auteur */
    public function author(Request $request): Response
    {
        $user = $this->findUserByUsername((string) $request->param('username'));
        if ($user === null) {
            return Response::html('<h1>404 — Gebruiker niet gevonden</h1>', 404);
        }

        $isOwner = $this->auth->check() && $this->auth->id() === (int) $user['id'];

        $html = $this->theme->render('blog/author.twig', [
            'page_title' => 'Blog van ' . ($user['display_name'] ?: $user['username']),
            'author'     => $user,
            'posts'      => $this->repo->getByAuthor((int) $user['id'], $isOwner),
            'is_owner'   => $isOwner,
        ]);

        return Response::html($html);
    }

    /** GET /blog/{username}/nieuw — nieuw-postformulier */
    public function createForm(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }

        $user = $this->findUserByUsername((string) $request->param('username'));
        if ($user === null || (int) $user['id'] !== $this->auth->id()) {
            return Response::html('<h1>403 — Je kunt alleen op je eigen blog schrijven</h1>', 403);
        }

        $html = $this->theme->render('blog/edit.twig', [
            'page_title' => 'Nieuwe blogpost',
            'post'       => null,
            'username'   => $user['username'],
        ]);

        return Response::html($html);
    }

    /** POST /blog/{username}/nieuw — nieuwe post opslaan */
    public function store(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }

        $user = $this->findUserByUsername((string) $request->param('username'));
        if ($user === null || (int) $user['id'] !== $this->auth->id()) {
            return Response::html('<h1>403 — Je kunt alleen op je eigen blog schrijven</h1>', 403);
        }

        CsrfProtection::validateRequest();

        [$title, $summary, $content, $status] = $this->readPostInput($request);
        if ($title === '' || $content === '') {
            return Response::redirect("/blog/{$user['username']}/nieuw?error=leeg");
        }

        $id   = $this->repo->create((int) $user['id'], $title, $summary, $content, $status);
        $post = $this->repo->findById($id);

        return Response::redirect("/blog/{$user['username']}/{$post['slug']}");
    }

    /** GET /blog/{username}/{slug} — post lezen */
    public function show(Request $request): Response
    {
        $user = $this->findUserByUsername((string) $request->param('username'));
        if ($user === null) {
            return Response::html('<h1>404 — Gebruiker niet gevonden</h1>', 404);
        }

        $post = $this->repo->findByAuthorAndSlug((int) $user['id'], (string) $request->param('slug'));
        if ($post === null) {
            return Response::html('<h1>404 — Post niet gevonden</h1>', 404);
        }

        $isOwner = $this->auth->check() && $this->auth->id() === (int) $user['id'];
        if ($post['status'] !== 'published' && !$isOwner && !$this->auth->can('blog.moderate')) {
            return Response::html('<h1>404 — Post niet gevonden</h1>', 404);
        }

        if ($post['status'] === 'published') {
            $this->repo->incrementViews((int) $post['id']);
        }

        $html = $this->theme->render('blog/show.twig', [
            'page_title'   => $post['title'],
            'post'         => $post,
            'can_manage'   => $isOwner || $this->auth->can('blog.moderate'),
        ]);

        return Response::html($html);
    }

    /** GET /blog/{username}/{slug}/bewerk */
    public function editForm(Request $request): Response
    {
        $post = $this->requireManageablePost($request);
        if ($post instanceof Response) return $post;

        $html = $this->theme->render('blog/edit.twig', [
            'page_title' => 'Post bewerken',
            'post'       => $post,
            'username'   => $request->param('username'),
        ]);

        return Response::html($html);
    }

    /** POST /blog/{username}/{slug}/bewerk */
    public function update(Request $request): Response
    {
        $post = $this->requireManageablePost($request);
        if ($post instanceof Response) return $post;

        [$title, $summary, $content, $status] = $this->readPostInput($request);
        if ($title === '' || $content === '') {
            return Response::redirect("/blog/{$request->param('username')}/{$post['slug']}/bewerk?error=leeg");
        }

        $this->repo->update((int) $post['id'], $title, $summary, $content, $status);

        return Response::redirect("/blog/{$request->param('username')}/{$post['slug']}");
    }

    /** POST /blog/{username}/{slug}/verwijder */
    public function delete(Request $request): Response
    {
        $post = $this->requireManageablePost($request);
        if ($post instanceof Response) return $post;

        $this->repo->delete((int) $post['id']);

        return Response::redirect("/blog/{$request->param('username')}");
    }

    /**
     * Gedeelde guard voor bewerken/verwijderen: login + (eigenaar OF
     * blog.moderate) + CSRF + dat de post daadwerkelijk bestaat.
     */
    private function requireManageablePost(Request $request): array|Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode($request->getPath()));
        }

        $user = $this->findUserByUsername((string) $request->param('username'));
        $post = $user !== null
            ? $this->repo->findByAuthorAndSlug((int) $user['id'], (string) $request->param('slug'))
            : null;

        if ($user === null || $post === null) {
            return Response::html('<h1>404 — Post niet gevonden</h1>', 404);
        }

        $isOwner = $this->auth->id() === (int) $user['id'];
        if (!$isOwner && !$this->auth->can('blog.moderate')) {
            return Response::html('<h1>403 — Geen toegang tot deze post</h1>', 403);
        }

        if ($request->getMethod() === 'POST') {
            CsrfProtection::validateRequest();
        }

        return $post;
    }

    /** @return array{0:string,1:string,2:string,3:string} [title, summary, content, status] */
    private function readPostInput(Request $request): array
    {
        $status = $request->input('status') === 'published' ? 'published' : 'draft';

        return [
            trim((string) $request->input('title', '')),
            trim((string) $request->input('summary', '')),
            ContentSanitizer::cleanForStorage((string) $request->input('content', '')),
            $status,
        ];
    }

    private function findUserByUsername(string $username): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, username, display_name, avatar_url, bio FROM cf_users WHERE username = ? AND is_active = 1 AND deleted_at IS NULL",
            [$username]
        );
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : BlogController.php                                   ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Blog core-module)                      ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
