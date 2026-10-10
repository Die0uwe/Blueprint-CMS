<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Backup;

/**
 * BackupScheduler — beslist of de dagelijkse automatische back-up aan de beurt is
 * en bewaart de instellingen in storage/backups/state.json (bewust NIET in de
 * database: een restore mag het schema/de planning niet terugzetten).
 *
 * Triggers (allemaal veilig naast elkaar; BackupService-lock + "al gedaan
 * vandaag" voorkomen dubbele runs):
 *   1. echte cron:   php cli/console.php backup:auto           (of 05:00 via DirectAdmin/Strato cron)
 *   2. webcron:      GET /cron/backup/{token}                  (cron-job.org e.d.)
 *   3. lazy:         eerste request ná 05:00, ná het versturen van de response
 */
final class BackupScheduler
{
    public const DEFAULTS = [
        'auto_enabled'    => true,
        'time'            => '05:00',
        'include_uploads' => false,
        'token'           => '',
        'last_attempt'    => 0,
        'last_success'    => 0,
        'last_error'      => '',
    ];

    /** Minimale tijd tussen twee (mislukte) pogingen. */
    private const RETRY_SECONDS = 1800;

    private string $stateFile;

    public function __construct(
        private readonly BackupService $service,
        string $backupDir,
    ) {
        $this->stateFile = rtrim($backupDir, '/') . '/state.json';
    }

    /** @return array<string,mixed> */
    public function state(): array
    {
        $raw = is_file($this->stateFile) ? json_decode((string) @file_get_contents($this->stateFile), true) : null;
        $s = array_merge(self::DEFAULTS, is_array($raw) ? $raw : []);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $s['time'])) {
            $s['time'] = self::DEFAULTS['time'];
        }
        return $s;
    }

    /** @param array<string,mixed> $changes */
    public function save(array $changes): void
    {
        $s = array_merge($this->state(), $changes);
        $this->service->backupDir();                       // zorgt dat de map bestaat
        $tmp = $this->stateFile . '.tmp';
        if (@file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, $this->stateFile)) {
            throw new \RuntimeException('Instellingen konden niet worden opgeslagen (storage/backups schrijfbaar?).');
        }
    }

    public function updateSettings(bool $enabled, string $time, bool $includeUploads): void
    {
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new \InvalidArgumentException('Ongeldige tijd (gebruik UU:MM).');
        }
        $this->save(['auto_enabled' => $enabled, 'time' => $time, 'include_uploads' => $includeUploads]);
    }

    /** Geheime token voor de webcron-URL; wordt bij eerste gebruik aangemaakt. */
    public function token(): string
    {
        $s = $this->state();
        if (strlen((string) $s['token']) < 32) {
            $this->save(['token' => bin2hex(random_bytes(24))]);
            $s = $this->state();
        }
        return (string) $s['token'];
    }

    public function regenerateToken(): string
    {
        $this->save(['token' => bin2hex(random_bytes(24))]);
        return $this->token();
    }

    public function verifyToken(string $given): bool
    {
        $t = $this->token();
        return $given !== '' && hash_equals($t, $given);
    }

    /** Moet er nu een automatische back-up gemaakt worden? */
    public function isDue(?int $now = null): bool
    {
        $now ??= time();
        $s = $this->state();
        if (!$s['auto_enabled']) {
            return false;
        }
        $slot = strtotime(date('Y-m-d', $now) . ' ' . $s['time'] . ':00');
        if ($slot === false || $now < $slot) {
            return false;
        }
        // Al een auto-back-up van vandaag?
        if ($this->service->hasAutoBackupOn(date('Y-m-d', $now))) {
            return false;
        }
        // Na een mislukte poging even wachten.
        return ($now - (int) $s['last_attempt']) >= self::RETRY_SECONDS;
    }

    /**
     * Voer de auto-back-up uit als die aan de beurt is.
     *
     * @return array<string,mixed>|null info van de back-up, of null als er niets te doen was
     */
    public function runIfDue(?int $now = null, bool $force = false): ?array
    {
        $now ??= time();
        if (!$force && !$this->isDue($now)) {
            return null;
        }
        $s = $this->state();
        $this->save(['last_attempt' => $now]);
        try {
            $info = $this->service->create('auto', (bool) $s['include_uploads'], $now);
            $this->save(['last_success' => $now, 'last_error' => '']);
            return $info;
        } catch (\Throwable $e) {
            $this->save(['last_error' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BackupScheduler.php | Role: Core | Version: 1.0.0            ║
// ║  Created: 2026-10-10 | Status: New — Backup & herstel               ║
// ╚══════════════════════════════════════════════════════════════════════╝
