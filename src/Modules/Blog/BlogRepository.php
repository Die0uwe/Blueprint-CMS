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

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * BlogRepository
 *
 * Elk lid heeft zijn eigen blog: posts zijn uniek per (author_id, slug),
 * niet globaal — twee leden mogen onafhankelijk allebei een post
 * "hello-world" hebben. URL's zijn daarom altijd /blog/{username}/{slug}.
 */
final class BlogRepository
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function perPage(): int { return self::PER_PAGE; }

    /** Alle gepubliceerde posts, van alle leden — de openbare blog-index. */
    public function getPublished(int $limit, int $offset): array
    {
        return $this->cache->remember("blog.published.{$limit}.{$offset}", 300, function () use ($limit, $offset) {
            return $this->db->fetchAll(
                "SELECT b.*, u.username, u.display_name, u.avatar_url
                 FROM cf_blog_posts b
                 JOIN cf_users u ON u.id = b.author_id
                 WHERE b.status = 'published' AND b.deleted_at IS NULL
                 ORDER BY b.published_at DESC
                 LIMIT ? OFFSET ?",
                [$limit, $offset]
            );
        });
    }

    public function countPublished(): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_blog_posts WHERE status = 'published' AND deleted_at IS NULL"
        );
        return (int) ($row['count'] ?? 0);
    }

    /** Alle posts van één auteur — gepubliceerd voor bezoekers, + drafts als het de eigenaar zelf is. */
    public function getByAuthor(int $authorId, bool $includeDrafts): array
    {
        $statusFilter = $includeDrafts ? '' : "AND b.status = 'published'";

        return $this->db->fetchAll(
            "SELECT b.*, u.username, u.display_name, u.avatar_url
             FROM cf_blog_posts b
             JOIN cf_users u ON u.id = b.author_id
             WHERE b.author_id = ? AND b.deleted_at IS NULL {$statusFilter}
             ORDER BY b.created_at DESC",
            [$authorId]
        );
    }

    public function findByAuthorAndSlug(int $authorId, string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT b.*, u.username, u.display_name, u.avatar_url
             FROM cf_blog_posts b
             JOIN cf_users u ON u.id = b.author_id
             WHERE b.author_id = ? AND b.slug = ? AND b.deleted_at IS NULL",
            [$authorId, $slug]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_blog_posts WHERE id = ? AND deleted_at IS NULL", [$id]);
    }

    public function create(int $authorId, string $title, string $summary, string $content, string $status): int
    {
        $slug = $this->uniqueSlug($authorId, $title);

        return (int) $this->db->insert('blog_posts', [
            'author_id'    => $authorId,
            'slug'         => $slug,
            'title'        => trim($title),
            'summary'      => trim($summary) !== '' ? trim($summary) : null,
            'content'      => $content,
            'status'       => $status,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function update(int $id, string $title, string $summary, string $content, string $status): void
    {
        $current = $this->findById($id);
        if ($current === null) return;

        // Publiceerdatum alleen zetten bij de OVERGANG draft → published,
        // zodat een al gepubliceerde post niet telkens "opnieuw" gepubliceerd lijkt.
        $publishedAt = $current['published_at'];
        if ($status === 'published' && $current['status'] !== 'published') {
            $publishedAt = date('Y-m-d H:i:s');
        }

        $this->db->update('blog_posts', [
            'title'        => trim($title),
            'summary'      => trim($summary) !== '' ? trim($summary) : null,
            'content'      => $content,
            'status'       => $status,
            'published_at' => $publishedAt,
        ], 'id = ?', [$id]);

        $this->cache->clear();
    }

    /** Alleen de inhoud bijwerken (bericht-editor). Titel, samenvatting en status blijven ongemoeid. */
    public function updateContent(int $id, string $content): void
    {
        $this->db->update('blog_posts', ['content' => $content], 'id = ?', [$id]);
        $this->cache->clear();
    }

    public function incrementViews(int $id): void
    {
        $this->db->execute("UPDATE cf_blog_posts SET views = views + 1 WHERE id = ?", [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute("UPDATE cf_blog_posts SET deleted_at = NOW() WHERE id = ?", [$id]);
        $this->cache->clear();
    }

    private function uniqueSlug(int $authorId, string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'post';
        $base = trim($base, '-');
        $base = substr($base, 0, 180) ?: 'post';

        $candidate = $base;
        $attempt   = 0;
        while ($this->findByAuthorAndSlug($authorId, $candidate) !== null) {
            $attempt++;
            $candidate = substr($base, 0, 180 - 6) . '-' . bin2hex(random_bytes(2));
            if ($attempt > 10) {
                throw new \RuntimeException('Kon geen unieke blog-slug genereren.');
            }
        }

        return $candidate;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : BlogRepository.php                                   ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Blog core-module)                      ║
// ║  Notes        : Uniek per (author_id, slug) — elk lid heeft z'n eigen║
// ║                 blog, geen globale slug-botsingen tussen leden       ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
