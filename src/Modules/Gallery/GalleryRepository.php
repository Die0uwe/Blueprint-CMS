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

    public function itemsPerPage(): int { return self::ITEMS_PER_PAGE; }

    // ─── ALBUMS (publiek) ───────────────────────────────────────────────────

    /**
     * Alle albums met live item-telling en een omslagminiatuur (het nieuwste
     * gepubliceerde item), voor de publieke albumindex.
     */
    public function getAlbums(): array
    {
        return $this->cache->remember('gallery.albums', 60, function () {
            return $this->db->fetchAll(
                "SELECT c.id, c.slug, c.name, c.description, c.position,
                        COUNT(i.id) AS item_count,
                        (SELECT COALESCE(gi.thumbnail_path, CASE WHEN gi.media_type = 'image' THEN gi.file_path END) FROM cf_gallery_items gi
                          WHERE gi.album_id = c.id AND gi.is_published = 1 AND gi.deleted_at IS NULL
                          ORDER BY gi.created_at DESC LIMIT 1) AS cover_thumbnail,
                        (SELECT gi.media_type FROM cf_gallery_items gi
                          WHERE gi.album_id = c.id AND gi.is_published = 1 AND gi.deleted_at IS NULL
                          ORDER BY gi.created_at DESC LIMIT 1) AS cover_media_type
                 FROM cf_categories c
                 LEFT JOIN cf_gallery_items i ON i.album_id = c.id AND i.is_published = 1 AND i.deleted_at IS NULL
                 WHERE c.type = 'gallery'
                 GROUP BY c.id, c.slug, c.name, c.description, c.position
                 ORDER BY c.position ASC, c.name ASC"
            );
        });
    }

    public function findAlbumBySlug(string $slug): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_categories WHERE type = 'gallery' AND slug = ?", [$slug]);
    }

    // ─── ALBUMS (admin — S11) ───────────────────────────────────────────────

    public function getAllAlbumsForAdmin(): array
    {
        return $this->db->fetchAll(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM cf_gallery_items i WHERE i.album_id = c.id AND i.deleted_at IS NULL) AS item_count
             FROM cf_categories c
             WHERE c.type = 'gallery'
             ORDER BY c.position ASC, c.name ASC"
        );
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

    public function createAlbum(string $slug, string $name, string $description, int $position): int
    {
        $id = $this->db->insert('categories', [
            'type'        => 'gallery',
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ]);
        $this->cache->clear();   // ook 'laatste items'-blok en albumhoezen
        return (int) $id;
    }

    public function updateAlbum(int $id, string $slug, string $name, string $description, int $position): void
    {
        $this->db->update('categories', [
            'slug'        => $slug,
            'name'        => $name,
            'description' => $description,
            'position'    => $position,
        ], 'id = ? AND type = ?', [$id, 'gallery']);
        $this->cache->delete('gallery.albums');
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
        $this->cache->delete('gallery.albums');
    }

    // ─── ITEMS (publiek) ────────────────────────────────────────────────────

    public function getPublishedItems(int $albumId, int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT i.*, u.username, u.display_name
             FROM cf_gallery_items i
             JOIN cf_users u ON u.id = i.author_id
             WHERE i.album_id = ? AND i.is_published = 1 AND i.deleted_at IS NULL
             ORDER BY i.created_at DESC
             LIMIT ? OFFSET ?",
            [$albumId, $limit, $offset]
        );
    }

    public function countPublishedItems(int $albumId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_gallery_items
             WHERE album_id = ? AND is_published = 1 AND deleted_at IS NULL",
            [$albumId]
        );
        return (int) ($row['count'] ?? 0);
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
    ): int {
        $id = $this->db->insert('gallery_items', [
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
        ]);
        $this->cache->delete('gallery.albums');
        return (int) $id;
    }

    public function deleteItem(int $id): void
    {
        $this->db->execute("UPDATE cf_gallery_items SET deleted_at = NOW() WHERE id = ?", [$id]);
        $this->cache->clear();
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryRepository.php | Role: Data | Version: 1.0.0          ║
// ║  Created: 2026-09-29 | Status: New — S11 (Media-galerij)            ║
// ╚══════════════════════════════════════════════════════════════════════╝
