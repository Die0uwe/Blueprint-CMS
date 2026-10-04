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

namespace CommunityFusion\Modules\Downloads;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Cache\CacheManager;

final class DownloadsRepository
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    public function perPage(): int { return self::PER_PAGE; }

    public function getPublished(int $limit, int $offset): array
    {
        return $this->cache->remember("downloads.published.{$limit}.{$offset}", 300, function () use ($limit, $offset) {
            return $this->db->fetchAll(
                "SELECT d.*, u.username, u.display_name
                 FROM cf_downloads d
                 JOIN cf_users u ON u.id = d.author_id
                 WHERE d.is_published = 1 AND d.deleted_at IS NULL
                 ORDER BY d.created_at DESC
                 LIMIT ? OFFSET ?",
                [$limit, $offset]
            );
        });
    }

    public function countPublished(): int
    {
        $row = $this->db->fetchOne(
            "SELECT COUNT(*) AS count FROM cf_downloads WHERE is_published = 1 AND deleted_at IS NULL"
        );
        return (int) ($row['count'] ?? 0);
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne(
            "SELECT d.*, u.username, u.display_name
             FROM cf_downloads d
             JOIN cf_users u ON u.id = d.author_id
             WHERE d.slug = ? AND d.deleted_at IS NULL",
            [$slug]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT d.*, u.username, u.display_name
             FROM cf_downloads d
             JOIN cf_users u ON u.id = d.author_id
             WHERE d.id = ? AND d.deleted_at IS NULL",
            [$id]
        );
    }

    public function create(
        int    $authorId,
        string $title,
        string $description,
        string $filePath,
        string $originalFilename,
        int    $fileSize,
    ): int {
        $slug = $this->uniqueSlug($title);

        return (int) $this->db->insert('downloads', [
            'author_id'         => $authorId,
            'slug'              => $slug,
            'title'             => trim($title),
            'description'       => trim($description) !== '' ? trim($description) : null,
            'file_path'         => $filePath,
            'original_filename' => $originalFilename,
            'file_size'         => $fileSize,
        ]);
    }

    public function updateDetails(int $id, string $title, string $description, bool $isPublished): void
    {
        $this->db->update('downloads', [
            'title'        => trim($title),
            'description'  => trim($description) !== '' ? trim($description) : null,
            'is_published' => $isPublished ? 1 : 0,
        ], 'id = ?', [$id]);

        $this->cache->clear();
    }

    /** Alle downloads incl. niet-gepubliceerde — voor het beheeroverzicht. */
    public function getAllForAdmin(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT d.*, u.username, u.display_name
             FROM cf_downloads d
             JOIN cf_users u ON u.id = d.author_id
             WHERE d.deleted_at IS NULL
             ORDER BY d.created_at DESC
             LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function countAll(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS count FROM cf_downloads WHERE deleted_at IS NULL");
        return (int) ($row['count'] ?? 0);
    }

    public function setPublished(int $id, bool $published): void
    {
        $this->db->update('downloads', ['is_published' => $published ? 1 : 0], 'id = ?', [$id]);
        $this->cache->clear();
    }

    public function incrementDownloadCount(int $id): void
    {
        $this->db->execute("UPDATE cf_downloads SET download_count = download_count + 1 WHERE id = ?", [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute("UPDATE cf_downloads SET deleted_at = NOW() WHERE id = ?", [$id]);
        $this->cache->clear();
    }

    private function uniqueSlug(string $title): string
    {
        $base = strtolower(trim($title));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?: 'download';
        $base = trim($base, '-');
        $base = substr($base, 0, 180) ?: 'download';

        $candidate = $base;
        $attempt   = 0;
        while ($this->findBySlug($candidate) !== null) {
            $attempt++;
            $candidate = substr($base, 0, 180 - 6) . '-' . bin2hex(random_bytes(2));
            if ($attempt > 10) {
                throw new \RuntimeException('Kon geen unieke download-slug genereren.');
            }
        }

        return $candidate;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : DownloadsRepository.php                              ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Downloads core-module)                 ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
