<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Settings;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Storage\UploadException;

final class AdminController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly UploadManager      $uploads,
        private readonly AuthManager        $auth,
        private readonly AuditLogger        $audit,
    ) {}

    public function dashboard(Request $request): Response
    {
        ob_start();
        include __DIR__ . '/views/dashboard.php';
        return Response::html(ob_get_clean());
    }

    /**
     * /admin/settings — was tot v1.18.0 een volledig statische pagina die de
     * beheerder doorverwees naar config/config.php (buiten webroot, niet
     * bewerkbaar via de UI) en /installer/ (bestaat na installatie niet meer —
     * zie Step5.php's uitleg over waarom de installer zichzelf hernoemt). Er
     * bestond geen enkele manier om sitenaam, MOTD/slogan of het site-icoon
     * na de installatie te wijzigen zonder rechtstreeks in de database te
     * werken. Dit scherm leest nu echt de 'core'-instellingengroep.
     */
    public function settings(Request $request): Response
    {
        $core    = $this->settings->getGroup('core');
        $contact = $this->settings->getGroup('contact');
        $error   = $request->query('error');
        $flash   = $request->query('ok');

        ob_start();
        include __DIR__ . '/views/settings.php';
        return Response::html(ob_get_clean());
    }

    public function updateSettings(Request $request): Response
    {
        CsrfProtection::validateRequest();

        $siteName    = trim((string) $request->input('site_name', ''));
        $siteMotd    = trim((string) $request->input('site_motd', ''));
        $siteDesc    = trim((string) $request->input('site_description', ''));
        $locale      = (string) $request->input('default_locale', 'nl');
        $timezone    = trim((string) $request->input('timezone', ''));
        $removeIcon  = $request->input('remove_icon') !== null;
        $notifyEmail = trim((string) $request->input('contact_notify_email', ''));

        if ($siteName === '') {
            return Response::redirect('/admin/settings?error=' . urlencode('Sitenaam mag niet leeg zijn.'));
        }
        if (!in_array($locale, ['nl', 'en'], true)) {
            $locale = 'nl';
        }
        // Leeg mag (dan valt ContactController terug op het standaard
        // afzenderadres) — alleen bij een ingevulde waarde moet het ook
        // echt een geldig e-mailadres zijn.
        if ($notifyEmail !== '' && !filter_var($notifyEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::redirect('/admin/settings?error=' . urlencode('Meldingen-e-mailadres is geen geldig e-mailadres.'));
        }

        $this->settings->set('core', 'site_name', $siteName);
        $this->settings->set('core', 'site_motd', $siteMotd);
        $this->settings->set('core', 'site_description', $siteDesc);
        $this->settings->set('core', 'default_locale', $locale);
        if ($timezone !== '') {
            $this->settings->set('core', 'timezone', $timezone);
        }
        $this->settings->set('contact', 'notify_email', $notifyEmail);

        $previousIcon = (string) $this->settings->get('core', 'site_icon', '');
        $file = $request->files()['site_icon'] ?? null;

        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $stored = $this->uploads->store($file, 'branding');
            } catch (UploadException $e) {
                return Response::redirect('/admin/settings?error=' . urlencode($e->getMessage()));
            }
            $this->settings->set('core', 'site_icon', '/media/' . $stored);
            $this->cleanupOldIcon($previousIcon);
        } elseif ($removeIcon) {
            $this->settings->set('core', 'site_icon', '');
            $this->cleanupOldIcon($previousIcon);
        }

        $this->audit->log('settings.update', $this->auth->id(), $this->auth->user()['username'] ?? null, [
            'site_name' => $siteName,
        ]);

        return Response::redirect('/admin/settings?ok=opgeslagen');
    }

    /**
     * Ruim een vervangen/verwijderd site-icoon op — nooit een externe URL
     * verwijderen, alleen een eigen '/media/branding/'-upload (zelfde
     * voorzichtigheidspatroon als ProfileController::updateAvatar()).
     */
    private function cleanupOldIcon(string $oldUrl): void
    {
        if ($oldUrl !== '' && str_starts_with($oldUrl, '/media/branding/')) {
            $this->uploads->delete(substr($oldUrl, strlen('/media/')));
        }
    }

    // This class used to declare handle() twice — a fatal "Cannot redeclare"
    // error that meant the class could never even be loaded, so EVERY /admin
    // route (dashboard, settings, this one included) was completely broken.
    // The two versions also weren't equivalent: the first took $path straight
    // into the include path with no sanitization at all (a path-traversal /
    // local-file-inclusion hole — e.g. path=../../../../etc/passwd), while
    // this second one whitelists to [a-z0-9/-] first. Kept the sanitized one.
    public function handle(Request $request): Response
    {
        $path = $request->param('path', '');
        $view = __DIR__ . '/views/' . preg_replace('/[^a-z0-9\/\-]/', '', $path) . '.php';

        if (!file_exists($view)) {
            return Response::redirect('/admin');
        }

        ob_start();
        include $view;
        return Response::html(ob_get_clean());
    }
}
