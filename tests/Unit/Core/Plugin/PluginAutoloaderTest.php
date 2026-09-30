<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Plugin;

use CommunityFusion\Core\Plugin\PluginAutoloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PluginAutoloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/pal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/plug/src/Sub', 0755, true);
        mkdir($this->root . '/outside', 0755, true);
        file_put_contents($this->root . '/plug/src/Hello.php', '<?php namespace CommunityFusion\Plugins\AlTest; final class Hello { public const X = 1; }');
        file_put_contents($this->root . '/plug/src/Sub/Deep.php', '<?php namespace CommunityFusion\Plugins\AlTest\Sub; final class Deep {}');
        file_put_contents($this->root . '/outside/Leak.php', '<?php namespace CommunityFusion\Plugins\AlTest; final class Leak {}');
        symlink($this->root . '/outside/Leak.php', $this->root . '/plug/src/Leak.php');
        PluginAutoloader::register($this->root . '/plug', ['CommunityFusion\\Plugins\\AlTest\\' => 'src/']);
    }

    protected function tearDown(): void
    {
        PluginAutoloader::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function loadsClassesFromTheDeclaredNamespaceDirectory(): void
    {
        $this->assertTrue(class_exists('CommunityFusion\\Plugins\\AlTest\\Hello'));
        $this->assertTrue(class_exists('CommunityFusion\\Plugins\\AlTest\\Sub\\Deep'));
    }

    #[Test]
    public function neverFollowsASymlinkOutsideThePluginFolder(): void
    {
        $this->assertFalse(class_exists('CommunityFusion\\Plugins\\AlTest\\Leak'));
    }

    #[Test]
    public function ignoresOtherNamespacesAndWeirdNames(): void
    {
        $this->assertFalse(class_exists('CommunityFusion\\Plugins\\Other\\Hello'));
        $this->assertFalse(class_exists('CommunityFusion\\Plugins\\AlTest\\..\\..\\outside\\Leak'));
    }
}
