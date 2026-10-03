<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Marketplace;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Settings\SettingsRepository;

/**
 * ModuleSettingsController — Golf 10 (OAuth-providers).
 *
 * Elke module (module.json) kan een "settings"-array declareren (client_id,
 * client_secret, enz. — zie modules/discord/module.json, modules/twitch/
 * module.json en, vanaf deze golf, modules/google + modules/battlenet).
 * Vóór deze golf bestond er GEEN admin-scherm dat dat schema ooit las: de
 * enige manier om een client_id/secret in te vullen was rechtstreeks in de
 * cf_settings-tabel via SQL. Dit scherm is de ontbrekende schakel — het leest
 * het schema uit module.json, rendert er een generiek formulier voor, en
 * slaat op via SettingsRepository (die 'encrypted'-velden sinds deze golf
 * ook echt versleutelt — zie Core\Security\Crypto).
 *
 * Routes:
 *   GET  /admin/marketplace/package/{slug}/instellingen
 *   POST /admin/marketplace/package/{slug}/instellingen
 */
final class ModuleSettingsController
{
    public function __construct(
        private readonly AuthManager        $auth,
        private readonly SettingsRepository $settings,
        private readonly Connection         $db,
    ) {}

    public function edit(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');

        $slug   = $request->param('slug');
        $schema = $this->loadSchema($slug);

        if ($schema === null) {
            return Response::redirect('/admin/marketplace?tab=installed');
        }

        $flash  = $request->query('saved', '') === '1';
        $justEnabled = $request->query('enabled', '') === '1';
        $values = $this->settings->getGroup($slug);

        ob_start();
        include __DIR__ . '/views/module_settings.php';
        return Response::html(ob_get_clean());
    }

    public function update(Request $request): Response
    {
        $this->auth->authorize('marketplace.install');
        CsrfProtection::validateRequest();

        $slug   = $request->param('slug');
        $schema = $this->loadSchema($slug);

        if ($schema === null) {
            return Response::redirect('/admin/marketplace?tab=installed');
        }

        foreach ($schema as $field) {
            $key   = $field['key'];
            $type  = $field['type'] ?? 'string';
            $input = $request->input($key, null);

            if ($type === 'encrypted') {
                // Leeg gelaten = bewuste keuze om de bestaande, versleutelde
                // waarde te behouden (het veld toont nooit het ontsleutelde
                // geheim terug in de browser — zie views/module_settings.php).
                if ($input === null || $input === '') {
                    continue;
                }
                $this->settings->set($slug, $key, $input, 'encrypted');
            } elseif ($type === 'bool') {
                $this->settings->set($slug, $key, $input !== null ? '1' : '0', 'bool');
            } else {
                $this->settings->set($slug, $key, (string) ($input ?? ''), $type);
            }
        }

        // Een login-provider (GitHub/Google/Discord/Twitch/Battle.net) staat pas aan als zijn
        // module is ingeschakeld. Zodra Client ID én Secret zijn ingevuld, doen we dat hier
        // meteen: anders slaat een beheerder de sleutels op en blijft de knop onzichtbaar.
        $enabled = $this->enableProviderIfConfigured($slug);

        return Response::redirect("/admin/marketplace/package/{$slug}/instellingen?saved=1" . ($enabled ? '&enabled=1' : ''));
    }

    /**
     * Schakel de module van een login-provider in als er sleutels zijn ingevuld.
     * Geeft true terug als de module hierdoor net is ingeschakeld.
     */
    private function enableProviderIfConfigured(string $slug): bool
    {
        if (!in_array($slug, OAuthProviders::slugs(), true)) {
            return false;
        }
        if (OAuthProviders::setting($this->db, $slug, 'client_id') === ''
            || OAuthProviders::setting($this->db, $slug, 'client_secret') === '') {
            return false;
        }

        $already = $this->db->fetchOne("SELECT is_enabled FROM cf_modules WHERE slug = ?", [$slug]);
        if ($already !== null && (int) $already['is_enabled'] === 1) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents(CF_ROOT . "/modules/{$slug}/module.json"), true) ?: [];
        $this->db->execute(
            "INSERT INTO cf_modules (slug, name, version, author, description, is_core, is_enabled)
             VALUES (?, ?, ?, ?, ?, 0, 1)
             ON DUPLICATE KEY UPDATE is_enabled = 1, version = VALUES(version)",
            [
                $slug,
                (string) ($manifest['name'] ?? $slug),
                (string) ($manifest['version'] ?? '1.0.0'),
                (string) ($manifest['author'] ?? ''),
                (string) ($manifest['description'] ?? ''),
            ]
        );

        return true;
    }

    /**
     * @return array<int,array{key:string,label:string,type:string}>|null
     *         null = module bestaat niet of declareert geen settings-schema.
     */
    private function loadSchema(string $slug): ?array
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        $path = CF_ROOT . "/modules/{$slug}/module.json";

        if (!is_file($path)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        $settings = $manifest['settings'] ?? [];

        return is_array($settings) && $settings !== [] ? $settings : null;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: ModuleSettingsController.php | Role: Core | Version: 1.0.0   ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
