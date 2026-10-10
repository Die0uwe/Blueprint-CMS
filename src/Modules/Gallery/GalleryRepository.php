<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

/**
 * GalleryRepository — S11 (Media-galerij).
 *
 * Albums zijn `cf_categories` met `type = 'gallery'` — hetzelfde
 * hergebruikpatroon als Forumborden (type=forum) en News-categorieën
 * (type=news), zie ForumRepository. Items krijgen een eigen tabel
 * (cf_gallery_items) omdat ze, anders dan een categorie, eigen
 * bestands-/type-/auteurmetadata nodig hebben.
 */
final class GalleryRepository
{
    private const ITEMS_PER_PAGE = 24;

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    /** null = nog niet gecontroleerd. */
    private ?bool $taxonomy = null;

    /** Wist de galerij-cache (albumhoezen, laatste-items-blok). */
    public function flushCache(): void
    {
        $this->cache->clear();
    }

    public function itemsPerPage(): int { return self::ITEMS_PER_PAGE; }

    /**
     * Bestaan de kolommen `style`/`tags` al? Pas na migratie 20261010_01 (of de meegeleverde
     * phpMyAdmin-SQL). Tot die tijd werkt de galerij ongewijzigd verder, zonder stijl/tags.
     */
    public function supportsTaxonomy(): bool
    {
        if ($this->taxonomy === null) {
            try {
                $this->db->fetchOne("SELECT style, tags FROM cf_gallery_items LIMIT 1");
                $this->taxonomy = true;
            } catch (\Throwable) {
                $this->taxonomy = false;
            }
        }
        return $this->taxonomy;
    }

    // ─── ALBUMS (publiek) ───────────────────────────────────────────────────

    /**
     * Alle albums met live item-telling en een omslagminiatuur (het nieuwste
     * gepubliceerde item), voor de publieke albumindex.
     */
    public function getAlbums(): array
    {
        return $this->cache->remember('gallery.albums', 60, function () {
            $scope = "gi.album_id IN (SELECT x.id FROM cf_categories x WHERE x.type = 'gallery' AND (x.id = c.id OR x.parent_id = c.id))";
            return $this->db->fetchAll(
                "SELECT c.id, c.slug, c.name, c.description, c.position,
                        (SELECT COUNT(*) FROM cf_gallery_items gi
                          WHERE {$scope} AND gi.is_published = 1 AND gi.deleted_at IS NULL) AS item_count,
                        (SELECT COUNT(*) FROM cf_categories s WHERE s.type = 'gallery' AND s.parent_id = c.id) AS subalbum_count,
                        (SELECT COALESCE(gi.thumbnail_path, CASE WHEN gi.media_type = 'image' THEN gi.file_path END) FROM cf_gallery_items gi
                          WHERE {$scope} AND gi.is_published = 1 AND gi.deleted_at IS NULL
                          ORDER BY gi.created_at DESC LIMIT 1) AS cover_thumbnail,
                        (SELECT gi.media_type FROM cf_gallery_items gi
                          WHERE {$scope} AND gi.is_published = 1 AND gi.deleted_at IS NULL
                          ORDER BY gi.created_at DESC LIMIT 1) AS cover_media_type
                 FROM cf_categories c
                 WHERE c.type = 'gallery' AND c.parent_id IS NULL
                 ORDER BY c.position ASC, c.name ASC"
            );
        });
    }

    /** Directe subalbums van een album, met item-telling en omslag (publiek). */
    public function getSubalbums(int $parentId): array
    {
        return $this->db->fetchAll(
            "SELECT c.id, c.slug, c.name, c.description, c.position,
                    (SELECT COUNT(*) FROM cf_gallery_items gi
                      WHERE gi.album_id = c.id AND gi.is_published = 1 AND gi.deleted_at IS NULL) AS item_count,
                    (SELECT COALESCE(gi.thumbnail_path, CASE WHEN gi.media_type = 'image' THEN gi.file_path END) FROM cf_gallery_items gi
                      WHERE gi.album_id = c.id AND gi.is_published = 1 AND gi.deleted_at IS NULL
                      ORDER BY gi.created_at DESC LIMIT 1) AS cover_thumbnail,
                    (SELECT gi.media_type FROM cf_gallery_items gi
                      WHERE gi.album_id = c.id AND gi.is_published = 1 AND gi.deleted_at IS NULL
                      ORDER BY gi.created_at DESC LIMIT 1) AS cover_media_type
             FROM cf_categories c
             WHERE c.type = 'gallery' AND c.parent_id = ?
             ORDER BY c.position ASC, c.name ASC",
            [$parentId]
        );
    }

    public function findAlbumBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_categories WHERE type = 'gallery' AND slug = ?", [$slug]);
    }

    // ─── ALBUMS (admin — S11) ───────────────────────────────────────────────

    /**
     * Alle albums als boom (hoofdcategorie gevolgd door zijn subalbums), met `depth`
     * (0/1) en `parent_name`. Items in subalbums tellen niet mee in de ouder.
     */
    public function getAllAlbumsForAdmin(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM cf_gallery_items i WHERE i.album_id = c.id AND i.deleted_at IS NULL) AS item_count,
                    (SELECT COUNT(*) FROM cf_categories s WHERE s.type = 'gallery' AND s.parent_id = c.id) AS subalbum_count
             FROM cf_categories c
             WHERE c.type = 'gallery'
             ORDER BY c.position ASC, c.name ASC"
        );

        $children = [];
        $tops     = [];
        $names    = [];
        foreach ($rows as $r) {
            $names[(int) $r['id']] = (string) $r['name'];
            if ($r['parent_id'] === null) {
                $tops[] = $r;
            } else {
                $children[(int) $r['parent_id']][] = $r;
            }
        }

        $out = [];
        foreach ($tops as $t) {
            $t['depth'] = 0;
            $t['parent_name'] = null;
            $out[] = $t;
            foreach ($children[(int) $t['id']] ?? [] as $c) {
                $c['depth'] = 1;
                $c['parent_name'] = $names[(int) $t['id']];
                $out[] = $c;
            }
        }
        // Wezen (parent verwijderd buiten de applicatie om) niet verbergen.
        $seen = array_column($out, 'id');
        foreach ($rows as $r) {
            if (!in_array($r['id'], $seen, true)) {
                $r['depth'] = 0;
                $r['parent_name'] = null;
                $out[] = $r;
            }
        }
        return $out;
    }

    /** Top-level albums die als ouder kunnen dienen (max. één niveau subalbums). */
    public function getParentCandidates(?int $exceptId = null): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, slug, name FROM cf_categories WHERE type = 'gallery' AND parent_id IS NULL ORDER BY position ASC, name ASC"
        );
        return array_values(array_filter($rows, static fn(array $r) => $exceptId === null || (int) $r['id'] !== $exceptId));
    }

    public function hasSubalbums(int $id): bool
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_categories WHERE type = 'gallery' AND parent_id = ?", [$id]);
        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * Bestaat er al een album met deze (genormaliseerde) naam onder dezelfde ouder?
     * "3D Art", "3d-art" en "3D_ART" tellen als hetzelfde — dit voorkomt dubbele categorieën.
     */
    public function findAlbumByName(string $name, ?int $parentId, ?int $exceptId = null): ?array
    {
        $needle = GalleryTaxonomy::normalizeName($name);
        foreach ($this->db->fetchAll("SELECT id, parent_id, slug, name FROM cf_categories WHERE type = 'gallery'") as $r) {
            $sameParent = ($r['parent_id'] === null ? null : (int) $r['parent_id']) === $parentId;
            if ($sameParent && (int) $r['id'] !== $exceptId && GalleryTaxonomy::normalizeName((string) $r['name']) === $needle) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Groepen albums die (nog) dubbel zijn: zelfde ouder + genormaliseerde naam.
     *
     * @return array<int,array<int,array{id:int,slug:string,name:string,item_count:int}>>
     */
    public function findDuplicateGroups(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.parent_id, c.slug, c.name,
                    (SELECT COUNT(*) FROM cf_gallery_items i WHERE i.album_id = c.id AND i.deleted_at IS NULL) AS item_count
             FROM cf_categories c WHERE c.type = 'gallery' ORDER BY c.id ASC"
        );
        $groups = [];
        foreach ($rows as $r) {
            $key = ($r['parent_id'] ?? 'top') . '|' . GalleryTaxonomy::normalizeName((string) $r['name']);
            $groups[$key][] = ['id' => (int) $r['id'], 'slug' => (string) $r['slug'], 'name' => (string) $r['name'], 'item_count' => (int) $r['item_count']];
        }
        return array_values(array_filter($groups, static fn(array $g) => count($g) > 1));
    }

    /**
     * Voegt album $fromId samen in $intoId: items en subalbums verhuizen mee,
     * daarna verdwijnt $fromId. Geeft het aantal verplaatste items terug.
     */
    public function mergeAlbums(int $fromId, int $intoId): int
    {
        if ($fromId === $intoId) {
            return 0;
        }
        $row   = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_gallery_items WHERE album_id = ?", [$fromId]);
        $moved = (int) ($row['c'] ?? 0);

        $this->db->execute("UPDATE cf_gallery_items SET album_id = ? WHERE album_id = ?", [$intoId, $fromId]);
        $this->db->execute("UPDATE cf_categories SET parent_id = ? WHERE type = 'gallery' AND parent_id = ?", [$intoId, $fromId]);
        $this->db->delete('categories', 'id = ? AND type = ?', [$fromId, 'gallery']);
        $this->cache->clear();
        return $moved;
    }

    public function findAlbumById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_categories WHERE type = 'gallery' AND id = ?", [$id]);
    }

    public function albumSlugTaken(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId !== null) {
            $row = $this->db->fetchOne(
                "SELECT id FROM cf_categories WHERE type = 'gallery' AND slug = ? AND id != ?",
                [$slug, $exceptId]
            );
        } else {
            $row = $this->db->fetchOne("SELECT id FROM cf_categories WHERE type = 'gallery' AND slug = ?", [$slug]);
        }
        return $row !== null;
    }

    public function createAlbum(string $slug, string $name, string $description, int $position, ?int $parentId = null): int
    {
        $id = $this->db->insert('categories', [
            'type'        => 'gallery',
            'parent_id'   => $parentId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ]);
        $this->cache->clear();   // ook 'laatste items'-blok en albumhoezen
        return (int) $id;
    }

    public function updateAlbum(int $id, string $slug, string $name, string $description, int $position, ?int $parentId = null): void
    {
        $this->db->update('categories', [
            'parent_id'   => $parentId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ], 'id = ? AND type = ?', [$id, 'gallery']);
        $this->cache->clear();
    }

    /**
     * Verwijdert een album alleen als het geen (niet-verwijderde) items meer
     * bevat — GalleryAdminController::delete() checkt dit vooraf
     * (countItemsInAlbum()); de FK cf_gallery_items.album_id staat ON DELETE
     * CASCADE als laatste vangnet, niet als bedoeld pad (zie schema.sql).
     */
    public function deleteAlbum(int $id): void
    {
        $this->db->delete('categories', 'id = ? AND type = ?', [$id, 'gallery']);
        $this->cache->clear();
    }

    // ─── ITEMS (publiek) ────────────────────────────────────────────────────

    public function getPublishedItems(int $albumId, int $limit, int $offset, ?string $style = null, ?string $tag = null): array
    {
        [$where, $params] = $this->itemFilter($albumId, $style, $tag);
        return $this->db->fetchAll(
            "SELECT i.*, u.username, u.display_name
             FROM cf_gallery_items i
             JOIN cf_users u ON u.id = i.author_id
             WHERE {$where}
             ORDER BY i.created_at DESC
             LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );
    }

    public function countPublishedItems(int $albumId, ?string $style = null, ?string $tag = null): int
    {
        [$where, $params] = $this->itemFilter($albumId, $style, $tag);
        $row = $this->db->fetchOne("SELECT COUNT(*) AS count FROM cf_gallery_items i WHERE {$where}", $params);
        return (int) ($row['count'] ?? 0);
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function itemFilter(int $albumId, ?string $style, ?string $tag): array
    {
        $where  = 'i.album_id = ? AND i.is_published = 1 AND i.deleted_at IS NULL';
        $params = [$albumId];
        if ($this->supportsTaxonomy()) {
            if ($style !== null && $style !== '') {
                $where   .= ' AND i.style = ?';
                $params[] = GalleryTaxonomy::slugify($style);
            }
            if ($tag !== null && $tag !== '') {
                $where   .= ' AND i.tags LIKE ?';
                $params[] = '%,' . GalleryTaxonomy::slugify($tag) . ',%';
            }
        }
        return [$where, $params];
    }

    /**
     * Gepubliceerde items per stijl in een album, voor de filterchips.
     *
     * @return array<string,int> stijl-slug => aantal
     */
    public function getStyleCounts(int $albumId): array
    {
        if (!$this->supportsTaxonomy()) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT style, COUNT(*) AS c FROM cf_gallery_items
             WHERE album_id = ? AND is_published = 1 AND deleted_at IS NULL AND style IS NOT NULL AND style <> ''
             GROUP BY style ORDER BY c DESC, style ASC",
            [$albumId]
        ) as $r) {
            $out[(string) $r['style']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * Laatste N gepubliceerde AFBEELDINGEN (met miniatuur) over alle albums
     * heen, voor GalleryLatestBlock. Video-items worden bewust overgeslagen
     * — die hebben geen miniatuur (zie GalleryThumbnailer), en een
     * miniaturen-widget zonder afbeelding oogt kapot.
     */
    public function getLatestPublishedImages(int $limit): array
    {
        return $this->db->fetchAll(
            "SELECT COALESCE(i.thumbnail_path, i.file_path) AS thumbnail_path, i.media_type, i.title, c.slug AS album_slug
             FROM cf_gallery_items i
             JOIN cf_categories c ON c.id = i.album_id
             WHERE (i.media_type = 'image' OR i.thumbnail_path IS NOT NULL)
               AND i.is_published = 1 AND i.deleted_at IS NULL
             ORDER BY i.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    // ─── ITEMS (admin — S11) ────────────────────────────────────────────────

    public function getAllItemsForAdmin(int $albumId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM cf_gallery_items WHERE album_id = ? AND deleted_at IS NULL ORDER BY created_at DESC",
            [$albumId]
        );
    }

    public function countItemsInAlbum(int $albumId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_gallery_items WHERE album_id = ? AND deleted_at IS NULL",
            [$albumId]
        );
        return (int) ($row['count'] ?? 0);
    }

    public function findItemById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_gallery_items WHERE id = ? AND deleted_at IS NULL", [$id]);
    }

    public function createItem(
        int     $albumId,
        int     $authorId,
        string  $mediaType,
        ?string $title,
        ?string $description,
        string  $filePath,
        ?string $thumbnailPath,
        string  $originalFilename,
        int     $fileSize,
        ?int    $width,
        ?int    $height,
        ?string $style = null,
        ?string $tags = null,
    ): int {
        $data = [
            'album_id'          => $albumId,
            'author_id'         => $authorId,
            'media_type'        => $mediaType,
            'title'             => $title !== '' ? $title : null,
            'description'       => $description !== '' ? $description : null,
            'file_path'         => $filePath,
            'thumbnail_path'    => $thumbnailPath,
            'original_filename' => $originalFilename,
            'file_size'         => $fileSize,
            'width'             => $width,
            'height'            => $height,
        ];
        if ($this->supportsTaxonomy()) {
            $data['style'] = $style !== '' ? $style : null;
            $data['tags']  = $tags !== '' ? $tags : null;
        }
        $id = $this->db->insert('gallery_items', $data);
        $this->cache->delete('gallery.albums');
        return (int) $id;
    }

    /** Titel/omschrijving (en bij ondersteuning stijl + tags) van een item bijwerken. */
    public function updateItem(int $id, ?string $title, ?string $description, ?string $style, ?string $tags): void
    {
        $data = [
            'title'       => $title !== '' ? $title : null,
            'description' => $description !== '' ? $description : null,
        ];
        if ($this->supportsTaxonomy()) {
            $data['style'] = $style !== '' ? $style : null;
            $data['tags']  = $tags !== '' ? $tags : null;
        }
        $this->db->update('gallery_items', $data, 'id = ?', [$id]);
        $this->cache->clear();
    }

    /**
     * Alle gepubliceerde items in de JSON-structuur van het taxonomie-concept
     * (id, title, filename, category, parent_album_id, sub_album, style, tags).
     */
    public function exportItems(): array
    {
        $cols = $this->supportsTaxonomy() ? 'i.style, i.tags' : 'NULL AS style, NULL AS tags';
        $rows = $this->db->fetchAll(
            "SELECT i.id, i.title, i.original_filename, i.file_path, i.media_type, {$cols},
                    a.id AS album_id, a.name AS album_name, a.slug AS album_slug, a.parent_id,
                    p.id AS top_id, p.name AS top_name, p.slug AS top_slug
             FROM cf_gallery_items i
             JOIN cf_categories a ON a.id = i.album_id
             LEFT JOIN cf_categories p ON p.id = a.parent_id
             WHERE i.is_published = 1 AND i.deleted_at IS NULL
             ORDER BY i.id ASC"
        );

        return array_map(static function (array $r): array {
            $isSub = $r['parent_id'] !== null;
            return [
                'id'              => 'img-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT),
                'title'           => $r['title'],
                'filename'        => $r['original_filename'],
                'media_type'      => $r['media_type'],
                'category'        => $isSub ? $r['top_name'] : $r['album_name'],
                'parent_album_id' => $isSub ? 'album-' . $r['top_id'] : 'album-' . $r['album_id'],
                'sub_album'       => $isSub ? $r['album_name'] : null,
                'style'           => $r['style'],
                'tags'            => GalleryTaxonomy::unpackTags($r['tags']),
                'path'            => '/media/' . $r['file_path'],
            ];
        }, $rows);
    }

    public function deleteItem(int $id): void
    {
        $this->db->execute("UPDATE cf_gallery_items SET deleted_at = NOW() WHERE id = ?", [$id]);
        $this->cache->clear();
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryRepository.php | Role: Data | Version: 1.1.0          ║
// ║  Created: 2026-09-29 | Status: Updated — Galerij-taxonomie 1.35.0   ║
// ╚══════════════════════════════════════════════════════════════════════╝
