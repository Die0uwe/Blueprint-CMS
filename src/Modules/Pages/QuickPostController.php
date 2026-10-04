<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Pages;

use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\ContentSanitizer;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Blog\BlogRepository;
use CommunityFusion\Modules\Forum\ForumRepository;
use CommunityFusion\Modules\News\NewsRepository;

/**
 * Snel plaatsen vanaf de homepage: nieuwsbericht, blogpost of forumtopic.
 *
 * Rechten per type (zelfde regels als de volledige formulieren):
 *  - post  → permissie news.create
 *  - blog  → elke ingelogde gebruiker (op de eigen blog)
 *  - forum → permissie forum.post
 */
final class QuickPostController
{
    public const TYPES = ['post', 'blog', 'forum'];

    public function __construct(
        private readonly NewsRepository  $news,
        private readonly BlogRepository  $blog,
        private readonly ForumRepository $forum,
        private readonly AuthManager     $auth,
    ) {}

    /**
     * Types die de huidige bezoeker mag plaatsen (leeg = widget niet tonen).
     *
     * @return list<string>
     */
    public function allowedTypes(): array
    {
        if (!$this->auth->check()) {
            return [];
        }
        $types = [];
        if ($this->auth->can('news.create')) {
            $types[] = 'post';
        }
        $types[] = 'blog';
        if ($this->auth->can('forum.post')) {
            $types[] = 'forum';
        }
        return $types;
    }

    /** @return list<array{slug:string,name:string}> */
    public function boards(): array
    {
        if (!in_array('forum', $this->allowedTypes(), true)) {
            return [];
        }
        $out = [];
        foreach ($this->forum->getBoards() as $b) {
            $out[] = ['slug' => (string) $b['slug'], 'name' => (string) $b['name']];
        }
        return $out;
    }

    /** POST /quick-post */
    public function store(Request $request): Response
    {
        if (!$this->auth->check()) {
            return Response::redirect('/login?redirect=' . urlencode('/'));
        }

        CsrfProtection::validateRequest();

        $type = (string) $request->input('type', '');
        if (!in_array($type, self::TYPES, true) || !in_array($type, $this->allowedTypes(), true)) {
            return Response::html('<h1>403 — Dit type bericht mag je niet plaatsen</h1>', 403);
        }

        $title   = trim((string) $request->input('title', ''));
        $content = ContentSanitizer::cleanForStorage((string) $request->input('content', ''));
        if ($title === '' || $content === '') {
            return Response::redirect('/?quick=leeg');
        }
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }

        $userId = (int) $this->auth->id();

        return match ($type) {
            'post'  => $this->storePost($userId, $title, $content),
            'blog'  => $this->storeBlog($userId, $title, $content),
            default => $this->storeTopic($userId, $title, $content, (string) $request->input('board', '')),
        };
    }

    private function storePost(int $userId, string $title, string $content): Response
    {
        $slug = $this->news->uniqueSlug($title);
        $this->news->create([
            'title'        => $title,
            'slug'         => $slug,
            'summary'      => null,
            'content'      => $content,
            'status'       => 'published',
            'is_sticky'    => 0,
            'author_id'    => $userId,
            'published_at' => date('Y-m-d H:i:s'),
        ]);

        return Response::redirect('/news/' . $slug);
    }

    private function storeBlog(int $userId, string $title, string $content): Response
    {
        $username = (string) ($this->auth->user()['username'] ?? '');
        if ($username === '') {
            return Response::redirect('/?quick=fout');
        }
        $id   = $this->blog->create($userId, $title, '', $content, 'published');
        $post = $this->blog->findById($id);

        return Response::redirect('/blog/' . rawurlencode($username) . '/' . ($post['slug'] ?? ''));
    }

    private function storeTopic(int $userId, string $title, string $content, string $boardSlug): Response
    {
        $board = $this->forum->findBoardBySlug($boardSlug);
        if ($board === null) {
            return Response::redirect('/?quick=bord');
        }
        $topicId = $this->forum->createTopic((int) $board['id'], $userId, $title, $content);
        $topic   = $this->forum->findTopicById($topicId);

        return Response::redirect("/forum/{$board['slug']}/{$topic['slug']}");
    }
}
