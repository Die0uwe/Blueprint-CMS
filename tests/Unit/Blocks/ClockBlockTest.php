<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Blocks;

use CommunityFusion\Blocks\Types\ClockBlock;
use CommunityFusion\Core\Block\BlockConfigNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClockBlockTest extends TestCase
{
    #[Test]
    public function digitalHasTimeAndNoFace(): void
    {
        $h = (new ClockBlock())->render(['style' => 'digital', 'timezone' => 'UTC', 'show_seconds' => true, 'show_date' => false]);
        $this->assertStringContainsString('cf-clock-time', $h);
        $this->assertStringNotContainsString('cf-clock-face', $h);
        $this->assertMatchesRegularExpression('/\d\d:\d\d:\d\d/', $h);
    }

    #[Test]
    public function analogHasFaceHandsAndNoDigitalTime(): void
    {
        $h = (new ClockBlock())->render(['style' => 'analog', 'size' => 500, 'show_seconds' => false]);
        $this->assertStringContainsString('cf-clock-face', $h);
        $this->assertStringContainsString('cf-clock-h', $h);
        $this->assertStringNotContainsString('cf-clock-s"', $h);
        $this->assertStringNotContainsString('cf-clock-time', $h);
        $this->assertStringContainsString('width="300"', $h, 'grootte wordt begrensd');
    }

    #[Test]
    public function invalidConfigFallsBackAndLabelIsEscaped(): void
    {
        $h = (new ClockBlock())->render(['style' => 'x', 'timezone' => 'Mars/Base', 'label' => '<script>alert(1)</script>']);
        $this->assertStringContainsString('data-style="digital"', $h);
        $this->assertStringContainsString('data-tz="Europe/Amsterdam"', $h);
        $this->assertStringNotContainsString('<script>alert', $h);
    }

    #[Test]
    public function normalizerRejectsUnknownSelectValues(): void
    {
        $b = new ClockBlock();
        $c = BlockConfigNormalizer::normalize($b->getConfigSchema(), ['style' => 'hax', 'timezone' => 'UTC', 'format' => '12']);
        $this->assertSame('digital', $c['style']);
        $this->assertSame('UTC', $c['timezone']);
        $this->assertSame('12', $c['format']);
    }
}
