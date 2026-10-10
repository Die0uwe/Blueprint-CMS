<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Modules\Backup\BackupScheduler;
use CommunityFusion\Modules\Backup\BackupService;
use CommunityFusion\Modules\Backup\DatabaseDumper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Back-ups: dump/restore-roundtrip, zip, retentie (7 dagen), weekdag-overzicht, upload-herstel, planning 05:00. */
final class BackupServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $root;
    private BackupService $svc;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $this->pdo->exec('CREATE TABLE cf_notes (id INTEGER PRIMARY KEY AUTOINCREMENT, body TEXT NULL, n INTEGER NULL, f REAL NULL)');
        $this->pdo->exec('CREATE TABLE other_table (id INTEGER PRIMARY KEY)');   // andere prefix: mag niet mee
        $this->pdo->exec('INSERT INTO other_table VALUES (1)');
        $ins = $this->pdo->prepare('INSERT INTO cf_notes (body, n, f) VALUES (?, ?, ?)');
        foreach ([["Hallo 'wereld'\nregel 2\r\nregel 3", 1, 1.5], ['Ünïcödé 🎮 "quotes" \\ backslash', 2, null], [null, null, 0.1], ['', 0, 2.0]] as $r) {
            $ins->execute($r);
        }
        $this->root = sys_get_temp_dir() . '/cf_backup_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/uploads/gallery', 0755, true);
        file_put_contents($this->root . '/uploads/gallery/a.jpg', 'JPEGDATA');
        $this->svc = new BackupService(new DatabaseDumper($this->pdo, 'cf_'), $this->root . '/backups', $this->root . '/uploads', '1.36.0', 7);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
    }

    private function rrmdir(string $d): void
    {
        if (!is_dir($d)) { return; }
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            is_dir("$d/$f") ? $this->rrmdir("$d/$f") : @unlink("$d/$f");
        }
        @rmdir($d);
    }

    /** @return array<int,array<string,mixed>> */
    private function notes(): array
    {
        return $this->pdo->query('SELECT * FROM cf_notes ORDER BY id')->fetchAll();
    }

    private function ts(string $s): int
    {
        return (int) strtotime($s);
    }

    #[Test]
    public function dumpAndRestoreRoundtripKeepsTrickyValues(): void
    {
        $before = $this->notes();
        $file = $this->root . '/d.sql';
        $stats = (new DatabaseDumper($this->pdo, 'cf_'))->dump($file);
        $this->assertSame(1, $stats['tables']);          // other_table valt buiten de prefix
        $this->assertSame(4, $stats['rows']);
        $this->assertTrue((new DatabaseDumper($this->pdo, 'cf_'))->isComplete($file));

        $this->pdo->exec('DELETE FROM cf_notes');
        $this->pdo->exec("INSERT INTO cf_notes (body) VALUES ('rommel')");
        (new DatabaseDumper($this->pdo, 'cf_'))->restore($file);

        $this->assertSame($before, $this->notes());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM other_table')->fetchColumn());
    }

    #[Test]
    public function truncatedDumpIsRejectedWithoutChangingAnything(): void
    {
        $file = $this->root . '/d.sql';
        $d = new DatabaseDumper($this->pdo, 'cf_');
        $d->dump($file);
        $sql = (string) file_get_contents($file);
        file_put_contents($file, substr($sql, 0, (int) (strlen($sql) * 0.6)));
        $this->assertFalse($d->isComplete($file));

        $before = $this->notes();
        $failed = false;
        try { $d->restore($file); } catch (\RuntimeException) { $failed = true; }
        $this->assertTrue($failed);
        $this->assertSame($before, $this->notes());
    }

    #[Test]
    public function restoreRefusesForeignSql(): void
    {
        $file = $this->root . '/evil.sql';
        file_put_contents($file, "DELETE FROM cf_notes;\n" . DatabaseDumper::END_MARKER . " tables=0 rows=0\n");
        $failed = false;
        try { (new DatabaseDumper($this->pdo, 'cf_'))->restore($file); } catch (\RuntimeException) { $failed = true; }
        $this->assertTrue($failed);
        $this->assertCount(4, $this->notes());
    }

    #[Test]
    public function createWritesZipWithManifestAndDatabase(): void
    {
        $info = $this->svc->create('manual', true, $this->ts('2026-10-10 12:00:00'));
        $this->assertSame('backup-2026-10-10-120000-manual.zip', $info['name']);
        $this->assertTrue($info['uploads']);
        $this->assertSame('1.36.0', $info['cms_version']);
        $this->assertSame(6, (int) $info['weekday']);     // zaterdag

        $zip = new \ZipArchive();
        $zip->open($this->root . '/backups/' . $info['name']);
        $this->assertTrue($zip->locateName('database.sql') !== false);
        $this->assertTrue($zip->locateName('manifest.json') !== false);
        $this->assertTrue($zip->locateName('uploads/gallery/a.jpg') !== false);
        $zip->close();

        // Mapbescherming staat er
        $this->assertTrue(is_file($this->root . '/backups/.htaccess'));
        // Geen tijdelijke resten
        $this->assertSame([], glob($this->root . '/backups/.dump-*') ?: []);
        $this->assertSame([], glob($this->root . '/backups/*.tmp') ?: []);
    }

    #[Test]
    public function autoRetentionKeepsSevenDaysAndReplacesSameDay(): void
    {
        for ($i = 10; $i >= 0; $i--) {                   // 11 dagen, oud → nieuw
            $this->svc->create('auto', false, strtotime("-{$i} day", $this->ts('2026-10-10 05:00:00')));
        }
        $auto = array_filter($this->svc->list(), fn($b) => $b['type'] === 'auto');
        $this->assertCount(7, $auto);
        $this->assertSame('2026-10-10', $this->svc->list()[0]['date']);
        $this->assertSame('2026-10-04', end($auto)['date']);

        // Zelfde dag nogmaals: vervangt, wordt geen tweede
        $this->svc->create('auto', false, $this->ts('2026-10-10 07:30:00'));
        $today = array_filter($this->svc->list(), fn($b) => $b['date'] === '2026-10-10');
        $this->assertCount(1, $today);
        $this->assertTrue($this->svc->hasAutoBackupOn('2026-10-10'));
        $this->assertFalse($this->svc->hasAutoBackupOn('2026-10-03'));
    }

    #[Test]
    public function manualBackupsAreNeverAutoDeleted(): void
    {
        $this->svc->create('manual', false, $this->ts('2026-09-01 10:00:00'));
        $this->svc->create('auto', false, $this->ts('2026-10-10 05:00:00'));
        $types = array_column($this->svc->list(), 'type');
        $this->assertTrue(in_array('manual', $types, true));
    }

    #[Test]
    public function lastDaysMapsEachWeekdayToItsNewestBackup(): void
    {
        $this->svc->create('auto', false, $this->ts('2026-10-10 05:00:00'));       // za
        $this->svc->create('auto', false, $this->ts('2026-10-08 05:00:00'));       // do
        $this->svc->create('manual', false, $this->ts('2026-10-08 18:00:00'));     // do, nieuwer
        $days = $this->svc->lastDays(7, $this->ts('2026-10-10 15:00:00'));

        $this->assertCount(7, $days);
        $this->assertSame(6, $days[0]['weekday']);
        $this->assertSame('2026-10-10', $days[0]['date']);
        $this->assertTrue($days[0]['backup'] !== null);
        $this->assertSame(4, $days[2]['weekday']);                                  // donderdag
        $this->assertSame('manual', $days[2]['backup']['type']);                    // nieuwste van die dag
        $this->assertNull($days[1]['backup']);                                      // vr: geen back-up
        $this->assertSame(7, $days[6]['weekday']);                                  // zondag, 6 dagen terug
    }

    #[Test]
    public function restoreBringsDataBackAndMakesSafetyBackup(): void
    {
        $before = $this->notes();
        $b = $this->svc->create('auto', false, $this->ts('2026-10-10 05:00:00'));

        $this->pdo->exec('DELETE FROM cf_notes');
        $this->pdo->exec("INSERT INTO cf_notes (body, n) VALUES ('nieuw', 9)");

        $res = $this->svc->restore($b['name']);
        $this->assertSame($before, $this->notes());
        $this->assertTrue($res['statements'] > 0);
        $this->assertTrue($this->svc->path($res['safety']) !== null);
        $this->assertTrue(str_ends_with($res['safety'], '-pre-restore.zip'));

        // Het veiligheidsmoment bevat de toestand van vóór het terugzetten → ongedaan maken kan
        $this->svc->restore($res['safety']);
        $this->assertSame('nieuw', $this->notes()[0]['body']);
    }

    #[Test]
    public function restoreUploadedZipAndSql(): void
    {
        $b = $this->svc->create('manual', false, $this->ts('2026-10-09 10:00:00'));
        $zipCopy = $this->root . '/ext.zip';
        copy($this->root . '/backups/' . $b['name'], $zipCopy);
        $this->pdo->exec('DELETE FROM cf_notes');
        $res = $this->svc->restoreUploadedFile($zipCopy, 'mijn-backup.zip');
        $this->assertCount(4, $this->notes());
        $this->assertTrue(str_ends_with($res['stored'], '-upload.zip'));

        // kale .sql-dump
        $sql = $this->root . '/x.sql';
        (new DatabaseDumper($this->pdo, 'cf_'))->dump($sql);
        $this->pdo->exec('DELETE FROM cf_notes');
        $this->svc->restoreUploadedFile($sql, 'dump.sql');
        $this->assertCount(4, $this->notes());
    }

    #[Test]
    public function uploadRejectsWrongTypesAndForeignZips(): void
    {
        $txt = $this->root . '/a.txt';
        file_put_contents($txt, 'x');
        $bad = $this->root . '/nodb.zip';
        $z = new \ZipArchive();
        $z->open($bad, \ZipArchive::CREATE);
        $z->addFromString('readme.txt', 'hoi');
        $z->close();
        $inc = $this->root . '/inc.sql';
        file_put_contents($inc, "INSERT INTO cf_notes (body) VALUES ('x');\n");   // geen eindmarkering

        foreach ([[$txt, 'a.php'], [$txt, 'a.txt'], [$bad, 'nodb.zip'], [$inc, 'inc.sql']] as [$p, $n]) {
            $failed = false;
            try { $this->svc->restoreUploadedFile($p, $n); } catch (\RuntimeException) { $failed = true; }
            $this->assertTrue($failed, "moet falen: $n");
        }
        $this->assertCount(4, $this->notes());
    }

    #[Test]
    public function uploadRestoreBlocksZipSlipAndExecutables(): void
    {
        $dump = $this->root . '/d.sql';
        (new DatabaseDumper($this->pdo, 'cf_'))->dump($dump);
        $zipPath = $this->root . '/evil.zip';
        $z = new \ZipArchive();
        $z->open($zipPath, \ZipArchive::CREATE);
        $z->addFile($dump, 'database.sql');
        $z->addFromString('uploads/ok/pic.png', 'PNG');
        $z->addFromString('uploads/../escaped.txt', 'nope');
        $z->addFromString('uploads/shell.php', '<?php evil();');
        $z->addFromString('uploads/x/.htaccess', 'AddType');
        $z->addFromString('uploads/y/a.php.jpg', 'sneaky');
        $z->addFromString('uploads/y/b.phtml', 'x');
        $z->close();

        $res = $this->svc->restoreUploadedFile($zipPath, 'evil.zip', true);
        $this->assertSame(1, $res['upload_files']);
        $this->assertTrue(is_file($this->root . '/uploads/ok/pic.png'));
        $this->assertFalse(is_file($this->root . '/escaped.txt'));
        $this->assertFalse(is_file($this->root . '/uploads/shell.php'));
        $this->assertFalse(is_file($this->root . '/uploads/x/.htaccess'));
        $this->assertFalse(is_file($this->root . '/uploads/y/a.php.jpg'));
        $this->assertTrue(is_file($this->root . '/uploads/gallery/a.jpg'));   // bestaande bestanden blijven
    }

    #[Test]
    public function pathRejectsTraversalAndUnknownNames(): void
    {
        $b = $this->svc->create('manual');
        $this->assertTrue($this->svc->path($b['name']) !== null);
        foreach (['../etc/passwd', 'backup-2026-10-10-120000-manual.zip/../../x', 'state.json', 'backup-x.zip', ''] as $n) {
            $this->assertNull($this->svc->path($n), $n);
            $this->assertFalse($this->svc->delete($n));
        }
        $this->assertTrue($this->svc->delete($b['name']));
    }

    #[Test]
    public function schedulerRunsOnceAtOrAfterFiveAndRespectsSettings(): void
    {
        $sch = new BackupScheduler($this->svc, $this->root . '/backups');
        $day = '2026-10-10';

        $this->assertFalse($sch->isDue($this->ts("$day 04:59:00")));
        $this->assertTrue($sch->isDue($this->ts("$day 05:00:00")));
        $this->assertNull($sch->runIfDue($this->ts("$day 04:00:00")));

        $info = $sch->runIfDue($this->ts("$day 05:03:00"));
        $this->assertSame("backup-$day-050300-auto.zip", $info['name']);
        $this->assertFalse($sch->isDue($this->ts("$day 23:00:00")));               // vandaag al gedaan
        $this->assertTrue($sch->isDue($this->ts('2026-10-11 05:00:00')));          // morgen weer
        $this->assertSame($this->ts("$day 05:03:00"), $sch->state()['last_success']);

        $sch->updateSettings(false, '06:30', false);
        $this->assertFalse($sch->isDue($this->ts('2026-10-11 09:00:00')));
        $sch->updateSettings(true, '06:30', false);
        $this->assertFalse($sch->isDue($this->ts('2026-10-11 06:29:00')));
        $this->assertTrue($sch->isDue($this->ts('2026-10-11 06:30:00')));

        $bad = false;
        try { $sch->updateSettings(true, '25:99', false); } catch (\InvalidArgumentException) { $bad = true; }
        $this->assertTrue($bad);
    }

    #[Test]
    public function schedulerBacksOffAfterFailureAndTokenWorks(): void
    {
        $sch = new BackupScheduler($this->svc, $this->root . '/backups');
        $sch->save(['last_attempt' => $this->ts('2026-10-10 05:00:00')]);
        $this->assertFalse($sch->isDue($this->ts('2026-10-10 05:10:00')));         // < 30 min geleden
        $this->assertTrue($sch->isDue($this->ts('2026-10-10 05:31:00')));

        $t = $sch->token();
        $this->assertTrue(strlen($t) >= 32);
        $this->assertSame($t, $sch->token());                                      // stabiel
        $this->assertTrue($sch->verifyToken($t));
        $this->assertFalse($sch->verifyToken(''));
        $this->assertFalse($sch->verifyToken('x' . substr($t, 1)));
        $new = $sch->regenerateToken();
        $this->assertFalse($sch->verifyToken($t));
        $this->assertTrue($sch->verifyToken($new));
    }
}
