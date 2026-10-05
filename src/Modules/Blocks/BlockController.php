<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Blocks;

use CommunityFusion\Core\Block\BlockConfigNormalizer;
use CommunityFusion\Core\Block\BlockRegistry;
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
        $schema = $type->getConfigSchema();
        // Geen invoer (de modal stuurt een lege config) → standaardwaarden uit het schema.
        $config = (is_array($config) && $config !== [])
            ? BlockConfigNormalizer::normalize($schema, $config)
            : BlockConfigNormalizer::defaults($schema);
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
            return Response::json([
                'success'  => true,
                'block_id' => $id,
                // Heeft dit type instellingen? Dan stuurt de UI direct door naar het bewerkscherm.
                'edit_url' => $schema !== [] ? "/admin/blocks/{$id}/edit" : null,
            ]);
        }
        return Response::redirect($schema !== [] ? "/admin/blocks/{$id}/edit" : '/admin/blocks');
    }

    public function edit(Request $request): Response
    {
        $id  = (int) $request->param('id');
        $row = $this->registry->findBlock($id);
        $type = $row !== null ? $this->registry->find((string) $row['type_slug']) : null;
        if ($row === null || $type === null) {
            return Response::redirect('/admin/blocks');
        }

        $block  = $row;
        $schema = $type->getConfigSchema();
        $values = json_decode((string) ($row['config'] ?? '{}'), true);
        $values = is_array($values) ? $values : [];
        $zones  = self::ZONES;
        $saved  = $request->query('saved', '') === '1';

        ob_start();
        include __DIR__ . '/views/edit.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id  = (int) $request->param('id');
        $row = $this->registry->findBlock($id);
        $type = $row !== null ? $this->registry->find((string) $row['type_slug']) : null;
        if ($row === null || $type === null) {
            return Response::json(['error' => 'Blok niet gevonden.'], 404);
        }

        // Alleen de MEEGESTUURDE velden bijwerken. Eerder schreef dit altijd
        // title = null en config = [] weg zodra een aanroep (zichtbaarheid wisselen,
        // verplaatsen naar een andere zone) die velden niet meestuurde — dat wiste
        // de configuratie van het blok.
        $data = [];

        $title = $request->input('title', null);
        if ($title !== null) {
            $title = trim((string) $title);
            $data['title'] = $title !== '' ? mb_substr($title, 0, 200) : null;
        }

        $vis = $request->input('is_visible', null);
        if ($vis !== null) {
            $data['is_visible'] = (int) ((string) $vis === '1' || $vis === 1 || $vis === true);
        }

        $zone = $request->input('zone', '');
        if ($zone !== '') {
            if (!array_key_exists($zone, self::ZONES)) {
                return Response::json(['error' => 'Ongeldige zone.'], 422);
            }
            $data['zone'] = $zone;
        }

        $rawConfig = $request->input('config', null);
        if (is_array($rawConfig) && $rawConfig !== []) {
            $config = BlockConfigNormalizer::normalize($type->getConfigSchema(), $rawConfig);
            try {
                $type->validateConfig($config);
            } catch (\Throwable $e) {
                return Response::json(['error' => $e->getMessage()], 422);
            }
            $data['config'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($data !== []) {
            $this->registry->updateBlock($id, $data);
        }

        if ($request->isJson() || $request->isAjax()) {
            return Response::json(['success' => true]);
        }
        return Response::redirect("/admin/blocks/{$id}/edit?saved=1");
    }

    /**
     * POST /admin/blocks/{id}/preview — render het blok met de (nog niet opgeslagen) formulierwaarden.
     * Nooit uit de cache, schrijft niets weg. De admin toont het resultaat in een sandboxed iframe.
     */
    public function preview(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id   = (int) $request->param('id');
        $row  = $this->registry->findBlock($id);
        $type = $row !== null ? $this->registry->find((string) $row['type_slug']) : null;
        if ($row === null || $type === null) {
            return Response::json(['error' => 'Blok niet gevonden.'], 404);
        }

        $raw    = $request->input('config', []);
        $config = BlockConfigNormalizer::normalize($type->getConfigSchema(), is_array($raw) ? $raw : []);
        try {
            $type->validateConfig($config);
        } catch (\Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }

        $row['config']    = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $row['cache_ttl'] = 0;
        $title = trim((string) $request->input('title', (string) ($row['title'] ?? '')));
        $row['title'] = $title !== '' ? mb_substr($title, 0, 200) : null;

        return Response::json(['html' => $this->registry->renderBlock($row, [])]);
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
            $placed[$zone] = $this->registry->getAllZoneBlocks($zone);
        }
        return $placed;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BlockController.php | Role: Core | Version: 1.1.0 (FIXED)    ║
// ║  Fixed: registry->db() bug → directe Connection dependency          ║
// ╚══════════════════════════════════════════════════════════════════════╝
