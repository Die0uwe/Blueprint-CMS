<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Block;

use CommunityFusion\Blocks\BlockInterface;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;

/**
 * BlockRegistry
 *
 * Centrale registry voor alle block types.
 * Modules registreren hun blocks hier.
 * Rendert blocks per zone met cache-ondersteuning.
 */
final class BlockRegistry
{
    /** @var array<string, BlockInterface> slug → instance */
    private array $types = [];

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
    ) {}

    // ─── REGISTRATIE ─────────────────────────────────────────────────────────

    /**
     * Registreer een block type.
     * Wordt aangeroepen door modules in hun boot() methode.
     */
    public function register(BlockInterface $block): void
    {
        $this->types[$block->getSlug()] = $block;
    }

    /**
     * Geef alle geregistreerde block types terug.
     *
     * @return array<string, BlockInterface>
     */
    public function all(): array
    {
        return $this->types;
    }

    public function find(string $slug): ?BlockInterface
    {
        return $this->types[$slug] ?? null;
    }

    // ─── ZONE RENDERING ───────────────────────────────────────────────────────

    /**
     * Haal alle block instanties op voor een zone, gesorteerd op positie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getZoneBlocks(string $zone, ?int $userId = null): array
    {
        return $this->cache->remember("blocks.zone.{$zone}", 120, function() use ($zone, $userId) {
            return $this->db->fetchAll(
                "SELECT b.*, bt.slug as type_slug, bt.schema
                 FROM cf_blocks b
                 JOIN cf_block_types bt ON bt.id = b.block_type_id
                 WHERE b.zone = ? AND b.is_visible = 1
                 ORDER BY b.position ASC",
                [$zone]
            );
        });
    }

    /**
     * Alle blokken van een zone voor het ADMIN-scherm: inclusief verborgen
     * blokken en zonder cache. (getZoneBlocks() filtert op is_visible = 1 voor
     * de publieke site; in de admin zou een verborgen blok daardoor nooit meer
     * terug zichtbaar gemaakt kunnen worden.)
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllZoneBlocks(string $zone): array
    {
        return $this->db->fetchAll(
            "SELECT b.*, bt.slug as type_slug
             FROM cf_blocks b
             JOIN cf_block_types bt ON bt.id = b.block_type_id
             WHERE b.zone = ?
             ORDER BY b.position ASC",
            [$zone]
        );
    }

    /**
     * Eén blok-instantie op id (met type-slug), of null.
     *
     * @return array<string, mixed>|null
     */
    public function findBlock(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT b.*, bt.slug as type_slug
             FROM cf_blocks b
             JOIN cf_block_types bt ON bt.id = b.block_type_id
             WHERE b.id = ?",
            [$id]
        );
    }

    /**
     * Render alle blocks in een zone naar HTML.
     */
    public function renderZone(string $zone, array $context = []): string
    {
        $blocks = $this->getZoneBlocks($zone);
        $html   = '';

        foreach ($blocks as $blockRow) {
            $html .= $this->renderBlock($blockRow, $context);
        }

        return $html;
    }

    /**
     * Render één block instantie.
     * Cache-aware: gebruikt de TTL uit de block instantie of de DB waarde.
     */
    public function renderBlock(array $blockRow, array $context = []): string
    {
        $slug = $blockRow['type_slug'] ?? '';
        $type = $this->find($slug);

        if ($type === null) {
            return "<!-- Block type '{$slug}' niet gevonden -->";
        }

        $config  = json_decode($blockRow['config'] ?? '{}', true) ?? [];
        $ttl     = (int) ($blockRow['cache_ttl'] ?? $type->getCacheTtl());
        $cacheKey = "block.render.{$blockRow['id']}";

        if ($ttl > 0) {
            return $this->cache->remember($cacheKey, $ttl, fn() =>
                $this->doRender($type, $blockRow, $config, $context)
            );
        }

        return $this->doRender($type, $blockRow, $config, $context);
    }

    private function doRender(BlockInterface $type, array $row, array $config, array $context): string
    {
        try {
            $type->validateConfig($config);
            $inner = $type->render($config, $context);

            $title = $row['title'] ? '<h3 class="cf-block-title">' . htmlspecialchars($row['title']) . '</h3>' : '';

            return <<<HTML
            <div class="cf-block cf-block--{$type->getSlug()}" data-block-id="{$row['id']}">
                {$title}
                {$inner}
            </div>
            HTML;
        } catch (\Throwable $e) {
            return "<!-- Block render fout: " . htmlspecialchars($e->getMessage()) . " -->";
        }
    }

    // ─── DATABASE HELPERS ─────────────────────────────────────────────────────

    /**
     * Sla een nieuwe block instantie op.
     */
    public function createBlock(array $data): int|string
    {
        $id = $this->db->insert('blocks', $data);
        $this->cache->delete("blocks.zone.{$data['zone']}");
        return $id;
    }

    /**
     * Update een block instantie.
     */
    public function updateBlock(int $id, array $data): void
    {
        $block = $this->db->fetchOne("SELECT zone FROM cf_blocks WHERE id = ?", [$id]);
        $this->db->update('blocks', $data, 'id = ?', [$id]);

        // Clear cache voor deze en eventueel nieuwe zone
        if ($block) $this->cache->delete("blocks.zone.{$block['zone']}");
        if (isset($data['zone'])) $this->cache->delete("blocks.zone.{$data['zone']}");
        $this->cache->delete("block.render.{$id}");
    }

    /**
     * Verwijder een block instantie.
     */
    public function deleteBlock(int $id): void
    {
        $block = $this->db->fetchOne("SELECT zone FROM cf_blocks WHERE id = ?", [$id]);
        $this->db->delete('blocks', 'id = ?', [$id]);
        if ($block) $this->cache->delete("blocks.zone.{$block['zone']}");
        $this->cache->delete("block.render.{$id}");
    }

    /**
     * Update posities van blocks in een zone (drag & drop save).
     *
     * @param array<int, int> $positions [block_id => position, ...]
     */
    public function updatePositions(array $positions, string $zone): void
    {
        $this->db->transaction(function($db) use ($positions, $zone) {
            foreach ($positions as $blockId => $position) {
                $db->execute(
                    "UPDATE cf_blocks SET position = ?, zone = ? WHERE id = ?",
                    [$position, $zone, $blockId]
                );
            }
        });

        // Clear alle zone-caches (block kon van zone wisselen)
        foreach (array_unique([$zone]) as $z) {
            $this->cache->delete("blocks.zone.{$z}");
        }
    }

    /**
     * Sync block types van geregistreerde modules naar de DB.
     */
    public function syncTypesToDatabase(int $moduleId): void
    {
        foreach ($this->types as $slug => $block) {
            $this->db->execute(
                "INSERT INTO cf_block_types (module_id, slug, name, description, schema)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   name = VALUES(name),
                   description = VALUES(description),
                   schema = VALUES(schema)",
                [
                    $moduleId,
                    $slug,
                    $block->getName(),
                    '',
                    json_encode($block->getConfigSchema()),
                ]
            );
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BlockRegistry.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ║  Notes: Centrale registry + zone renderer + drag&drop save          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
