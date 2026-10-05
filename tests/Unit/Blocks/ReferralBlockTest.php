<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Blocks;

use CommunityFusion\Blocks\Types\ReferralBlock;
use CommunityFusion\Core\Block\BlockConfigNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReferralBlockTest extends TestCase
{
    #[Test]
    public function parsesLinesAndSkipsInvalidOnes(): void
    {
        $l = ReferralBlock::parseLinks("Kling | https://klingai.com/?ref=x | Video | -20%\n# commentaar\nKapot | javascript:alert(1)\nZonder url\n\nSuno | http://suno.com");
        $this->assertCount(2, $l);
        $this->assertSame('-20%', $l[0]['badge']);
        $this->assertSame('', $l[1]['note']);
    }

    #[Test]
    public function rendersSponsoredRelEscapesAndDisclosure(): void
    {
        $h = (new ReferralBlock())->render(['links' => 'A<b> | https://e.com/?a=1&b=2 | "x"', 'layout' => 'buttons']);
        $this->assertStringContainsString('rel="sponsored nofollow noopener noreferrer"', $h);
        $this->assertStringContainsString('target="_blank"', $h);
        $this->assertStringContainsString('cf-ref--buttons', $h);
        $this->assertStringContainsString('A&lt;b&gt;', $h);
        $this->assertStringContainsString('a=1&amp;b=2', $h);
        $this->assertStringContainsString('cf-ref-disclosure', $h);
        $off = (new ReferralBlock())->render(['links' => 'A | https://e.com', 'disclosure' => false, 'open_new' => false]);
        $this->assertStringNotContainsString('cf-ref-disclosure', $off);
        $this->assertStringNotContainsString('target=', $off);
    }

    #[Test]
    public function emptyOrInvalidGivesComment(): void
    {
        $this->assertStringContainsString('<!--', (new ReferralBlock())->render(['links' => 'x | ftp://nope']));
    }

    #[Test]
    public function defaultsAreValidAndNormalizerKeepsNewlines(): void
    {
        $b = new ReferralBlock();
        $d = BlockConfigNormalizer::defaults($b->getConfigSchema());
        $this->assertCount(2, ReferralBlock::parseLinks($d['links']));
        $n = BlockConfigNormalizer::normalize($b->getConfigSchema(), ['links' => "A | https://a.nl\nB | https://b.nl", 'layout' => 'zzz']);
        $this->assertSame('cards', $n['layout']);
        $this->assertCount(2, ReferralBlock::parseLinks($n['links']));
    }
}
