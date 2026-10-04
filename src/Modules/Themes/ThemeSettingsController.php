<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Themes;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadException;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Template\ThemeSettings;
use CommunityFusion\Modules\Settings\SettingsRepository;

/**
 * /admin/themes/instellingen — thema-instellingen in 5 tabs (permissie themes.manage).
 *
 *  1 Algemeen & layout   Wide/Boxed, presets, breedtes
 *  2 Branding & headers  logo, site-icoon (favicon), headerbanner
 *  3 Kleuren & stijl     live kleur-customizer → CSS-variabelen
 *  4 / 5                 gereserveerd voor uitbreiding (bewust leeg)
 */
final class ThemeSettingsController
{
    public const TABS = [
        1 => ['key' => 'algemeen', 'label' => '⚙️ Algemeen & layout'],
        2 => ['key' => 'branding', 'label' => '🖼️ Branding & headers'],
        3 => ['key' => 'kleuren',  'label' => '🎨 Kleuren & stijl'],
        4 => ['key' => 'tab4',     'label' => '🧩 Gereserveerd'],
        5 => ['key' => 'tab5',     'label' => '🧩 Gereserveerd'],
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AuthManager        $auth,
        private readonly AuditLogger        $audit,
        private readonly UploadManager      $uploads,
    ) {}

    /** GET /admin/themes/instellingen?tab=1..5 */
    public function index(Request $request): Response
    {
        $tab   = max(1, min(5, (int) $request->query('tab', 1)));
        $s     = ThemeSettings::load($this->settings->getGroup(ThemeSettings::GROUP));
        $icon  = (string) $this->settings->get('core', 'site_icon', '');
        $flash = $request->query('ok');
        $error = $request->query('error');
        $tabs  = self::TABS;
        $presets = ThemeSettings::PRESETS;
        $colorFields = ThemeSettings::COLOR_FIELDS;

        ob_start();
        include __DIR__ . '/views/admin_settings.php';
        return Response::html((string) ob_get_clean());
    }

    /** POST — tab 1 */
    public function saveGeneral(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $clean  = ThemeSettings::load([
            'layout_mode'   => $request->input('layout_mode'),
            'layout_width'  => $request->input('layout_width'),
            'sidebar_width' => $request->input('sidebar_width'),
        ]);
        $preset = (string) $request->input('layout_preset', 'aangepast');
        $clean  = ThemeSettings::applyPreset($clean, $preset);

        foreach (['layout_mode', 'layout_preset', 'layout_width', 'sidebar_width'] as $k) {
            $this->settings->set(ThemeSettings::GROUP, $k, (string) $clean[$k]);
        }
        $this->log('themes.layout', ['preset' => $clean['layout_preset'], 'mode' => $clean['layout_mode']]);

        return Response::redirect('/admin/themes/instellingen?tab=1&ok=opgeslagen');
    }

    /** POST — tab 2 (multipart) */
    public function saveBranding(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $back  = '/admin/themes/instellingen?tab=2';
        $files = $request->files();
        $cur   = ThemeSettings::load($this->settings->getGroup(ThemeSettings::GROUP));

        // logo + banner → /media/theme/…
        foreach (['logo' => 'logo', 'banner' => 'banner'] as $field => $key) {
            $file = $files[$field] ?? null;
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $stored = $this->uploads->store($file, 'theme');
                } catch (UploadException $e) {
                    return Response::redirect($back . '&error=' . urlencode($e->getMessage()));
                }
                $this->settings->set(ThemeSettings::GROUP, $key, '/media/' . $stored);
                $this->removeThemeFile((string) $cur[$key]);
            } elseif ($request->input('remove_' . $field) !== null) {
                $this->settings->set(ThemeSettings::GROUP, $key, '');
                $this->removeThemeFile((string) $cur[$key]);
            }
        }

        $banner = ThemeSettings::load(['banner_height' => $request->input('banner_height')]);
        $this->settings->set(ThemeSettings::GROUP, 'banner_height', (string) $banner['banner_height']);
        $this->settings->set(ThemeSettings::GROUP, 'logo_show_name', $request->input('logo_show_name') !== null ? '1' : '0');

        // site-icoon (favicon) = dezelfde instelling als /admin/settings (core.site_icon)
        $prevIcon = (string) $this->settings->get('core', 'site_icon', '');
        $icon     = $files['site_icon'] ?? null;
        if ($icon !== null && ($icon['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = $this->uploads->store($icon, 'branding');
            } catch (UploadException $e) {
                return Response::redirect($back . '&error=' . urlencode($e->getMessage()));
            }
            $this->settings->set('core', 'site_icon', '/media/' . $stored);
            $this->removeFile($prevIcon, '/media/branding/');
        } elseif ($request->input('remove_site_icon') !== null) {
            $this->settings->set('core', 'site_icon', '');
            $this->removeFile($prevIcon, '/media/branding/');
        }

        $this->log('themes.branding', []);
        return Response::redirect($back . '&ok=opgeslagen');
    }

    /** POST — tab 3 */
    public function saveColors(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $reset = $request->input('reset') !== null;
        foreach (array_keys(ThemeSettings::COLOR_FIELDS) as $key) {
            $value = '';
            if (!$reset && $request->input('use_theme_' . $key) === null) {
                $value = ThemeSettings::hex($request->input($key)) ?? '';
            }
            $this->settings->set(ThemeSettings::GROUP, $key, $value);
        }
        $this->log($reset ? 'themes.colors_reset' : 'themes.colors', []);

        return Response::redirect('/admin/themes/instellingen?tab=3&ok=' . ($reset ? 'hersteld' : 'opgeslagen'));
    }

    private function removeThemeFile(string $url): void
    {
        $this->removeFile($url, '/media/theme/');
    }

    /** Ruimt alleen eigen uploads op (nooit externe URL's of andere mappen). */
    private function removeFile(string $url, string $prefix): void
    {
        if ($url !== '' && str_starts_with($url, $prefix)) {
            $this->uploads->delete(substr($url, strlen('/media/')));
        }
    }

    private function log(string $action, array $ctx): void
    {
        $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, $ctx);
    }
}
