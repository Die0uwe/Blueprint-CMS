<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

/**
 * GalleryDeduplicator — zaait de hoofdcategorieën van de galerij-taxonomie en
 * voegt dubbele albums samen.
 *
 * Dubbel = zelfde parent + zelfde genormaliseerde naam ("3D Art", "3d-art" en
 * "3D_ART" zijn hetzelfde). Vóór deze versie maakte GalleryAdminController
 * bij een bezette slug stilletjes een tweede album met een willekeurig
 * achtervoegsel aan ("3d-art-a1b2"), waardoor dubbele categorieën ontstonden.
 *
 * Gebruikt portable SQL (PDO) zodat dezelfde code in de migratie (MariaDB) en
 * in de unit-tests (SQLite) draait. Idempotent: een tweede run doet niets.
 */
final class GalleryDeduplicator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $prefix = 'cf_',
    ) {}

    /**
     * Zaait ontbrekende hoofdcategorieën en voegt duplicaten samen.
     *
     * @return array{seeded:string[], merged:array<int,array{kept:int, removed:int[], items_moved:int}>}
     */
    public function run(): array
    {
        return [
            'seeded' => $this->seedMainCategories(),
            'merged' => $this->mergeDuplicates(),
        ];
    }

    /** @return string[] slugs van nieuw aangemaakte hoofdcategorieën */
    public function seedMainCategories(): array
    {
        $cats = $this->prefix . 'categories';
        $rows = $this->pdo->query("SELECT slug, name, parent_id FROM `{$cats}` WHERE type = 'gallery'")->fetchAll(\PDO::FETCH_ASSOC);

        $takenSlugs = [];
        $topNames   = [];
        foreach ($rows as $r) {
            $takenSlugs[(string) $r['slug']] = true;
            if ($r['parent_id'] === null) {
                $topNames[GalleryTaxonomy::normalizeName((string) $r['name'])] = true;
            }
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO `{$cats}` (type, slug, name, description, position) VALUES ('gallery', ?, ?, ?, ?)"
        );

        $seeded = [];
        foreach (GalleryTaxonomy::MAIN as $slug => $def) {
            if (isset($takenSlugs[$slug]) || isset($topNames[GalleryTaxonomy::normalizeName($def['name'])])) {
                continue;
            }
            $insert->execute([$slug, $def['name'], $def['description'], $def['position']]);
            $seeded[] = $slug;
        }
        return $seeded;
    }

    /** @return array<int,array{kept:int, removed:int[], items_moved:int}> */
    public function mergeDuplicates(): array
    {
        $cats  = $this->prefix . 'categories';
        $items = $this->prefix . 'gallery_items';

        $rows = $this->pdo->query(
            "SELECT id, parent_id, name FROM `{$cats}` WHERE type = 'gallery' ORDER BY id ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $r) {
            $key = ($r['parent_id'] ?? 'top') . '|' . GalleryTaxonomy::normalizeName((string) $r['name']);
            $groups[$key][] = (int) $r['id'];
        }

        $report = [];
        foreach ($groups as $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $kept    = array_shift($ids);          // oudste (laagste id) blijft
            $removed = $ids;
            $in      = implode(',', array_map('intval', $removed));

            $count = $this->pdo->query("SELECT COUNT(*) FROM `{$items}` WHERE album_id IN ({$in})");
            $moved = $count !== false ? (int) $count->fetchColumn() : 0;

            $this->pdo->exec("UPDATE `{$items}` SET album_id = " . (int) $kept . " WHERE album_id IN ({$in})");
            // Subalbums van een samengevoegd duplicaat verhuizen mee naar het behouden album.
            $this->pdo->exec("UPDATE `{$cats}` SET parent_id = " . (int) $kept . " WHERE type = 'gallery' AND parent_id IN ({$in})");
            $this->pdo->exec("DELETE FROM `{$cats}` WHERE type = 'gallery' AND id IN ({$in})");

            $report[] = ['kept' => $kept, 'removed' => $removed, 'items_moved' => $moved];
        }

        // Verhuisde subalbums kunnen nu zelf dubbel zijn onder het behouden album: nog één ronde.
        if ($report !== []) {
            array_push($report, ...$this->mergeDuplicates());
        }
        return $report;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryDeduplicator.php | Role: Data | Version: 1.0.0        ║
// ║  Created: 2026-10-10 | Status: New — Galerij-taxonomie              ║
// ╚══════════════════════════════════════════════════════════════════════╝
