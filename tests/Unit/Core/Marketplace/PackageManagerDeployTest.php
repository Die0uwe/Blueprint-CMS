<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Marketplace;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Marketplace\PackageManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test de bestandsstappen van PackageManager (uitpakken, valideren, atomisch deployen,
 * rollback) zonder database, via reflectie op de private methoden.
 */
final class PackageManagerDeployTest extends TestCase
{
    private PackageManager $pm;
    private string $zip;

    protected function setUp(): void
    {
        $this->pm = (new \ReflectionClass(PackageManager::class))->newInstanceWithoutConstructor();
        $this->zip = sys_get_temp_dir() . '/pm-' . bin2hex(random_bytes(6)) . '.zip';
        foreach (['modules', 'themes', 'storage/marketplace/downloads'] as $d) {
            @mkdir(CF_ROOT . '/' . $d, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->zip);
        exec('rm -rf ' . escapeshellarg(CF_ROOT . '/modules') . '/* ' . escapeshellarg(CF_ROOT . '/themes') . '/* ' . escapeshellarg(CF_ROOT . '/storage/marketplace/downloads') . '/*');
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod(PackageManager::class, $method);
        return $m->invoke($this->pm, ...$args);
    }

    /** @param array<string,string> $files */
    private function makeZip(array $files): void
    {
        $z = new \ZipArchive();
        $z->open($this->zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $n => $c) {
            $z->addFromString($n, $c);
        }
        $z->close();
    }

    private function manifest(array $over = []): string
    {
        return json_encode(array_merge(['slug' => 'demo', 'name' => 'Demo', 'version' => '1.0.0'], $over));
    }

    #[Test]
    public function extractsAndValidatesAModuleInASubfolder(): void
    {
        $this->makeZip(['demo/module.json' => $this->manifest(), 'demo/src/Demo.php' => '<?php']);
        [$manifest, $root, $work] = $this->call('extractAndValidate', $this->zip, null);
        $this->assertSame('demo', $manifest['slug']);
        $this->assertFileExists($root . '/src/Demo.php');
        $this->assertStringContainsString('work_', $work);
    }

    #[Test]
    public function ignoresExtractedPathSmuggledInTheManifest(): void
    {
        $this->makeZip(['module.json' => $this->manifest(['_extracted_path' => '/etc']), 'a.txt' => 'x']);
        [$manifest, $root] = $this->call('extractAndValidate', $this->zip, 'demo');
        $this->assertArrayNotHasKey('_extracted_path', $manifest);
        $this->assertNotSame('/etc', $root);
    }

    #[Test]
    public function rejectsEvilZipsAndLeavesNoWorkDirBehind(): void
    {
        $cases = [
            'traversal'     => ['module.json' => $this->manifest(), '../evil.php' => 'x'],
            'slug-dotdot'   => ['module.json' => $this->manifest(['slug' => '..'])],
            'slug-mismatch' => ['module.json' => $this->manifest(['slug' => 'other'])],
            'no-manifest'   => ['readme.txt' => 'x'],
            'bad-json'      => ['module.json' => '{nope'],
            'theme-class'   => ['theme.json' => $this->manifest(['type' => 'theme', 'class' => 'CommunityFusion\\Modules\\X\\X'])],
            'type-mismatch' => ['theme.json' => $this->manifest(['type' => 'module'])],
        ];
        foreach ($cases as $label => $files) {
            $this->makeZip($files);
            try {
                $this->call('extractAndValidate', $this->zip, 'demo');
                $this->fail("Had geweigerd moeten worden: {$label}");
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], glob(CF_ROOT . '/storage/marketplace/downloads/work_*') ?: []);
        $this->assertFileDoesNotExist(CF_ROOT . '/evil.php');
        $this->assertFileDoesNotExist(CF_ROOT . '/storage/evil.php');
    }

    #[Test]
    public function deployReplacesAtomicallyAndCommitRemovesBackup(): void
    {
        mkdir(CF_ROOT . '/modules/demo');
        file_put_contents(CF_ROOT . '/modules/demo/old.txt', 'old');
        $src = sys_get_temp_dir() . '/src-' . bin2hex(random_bytes(4));
        mkdir($src);
        file_put_contents($src . '/new.txt', 'new');

        $d = $this->call('deployPackage', 'demo', 'module', $src);
        $this->assertFileExists(CF_ROOT . '/modules/demo/new.txt');
        $this->assertFileDoesNotExist(CF_ROOT . '/modules/demo/old.txt');
        $this->assertNotNull($d['backup']);
        $this->assertFileExists($d['backup'] . '/old.txt');

        $this->call('commitDeploy', $d);
        $this->assertDirectoryDoesNotExist($d['backup']);
        exec('rm -rf ' . escapeshellarg($src));
    }

    #[Test]
    public function rollbackRestoresThePreviousVersion(): void
    {
        mkdir(CF_ROOT . '/modules/demo');
        file_put_contents(CF_ROOT . '/modules/demo/old.txt', 'old');
        $src = sys_get_temp_dir() . '/src-' . bin2hex(random_bytes(4));
        mkdir($src);
        file_put_contents($src . '/new.txt', 'new');

        $d = $this->call('deployPackage', 'demo', 'module', $src);
        $this->call('rollbackDeploy', $d);
        $this->assertFileExists(CF_ROOT . '/modules/demo/old.txt');
        $this->assertFileDoesNotExist(CF_ROOT . '/modules/demo/new.txt');
        exec('rm -rf ' . escapeshellarg($src));
    }

    #[Test]
    public function rollbackOfAFreshInstallRemovesIt(): void
    {
        $src = sys_get_temp_dir() . '/src-' . bin2hex(random_bytes(4));
        mkdir($src);
        file_put_contents($src . '/new.txt', 'new');
        $d = $this->call('deployPackage', 'fresh', 'module', $src);
        $this->assertNull($d['backup']);
        $this->call('rollbackDeploy', $d);
        $this->assertDirectoryDoesNotExist(CF_ROOT . '/modules/fresh');
        exec('rm -rf ' . escapeshellarg($src));
    }

    #[Test]
    public function deployRefusesDangerousSlugsAndNeverTouchesTheModulesFolder(): void
    {
        mkdir(CF_ROOT . '/modules/keep');
        file_put_contents(CF_ROOT . '/modules/keep/f.txt', 'x');
        $src = sys_get_temp_dir() . '/src-' . bin2hex(random_bytes(4));
        mkdir($src);
        foreach (['..', '', '../x', 'a/b'] as $slug) {
            try {
                $this->call('deployPackage', $slug, 'module', $src);
                $this->fail('Had geweigerd moeten worden: ' . json_encode($slug));
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertFileExists(CF_ROOT . '/modules/keep/f.txt');
        exec('rm -rf ' . escapeshellarg($src));
    }

    #[Test]
    public function uploadWithoutZipExtensionIsRejected(): void
    {
        $this->expectException(PackageException::class);
        $this->pm->installFromUpload('/tmp/x', 'evil.php');
    }
}
