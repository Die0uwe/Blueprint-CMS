<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Security;

use CommunityFusion\Core\Security\ContentSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentSanitizerTest extends TestCase
{
    #[Test]
    public function testEscapeNeutralisesMarkupAndQuotes(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;',
            ContentSanitizer::escape('<script>alert("x")</script> & \'')
        );
    }

    #[Test]
    public function testEscapeSubstitutesInvalidUtf8(): void
    {
        self::assertSame('a' . "\u{FFFD}" . 'b', ContentSanitizer::escape("a\xC3b"));
    }

    #[Test]
    public function testTextStripsControlCharsAndTruncates(): void
    {
        self::assertSame("a\nb\tc", ContentSanitizer::text("  a\x00\nb\x07\tc\x1B  "));
        self::assertSame('héll', ContentSanitizer::text('héllo wereld', 4));
    }

    #[Test]
    public function testKeepsAllowedFormatting(): void
    {
        $out = ContentSanitizer::sanitizeHtml('<p>Hallo <strong>wereld</strong> <em>x</em></p><ul><li>een</li></ul>');
        self::assertSame('<p>Hallo <strong>wereld</strong> <em>x</em></p><ul><li>een</li></ul>', $out);
    }

    #[Test]
    public function testDropsDangerousElementsIncludingContent(): void
    {
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'textarea', 'template', 'noscript'] as $tag) {
            $out = ContentSanitizer::sanitizeHtml("<p>ok</p><{$tag}>EVIL</{$tag}>");
            self::assertSame('<p>ok</p>', $out, "<{$tag}> moet met inhoud verdwijnen");
        }
    }

    #[Test]
    public function testStripsEventHandlersAndStyleAttributes(): void
    {
        $out = ContentSanitizer::sanitizeHtml('<p onclick="x()" style="background:url(javascript:1)" class="a" id="b">hi</p><img src="https://e.nl/a.png" onerror="x()" alt="a">');
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringNotContainsString('onerror', $out);
        self::assertStringNotContainsString('style', $out);
        self::assertStringNotContainsString('class', $out);
        self::assertStringContainsString('<img src="https://e.nl/a.png" alt="a">', $out);
    }

    #[Test]
    public function testBlocksDangerousUrlSchemes(): void
    {
        $evil = [
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            "java\tscript:alert(1)",
            "java\nscript:alert(1)",
            ' javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            '&#106;avascript:alert(1)',
            'jav&#x09;ascript:alert(1)',
            '\\\\evil.example\\share',
        ];
        foreach ($evil as $href) {
            $out = ContentSanitizer::sanitizeHtml('<a href="' . $href . '">x</a>');
            self::assertStringNotContainsStringIgnoringCase('javascript', $out, $href);
            self::assertStringNotContainsString('href=', $out, 'href moet eruit voor: ' . $href);
        }
    }

    #[Test]
    public function testAllowsSafeLinksAndAddsRel(): void
    {
        $out = ContentSanitizer::sanitizeHtml('<a href="https://example.nl/x?a=1&b=2" target="_blank" title="t">l</a><a href="/intern">i</a><a href="mailto:a@b.nl">m</a>');
        self::assertStringContainsString('href="https://example.nl/x?a=1&amp;b=2"', $out);
        self::assertStringContainsString('rel="noopener noreferrer nofollow"', $out);
        self::assertStringNotContainsString('target', $out);
        self::assertStringContainsString('href="/intern"', $out);
        self::assertStringContainsString('href="mailto:a@b.nl"', $out);
    }

    #[Test]
    public function testImageOnlyAllowsHttpSchemes(): void
    {
        self::assertStringNotContainsString('src=', ContentSanitizer::sanitizeHtml('<img src="data:image/svg+xml,<svg onload=alert(1)>">'));
        self::assertStringNotContainsString('src=', ContentSanitizer::sanitizeHtml('<img src="mailto:a@b.nl">'));
        self::assertStringContainsString('src="http://a.nl/x.png"', ContentSanitizer::sanitizeHtml('<img src="http://a.nl/x.png">'));
    }

    #[Test]
    public function testUnknownTagsAreUnwrappedNotKept(): void
    {
        self::assertSame('<p>tekst</p>', ContentSanitizer::sanitizeHtml('<p><blink>tekst</blink></p>'));
        self::assertSame('<p>x</p>', ContentSanitizer::sanitizeHtml('<p><custom-el onload="x">x</custom-el></p>'));
    }

    #[Test]
    public function testRemovesCommentsAndConditionalComments(): void
    {
        $out = ContentSanitizer::sanitizeHtml('<p>a</p><!-- <script>alert(1)</script> --><!--[if IE]><script>x</script><![endif]--><p>b</p>');
        self::assertSame('<p>a</p><p>b</p>', $out);
    }

    #[Test]
    public function testTextContentStaysEscaped(): void
    {
        $out = ContentSanitizer::sanitizeHtml('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $out);
    }

    #[Test]
    public function testMalformedAndNestedTricksProduceNoActiveContent(): void
    {
        $payloads = [
            '<scr<script>ipt>alert(1)</scr</script>ipt>',
            '<img src=x onerror=alert(1)//',
            '<p><svg><script>alert(1)</script></svg></p>',
            '<math><mtext><table><mglyph><style><!--</style><img title="--&gt;&lt;img src=1 onerror=alert(1)&gt;">',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<a href="x" onmouseover="alert(1)">x</a>',
            '<div><p>unclosed',
            '<<script>script>alert(1)<</script>/script>',
            "<p\x00onclick=alert(1)>x</p>",
        ];
        foreach ($payloads as $p) {
            $out = ContentSanitizer::sanitizeHtml($p);
            foreach (['<script', 'onerror', 'onclick', 'onmouseover', 'onload', '<svg', '<style', '<iframe'] as $needle) {
                self::assertStringNotContainsStringIgnoringCase($needle, $out, "Payload: {$p} -> {$out}");
            }
        }
    }

    #[Test]
    public function testEmptyInput(): void
    {
        self::assertSame('', ContentSanitizer::sanitizeHtml(''));
        self::assertSame('', ContentSanitizer::sanitizeHtml("  \n "));
    }

    #[Test]
    public function testOutputIsStableWhenSanitizedTwice(): void
    {
        $once = ContentSanitizer::sanitizeHtml('<p>a <a href="https://x.nl/?a=1&b=2">l</a> &amp; <code>&lt;b&gt;</code></p>');
        self::assertSame($once, ContentSanitizer::sanitizeHtml($once), 'Idempotent: nogmaals schonen mag niets veranderen.');
    }
}
