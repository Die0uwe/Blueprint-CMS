<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Marketplace;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Marketplace\PackageManager;
use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;

/**
 * MarketplaceController
 *
 * Beheert de marketplace UI én API endpoints voor:
 * - Catalogus browsen (filter op type/zoekterm/sortering)
 * - Package installeren (via URL of ZIP upload)
 * - Package verwijderen, in/uitschakelen
 * - Updates controleren en uitvoeren
 */
final class MarketplaceController
{
    public function __construct(
        private readonly PackageManager $packages,
        private readonly AuthManager    $auth,
        private readonly Connection     $db,
    ) {}

    // ─── ADMIN UI ────────────────────────────────────────────────────────────

    /** GET /admin/marketplace */
    public function index(Request $request): Response
    {
        $this->auth->authorize('marketplace.view');

        $tab     = $request->query('tab', 'browse');
        $type    = $request->query('type', '');
        $search  = $request->query('search', '');
        $sortBy  = $request->query('sort', 'featured');

        $catalog   = $this->packages->getCatalog($type, $search, $sortBy, 20, 0);
        $installed = $this->packages->getInstalledPackages();
        $updates   = $this->packages->checkForUpdates();
        $configurableModules = $this->getConfigurableModules();

        ob_start();
        include __DIR__ . '/views/index.php';
        return Response::html(ob_get_clean());
    }

    /**
     * Golf 10 — ontdekte gat: $installed (hierboven) komt uit
     * cf_marketplace_installed, een tabel die ALLEEN gevuld wordt door
     * PackageManager::install()/installFromUpload() (de ZIP-download/upload-
     * flow). Een module die tijdens de installer (Step5.php) of rechtstreeks
     * via cf_modules is ingeschakeld — wat voor Discord/Twitch/Google/
     * Battle.net de normale weg is — staat daardoor NOOIT in $installed en
     * had dus ook nooit een link naar zijn instellingenscherm. Deze losse
     * query leest cf_modules zelf (de tabel die Application::loadModules()
     * ook echt gebruikt om te bepalen wat er draait) en filtert op modules
     * die een 'settings'-schema declareren — ongeacht hoe ze zijn ingeschakeld.
     *
     * @return array<int,array{slug:string,name:string,enabled:bool}>
     */
    private function getConfigurableModules(): array
    {
        $enabled = array_column(
            $this->db->fetchAll("SELECT slug, name FROM cf_modules WHERE is_enabled = 1 ORDER BY name"),
            'name',
            'slug'
        );

        // Ingeschakelde modules mét een settings-schema, plus ALTIJD de login-providers —
        // ook uitgeschakeld, anders is er nergens een knop om ze voor het eerst in te stellen.
        $slugs = array_unique([...array_keys($enabled), ...OAuthProviders::slugs()]);

        $result = [];
        foreach ($slugs as $slug) {
            $manifestPath = CF_ROOT . "/modules/{$slug}/module.json";
            if (!is_file($manifestPath)) continue;

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (!empty($manifest['settings'])) {
                $result[] = [
                    'slug'    => $slug,
                    'name'    => ($enabled[$slug] ?? '') ?: ($manifest['name'] ?? $slug),
                    'enabled' => isset($enabled[$slug]),
                ];
            }
        }

        usort($result, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $result;
    }

    /** GET /admin/marketplace/package/{slug} */
    public function detail(Request $request): Response
    {
        $this->auth->authorize('marketplace.view');

        $slug    = $request->param('slug');
        $package = $this->packages->getCatalog(search: $slug, limit: 1)[0] ?? null;

        if (!$package) {
            return Response::redirect('/admin/marketplace');
        }

        $isInstalled = $this->packages->isInstalled($slug);
        $installed   = $this->packages->getInstalled($slug);

        ob_start();
        include __DIR__ . '/views/detail.php';
        return Response::html(ob_get_clean());
    }

    // ─── INSTALLATIE ─────────────────────────────────────────────────────────

    /** POST /admin/marketplace/install */
    public function install(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');
        CsrfProtection::validateRequest();

        $slug        = $request->input('slug', '');
        $downloadUrl = $request->input('download_url', '');

        if (empty($slug)) {
            return Response::json(['error' => 'slug is verplicht.'], 422);
        }

        try {
            // Haal download URL op uit catalogus als niet meegegeven
            if (empty($downloadUrl)) {
                $pkg = $this->packages->getCatalog(search: $slug, limit: 1)[0] ?? null;
                $downloadUrl = $pkg['download_url'] ?? '';
            }

            if (empty($downloadUrl)) {
                return Response::json(['error' => 'Geen download URL beschikbaar voor dit pakket.'], 404);
            }

            $result = $this->packages->install($slug, $downloadUrl);
            return Response::json(['success' => true, 'package' => $result->toArray()]);

        } catch (PackageException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return Response::json(['error' => 'Onverwachte fout: ' . $e->getMessage()], 500);
        }
    }

    /** POST /admin/marketplace/upload — ZIP upload */
    public function upload(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');
        CsrfProtection::validateRequest();

        $file = $_FILES['package'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'Geen geldig bestand geüpload.'], 422);
        }

        // Valideer: alleen ZIP
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) {
            return Response::json(['error' => 'Alleen ZIP bestanden zijn toegestaan.'], 422);
        }

        // Max 50MB
        if ($file['size'] > 50 * 1024 * 1024) {
            return Response::json(['error' => 'ZIP is groter dan 50MB.'], 422);
        }

        try {
            $result = $this->packages->installFromUpload($file['tmp_name'], $file['name']);
            return Response::json(['success' => true, 'package' => $result->toArray()]);
        } catch (PackageException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /** POST /admin/marketplace/uninstall */
    public function uninstall(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');
        CsrfProtection::validateRequest();

        $slug = $request->input('slug', '');
        if (empty($slug)) return Response::json(['error' => 'slug vereist.'], 422);

        try {
            $this->packages->uninstall($slug);
            return Response::json(['success' => true]);
        } catch (PackageException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /** POST /admin/marketplace/toggle */
    public function toggle(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');
        CsrfProtection::validateRequest();

        $slug   = $request->input('slug', '');
        $enable = (bool)$request->input('enable', true);

        try {
            $enable ? $this->packages->enable($slug) : $this->packages->disable($slug);
            return Response::json(['success' => true]);
        } catch (PackageException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    /** POST /admin/marketplace/update */
    public function update(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');
        CsrfProtection::validateRequest();

        $slug = $request->input('slug', '');
        if (empty($slug)) return Response::json(['error' => 'slug vereist.'], 422);

        try {
            $result = $this->packages->update($slug);
            return Response::json(['success' => true, 'package' => $result->toArray()]);
        } catch (PackageException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
    }

    // ─── REST API ─────────────────────────────────────────────────────────────

    /** GET /api/v1/marketplace */
    public function apiCatalog(Request $request): Response
    {
        $packages = $this->packages->getCatalog(
            type:   $request->query('type', ''),
            search: $request->query('q', ''),
            sortBy: $request->query('sort', 'downloads'),
            limit:  min(50, (int)$request->query('limit', 20)),
            offset: (int)$request->query('offset', 0),
        );

        return Response::json(['data' => $packages]);
    }

    /** GET /api/v1/marketplace/installed */
    public function apiInstalled(Request $request): Response
    {
        $this->auth->authorize('marketplace.view');
        return Response::json(['data' => $this->packages->getInstalledPackages()]);
    }

    /** GET /api/v1/marketplace/updates */
    public function apiUpdates(Request $request): Response
    {
        $this->auth->authorize('marketplace.view');
        return Response::json(['updates' => $this->packages->checkForUpdates()]);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: MarketplaceController.php | Role: Core | Version: 1.0.0      ║
// ║  Created: 2026-06-06 | Status: New                                  ║
// ╚══════════════════════════════════════════════════════════════════════╝
