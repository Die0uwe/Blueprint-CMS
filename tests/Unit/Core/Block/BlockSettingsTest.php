<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Block;

use CommunityFusion\Core\Block\BlockSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BlockSettingsTest extends TestCase
{
    private const SCHEMA = [
        'server_id' => ['type' => 'string', 'label' => 'Server', 'required' => false],
        'theme'     => ['type' => 'select', 'label' => 'Thema', 'options' => ['dark', 'light'], 'default' => 'dark'],
        'width'     => ['type' => 'integer', 'label' => 'Breedte', 'default' => 350, 'min' => 200, 'max' => 1000],
        'show'      => ['type' => 'boolean', 'label' => 'Tonen', 'default' => true],
        'invite'    => ['type' => 'url', 'label' => 'Invite'],
    ];

    #[Test]
    public function unknownKeysAreDropped(): void
    {
        $r = BlockSettings::normalize(self::SCHEMA, ['server_id' => '123', 'evil' => '<script>']);
        $this->assertSame('123', $r['config']['server_id']);
        $this->assertFalse(array_key_exists('evil', $r['config']));
    }

    #[Test]
    public function integersAreClampedAndValidated(): void
    {
        $this->assertSame(1000, BlockSettings::normalize(self::SCHEMA, ['width' => '5000'])['config']['width']);
        $this->assertSame(200, BlockSettings::normalize(self::SCHEMA, ['width' => 5])['config']['width']);
        $this->assertArrayHasKey('width', BlockSettings::normalize(self::SCHEMA, ['width' => 'abc'])['errors']);
        $this->assertSame(350, BlockSettings::normalize(self::SCHEMA, ['width' => ''])['config']['width']);
    }

    #[Test]
    public function selectOnlyAcceptsListedOptions(): void
    {
        $this->assertSame('light', BlockSettings::normalize(self::SCHEMA, ['theme' => 'light'])['config']['theme']);
        $this->assertArrayHasKey('theme', BlockSettings::normalize(self::SCHEMA, ['theme' => 'neon'])['errors']);
    }

    #[Test]
    public function booleansFollowTheFormAndDefault(): void
    {
        $this->assertFalse(BlockSettings::normalize(self::SCHEMA, ['show' => '0'])['config']['show']);
        $this->assertTrue(BlockSettings::normalize(self::SCHEMA, ['show' => 'on'])['config']['show']);
        $this->assertTrue(BlockSettings::normalize(self::SCHEMA, [])['config']['show']);
    }

    #[Test]
    public function urlsMustBeHttp(): void
    {
        $this->assertArrayHasKey('invite', BlockSettings::normalize(self::SCHEMA, ['invite' => 'javascript:alert(1)'])['errors']);
        $this->assertArrayHasKey('invite', BlockSettings::normalize(self::SCHEMA, ['invite' => 'data:text/html,x'])['errors']);
        $this->assertSame('https://discord.gg/abc', BlockSettings::normalize(self::SCHEMA, ['invite' => 'https://discord.gg/abc'])['config']['invite']);
    }

    #[Test]
    public function requiredAndTooLongAreReported(): void
    {
        $s = ['a' => ['type' => 'string', 'required' => true]];
        $this->assertArrayHasKey('a', BlockSettings::normalize($s, [])['errors']);
        $this->assertArrayHasKey('a', BlockSettings::normalize($s, ['a' => str_repeat('x', 501)])['errors']);
        $this->assertArrayHasKey('a', BlockSettings::normalize($s, ['a' => ['x']])['errors']);
    }

    #[Test]
    public function describeHandlesListAndAssocOptions(): void
    {
        $d = BlockSettings::describe(['t' => ['type' => 'select', 'options' => ['a' => 'Alfa', 'b' => 'Beta']], 'u' => ['type' => 'select', 'options' => ['x']]]);
        $this->assertSame('a', $d[0]['options'][0]['value']);
        $this->assertSame('Alfa', $d[0]['options'][0]['label']);
        $this->assertSame('x', $d[1]['options'][0]['value']);
    }

    #[Test]
    public function stringPatternIsEnforcedAndValueTrimmed(): void
    {
        $schema = ['sid' => ['type' => 'string', 'pattern' => '/^\\d{15,25}$/', 'pattern_msg' => 'nope']];
        $r = BlockSettings::normalize($schema, ['sid' => ' 123456789012345678 ']);
        $this->assertSame('123456789012345678', $r['config']['sid']);
        $r = BlockSettings::normalize($schema, ['sid' => 'abc']);
        $this->assertSame(['sid' => 'nope'], $r['errors']);
        $r = BlockSettings::normalize($schema, ['sid' => '']);
        $this->assertSame([], $r['errors']);
    }
}
