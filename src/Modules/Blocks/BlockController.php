<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Blocks;

use CommunityFusion\Core\Block\BlockRegistry;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Block\BlockOverrides;
use CommunityFusion\Core\Block\BlockSettings;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Template\MarkupException;
use CommunityFusion\Core\Template\PreviewDocument;
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
        private readonly ?AuthManager  $auth = null,
        private readonly ?BlockOverrides $overrides = null,
        private readonly ?AuditLogger  $audit = null,
        private readonly ?CacheManager $cache = null,
    ) {}

    private function canMarkup(): bool
    {
        return $this->auth !== null && $this->auth->can('blocks.override_template');
    }

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
        if ($typeSlug === 'markup' && !$this->canMarkup()) {
            return Response::json(['error' => 'Geen recht om markup-blokken te maken (blocks.override_template).'], 403);
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
            'can_markup' => $this->canMarkup(),
            'markup' => $this->canMarkup() ? $this->markupFor($block) : null,
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

    // ── Markup-tab ────────────────────────────────────────────────────────

    /** @return array{scope:string,slug:string,value:string,has_override:bool}|null */
    private function markupFor(array $block): ?array
    {
        if ($block['type_slug'] === 'markup') {
            $row = $this->db->fetchOne('SELECT content_markup FROM cf_block_content WHERE block_id = ?', [(int) $block['id']]);
            return ['scope' => 'instance', 'slug' => 'markup', 'value' => (string) ($row['content_markup'] ?? ''), 'has_override' => false];
        }
        $slug = (string) $block['type_slug'];
        return [
            'scope' => 'type', 'slug' => $slug,
            'value' => (string) ($this->overrides?->get($slug) ?? ''),
            'has_override' => $this->overrides?->has($slug) ?? false,
        ];
    }

    /**
     * Bewaart de markup: bij een markup-blok per blok (cf_block_content), anders als sjabloon-override voor het hele blocktype.
     * Leeg = override verwijderen. Vereist blocks.override_template; PHP-tags vereisen daarnaast editor.markup.php.
     */
    public function saveMarkup(Request $request): Response
    {
        CsrfProtection::validateRequest();
        if (!$this->canMarkup() || $this->overrides === null || $this->auth === null) {
            return Response::json(['error' => 'Geen recht (blocks.override_template).'], 403);
        }
        $block = $this->registry->getBlock((int) $request->param('id'));
        $markup = $request->input('markup', null);
        if ($block === null || !is_string($markup)) {
            return Response::json(['error' => 'Ongeldige invoer.'], 422);
        }
        $renderer = new \CommunityFusion\Core\Template\MarkupRenderer();
        if ($renderer->containsPhp($markup) && !$this->auth->can('editor.markup.php')) {
            return Response::json(['error' => 'PHP-tags zijn niet toegestaan voor jouw rol (recht editor.markup.php).'], 403);
        }
        $slug = (string) $block['type_slug'];
        try {
            if ($slug === 'markup') {
                $renderer->render($markup, ['title' => '', 'today' => date('Y-m-d')]);   // proefrender
                $this->db->execute(
                    'INSERT INTO cf_block_content (block_id, content_markup, updated_by) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE content_markup = VALUES(content_markup), updated_by = VALUES(updated_by)',
                    [(int) $block['id'], $markup, $this->auth->id()]
                );
                $this->forgetRender([(int) $block['id']]);
            } elseif (trim($markup) === '') {
                $this->overrides->delete($slug);
                $this->forgetRenderForType($slug);
            } else {
                $this->overrides->save($slug, $markup);
                $this->forgetRenderForType($slug);
            }
        } catch (MarkupException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $this->audit?->log('block.markup.save', $this->auth->id(), (string) ($this->auth->user()['username'] ?? ''), [
            'block_id' => (int) $block['id'], 'type' => $slug, 'bytes' => strlen($markup),
        ], $request->ip());
        return Response::json(['success' => true]);
    }

    /** Sandbox-preview van een stuk markup voor het blok-formulier (zelfde regels als opslaan). */
    public function previewMarkup(Request $request): Response
    {
        CsrfProtection::validateRequest();
        if (!$this->canMarkup() || $this->auth === null) {
            return Response::json(['error' => 'Geen recht (blocks.override_template).'], 403);
        }
        $markup = $request->input('markup', '');
        $renderer = new \CommunityFusion\Core\Template\MarkupRenderer();
        if (!is_string($markup)) {
            return Response::json(['error' => 'Ongeldige invoer.'], 422);
        }
        if ($renderer->containsPhp($markup) && !$this->auth->can('editor.markup.php')) {
            return Response::json(['error' => 'PHP-tags zijn niet toegestaan voor jouw rol.'], 403);
        }
        try {
            $html = $renderer->render($markup, ['title' => 'Voorbeeld', 'config' => [], 'today' => date('Y-m-d')]);
        } catch (MarkupException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        return Response::json(['html' => PreviewDocument::wrap($html)]);
    }

    /** Preview van een hele zone zoals bezoekers die zien (zichtbare blokken), in een sandbox-document. */
    public function zonePreview(Request $request): Response
    {
        $zone = (string) $request->param('zone');
        if (!array_key_exists($zone, self::ZONES)) {
            return Response::json(['error' => 'Ongeldige zone.'], 404);
        }
        return Response::json(['html' => PreviewDocument::wrap($this->registry->renderZone($zone))]);
    }

    /** @param list<int> $ids */
    private function forgetRender(array $ids): void
    {
        foreach ($ids as $id) {
            $this->cache?->delete("block.render.{$id}");
        }
    }

    private function forgetRenderForType(string $slug): void
    {
        $rows = $this->db->fetchAll(
            'SELECT b.id FROM cf_blocks b JOIN cf_block_types bt ON bt.id = b.block_type_id WHERE bt.slug = ?',
            [$slug]
        );
        $this->forgetRender(array_map(static fn ($r) => (int) $r['id'], $rows));
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
