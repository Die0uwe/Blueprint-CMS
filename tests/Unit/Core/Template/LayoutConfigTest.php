<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Template;

use CommunityFusion\Core\Template\LayoutConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LayoutConfigTest extends TestCase
{
    #[Test]
    public function garbageGivesDefaults(): void
    {
        foreach (['', 'nonsense', '{', '[]', null, 5] as $raw) {
            $c = LayoutConfig::normalize($raw);
            $this->assertSame('left', $c['header']['logo_align']);
            $this->assertTrue($c['header']['sticky']);
            $this->assertSame([], $c['footer']['cells']);
            $this->assertSame(3, $c['footer']['columns']);
        }
    }

    #[Test]
    public function jsonRoundTripIsStable(): void
    {
        $in = LayoutConfig::fromForm([
            'logo_align' => 'center', 'nav_align' => 'right', 'footer_columns' => '2',
            'cell_type' => ['text', 'links'], 'cell_title' => ['Over', 'Snel'],
            'cell_text' => ['Hallo', ''], 'cell_links' => ['', "Home|/\nGitHub|https://github.com"],
        ]);
        $this->assertSame($in, LayoutConfig::normalize(LayoutConfig::toJson($in)));
        $this->assertSame('center', $in['header']['logo_align']);
        $this->assertFalse($in['header']['show_motd']);   // vinkje niet aangevinkt
        $this->assertSame(2, $in['footer']['columns']);
        $this->assertCount(2, $in['footer']['cells']);
        $this->assertSame('Hallo', $in['footer']['cells'][0]['text']);
        $this->assertCount(2, $in['footer']['cells'][1]['links']);
    }

    #[Test]
    public function invalidOptionsFallBackAndLimitsHold(): void
    {
        $c = LayoutConfig::normalize(['header' => ['logo_align' => 'evil', 'nav_align' => ['x']], 'footer' => ['columns' => 99, 'cells' => array_fill(0, 20, ['type' => 'text', 'text' => 'x'])]]);
        $this->assertSame('left', $c['header']['logo_align']);
        $this->assertSame('left', $c['header']['nav_align']);
        $this->assertSame(4, $c['footer']['columns']);
        $this->assertCount(8, $c['footer']['cells']);
        $this->assertSame(1, LayoutConfig::normalize(['footer' => ['columns' => 0]])['footer']['columns']);
        $this->assertSame([], LayoutConfig::normalize(['footer' => ['cells' => [['type' => 'script'], 'x', ['type' => ['a']]]]])['footer']['cells']);
    }

    #[Test]
    public function linksOnlyAllowOwnPathsAndHttp(): void
    {
        $links = LayoutConfig::parseLinkLines("Ok|/pad\nExtern|https://example.com/x\nEvil|javascript:alert(1)\nProto|//evil.com\nData|data:text/html,x\nQuote|/a\"onclick=\"x\n/kaal\nLeeg|");
        $urls = array_column($links, 'url');
        $this->assertSame(['/pad', 'https://example.com/x', '/kaal'], $urls);
        $this->assertSame('/kaal', $links[2]['label']);
    }

    #[Test]
    public function textIsPlainAndBounded(): void
    {
        $c = LayoutConfig::normalize(['footer' => ['cells' => [['type' => 'text', 'title' => '<b>Titel</b>' . str_repeat('x', 200), 'text' => "<script>alert(1)</script>Regel1\nRegel2\0"]]]]);
        $cell = $c['footer']['cells'][0];
        $this->assertStringNotContainsString('<', $cell['title']);
        $this->assertSame(80, mb_strlen($cell['title']));
        $this->assertStringNotContainsString('<script', $cell['text']);
        $this->assertStringContainsString("Regel1\nRegel2", $cell['text']);
        $this->assertStringNotContainsString("\0", $cell['text']);
    }

    #[Test]
    public function onlyRelevantFieldsSurvivePerCellType(): void
    {
        $c = LayoutConfig::normalize(['footer' => ['cells' => [['type' => 'siteinfo', 'text' => 'weg', 'links' => [['label' => 'a', 'url' => '/a']]]]]]);
        $this->assertSame('', $c['footer']['cells'][0]['text']);
        $this->assertSame([], $c['footer']['cells'][0]['links']);
    }

    #[Test]
    public function cssIsEmptyByDefaultAndSafeOtherwise(): void
    {
        $this->assertSame('', LayoutConfig::css(LayoutConfig::defaults()));
        $css = LayoutConfig::css(['header' => ['logo_align' => 'right', 'nav_align' => 'center', 'sticky' => false]]);
        $this->assertStringContainsString('@media(min-width:769px)', $css);
        $this->assertStringContainsString('order:2', $css);
        $this->assertStringContainsString('justify-content:center', $css);
        $this->assertStringContainsString('#cf-header{position:static}', $css);
        $this->assertStringNotContainsString('<', LayoutConfig::css(['header' => ['logo_align' => '</style><script>']]));
    }

    #[Test]
    public function onlyOneBlocksCellIsKept(): void
    {
        $c = LayoutConfig::normalize(['footer' => ['cells' => [
            ['type' => 'blocks'], ['type' => 'copyright'], ['type' => 'blocks'],
        ]]]);
        $types = array_column($c['footer']['cells'], 'type');
        $this->assertSame(['blocks', 'copyright'], $types);
    }
}

