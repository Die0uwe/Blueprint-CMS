<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Plugin;

use CommunityFusion\Core\Marketplace\PackageException;
use CommunityFusion\Core\Plugin\PluginManifest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PluginManifestTest extends TestCase
{
    /** @return array<string,mixed> */
    private function ok(array $over = []): array
    {
        return array_merge(['slug' => 'mijn-plugin', 'name' => 'Mijn Plugin', 'version' => '1.0.0'], $over);
    }

    private function validate(array $m, ?string $slug = 'mijn-plugin', string $cms = '1.29.0', string $php = '8.3.0'): array
    {
        return PluginManifest::validate($m, $slug, $cms, $php);
    }

    private function assertInvalid(array $over, string $why): void
    {
        try {
            $this->validate($this->ok($over));
            $this->fail("Had geweigerd moeten worden: {$why}");
        } catch (PackageException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function acceptsAMinimalAndAFullManifest(): void
    {
        $this->assertSame('mijn-plugin', $this->validate($this->ok())['slug']);
        $full = $this->ok([
            'author' => 'Ouwe', 'description' => 'x', 'requires' => ['php' => '>=8.3', 'blueprint' => '>=1.28.0'],
            'class' => 'CommunityFusion\\Plugins\\MijnPlugin\\Main',
            'autoload' => ['CommunityFusion\\Plugins\\MijnPlugin\\' => 'src/'],
            'permissions' => ['mijn-plugin.use'], 'hooks' => ['content.render', 'admin.menu'],
            'blocks' => ['MijnCustomBlock'], 'routes' => ['routes.php'],
            'settings' => [['key' => 'api_key', 'type' => 'encrypted', 'label' => 'Key'], ['key' => 'aantal', 'type' => 'int']],
        ]);
        $this->assertSame(['mijn-plugin.use'], $this->validate($full)['permissions']);
    }

    #[Test]
    public function rejectsBadSlugsAndMismatchWithFolderName(): void
    {
        foreach (['..', '', '../x', 'A', 'a b'] as $slug) {
            $this->assertInvalid(['slug' => $slug], 'slug ' . json_encode($slug));
        }
        $this->expectException(PackageException::class);
        PluginManifest::validate($this->ok(), 'andere-map', '1.29.0');
    }

    #[Test]
    public function enforcesRequirementsAgainstPhpAndCmsVersion(): void
    {
        $this->assertInvalid(['requires' => ['blueprint' => '>=9.0.0']], 'cms te oud');
        $this->assertInvalid(['requires' => ['php' => '>=99.0']], 'php te oud');
        $this->assertInvalid(['requires' => ['wordpress' => '>=1']], 'onbekende vereiste');
        $this->assertInvalid(['requires' => ['php' => '8.3']], 'geen >=');
        $this->assertSame('mijn-plugin', $this->validate($this->ok(['requires' => ['blueprint' => '>=1.29.0']]))['slug']);
    }

    #[Test]
    public function classMustLiveInThePluginsNamespaceAndNeedAutoload(): void
    {
        $ns = ['CommunityFusion\\Plugins\\X\\' => 'src'];
        $this->assertInvalid(['class' => 'CommunityFusion\\Core\\Application', 'autoload' => $ns], 'core-klasse');
        $this->assertInvalid(['class' => 'CommunityFusion\\Modules\\Evil\\E', 'autoload' => $ns], 'modules-namespace');
        $this->assertInvalid(['class' => 'CommunityFusion\\Plugins\\X\\Main'], 'zonder autoload');
        $this->assertInvalid(['autoload' => ['Evil\\' => 'src']], 'autoload buiten namespace');
        $this->assertInvalid(['autoload' => ['CommunityFusion\\Plugins\\X' => 'src']], 'namespace zonder \\');
        $this->assertInvalid(['autoload' => ['CommunityFusion\\Plugins\\X\\' => '../../src']], 'autoload-pad met ..');
        $this->assertInvalid(['autoload' => ['CommunityFusion\\Plugins\\X\\' => '/etc']], 'absoluut autoload-pad');
    }

    #[Test]
    public function permissionsMustCarryThePluginSlugPrefix(): void
    {
        $this->assertInvalid(['permissions' => ['users.manage']], 'core-permissie');
        $this->assertInvalid(['permissions' => ['*']], 'wildcard');
        $this->assertInvalid(['permissions' => ['mijn-plugin']], 'zonder punt');
        $this->assertInvalid(['permissions' => ['mijn-plugin.Use']], 'hoofdletter');
        $this->assertInvalid(['permissions' => 'mijn-plugin.use'], 'geen lijst');
    }

    #[Test]
    public function validatesSettingsHooksBlocksAndRoutes(): void
    {
        $this->assertInvalid(['settings' => [['key' => 'a', 'type' => 'wat']]], 'onbekend type');
        $this->assertInvalid(['settings' => [['key' => 'A b', 'type' => 'string']]], 'slechte key');
        $this->assertInvalid(['settings' => [['key' => 'a', 'type' => 'string'], ['key' => 'a', 'type' => 'int']]], 'dubbele key');
        $this->assertInvalid(['hooks' => ['Bad Hook']], 'hooknaam');
        $this->assertInvalid(['blocks' => ['lowercase']], 'blocknaam');
        $this->assertInvalid(['blocks' => ['Ok']], 'blocks zonder autoload');
        $this->assertInvalid(['routes' => ['../routes.php']], 'route met ..');
        $this->assertInvalid(['routes' => ['routes.txt']], 'route niet .php');
        $this->assertInvalid(['routes' => ['/etc/x.php']], 'absolute route');
    }

    #[Test]
    public function stripsUnderscoreKeys(): void
    {
        $this->assertArrayNotHasKey('_extracted_path', $this->validate($this->ok(['_extracted_path' => '/etc'])));
    }
}
