<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Block;

use CommunityFusion\Core\Block\BlockConfigNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockConfigNormalizerTest extends TestCase
{
    private const SCHEMA = [
        'host'    => ['type' => 'string',  'label' => 'Host'],
        'port'    => ['type' => 'integer', 'label' => 'Poort', 'default' => 25565],
        'motd'    => ['type' => 'boolean', 'label' => 'MOTD',  'default' => true],
        'theme'   => ['type' => 'select',  'label' => 'Thema', 'options' => ['dark', 'light'], 'default' => 'dark'],
        'content' => ['type' => 'code',    'label' => 'HTML'],
    ];

    #[Test]
    public function testOnlySchemaKeysSurvive(): void
    {
        $out = BlockConfigNormalizer::normalize(self::SCHEMA, ['host' => 'a', 'evil' => 'x']);
        self::assertArrayNotHasKey('evil', $out);
        self::assertSame('a', $out['host']);
    }

    #[Test]
    public function testTypesAreCast(): void
    {
        $out = BlockConfigNormalizer::normalize(self::SCHEMA, ['host' => '  mc.example.nl ', 'port' => '25570', 'motd' => '1']);
        self::assertSame('mc.example.nl', $out['host']);
        self::assertSame(25570, $out['port']);
        self::assertTrue($out['motd']);
    }

    #[Test]
    public function testUncheckedBooleanBecomesFalseAndEmptyIntegerFallsBackToDefault(): void
    {
        $out = BlockConfigNormalizer::normalize(self::SCHEMA, ['motd' => '0', 'port' => '']);
        self::assertFalse($out['motd']);
        self::assertSame(25565, $out['port']);
    }

    #[Test]
    public function testSelectRejectsUnknownOption(): void
    {
        $out = BlockConfigNormalizer::normalize(self::SCHEMA, ['theme' => 'neon']);
        self::assertSame('dark', $out['theme']);
        self::assertSame('light', BlockConfigNormalizer::normalize(self::SCHEMA, ['theme' => 'light'])['theme']);
    }

    #[Test]
    public function testCodeFieldIsNotTrimmed(): void
    {
        $html = "  <p>hi</p>\n";
        self::assertSame($html, BlockConfigNormalizer::normalize(self::SCHEMA, ['content' => $html])['content']);
    }

    #[Test]
    public function testNonScalarInputIsIgnored(): void
    {
        $out = BlockConfigNormalizer::normalize(self::SCHEMA, ['host' => ['x'], 'content' => ['y']]);
        self::assertArrayNotHasKey('host', $out);
        self::assertArrayNotHasKey('content', $out);
    }

    #[Test]
    public function testDefaultsFromSchema(): void
    {
        self::assertSame(
            ['port' => 25565, 'motd' => true, 'theme' => 'dark'],
            BlockConfigNormalizer::defaults(self::SCHEMA)
        );
    }
}
