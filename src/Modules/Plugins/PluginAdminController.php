<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Plugins;

use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Plugin\ManifestSlug;
use CommunityFusion\Core\Plugin\PluginManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/plugins — overzicht, activeren/deactiveren/verwijderen, instellingen en (optioneel) uploaden.
 *
 * Alles hier vereist `plugins.manage`. ZIP-upload is bovendien standaard UIT: het kan alleen als
 * ALLOW_PLUGIN_UPLOAD=true in .env staat én de gebruiker de rol super_admin heeft. Een plugin is
 * uitvoerbare PHP-code; upload is dus gelijk aan "code op de server zetten".
 */
final class PluginAdminController
{
    public function __construct(
        private readonly PluginManager $plugins,
        private readonly AuthManager $auth,
        private readonly Connection $db,
    ) {}

    public function index(Request $request): Response
    {
        $this->auth->authorize('plugins.manage');
        $items = $this->plugins->discover();
        $flash = $this->takeFlash();
        $canUpload = $this->uploadAllowed();
        $uploadEnabled = $this->uploadFlag();
        return $this->view('index', compact('items', 'flash', 'canUpload', 'uploadEnabled'));
    }

    public function activate(Request $request): Response
    {
        return $this->act($request, function (string $slug): string {
            $this->plugins->activate($slug, $this->actor());
            return "Plugin '{$slug}' is geactiveerd. Zichtbaar vanaf het volgende verzoek.";
        });
    }

    public function deactivate(Request $request): Response
    {
        return $this->act($request, function (string $slug): string {
            $this->plugins->deactivate($slug, $this->actor());
            return "Plugin '{$slug}' is gedeactiveerd.";
        });
    }

    public function migrate(Request $request): Response
    {
        return $this->act($request, function (string $slug): string {
            $done = $this->plugins->migrate($slug);
            return $done === [] ? 'Geen openstaande migraties.' : 'Migraties uitgevoerd: ' . implode(', ', $done);
        });
    }

    public function uninstall(Request $request): Response
    {
        return $this->act($request, function (string $slug) use ($request): string {
            if ($request->input('confirm', '') !== $slug) {
                throw new PackageException('Typ de naam van de plugin ter bevestiging.');
            }
            $drop = $request->input('drop_data', '') === '1';
            $this->plugins->uninstall($slug, $drop, $this->actor());
            return "Plugin '{$slug}' is verwijderd" . ($drop ? ' (inclusief gegevens).' : ' (gegevens bewaard).');
        });
    }

    public function upload(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('plugins.manage');
        if (!$this->uploadAllowed()) {
            $this->flash('error', 'Uploaden staat uit. Zet ALLOW_PLUGIN_UPLOAD=true in .env (alleen voor super_admin).');
            return Response::redirect('/admin/plugins');
        }
        $f = $request->files()['zip'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) {
            $this->flash('error', 'Geen (geldig) bestand ontvangen.');
            return Response::redirect('/admin/plugins');
        }
        try {
            $slug = $this->plugins->installFromUpload((string)$f['tmp_name'], (string)($f['name'] ?? ''), $this->actor());
            $this->flash('ok', "Plugin '{$slug}' is geïnstalleerd maar nog niet geactiveerd. Lees eerst wat hij doet.");
        } catch (PackageException $e) {
            $this->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/plugins');
    }

    public function settings(Request $request): Response
    {
        $this->auth->authorize('plugins.manage');
        $slug = (string)$request->param('slug');
        try {
            ManifestSlug::assert($slug);
            $manifest = $this->plugins->readManifest($slug);
        } catch (PackageException $e) {
            $this->flash('error', $e->getMessage());
            return Response::redirect('/admin/plugins');
        }
        $defs = $manifest['settings'] ?? [];
        $values = $this->plugins->settings($slug);
        $flash = $this->takeFlash();
        return $this->view('settings', compact('slug', 'manifest', 'defs', 'values', 'flash'));
    }

    public function saveSettings(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('plugins.manage');
        $slug = (string)$request->param('slug');
        try {
            ManifestSlug::assert($slug);
            $manifest = $this->plugins->readManifest($slug);
            $input = [];
            foreach ($manifest['settings'] ?? [] as $def) {
                $k = $def['key'];
                if ($def['type'] === 'bool') {
                    $input[$k] = $request->input($k, null) !== null ? '1' : '0';   // aangevinkt of niet
                } else {
                    $input[$k] = $request->input($k, '');
                }
            }
            $this->plugins->saveSettings($slug, $input, $this->actor());
            $this->flash('ok', 'Instellingen opgeslagen.');
        } catch (PackageException $e) {
            $this->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/plugins/' . rawurlencode($slug) . '/instellingen');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @param callable(string):string $fn */
    private function act(Request $request, callable $fn): Response
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('plugins.manage');
        $slug = (string)$request->param('slug');
        try {
            ManifestSlug::assert($slug);
            $this->flash('ok', $fn($slug));
        } catch (PackageException $e) {
            $this->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('Plugin-actie mislukt: ' . $e->getMessage());
            $this->flash('error', 'De actie is mislukt. Zie de serverlog.');
        }
        return Response::redirect('/admin/plugins');
    }

    private function uploadFlag(): bool
    {
        return strtolower((string)($_ENV['ALLOW_PLUGIN_UPLOAD'] ?? getenv('ALLOW_PLUGIN_UPLOAD') ?: 'false')) === 'true';
    }

    private function uploadAllowed(): bool
    {
        if (!$this->uploadFlag() || $this->auth->id() === null) {
            return false;
        }
        $row = $this->db->fetchOne(
            "SELECT 1 FROM cf_user_roles ur JOIN cf_roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = 'super_admin'",
            [$this->auth->id()]
        );
        return (bool)$row;
    }

    /** @return array{id:?int,username:?string} */
    private function actor(): array
    {
        return ['id' => $this->auth->id(), 'username' => (string)($this->auth->user()['username'] ?? '')];
    }

    private function flash(string $type, string $msg): void
    {
        $_SESSION['plugin_flash'] = ['type' => $type, 'msg' => $msg];
    }

    /** @return array{type:string,msg:string}|null */
    private function takeFlash(): ?array
    {
        $f = $_SESSION['plugin_flash'] ?? null;
        unset($_SESSION['plugin_flash']);
        return is_array($f) ? $f : null;
    }

    /** @param array<string,mixed> $vars */
    private function view(string $name, array $vars): Response
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include __DIR__ . '/views/' . $name . '.php';
        return Response::html((string)ob_get_clean());
    }
}
