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

    // ── Categoriebeheer (Wave 9 — /admin/news/categories bestond niet; het
    //    identieke gat dat Wave 4 voor Forumborden dichtte, bestond hier ook:
    //    een News-categorie aanmaken/hernoemen/herordenen/verwijderen kon
    //    alleen rechtstreeks in cf_categories(type=news) ─────────────────────
    //
    //    Cascade-keuze bij verwijderen: in tegenstelling tot
    //    cf_forum_topics.board_id (ON DELETE CASCADE — vandaar de blokkade in
    //    BoardAdminController::delete() zolang er nog topics zijn) staat
    //    cf_news.category_id op ON DELETE SET NULL (zie schema.sql,
    //    fk_news_category). Een categorie verwijderen verwijdert dus nooit
    //    artikelen — die blijven gewoon bestaan, alleen ontkoppeld
    //    ("categorieloos"). Omdat dat non-destructief is, blokkeert
    //    deleteCategory() hieronder — anders dan deleteBoard() — het
    //    verwijderen niet, maar de admin-UI toont wel hoeveel artikelen
    //    ontkoppeld raken zodat dat geen verrassing is. ─────────────────────

    /** Alle News-categorieën met live artikeltelling, voor het admin-overzicht. */
    public function getAllCategoriesForAdmin(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM cf_news n WHERE n.category_id = c.id AND n.deleted_at IS NULL) AS article_count
             FROM cf_categories c
             WHERE c.type = 'news'
             ORDER BY c.position ASC, c.name ASC"
        );
    }

    public function findCategoryById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_categories WHERE type = 'news' AND id = ?",
            [$id]
        );
    }

    public function categorySlugTaken(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $row = $this->db->fetchOne(
                "SELECT id FROM cf_categories WHERE type = 'news' AND slug = ? AND id != ?",
                [$slug, $exceptId]
            );
        } else {
            $row = $this->db->fetchOne(
                "SELECT id FROM cf_categories WHERE type = 'news' AND slug = ?",
                [$slug]
            );
        }
        return $row !== null;
    }

    /** Aantal (niet-verwijderde) artikelen in een categorie — voor de verwijder-waarschuwing. */
    public function countArticlesInCategory(int $categoryId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_news WHERE category_id = ? AND deleted_at IS NULL",
            [$categoryId]
        );
        return (int) ($row['count'] ?? 0);
    }

    public function createCategory(string $slug, string $name, string $description, int $position, ?int $parentId): int
    {
        $id = $this->db->insert('categories', [
            'type'        => 'news',
            'parent_id'   => $parentId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ]);
        $this->cache->clear();
        return (int) $id;
    }

    public function updateCategory(int $id, string $slug, string $name, string $description, int $position, ?int $parentId): void
    {
        $this->db->update('categories', [
            'parent_id'   => $parentId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ], 'id = ? AND type = ?', [$id, 'news']);
        $this->cache->clear();
    }

    /**
     * Verwijdert een categorie. Zie de uitleg bovenaan deze sectie: artikelen
     * in deze categorie worden NIET verwijderd — cf_news.category_id staat op
     * ON DELETE SET NULL, dus de database ontkoppelt ze automatisch.
     */
    public function deleteCategory(int $id): void
    {
        $this->db->delete('categories', 'id = ? AND type = ?', [$id, 'news']);
        $this->cache->clear();
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : NewsRepository.php                                   ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.1.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-09-29  Wave 9 — categoriebeheer (admin CRUD)     ║
// ║  Status       : New                                                  ║
// ║  Notes        : Nieuws CRUD + cache-aside; categorieën = cf_categories║
// ║                 (type=news), FK ON DELETE SET NULL (zie hierboven)   ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
