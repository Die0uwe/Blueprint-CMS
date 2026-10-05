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

namespace CommunityFusion\Modules\Pages;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

final class PageRepository
{
    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function findBySlug(string $slug): ?array
    {
        return $this->cache->remember("page.{$slug}", 900, function() use ($slug) {
            return $this->db->fetchOne(
                "SELECT * FROM cf_pages WHERE slug = ? AND status = 'published' AND deleted_at IS NULL",
                [$slug]
            );
        });
    }

    public function getMenuPages(): array
    {
        return $this->cache->remember('pages.menu', 900, function() {
            return $this->db->fetchAll(
                "SELECT id, slug, title, menu_position FROM cf_pages
                 WHERE status = 'published' AND menu_position IS NOT NULL AND deleted_at IS NULL
                 ORDER BY menu_position ASC"
            );
        });
    }

    // ── Menubeheer (Wave 5 — /admin/menus. De ruwe mechaniek (menu_position
    //    op cf_pages) bestond al sinds Sprint 2 en was al bewerkbaar via het
    //    getal-invoerveld in /admin/pages/{id}/bewerk — maar een pagina aan
    //    het menu toevoegen/verwijderen of herordenen betekende zelf een
    //    vrij positienummer verzinnen en hopen dat het klopt. Dit scherm zet
    //    er knoppen op i.p.v. een nieuw opslagmechanisme te verzinnen.) ──

    /**
     * Alle gepubliceerde pagina's, menu-pagina's eerst (op positie), dan de
     * rest alfabetisch — precies de twee groepen die het menu-scherm toont.
     */
    public function getPublishedPagesForMenuScreen(): array
    {
        return $this->db->fetchAll(
            "SELECT id, slug, title, menu_position FROM cf_pages
             WHERE status = 'published' AND deleted_at IS NULL
             ORDER BY (menu_position IS NULL) ASC, menu_position ASC, title ASC"
        );
    }

    public function addToMenu(int $id): void
    {
        $row  = $this->db->fetchOne("SELECT COALESCE(MAX(menu_position), -1) + 1 AS next_pos FROM cf_pages WHERE menu_position IS NOT NULL");
        $next = (int) ($row['next_pos'] ?? 0);
        $this->db->update('pages', ['menu_position' => $next], 'id = ?', [$id]);
        $this->cache->clear();
    }

    public function removeFromMenu(int $id): void
    {
        $this->db->update('pages', ['menu_position' => null], 'id = ?', [$id]);
        $this->cache->clear();
    }

    /**
     * Wissel de positie van deze menu-pagina met haar directe buur
     * ($direction: -1 = omhoog, +1 = omlaag). Geen effect aan de randen.
     */
    public function swapMenuPosition(int $id, int $direction): void
    {
        $current = $this->db->fetchOne("SELECT id, menu_position FROM cf_pages WHERE id = ? AND menu_position IS NOT NULL", [$id]);
        if ($current === null) return;

        $neighbor = $direction < 0
            ? $this->db->fetchOne("SELECT id, menu_position FROM cf_pages WHERE menu_position < ? AND menu_position IS NOT NULL ORDER BY menu_position DESC LIMIT 1", [$current['menu_position']])
            : $this->db->fetchOne("SELECT id, menu_position FROM cf_pages WHERE menu_position > ? AND menu_position IS NOT NULL ORDER BY menu_position ASC LIMIT 1", [$current['menu_position']]);

        if ($neighbor === null) return;

        $this->db->transaction(function (Connection $db) use ($current, $neighbor) {
            $db->update('pages', ['menu_position' => $neighbor['menu_position']], 'id = ?', [$current['id']]);
            $db->update('pages', ['menu_position' => $current['menu_position']], 'id = ?', [$neighbor['id']]);
        });
        $this->cache->clear();
    }

    // ── Admin CRUD (Wave 2 — /admin/pages bestond niet, dashboard.php linkte
    //    er wel al sinds Sprint 2 naartoe; zelfde patroon als NewsRepository) ──

    public function create(array $data): int|string
    {
        $id = $this->db->insert('pages', $data);
        $this->cache->clear();
        return $id;
    }

    /** Alle niet-verwijderde pagina's, ook drafts — voor het admin-overzicht. */
    public function getAll(int $limit = 20, int $offset = 0): array
    {
        return $this->db->fetchAll(
            "SELECT p.*, u.username, u.display_name
             FROM cf_pages p
             JOIN cf_users u ON u.id = p.author_id
             WHERE p.deleted_at IS NULL
             ORDER BY p.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function countAll(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) as count FROM cf_pages WHERE deleted_at IS NULL");
        return (int) ($row['count'] ?? 0);
    }

    /** Zoals findBySlug(), maar zonder de 'published'-restrictie — voor bewerken van drafts. */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT p.*, u.username, u.display_name
             FROM cf_pages p JOIN cf_users u ON u.id = p.author_id
             WHERE p.id = ? AND p.deleted_at IS NULL",
            [$id]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $sql  = "SELECT id FROM cf_pages WHERE slug = ?"; // ook soft-verwijderde rijen: uq_slug geldt voor alle rijen
        $bind = [$slug];
        if ($exceptId !== null) {
            $sql   .= " AND id != ?";
            $bind[] = $exceptId;
        }
        return $this->db->fetchOne($sql, $bind) !== null;
    }

    public function update(int $id, array $data): void
    {
        $this->db->update('pages', $data, 'id = ?', [$id]);
        $this->cache->clear();
    }

    /** Soft delete — consistent met de rest van de content-modules. */
    public function delete(int $id): void
    {
        $this->db->update('pages', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        $this->cache->clear();
    }

    /** Zelfde patroon als NewsRepository::uniqueSlug(). */
    public function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'pagina';
        $base = trim($base, '-');
        $base = substr($base, 0, 190) ?: 'pagina';

        $candidate = $base;
        $attempt   = 0;
        while ($this->slugExists($candidate, $exceptId)) {
            $attempt++;
            $candidate = substr($base, 0, 190 - 6) . '-' . bin2hex(random_bytes(2));
            if ($attempt > 10) {
                throw new \RuntimeException('Kon geen unieke pagina-slug genereren.');
            }
        }

        return $candidate;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : PageRepository.php                                   ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : Pagina ophalen + menu                                ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
