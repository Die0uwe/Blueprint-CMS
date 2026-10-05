<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Blocks;

use CommunityFusion\Blocks\Types\HtmlBlock;
use CommunityFusion\Blocks\Types\LoginBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** AbstractBlock::isVisibleFor(): bloktype-eigen zichtbaarheid per bezoeker. */
final class BlockVisibilityTest extends TestCase
{
    #[Test]
    public function theLoginBlockIsOnlyForGuests(): void
    {
        $login = new LoginBlock();
        $this->assertTrue($login->isVisibleFor([], false), 'uitgelogd: login tonen');
        $this->assertFalse($login->isVisibleFor([], true), 'ingelogd: login verbergen');
    }

    #[Test]
    public function otherBlocksAreVisibleToEveryone(): void
    {
        $html = new HtmlBlock();
        $this->assertTrue($html->isVisibleFor(['content' => '<p>x</p>'], false));
        $this->assertTrue($html->isVisibleFor(['content' => '<p>x</p>'], true));
    }
}
