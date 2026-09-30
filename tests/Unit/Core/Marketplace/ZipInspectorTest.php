<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Marketplace;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Marketplace\ZipInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ZipInspectorTest extends TestCase
{
    private string $zipPath;

    protected function setUp(): void
    {
        $this->zipPath = sys_get_temp_dir() . '/zi-' . bin2hex(random_bytes(6)) . '.zip';
    }

    protected function tearDown(): void
    {
        @unlink($this->zipPath);
    }

    /** @param array<string,string> $files */
    private function zip(array $files, ?callable $after = null): \ZipArchive
    {
        $z = new \ZipArchive();
        $z->open($this->zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $z->addFromString($name, $content);
        }
        if ($after) {
            $after($z);
        }
        $z->close();
        $z->open($this->zipPath);
        return $z;
    }

    private function assertRejected(array $files, string $why, ?callable $after = null): void
    {
        $zip = $this->zip($files, $after);
        try {
            (new ZipInspector())->inspect($zip);
            $this->fail("Had geweigerd moeten worden: {$why}");
        } catch (PackageException) {
            $this->addToAssertionCount(1);
        } finally {
            $zip->close();
        }
    }

    #[Test]
    public function acceptsANormalPackage(): void
    {
        $zip = $this->zip(['module.json' => '{}', 'src/A.php' => '<?php', 'templates/a.twig' => 'x', 'LICENSE' => 'GPL', 'lang/nl.json' => '{}']);
        $r = (new ZipInspector())->inspect($zip);
        $this->assertSame(5, $r['entries']);
    }

    #[Test]
    public function rejectsPathTraversalAndAbsolutePaths(): void
    {
        $this->assertRejected(['../evil.php' => 'x'], '../');
        $this->assertRejected(['a/../../evil.php' => 'x'], 'a/../..');
        $this->assertRejected(['/etc/evil.php' => 'x'], 'absoluut');
        $this->assertRejected(['a/./b.php' => 'x'], 'punt-segment');
    }

    #[Test]
    public function rejectsBackslashDriveAndControlCharacters(): void
    {
        $this->assertRejected(['a\\..\\evil.php' => 'x'], 'backslash');
        $this->assertRejected(['C:evil.php' => 'x'], 'drive-letter');
        $this->assertRejected(["a\nb.php" => 'x'], 'newline in naam');
    }

    #[Test]
    public function rejectsHiddenAndDangerousFileTypes(): void
    {
        $this->assertRejected(['module.json' => '{}', '.htaccess' => 'x'], '.htaccess');
        $this->assertRejected(['module.json' => '{}', 'x.phar' => 'x'], '.phar');
        $this->assertRejected(['module.json' => '{}', 'x.phtml' => 'x'], '.phtml');
        $this->assertRejected(['module.json' => '{}', 'x.exe' => 'x'], '.exe');
        $this->assertRejected(['module.json' => '{}', 'x.sh' => 'x'], '.sh');
        $this->assertRejected(['module.json' => '{}', 'x.php.jpg' => 'x'], 'dubbele extensie');
        $this->assertRejected(['module.json' => '{}', 'noext' => 'x'], 'geen extensie');
    }

    #[Test]
    public function rejectsSymlinks(): void
    {
        $this->assertRejected(['module.json' => '{}', 'link.txt' => '/etc/passwd'], 'symlink', function (\ZipArchive $z): void {
            $z->setExternalAttributesName('link.txt', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        });
    }

    #[Test]
    public function rejectsZipBombByRatio(): void
    {
        $this->assertRejected(['module.json' => '{}', 'big.txt' => str_repeat("\0", 3 * 1024 * 1024)], 'compressieratio');
    }

    #[Test]
    public function rejectsTooManyEntries(): void
    {
        $files = ['module.json' => '{}'];
        for ($i = 0; $i < ZipInspector::MAX_ENTRIES; $i++) {
            $files["f{$i}.txt"] = 'x';
        }
        $this->assertRejected($files, 'te veel bestanden');
    }

    #[Test]
    public function rejectsTooLargeTotalOrSingleFile(): void
    {
        $noise = random_bytes(1024 * 1024);
        $files = ['module.json' => '{}'];
        for ($i = 0; $i < 11; $i++) {
            $files["part{$i}.txt"] = $noise;   // 11 MB totaal is OK, maar één bestand > 10 MB is dat niet
        }
        $this->assertRejected(['module.json' => '{}', 'huge.txt' => random_bytes(ZipInspector::MAX_ENTRY_BYTES + 1)], 'bestand te groot');
    }
}
