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

namespace CommunityFusion\Modules\News;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

final class NewsRepository
{
    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function getPublished(int $limit = 10, int $offset = 0): array
    {
        return $this->cache->remember("news.published.{$limit}.{$offset}", 300, function() use ($limit, $offset) {
            return $this->db->fetchAll(
                "SELECT n.*, u.username, u.display_name, u.avatar_url
                 FROM cf_news n
                 JOIN cf_users u ON u.id = n.author_id
                 WHERE n.status = 'published' AND n.deleted_at IS NULL
                 ORDER BY n.is_sticky DESC, n.published_at DESC
                 LIMIT ? OFFSET ?",
                [$limit, $offset]
            );
        });
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->cache->remember("news.slug.{$slug}", 600, function() use ($slug) {
            return $this->db->fetchOne(
                "SELECT n.*, u.username, u.display_name, u.avatar_url
                 FROM cf_news n
                 JOIN cf_users u ON u.id = n.author_id
                 WHERE n.slug = ? AND n.status = 'published' AND n.deleted_at IS NULL",
                [$slug]
            );
        });
    }

    public function create(array $data): int|string
    {
        $id = $this->db->insert('news', $data);
        $this->cache->clear();
        return $id;
    }

    public function incrementViews(int $id): void
    {
        $this->db->execute("UPDATE cf_news SET views = views + 1 WHERE id = ?", [$id]);
    }

    public function countPublished(): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) as count FROM cf_news WHERE status = 'published' AND deleted_at IS NULL"
        );
        return (int) ($row['count'] ?? 0);
    }

    // ── Admin CRUD (Wave 2 — /admin/news bestond niet, dashboard.php linkte
    //    er wel al sinds Sprint 2 naartoe) ──────────────────────────────────

    /** Alle niet-verwijderde artikelen, ook drafts/archived — voor het admin-overzicht. */
    public function getAll(int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT n.*, u.username, u.display_name
             FROM cf_news n
             JOIN cf_users u ON u.id = n.author_id
             WHERE n.deleted_at IS NULL
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function countAll(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) as count FROM cf_news WHERE deleted_at IS NULL");
        return (int) ($row['count'] ?? 0);
    }

    /** Zoals findBySlug(), maar zonder de 'published'-restrictie — voor bewerken van drafts. */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT n.*, u.username, u.display_name
             FROM cf_news n JOIN cf_users u ON u.id = n.author_id
             WHERE n.id = ? AND n.deleted_at IS NULL",
            [$id]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql  = "SELECT id FROM cf_news WHERE slug = ? AND deleted_at IS NULL";
        $bind = [$slug];
        if ($exceptId !== null) {
            $sql   .= " AND id != ?";
            $bind[] = $exceptId;
        }
        return $this->db->fetchOne($sql, $bind) !== null;
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('news', $data, 'id = ?', [$id]);
        $this->cache->clear();
    }

    /** Soft delete — consistent met de rest van de content-modules. */
    public function delete(int $id): void
    {
        $this->db->update('news', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $this->cache->clear();
    }

    /** Zelfde patroon als BlogRepository/ForumRepository/DownloadsRepository. */
    public function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'artikel';
        $base = trim($base, '-');
        $base = substr($base, 0, 190) ?: 'artikel';

        $candidate = $base;
        $attempt   = 0;
        while ($this->slugExists($candidate, $exceptId)) {
            $attempt++;
            $candidate = substr($base, 0, 190 - 6) . '-' . bin2hex(random_bytes(2));
            if ($attempt > 10) {
                throw new \RuntimeException('Kon geen unieke nieuws-slug genereren.');
            }
        }

        return $candidate;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : NewsRepository.php                                   ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : Nieuws CRUD + cache-aside                            ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
