<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Marketplace;

use CommunityFusion\Core\Marketplace\ManifestValidator;
use CommunityFusion\Core\Marketplace\PackageException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManifestValidatorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function ok(array $over = []): array
    {
        return array_merge([
            'slug' => 'mijn-plugin', 'name' => 'Mijn Plugin', 'version' => '1.0.0',
            'class' => 'CommunityFusion\\Plugins\\MijnPlugin\\MijnPlugin',
        ], $over);
    }

    #[Test]
    public function acceptsAValidManifestAndDefaultsTypeToModule(): void
    {
        $m = (new ManifestValidator())->validate($this->ok(), 'mijn-plugin');
        $this->assertSame('module', $m['type']);
    }

    #[Test]
    public function rejectsDangerousOrMalformedSlugs(): void
    {
        foreach (['..', '', 'A', 'a/b', '../config', '-abc', 'abc-', 'a b', str_repeat('a', 65), "a\0b"] as $slug) {
            try {
                (new ManifestValidator())->validate($this->ok(['slug' => $slug]));
                $this->fail('Slug had geweigerd moeten worden: ' . json_encode($slug));
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function assertSlugRejectsEmptyAndTraversal(): void
    {
        foreach (['', '..', '../x', '.'] as $slug) {
            $this->expectException(PackageException::class);
            ManifestValidator::assertSlug($slug);
        }
    }

    #[Test]
    public function rejectsSlugThatDiffersFromRequestedSlug(): void
    {
        $this->expectException(PackageException::class);
        (new ManifestValidator())->validate($this->ok(), 'iets-anders');
    }

    #[Test]
    public function stripsInternalUnderscoreKeysFromThePackage(): void
    {
        $m = (new ManifestValidator())->validate($this->ok(['_extracted_path' => '/etc']));
        $this->assertArrayNotHasKey('_extracted_path', $m);
    }

    #[Test]
    public function rejectsClassesOutsideAllowedNamespaces(): void
    {
        foreach (['Evil\\Thing', 'CommunityFusion\\Core\\Application', 'notaclass', 'CommunityFusion\\Modules\\..\\X'] as $class) {
            try {
                (new ManifestValidator())->validate($this->ok(['class' => $class]));
                $this->fail("Klasse had geweigerd moeten worden: {$class}");
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function aThemeMayNotDeclareAPhpClass(): void
    {
        $this->expectException(PackageException::class);
        (new ManifestValidator())->validate($this->ok(['type' => 'theme']));
    }

    #[Test]
    public function rejectsUnknownTypeAndBadVersion(): void
    {
        foreach ([['type' => 'plugin'], ['version' => 'latest'], ['version' => '1.0'], ['name' => '']] as $over) {
            try {
                (new ManifestValidator())->validate($this->ok($over));
                $this->fail('Had geweigerd moeten worden: ' . json_encode($over));
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function rejectsUnsafeAutoloadAndPermissionNames(): void
    {
        foreach ([['autoload' => '../x'], ['autoload' => '/etc'], ['autoload' => 'a\\b'], ['permissions' => ['Bad Name']], ['permissions' => 'x']] as $over) {
            try {
                (new ManifestValidator())->validate($this->ok($over));
                $this->fail('Had geweigerd moeten worden: ' . json_encode($over));
            } catch (PackageException) {
                $this->addToAssertionCount(1);
            }
        }
        $m = (new ManifestValidator())->validate($this->ok(['autoload' => 'src/', 'permissions' => ['mijn-plugin.use']]));
        $this->assertSame('src/', $m['autoload']);
    }
}
