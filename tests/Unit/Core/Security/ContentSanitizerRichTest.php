<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Security;

use CommunityFusion\Core\Security\ContentSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentSanitizerRichTest extends TestCase
{
    #[Test]
    public function testLegacyPlainTextIsEscapedWithLineBreaks(): void
    {
        // Platte tekst met een "<"-teken dat geen tag is: blijft tekst, geëscaped.
        self::assertSame("1 &lt; 2<br />\n3 &gt; 2", ContentSanitizer::renderRich("1 < 2\n3 > 2"));
        self::assertSame("regel1<br />\nregel2", ContentSanitizer::renderRich("regel1\nregel2"));
    }

    #[Test]
    public function testEditorHtmlKeepsAllowedFormatting(): void
    {
        $out = ContentSanitizer::renderRich('<p>Hallo <strong>wereld</strong></p><ul><li>een</li></ul>');
        self::assertStringContainsString('<strong>wereld</strong>', $out);
        self::assertStringContainsString('<li>een</li>', $out);
    }

    #[Test]
    public function testScriptEventHandlersAndJavascriptUrlsAreRemoved(): void
    {
        $evil = '<p onclick="x()">hi</p><script>alert(1)</script><img src="x" onerror="alert(1)">'
              . '<a href="javascript:alert(1)">klik</a><iframe src="https://evil"></iframe>'
              . '<p style="position:fixed">s</p>';
        $out = ContentSanitizer::renderRich($evil);
        foreach (['<script', 'onerror', 'onclick', 'javascript:', '<iframe', 'style='] as $bad) {
            self::assertStringNotContainsString($bad, $out, "bevat nog: {$bad}");
        }
        self::assertStringContainsString('hi', $out);
    }

    #[Test]
    public function testLinksGetNoopenerRel(): void
    {
        $out = ContentSanitizer::renderRich('<p><a href="https://example.nl" target="_blank">x</a></p>');
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $out);
        self::assertStringNotContainsString('target=', $out);
    }

    #[Test]
    public function testCleanForStorageTurnsEmptyEditorOutputIntoEmptyString(): void
    {
        self::assertSame('', ContentSanitizer::cleanForStorage('<p></p>'));
        self::assertSame('', ContentSanitizer::cleanForStorage("<p><br></p>\n"));
        self::assertSame('', ContentSanitizer::cleanForStorage("<p>&nbsp;</p>"));
        self::assertSame('', ContentSanitizer::cleanForStorage('   '));
    }

    #[Test]
    public function testCleanForStorageKeepsMediaOnlyContentAndPlainText(): void
    {
        self::assertStringContainsString('<img', ContentSanitizer::cleanForStorage('<p><img src="https://x.nl/a.png" alt=""></p>'));
        self::assertSame('gewoon tekst', ContentSanitizer::cleanForStorage("  gewoon tekst "));
    }

    #[Test]
    public function testCleanForStorageSanitizesHtml(): void
    {
        $out = ContentSanitizer::cleanForStorage('<p>ok</p><script>alert(1)</script>');
        self::assertSame('<p>ok</p>', $out);
    }

    #[Test]
    public function testLooksLikeHtml(): void
    {
        self::assertTrue(ContentSanitizer::looksLikeHtml('<p>x</p>'));
        self::assertFalse(ContentSanitizer::looksLikeHtml('1 < 2 en 3 > 2'));
        self::assertFalse(ContentSanitizer::looksLikeHtml('mail me @ a<b.nl'));
    }
}
