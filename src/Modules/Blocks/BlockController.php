<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Blocks;

use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Block\BlockSettings;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

final class BlockController
{
    private const ZONES = [
        'header'        => 'Header',
        'topmenu'       => 'Top Menu',
        'sidebar_left'  => 'Linker Sidebar',
        'content'       => 'Content',
        'sidebar_right' => 'Rechter Sidebar',
        'footer'        => 'Footer',
    ];

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly Connection    $db,          // FIX: directe DB dependency
    ) {}

    public function index(Request $request): Response
    {
        $zones    = self::ZONES;
        $allTypes = $this->registry->all();
        $placed   = $this->getPlacedByZone();
        ob_start();
        include __DIR__ . '/views/index.php';
        return Response::html(ob_get_clean());
    }

    public function create(Request $request): Response
    {
        $slug = $request->input('type_slug', '');
        $type = $this->registry->find($slug);
        if ($type === null) {
            return Response::json(['error' => "Block type '{$slug}' niet gevonden."], 404);
        }
        ob_start();
        include __DIR__ . '/views/create.php';
        return Response::html(ob_get_clean());
    }

    public function store(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $typeSlug = $request->input('type_slug', '');
        $zone     = $request->input('zone', 'sidebar_right');
        $title    = $request->input('title', '');
        $config   = $request->input('config', []);

        if (!array_key_exists($zone, self::ZONES)) {
            return Response::json(['error' => 'Ongeldige zone.'], 422);
        }
        $type = $this->registry->find($typeSlug);
        if ($type === null) {
            return Response::json(['error' => 'Block type niet gevonden.'], 404);
        }
        $norm = BlockSettings::normalize($type->getConfigSchema(), is_array($config) ? $config : []);
        if ($norm['errors'] !== []) {
            return Response::json(['error' => 'Ongeldige instellingen.', 'fields' => $norm['errors']], 422);
        }
        $config = $norm['config'];
        try {
            $type->validateConfig($config);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        // FIX: gebruik $this->db direct i.p.v. $this->registry->db()
        $maxPos = $this->db->fetchOne(
            "SELECT COALESCE(MAX(position), -1) as max_pos FROM cf_blocks WHERE zone = ?",
            [$zone]
        );
        $blockTypeId = $this->db->fetchOne(
            "SELECT id FROM cf_block_types WHERE slug = ?",
            [$typeSlug]
        );
        if (!$blockTypeId) {
            return Response::json(['error' => 'Block type niet in DB geregistreerd.'], 500);
        }

        $id = $this->registry->createBlock([
            'block_type_id' => (int) $blockTypeId['id'],
            'zone'          => $zone,
            'position'      => (int) ($maxPos['max_pos'] ?? -1) + 1,
            'title'         => $title ?: null,
            'config'        => json_encode($config),
            'is_visible'    => 1,
        ]);

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true, 'block_id' => $id]);
        }
        return Response::redirect('/admin/blocks');
    }

    /** JSON voor het instellingenscherm: schema + huidige waarden van één blok. */
    public function settings(Request $request): Response
    {
        $block = $this->registry->getBlock((int) $request->param('id'));
        $type  = $block ? $this->registry->find((string) $block['type_slug']) : null;
        if ($block === null || $type === null) {
            return Response::json(['error' => 'Blok niet gevonden.'], 404);
        }
        $schema = $type->getConfigSchema();
        $saved  = json_decode((string) ($block['config'] ?? '{}'), true);
        return Response::json([
            'id' => (int) $block['id'], 'type' => $block['type_slug'], 'name' => $type->getName(),
            'title' => (string) ($block['title'] ?? ''), 'is_visible' => (int) $block['is_visible'],
            'fields' => BlockSettings::describe($schema),
            'config' => array_replace(BlockSettings::defaults($schema), is_array($saved) ? $saved : []),
        ]);
    }

    /**
     * Wijzigt alleen wat is meegestuurd. (Voorheen werd config altijd overschreven met [] zodra
     * je een blok alleen verborg of verplaatste.)
     */
    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id    = (int) $request->param('id');
        $block = $this->registry->getBlock($id);
        $type  = $block ? $this->registry->find((string) $block['type_slug']) : null;
        if ($block === null || $type === null) {
            return Response::json(['error' => 'Blok niet gevonden.'], 404);
        }

        $data = [];
        $title = $request->input('title', null);
        if (is_string($title)) {
            $data['title'] = mb_substr(trim($title), 0, 200) ?: null;
        }
        $vis = $request->input('is_visible', null);
        if ($vis !== null) {
            $data['is_visible'] = (int) ((int) $vis === 1);
        }
        $zone = $request->input('zone', '');
        if (is_string($zone) && $zone !== '' && array_key_exists($zone, self::ZONES)) {
            $data['zone'] = $zone;
        }
        $config = $request->input('config', null);
        if ($config !== null) {
            $norm = BlockSettings::normalize($type->getConfigSchema(), is_array($config) ? $config : []);
            if ($norm['errors'] !== []) {
                return Response::json(['error' => 'Ongeldige instellingen.', 'fields' => $norm['errors']], 422);
            }
            try {
                $type->validateConfig($norm['config']);
            } catch (\Throwable $e) {
                return Response::json(['error' => $e->getMessage()], 422);
            }
            $data['config'] = json_encode($norm['config'], JSON_UNESCAPED_UNICODE);
        }
        if ($data !== []) {
            $this->registry->updateBlock($id, $data);
        }

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }
        return Response::redirect('/admin/blocks');
    }

    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id = (int) $request->param('id');
        $this->registry->deleteBlock($id);
        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }
        return Response::redirect('/admin/blocks');
    }

    public function savePositions(Request $request): Response
    {
        // Ontbrak hier — enige state-wijzigende actie in dit bestand zonder
        // CSRF-check, terwijl store()/update()/delete() 'm wel hebben.
        // Gevonden tijdens de S13-inventarisatiepas.
        CsrfProtection::validateRequest();
        $zone      = $request->input('zone', '');
        $positions = $request->input('positions', []);
        if (!array_key_exists($zone, self::ZONES) || !is_array($positions)) {
            return Response::json(['error' => 'Ongeldige invoer.'], 422);
        }
        $clean = [];
        foreach ($positions as $blockId => $pos) {
            $clean[(int) $blockId] = (int) $pos;
        }
        $this->registry->updatePositions($clean, $zone);
        return Response::json(['success' => true, 'updated' => count($clean)]);
    }

    public function getZonesApi(Request $request): Response
    {
        $result = [];
        foreach (self::ZONES as $zoneSlug => $zoneLabel) {
            $blocks = $this->registry->getZoneBlocks($zoneSlug);
            $result[$zoneSlug] = [
                'label'  => $zoneLabel,
                'blocks' => array_map(fn($b) => [
                    'id'       => $b['id'],
                    'title'    => $b['title'],
                    'type'     => $b['type_slug'],
                    'position' => $b['position'],
                    'visible'  => (bool) $b['is_visible'],
                ], $blocks),
            ];
        }
        return Response::json($result);
    }

    private function getPlacedByZone(): array
    {
        $placed = [];
        foreach (self::ZONES as $zone => $_) {
            $placed[$zone] = $this->registry->getZoneBlocksForAdmin($zone);
        }
        return $placed;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BlockController.php | Role: Core | Version: 1.1.0 (FIXED)    ║
// ║  Fixed: registry->db() bug → directe Connection dependency          ║
// ╚══════════════════════════════════════════════════════════════════════╝
