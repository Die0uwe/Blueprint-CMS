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

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * ForumRepository
 *
 * Borden zijn `cf_categories` met `type = 'forum'` — de gedeelde
 * categorieën-tabel uit SD §3.5, dezelfde tabel als News gebruikt voor
 * zijn categorieën. Topics/posts krijgen eigen tabellen (cf_forum_topics,
 * cf_forum_posts) omdat die, anders dan een categorie, eigen auteur-,
 * status- en telvelden nodig hebben.
 */
final class ForumRepository
{
    private const TOPICS_PER_PAGE = 20;
    private const POSTS_PER_PAGE  = 15;

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function topicsPerPage(): int { return self::TOPICS_PER_PAGE; }
    public function postsPerPage(): int  { return self::POSTS_PER_PAGE; }

    // ─── BORDEN ─────────────────────────────────────────────────────────────

    /**
     * Alle forumborden met live topic-/post-tellingen en het laatste bericht.
     */
    public function getBoards(): array
    {
        return $this->cache->remember('forum.boards', 60, function () {
            return $this->db->fetchAll(
                "SELECT c.id, c.slug, c.name, c.description, c.position,
                        COUNT(DISTINCT t.id)                              AS topic_count,
                        COALESCE(SUM(t.reply_count), 0) + COUNT(DISTINCT t.id) AS post_count,
                        MAX(t.last_post_at)                               AS last_post_at,
                        (SELECT lt.title FROM cf_forum_topics lt
                          WHERE lt.board_id = c.id AND lt.deleted_at IS NULL
                          ORDER BY lt.last_post_at DESC LIMIT 1)          AS last_topic_title,
                        (SELECT lt.slug FROM cf_forum_topics lt
                          WHERE lt.board_id = c.id AND lt.deleted_at IS NULL
                          ORDER BY lt.last_post_at DESC LIMIT 1)          AS last_topic_slug,
                        (SELECT u.username FROM cf_forum_topics lt
                          JOIN cf_users u ON u.id = lt.last_post_user_id
                          WHERE lt.board_id = c.id AND lt.deleted_at IS NULL
                          ORDER BY lt.last_post_at DESC LIMIT 1)          AS last_post_username
                 FROM cf_categories c
                 LEFT JOIN cf_forum_topics t ON t.board_id = c.id AND t.deleted_at IS NULL
                 WHERE c.type = 'forum'
                 GROUP BY c.id, c.slug, c.name, c.description, c.position
                 ORDER BY c.position ASC, c.name ASC"
            );
        });
    }

    public function findBoardBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_categories WHERE type = 'forum' AND slug = ?",
            [$slug]
        );
    }

    // ─── BORDBEHEER (admin — Wave 4) ────────────────────────────────────────
    // De publieke getBoards() hierboven levert live tellingen voor de
    // forumindex; deze admin-variant is lichter (geen tellingen/cache) en
    // toont ook borden zonder topics, voor het /admin/forum/boards-scherm.

    public function getAllBoardsForAdmin(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, COUNT(t.id) AS topic_count
             FROM cf_categories c
             LEFT JOIN cf_forum_topics t ON t.board_id = c.id AND t.deleted_at IS NULL
             WHERE c.type = 'forum'
             GROUP BY c.id
             ORDER BY c.position ASC, c.name ASC"
        );
    }

    public function findBoardById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_categories WHERE type = 'forum' AND id = ?",
            [$id]
        );
    }

    public function boardSlugTaken(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $row = $this->db->fetchOne(
                "SELECT id FROM cf_categories WHERE type = 'forum' AND slug = ? AND id != ?",
                [$slug, $exceptId]
            );
        } else {
            $row = $this->db->fetchOne(
                "SELECT id FROM cf_categories WHERE type = 'forum' AND slug = ?",
                [$slug]
            );
        }
        return $row !== null;
    }

    public function createBoard(string $slug, string $name, string $description, int $position): int
    {
        $id = $this->db->insert('categories', [
            'type'        => 'forum',
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ]);
        $this->cache->delete('forum.boards');
        return (int) $id;
    }

    public function updateBoard(int $id, string $slug, string $name, string $description, int $position): void
    {
        $this->db->update('categories', [
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ], 'id = ? AND type = ?', [$id, 'forum']);
        $this->cache->delete('forum.boards');
    }

    /**
     * Verwijdert een bord alleen als het geen (niet-verwijderde) topics meer
     * bevat — de FK cf_forum_topics.board_id staat ON DELETE CASCADE, dus
     * zonder deze check zou een bord verwijderen ook stilzwijgend alle
     * topics + reacties erin meenemen.
     */
    public function deleteBoard(int $id): void
    {
        $this->db->delete('categories', 'id = ? AND type = ?', [$id, 'forum']);
        $this->cache->delete('forum.boards');
    }

    // ─── TOPICS ─────────────────────────────────────────────────────────────

    public function getTopics(int $boardId, int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT t.*, u.username, u.display_name, u.avatar_url,
                    lu.username AS last_post_username
             FROM cf_forum_topics t
             JOIN cf_users u ON u.id = t.author_id
             LEFT JOIN cf_users lu ON lu.id = t.last_post_user_id
             WHERE t.board_id = ? AND t.deleted_at IS NULL
             ORDER BY t.is_pinned DESC, t.last_post_at DESC, t.created_at DESC
             LIMIT ? OFFSET ?",
            [$boardId, $limit, $offset]
        );
    }

    public function countTopics(int $boardId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_forum_topics WHERE board_id = ? AND deleted_at IS NULL",
            [$boardId]
        );
        return (int) ($row['count'] ?? 0);
    }

    public function findTopicBySlug(int $boardId, string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT t.*, u.username, u.display_name, u.avatar_url
             FROM cf_forum_topics t
             JOIN cf_users u ON u.id = t.author_id
             WHERE t.board_id = ? AND t.slug = ? AND t.deleted_at IS NULL",
            [$boardId, $slug]
        );
    }

    public function findTopicById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_forum_topics WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Maak een nieuw topic aan mét het eerste bericht, in één transactie —
     * een topic zonder post (of andersom) mag nooit blijven bestaan.
     *
     * @return int Het nieuwe topic-ID
     */
    public function createTopic(int $boardId, int $authorId, string $title, string $content): int
    {
        return $this->db->transaction(function (Connection $db) use ($boardId, $authorId, $title, $content) {
            $slug = $this->uniqueTopicSlug($boardId, $title);

            $topicId = $db->insert('forum_topics', [
                'board_id'   => $boardId,
                'author_id'  => $authorId,
                'slug'       => $slug,
                'title'      => trim($title),
                'reply_count' => 0,
            ]);

            $postId = $db->insert('forum_posts', [
                'topic_id'      => $topicId,
                'author_id'     => $authorId,
                'content'       => $content,
                'is_first_post' => 1,
            ]);

            $db->execute(
                "UPDATE cf_forum_topics SET last_post_id = ?, last_post_at = NOW(), last_post_user_id = ? WHERE id = ?",
                [$postId, $authorId, $topicId]
            );

            $this->cache->delete('forum.boards');

            return (int) $topicId;
        });
    }

    public function incrementViews(int $topicId): void
    {
        $this->db->execute("UPDATE cf_forum_topics SET views = views + 1 WHERE id = ?", [$topicId]);
    }

    public function setPinned(int $topicId, bool $pinned): void
    {
        $this->db->execute("UPDATE cf_forum_topics SET is_pinned = ? WHERE id = ?", [$pinned ? 1 : 0, $topicId]);
    }

    public function setLocked(int $topicId, bool $locked): void
    {
        $this->db->execute("UPDATE cf_forum_topics SET is_locked = ? WHERE id = ?", [$locked ? 1 : 0, $topicId]);
    }

    public function deleteTopic(int $topicId): void
    {
        $this->db->execute("UPDATE cf_forum_topics SET deleted_at = NOW() WHERE id = ?", [$topicId]);
        $this->cache->delete('forum.boards');
    }

    // ─── POSTS (REACTIES) ───────────────────────────────────────────────────

    public function getPosts(int $topicId, int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT p.*, u.username, u.display_name, u.avatar_url
             FROM cf_forum_posts p
             JOIN cf_users u ON u.id = p.author_id
             WHERE p.topic_id = ? AND p.deleted_at IS NULL
             ORDER BY p.created_at ASC
             LIMIT ? OFFSET ?",
            [$topicId, $limit, $offset]
        );
    }

    public function countPosts(int $topicId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_forum_posts WHERE topic_id = ? AND deleted_at IS NULL",
            [$topicId]
        );
        return (int) ($row['count'] ?? 0);
    }

    public function findPostById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_forum_posts WHERE id = ? AND deleted_at IS NULL", [$id]);
    }

    /**
     * Voeg een reactie toe aan een topic en werk de topic-tellers/laatste-
     * bericht-cache bij, in één transactie.
     *
     * @return int Het nieuwe post-ID
     */
    public function createPost(int $topicId, int $authorId, string $content): int
    {
        return $this->db->transaction(function (Connection $db) use ($topicId, $authorId, $content) {
            $postId = $db->insert('forum_posts', [
                'topic_id'  => $topicId,
                'author_id' => $authorId,
                'content'   => $content,
            ]);

            $db->execute(
                "UPDATE cf_forum_topics
                 SET reply_count = reply_count + 1, last_post_id = ?, last_post_at = NOW(), last_post_user_id = ?
                 WHERE id = ?",
                [$postId, $authorId, $topicId]
            );

            $this->cache->delete('forum.boards');

            return (int) $postId;
        });
    }

    public function deletePost(int $postId): void
    {
        $this->db->execute("UPDATE cf_forum_posts SET deleted_at = NOW() WHERE id = ?", [$postId]);
    }

    // ─── HELPERS ────────────────────────────────────────────────────────────

    private function uniqueTopicSlug(int $boardId, string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'topic';
        $base = trim($base, '-');
        $base = substr($base, 0, 180) ?: 'topic';

        $candidate = $base;
        $attempt   = 0;
        while ($this->findTopicBySlug($boardId, $candidate) !== null) {
            $attempt++;
            $candidate = substr($base, 0, 180 - 6) . '-' . bin2hex(random_bytes(2));
            if ($attempt > 10) {
                throw new \RuntimeException('Kon geen unieke topic-slug genereren.');
            }
        }

        return $candidate;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : ForumRepository.php                                  ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Forum core-module)                     ║
// ║  Notes        : Borden = cf_categories(type=forum); topics/posts     ║
// ║                 eigen tabellen; teller-updates in transacties        ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
