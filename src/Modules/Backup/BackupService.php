<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Backup;

/**
 * BackupService — maakt, bewaart, downloadt en herstelt back-ups.
 *
 * Een back-up is één zip in storage/backups/:
 *   backup-YYYY-MM-DD-HHMMSS-{auto|manual|pre-restore|upload}.zip
 *     manifest.json   versie, tijdstip, type, aantallen
 *     database.sql    dump van alle cf_-tabellen (zie DatabaseDumper)
 *     uploads/…       optioneel: storage/uploads
 *
 * Retentie: auto-back-ups = laatste $keepDays kalenderdagen (max. één per dag,
 * een nieuwe op dezelfde dag vervangt de oude). pre-restore: laatste 3.
 * manual/upload worden nooit automatisch opgeruimd.
 */
final class BackupService
{
    public const TYPES = ['auto', 'manual', 'pre-restore', 'upload'];

    /** Uitbreidingen die nooit uit een zip naar uploads/ teruggezet worden. */
    private const BLOCKED_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'htaccess', 'htpasswd', 'ini', 'sh', 'cgi', 'pl'];

    private const NAME_RE = '/^backup-\d{4}-\d{2}-\d{2}-\d{6}-(auto|manual|pre-restore|upload)\.zip$/';

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly string $backupDir,
        private readonly string $uploadsDir,
        private readonly string $cmsVersion = '0.0.0',
        private readonly int $keepDays = 7,
    ) {}

    public function backupDir(): string
    {
        $this->ensureDir();
        return $this->backupDir;
    }

    // ─── aanmaken ───────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed> info over de nieuwe back-up (zie describe())
     */
    public function create(string $type = 'manual', bool $includeUploads = false, ?int $now = null): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Onbekend back-uptype.');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('De PHP-extensie zip (ZipArchive) ontbreekt op deze server.');
        }
        $this->ensureDir();
        $lock = $this->lock();
        try {
            $now  ??= time();
            $name  = 'backup-' . date('Y-m-d-His', $now) . '-' . $type . '.zip';
            $final = $this->backupDir . '/' . $name;
            $tmpZip = $final . '.tmp';
            $tmpSql = $this->backupDir . '/.dump-' . bin2hex(random_bytes(4)) . '.sql';
            @unlink($tmpZip);

            try {
                set_time_limit(0);
                $stats = $this->dumper->dump($tmpSql);

                $zip = new \ZipArchive();
                if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                    throw new \RuntimeException('Kan het zipbestand niet aanmaken.');
                }
                $files = 0;
                $zip->addFile($tmpSql, 'database.sql');
                if ($includeUploads && is_dir($this->uploadsDir)) {
                    $base = rtrim(str_replace('\\', '/', (string) realpath($this->uploadsDir)), '/');
                    $it = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($this->uploadsDir, \FilesystemIterator::SKIP_DOTS),
                    );
                    foreach ($it as $f) {
                        /** @var \SplFileInfo $f */
                        if (!$f->isFile() || $f->isLink()) {
                            continue;
                        }
                        $abs = str_replace('\\', '/', (string) $f->getRealPath());
                        if (!str_starts_with($abs, $base . '/')) {
                            continue;
                        }
                        $zip->addFile($abs, 'uploads/' . substr($abs, strlen($base) + 1));
                        $files++;
                    }
                }
                $manifest = [
                    'app'        => 'blueprint-cms',
                    'cms_version' => $this->cmsVersion,
                    'type'       => $type,
                    'created_at' => date('c', $now),
                    'tables'     => $stats['tables'],
                    'rows'       => $stats['rows'],
                    'uploads'    => $includeUploads,
                    'upload_files' => $files,
                ];
                $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                if (!$zip->close()) {
                    throw new \RuntimeException('Het zipbestand kon niet worden afgesloten (schijf vol?).');
                }
                if (!@rename($tmpZip, $final)) {
                    throw new \RuntimeException('Kan de back-up niet op zijn plek zetten.');
                }
            } finally {
                @unlink($tmpSql);
                @unlink($tmpZip);
            }

            if ($type === 'auto') {
                $this->removeSameDayAuto($now, $name);
            }
            $this->applyRetention($now);
            return $this->describe($name);
        } finally {
            $this->unlock($lock);
        }
    }

    // ─── lijst / opzoeken ───────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> nieuwste eerst */
    public function list(): array
    {
        $this->ensureDir();
        $out = [];
        foreach (scandir($this->backupDir) ?: [] as $f) {
            if (preg_match(self::NAME_RE, $f) === 1) {
                $out[] = $this->describe($f);
            }
        }
        usort($out, fn($a, $b) => strcmp((string) $b['name'], (string) $a['name']));
        return $out;
    }

    /**
     * Laatste $days kalenderdagen (vandaag eerst) met per dag de nieuwste
     * back-up van die dag (of null). Voor de weekdag-tegels in de UI.
     *
     * @return array<int,array{date:string,weekday:int,backup:?array<string,mixed>}>
     */
    public function lastDays(int $days = 7, ?int $now = null): array
    {
        $now ??= time();
        $byDate = [];
        foreach ($this->list() as $b) {          // nieuwste eerst → eerste per datum wint
            $byDate[$b['date']] ??= $b;
        }
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $ts = strtotime('-' . $i . ' day', $now);
            $d  = date('Y-m-d', $ts);
            $out[] = ['date' => $d, 'weekday' => (int) date('N', $ts), 'backup' => $byDate[$d] ?? null];
        }
        return $out;
    }

    /** Goedkope check (alleen bestandsnamen) — wordt op elk request gebruikt door de lazy trigger. */
    public function hasAutoBackupOn(string $date): bool
    {
        if (!is_dir($this->backupDir)) {
            return false;
        }
        foreach (scandir($this->backupDir) ?: [] as $f) {
            if (str_starts_with($f, 'backup-' . $date . '-') && str_ends_with($f, '-auto.zip')) {
                return true;
            }
        }
        return false;
    }

    public function isValidName(string $name): bool
    {
        return preg_match(self::NAME_RE, $name) === 1;
    }

    /** Absoluut pad van een bestaande back-up, of null. */
    public function path(string $name): ?string
    {
        if (!$this->isValidName($name)) {
            return null;
        }
        $p = $this->backupDir . '/' . $name;
        return is_file($p) ? $p : null;
    }

    public function delete(string $name): bool
    {
        $p = $this->path($name);
        return $p !== null && @unlink($p);
    }

    /** @return array<string,mixed> */
    public function describe(string $name): array
    {
        preg_match('/^backup-(\d{4}-\d{2}-\d{2})-(\d{2})(\d{2})(\d{2})-(.+)\.zip$/', $name, $m);
        $date = $m[1] ?? '';
        $ts   = $date !== '' ? (int) strtotime($date . ' ' . $m[2] . ':' . $m[3] . ':' . $m[4]) : 0;
        $info = [
            'name'     => $name,
            'date'     => $date,
            'time'     => ($m[2] ?? '00') . ':' . ($m[3] ?? '00'),
            'ts'       => $ts,
            'weekday'  => $ts ? (int) date('N', $ts) : 0,
            'type'     => $m[5] ?? 'manual',
            'size'     => (int) @filesize($this->backupDir . '/' . $name),
            'uploads'  => false,
            'cms_version' => null,
            'rows'     => null,
        ];
        $mf = $this->readManifest($this->backupDir . '/' . $name);
        if ($mf !== null) {
            $info['uploads']     = (bool) ($mf['uploads'] ?? false);
            $info['cms_version'] = $mf['cms_version'] ?? null;
            $info['rows']        = $mf['rows'] ?? null;
        }
        return $info;
    }

    // ─── herstellen ─────────────────────────────────────────────────────────

    /**
     * Zet een bestaande back-up terug. Maakt eerst automatisch een
     * pre-restore-back-up zodat een vergissing ongedaan te maken is.
     *
     * @return array{statements:int, upload_files:int, safety:string}
     */
    public function restore(string $name, bool $restoreUploads = false): array
    {
        $path = $this->path($name);
        if ($path === null) {
            throw new \RuntimeException('Back-up niet gevonden.');
        }
        return $this->restoreZip($path, $restoreUploads);
    }

    /**
     * Herstel vanuit een geüpload bestand (.zip van dit systeem of kale .sql-dump).
     * Het bestand wordt eerst gevalideerd; bij geldig wordt het bewaard als
     * 'upload'-back-up en daarna teruggezet.
     *
     * @return array{statements:int, upload_files:int, safety:string, stored:string}
     */
    public function restoreUploadedFile(string $tmpPath, string $originalName, bool $restoreUploads = false): array
    {
        if (!is_file($tmpPath)) {
            throw new \RuntimeException('Geen bestand ontvangen.');
        }
        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $this->ensureDir();
        $stored = 'backup-' . date('Y-m-d-His') . '-upload.zip';
        $dest   = $this->backupDir . '/' . $stored;

        if ($ext === 'sql') {
            if (!$this->dumper->isComplete($tmpPath)) {
                throw new \RuntimeException('Dit .sql-bestand is geen complete Blueprint-dump (eindmarkering ontbreekt).');
            }
            $zip = new \ZipArchive();
            if ($zip->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Kan geen zipbestand aanmaken.');
            }
            $zip->addFile($tmpPath, 'database.sql');
            $zip->addFromString('manifest.json', json_encode([
                'app' => 'blueprint-cms', 'type' => 'upload', 'created_at' => date('c'),
                'uploads' => false, 'source' => 'sql-upload',
            ]));
            $zip->close();
        } elseif ($ext === 'zip') {
            $this->validateZip($tmpPath);
            if (!@copy($tmpPath, $dest)) {
                throw new \RuntimeException('Kan het geüploade bestand niet opslaan.');
            }
        } else {
            throw new \RuntimeException('Alleen .zip (back-up) of .sql (dump) is toegestaan.');
        }

        try {
            $res = $this->restoreZip($dest, $restoreUploads);
        } catch (\Throwable $e) {
            throw $e;                  // de upload blijft staan; handmatig te verwijderen
        }
        $res['stored'] = $stored;
        return $res;
    }

    /** @return array{statements:int, upload_files:int, safety:string} */
    private function restoreZip(string $zipPath, bool $restoreUploads): array
    {
        $this->validateZip($zipPath);
        $lock = $this->lock();
        try {
            set_time_limit(0);
            $tmpSql = $this->backupDir . '/.restore-' . bin2hex(random_bytes(4)) . '.sql';
            try {
                $zip = new \ZipArchive();
                if ($zip->open($zipPath) !== true) {
                    throw new \RuntimeException('Kan de zip niet openen.');
                }
                $in = $zip->getStream('database.sql');
                if ($in === false) {
                    $zip->close();
                    throw new \RuntimeException('database.sql ontbreekt in de back-up.');
                }
                $out = fopen($tmpSql, 'wb');
                stream_copy_to_stream($in, $out);
                fclose($in);
                fclose($out);

                // Vooraf controleren: pas dán veiligheidsback-up en terugzetten.
                if (!$this->dumper->isComplete($tmpSql)) {
                    $zip->close();
                    throw new \RuntimeException('De database-dump in deze back-up is onvolledig. Er is niets gewijzigd.');
                }

                // Het lock is al vast → create() mag niet nogmaals blokkeren.
                $this->unlock($lock);
                $lock = null;
                $safety = $this->create('pre-restore', false)['name'];
                $lock = $this->lock();

                $statements = $this->dumper->restore($tmpSql);
                $files = $restoreUploads ? $this->extractUploads($zip) : 0;
                $zip->close();
            } finally {
                @unlink($tmpSql);
            }
            return ['statements' => $statements, 'upload_files' => $files, 'safety' => $safety];
        } finally {
            $this->unlock($lock);
        }
    }

    /** Controleer structuur van een zip vóór we iets doen. */
    private function validateZip(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Dit is geen geldig zipbestand.');
        }
        $hasSql = false;
        $total  = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $n = (string) $st['name'];
            if ($n === 'database.sql') {
                $hasSql = true;
            }
            $total += (int) $st['size'];
        }
        $zip->close();
        if (!$hasSql) {
            throw new \RuntimeException('Dit is geen Blueprint-back-up (database.sql ontbreekt).');
        }
        if ($total > 4 * 1024 * 1024 * 1024) {
            throw new \RuntimeException('De back-up is onredelijk groot (>4 GB uitgepakt).');
        }
    }

    private function extractUploads(\ZipArchive $zip): int
    {
        if (!is_dir($this->uploadsDir) && !@mkdir($this->uploadsDir, 0755, true) && !is_dir($this->uploadsDir)) {
            throw new \RuntimeException('Uploadmap kan niet worden aangemaakt.');
        }
        $base = rtrim(str_replace('\\', '/', (string) realpath($this->uploadsDir)), '/');
        $count = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                continue;
            }
            $rel = substr($name, strlen('uploads/'));
            if ($rel === '' || str_contains($rel, '..') || str_contains($rel, '\\') || str_contains($rel, "\0") || $rel[0] === '/') {
                continue;                                          // zip-slip
            }
            $segments = explode('/', $rel);
            $badName = false;
            foreach ($segments as $s) {
                $ext = strtolower(pathinfo($s, PATHINFO_EXTENSION));
                if ($s === '' || $s[0] === '.' || in_array($ext, self::BLOCKED_EXT, true)
                    || preg_match('/\.(php\d?|phtml|phar)\./i', $s) === 1) {
                    $badName = true;
                    break;
                }
            }
            if ($badName) {
                continue;
            }
            $target = $base . '/' . $rel;
            $dir = dirname($target);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                continue;
            }
            $realDir = str_replace('\\', '/', (string) realpath($dir));
            if ($realDir !== $base && !str_starts_with($realDir, $base . '/')) {
                continue;
            }
            $in = $zip->getStream($name);
            if ($in === false) {
                continue;
            }
            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($in);
                continue;
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            $count++;
        }
        return $count;
    }

    // ─── retentie ───────────────────────────────────────────────────────────

    private function removeSameDayAuto(int $now, string $keep): void
    {
        $prefix = 'backup-' . date('Y-m-d', $now) . '-';
        foreach (scandir($this->backupDir) ?: [] as $f) {
            if ($f !== $keep && str_starts_with($f, $prefix) && str_ends_with($f, '-auto.zip')) {
                @unlink($this->backupDir . '/' . $f);
            }
        }
    }

    private function applyRetention(int $now): void
    {
        $cutoff = date('Y-m-d', strtotime('-' . max(1, $this->keepDays - 1) . ' day', $now));
        $pre = [];
        foreach (scandir($this->backupDir) ?: [] as $f) {
            if (preg_match(self::NAME_RE, $f, $m) !== 1) {
                continue;
            }
            $date = substr($f, 7, 10);
            if ($m[1] === 'auto' && $date < $cutoff) {
                @unlink($this->backupDir . '/' . $f);
            } elseif ($m[1] === 'pre-restore') {
                $pre[] = $f;
            }
        }
        rsort($pre);
        foreach (array_slice($pre, 3) as $old) {
            @unlink($this->backupDir . '/' . $old);
        }
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    private function readManifest(string $zipPath): ?array
    {
        if (!is_file($zipPath)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return null;
        }
        $raw = $zip->getFromName('manifest.json');
        $zip->close();
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : null;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException('Back-upmap kan niet worden aangemaakt: storage/backups.');
        }
        // Defense in depth naast .htaccess: nooit direct opvraagbaar.
        $ht = $this->backupDir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
        $ix = $this->backupDir . '/index.html';
        if (!is_file($ix)) {
            @file_put_contents($ix, '');
        }
    }

    /** @return resource|null */
    private function lock()
    {
        $h = @fopen($this->backupDir . '/.lock', 'c');
        if ($h === false) {
            return null;
        }
        if (!flock($h, LOCK_EX | LOCK_NB)) {
            fclose($h);
            throw new \RuntimeException('Er loopt al een back-up of herstelactie. Probeer het over een minuut opnieuw.');
        }
        return $h;
    }

    /** @param resource|null $h */
    private function unlock($h): void
    {
        if (is_resource($h)) {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: BackupService.php | Role: Core | Version: 1.0.0              ║
// ║  Created: 2026-10-10 | Status: New — Backup & herstel               ║
// ╚══════════════════════════════════════════════════════════════════════╝
