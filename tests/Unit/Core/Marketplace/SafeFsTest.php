<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Marketplace;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Marketplace\SafeFs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafeFsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/safefs-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/base/modA/sub', 0755, true);
        mkdir($this->root . '/base-evil', 0755, true);
        mkdir($this->root . '/outside', 0755, true);
        file_put_contents($this->root . '/base/modA/sub/f.txt', 'x');
        file_put_contents($this->root . '/outside/keep.txt', 'keep');
        file_put_contents($this->root . '/base-evil/keep.txt', 'keep');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function deletesATreeInsideTheBase(): void
    {
        SafeFs::deleteTree($this->root . '/base', $this->root . '/base/modA');
        $this->assertDirectoryDoesNotExist($this->root . '/base/modA');
        $this->assertDirectoryExists($this->root . '/base');
    }

    #[Test]
    public function refusesToDeleteTheBaseItselfOrAParent(): void
    {
        foreach ([$this->root . '/base', $this->root . '/base/modA/..', $this->root . '/base/..'] as $path) {
            try {
                SafeFs::deleteTree($this->root . '/base', $path);
                $this->fail("Had geweigerd moeten worden: {$path}");
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertDirectoryExists($this->root . '/base/modA');
    }

    #[Test]
    public function refusesSiblingDirectoryWithSamePrefix(): void
    {
        $this->expectException(PackageException::class);
        SafeFs::deleteTree($this->root . '/base', $this->root . '/base-evil');
    }

    #[Test]
    public function neverFollowsSymlinksOutsideTheBase(): void
    {
        symlink($this->root . '/outside', $this->root . '/base/modA/escape');
        SafeFs::deleteTree($this->root . '/base', $this->root . '/base/modA');
        $this->assertFileExists($this->root . '/outside/keep.txt');
        $this->assertDirectoryDoesNotExist($this->root . '/base/modA');
    }

    #[Test]
    public function copyTreeSkipsSymlinks(): void
    {
        symlink($this->root . '/outside', $this->root . '/base/modA/escape');
        SafeFs::copyTree($this->root . '/base/modA', $this->root . '/copy');
        $this->assertFileExists($this->root . '/copy/sub/f.txt');
        $this->assertFalse(file_exists($this->root . '/copy/escape'));
    }
}
