<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Marketplace;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Marketplace\PackageManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Extractie + deploy van ZIP-packages: thema's (theme.json, submap) en slug-validatie. */
final class PackageManagerTest extends TestCase
{
    private static ?string $root = null;
    private PackageManager $pm;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('CF_ROOT')) {
            self::$root = sys_get_temp_dir() . '/cf_pkg_' . bin2hex(random_bytes(4));
            mkdir(self::$root, 0755, true);
            define('CF_ROOT', self::$root);
        }
        if (!isset(self::$root) || CF_ROOT !== self::$root) {
            $this->markTestSkipped('CF_ROOT is al door een andere test gedefinieerd');
        }
        @mkdir(CF_ROOT . '/storage/marketplace/downloads', 0755, true);
        @mkdir(CF_ROOT . '/themes', 0755, true);
        @mkdir(CF_ROOT . '/modules', 0755, true);
        $this->pm = (new \ReflectionClass(PackageManager::class))->newInstanceWithoutConstructor();
    }

    /** @param array<string,string> $files */
    private function zip(string $slug, array $files): string
    {
        $path = CF_ROOT . "/storage/marketplace/downloads/{$slug}.zip";
        $z = new \ZipArchive();
        $z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $z->addFromString($name, $content);
        }
        $z->close();
        return $path;
    }

    private function call(string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod(PackageManager::class, $method);
        return $m->invoke($this->pm, ...$args);
    }

    #[Test]
    public function themeZipInSubfolderIsDeployedToThemes(): void
    {
        $zip = $this->zip('neon', [
            'neon/theme.json'          => json_encode(['slug' => 'neon', 'name' => 'Neon', 'version' => '1.0.0']),
            'neon/templates/layout.twig' => '<html></html>',
        ]);

        $manifest = $this->call('extractAndValidate', $zip, 'neon');
        $this->assertSame('theme', $manifest['type']);

        $dest = $this->call('deployPackage', 'neon', $manifest);
        $this->assertSame(CF_ROOT . '/themes/neon', $dest);
        $this->assertFileExists($dest . '/theme.json');
        $this->assertFileExists($dest . '/templates/layout.twig');
        $this->assertFileDoesNotExist(CF_ROOT . '/modules/neon');
    }

    #[Test]
    public function moduleZipInRootIsDeployedToModules(): void
    {
        $zip = $this->zip('demo', [
            'module.json' => json_encode(['slug' => 'demo', 'name' => 'Demo', 'version' => '1.0.0']),
            'Demo.php'    => '<?php',
        ]);
        $manifest = $this->call('extractAndValidate', $zip, 'demo');
        $dest = $this->call('deployPackage', 'demo', $manifest);
        $this->assertSame(CF_ROOT . '/modules/demo', $dest);
        $this->assertFileExists($dest . '/Demo.php');
    }

    #[Test]
    public function manifestSlugDecidesTheDestinationFolder(): void
    {
        $zip = $this->zip('upload-name', [
            'module.json' => json_encode(['slug' => 'real-slug', 'name' => 'X', 'version' => '1.0.0']),
        ]);
        $manifest = $this->call('extractAndValidate', $zip, 'upload-name');
        $dest = $this->call('deployPackage', 'upload-name', $manifest);
        $this->assertSame(CF_ROOT . '/modules/real-slug', $dest);
    }

    #[Test]
    public function traversalSlugInManifestIsRejected(): void
    {
        $zip = $this->zip('evil', [
            'module.json' => json_encode(['slug' => 'x/../../etc', 'name' => 'X', 'version' => '1.0.0']),
        ]);
        $this->expectException(PackageException::class);
        $this->call('extractAndValidate', $zip, 'evil');
    }

    #[Test]
    public function invalidInstallSlugIsRejected(): void
    {
        $this->expectException(PackageException::class);
        $this->call('extractAndValidate', CF_ROOT . '/x.zip', '../x');
    }
}
