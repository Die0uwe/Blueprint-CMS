<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Backup;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/backup — back-ups maken, downloaden, verwijderen en terugzetten
 * (per weekdag of via upload) + planning (dagelijks 05:00).
 *
 * Permissies: backup.manage (maken/downloaden/verwijderen/instellingen),
 * backup.restore (terugzetten; standaard alleen super_admin).
 * Elke POST valideert CSRF (RouteCsrfContractTest).
 */
final class BackupAdminController
{
    public const WEEKDAYS = [1 => 'Maandag', 2 => 'Dinsdag', 3 => 'Woensdag', 4 => 'Donderdag', 5 => 'Vrijdag', 6 => 'Zaterdag', 7 => 'Zondag'];

    public function __construct(
        private readonly BackupService   $service,
        private readonly BackupScheduler $scheduler,
        private readonly AuthManager     $auth,
        private readonly AuditLogger     $audit,
    ) {}

    /** GET /admin/backup */
    public function index(Request $request): Response
    {
        $backups  = $this->service->list();
        $days     = $this->service->lastDays(7);
        $state    = $this->scheduler->state();
        $token    = $this->scheduler->token();
        $canRestore = $this->auth->can('backup.restore');
        $flash    = (string) $request->query('ok', '');
        $error    = (string) $request->query('error', '');
        $detail   = (string) $request->query('d', '');
        $weekdays = self::WEEKDAYS;
        $uploadLimit = (string) ini_get('upload_max_filesize');
        $hasZip   = class_exists(\ZipArchive::class);
        $siteUrl  = $this->baseUrl($request);
        $nextRun  = $this->nextRun($state);

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    /** POST /admin/backup/maken */
    public function create(Request $request): Response
    {
        CsrfProtection::validateRequest();
        try {
            $info = $this->service->create('manual', (string) $request->input('include_uploads', '') === '1');
        } catch (\Throwable $e) {
            return $this->back('error', $e->getMessage());
        }
        $this->log('backup.create', ['name' => $info['name'], 'size' => $info['size']]);
        return $this->back('ok', 'gemaakt', $info['name']);
    }

    /** POST /admin/backup/instellingen */
    public function settings(Request $request): Response
    {
        CsrfProtection::validateRequest();
        try {
            $this->scheduler->updateSettings(
                (string) $request->input('auto_enabled', '') === '1',
                trim((string) $request->input('time', '05:00')),
                (string) $request->input('include_uploads', '') === '1',
            );
        } catch (\Throwable $e) {
            return $this->back('error', $e->getMessage());
        }
        $this->log('backup.settings', []);
        return $this->back('ok', 'instellingen');
    }

    /** POST /admin/backup/token */
    public function regenerateToken(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->scheduler->regenerateToken();
        $this->log('backup.token', []);
        return $this->back('ok', 'token');
    }

    /** GET /admin/backup/download/{name} */
    public function download(Request $request): Response
    {
        $name = (string) $request->param('name', '');
        $path = $this->service->path($name);
        if ($path === null) {
            return new Response('Back-up niet gevonden.', 404);
        }
        $this->log('backup.download', ['name' => $name]);
        return Response::stream($path, 0, (int) filesize($path), 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }

    /** POST /admin/backup/verwijder */
    public function delete(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $name = (string) $request->input('name', '');
        if (!$this->service->delete($name)) {
            return $this->back('error', 'Back-up niet gevonden.');
        }
        $this->log('backup.delete', ['name' => $name]);
        return $this->back('ok', 'verwijderd');
    }

    /** POST /admin/backup/herstel — bestaande back-up (weekdag-tegel of lijst) terugzetten */
    public function restore(Request $request): Response
    {
        CsrfProtection::validateRequest();
        if (!$this->confirmed($request)) {
            return $this->back('error', 'Typ HERSTEL in het bevestigingsveld om door te gaan.');
        }
        $name = (string) $request->input('name', '');
        try {
            $res = $this->service->restore($name, (string) $request->input('restore_uploads', '') === '1');
        } catch (\Throwable $e) {
            $this->log('backup.restore_failed', ['name' => $name, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return $this->back('error', 'Terugzetten mislukt: ' . $e->getMessage());
        }
        $this->log('backup.restore', ['name' => $name, 'safety' => $res['safety'], 'files' => $res['upload_files']]);
        return $this->back('ok', 'hersteld', $res['safety']);
    }

    /** POST /admin/backup/herstel-upload — zip/sql uploaden en terugzetten */
    public function restoreUpload(Request $request): Response
    {
        CsrfProtection::validateRequest();
        if (!$this->confirmed($request)) {
            return $this->back('error', 'Typ HERSTEL in het bevestigingsveld om door te gaan.');
        }
        $f = $request->files()['backup_file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
            $code = is_array($f) ? (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            $msg = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'Het bestand is groter dan de uploadlimiet van de server (' . ini_get('upload_max_filesize') . '). Zet het via FTP in storage/backups/ en kies het in de lijst.'
                : 'Geen bestand ontvangen.';
            return $this->back('error', $msg);
        }
        try {
            $res = $this->service->restoreUploadedFile(
                (string) $f['tmp_name'],
                (string) ($f['name'] ?? ''),
                (string) $request->input('restore_uploads', '') === '1',
            );
        } catch (\Throwable $e) {
            $this->log('backup.restore_failed', ['upload' => true, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            return $this->back('error', 'Terugzetten mislukt: ' . $e->getMessage());
        }
        $this->log('backup.restore_upload', ['stored' => $res['stored'], 'safety' => $res['safety']]);
        return $this->back('ok', 'hersteld', $res['safety']);
    }

    /**
     * GET /cron/backup/{token} — voor webcron/echte cron (curl). Publiek maar
     * beveiligd met het geheime token; doet niets als de back-up van vandaag er al is.
     */
    public function cron(Request $request): Response
    {
        $token = (string) $request->param('token', '');
        if (!$this->scheduler->verifyToken($token)) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $force = (string) $request->query('force', '') === '1';
        try {
            $info = $this->scheduler->runIfDue(null, $force);
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'backup failed'], 500);
        }
        return Response::json(['ok' => true, 'ran' => $info !== null, 'backup' => $info['name'] ?? null]);
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    private function confirmed(Request $request): bool
    {
        return strtoupper(trim((string) $request->input('confirm', ''))) === 'HERSTEL';
    }

    private function back(string $kind, string $value, string $detail = ''): Response
    {
        $q = $kind . '=' . urlencode($value);
        if ($detail !== '') {
            $q .= '&d=' . urlencode($detail);
        }
        return Response::redirect('/admin/backup?' . $q);
    }

    private function log(string $action, array $context): void
    {
        try {
            $this->audit->log($action, $this->auth->id(), $this->auth->user()['username'] ?? null, $context);
        } catch (\Throwable) {
            // auditlog mag een back-up nooit blokkeren
        }
    }

    private function baseUrl(Request $request): string
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'jouwsite.nl');
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $host) ?? 'jouwsite.nl';
        return ($https ? 'https://' : 'http://') . $host;
    }

    /** @param array<string,mixed> $state */
    private function nextRun(array $state): ?int
    {
        if (!$state['auto_enabled']) {
            return null;
        }
        $now  = time();
        $slot = strtotime(date('Y-m-d', $now) . ' ' . $state['time'] . ':00');
        if ($slot === false) {
            return null;
        }
        if ($now >= $slot && !$this->service->hasAutoBackupOn(date('Y-m-d', $now))) {
            return $now;                                   // achterstallig: eerstvolgende trigger
        }
        return $now < $slot ? $slot : (int) strtotime('+1 day', $slot);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BackupAdminController.php | Role: Core | Version: 1.0.0      ║
// ║  Created: 2026-10-10 | Status: New — Backup & herstel               ║
// ╚══════════════════════════════════════════════════════════════════════╝
