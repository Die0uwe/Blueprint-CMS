<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Blocks;

use CommunityFusion\Blocks\Types\HtmlBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** HTML-blok: losse markup inline, een volledige pagina afgeschermd in een iframe. */
final class HtmlBlockTest extends TestCase
{
    private const DOCUMENT = <<<'HTML'
<!DOCTYPE html>
<html lang="nl"><head><style>:root{--bg:#0f1220}body{background:var(--bg)}h1{color:red}</style></head>
<body><h1>Hallo "ScriptSpace" & co</h1>
<script>const x = `a<\/script>`; document.title = 'x';</script></body></html>
HTML;

    #[Test]
    public function aLooseFragmentStaysInlineAndUntouched(): void
    {
        $html = (new HtmlBlock())->render(['content' => '<p class="x">Hoi <b>daar</b></p>']);
        $this->assertSame('<p class="x">Hoi <b>daar</b></p>', $html);
    }

    #[Test]
    public function aFullDocumentIsIsolatedInASandboxedIframe(): void
    {
        $html = (new HtmlBlock())->render(['content' => self::DOCUMENT]);

        $this->assertStringStartsWith('<iframe', $html);
        $this->assertStringContainsString('sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"', $html);
        $this->assertStringNotContainsString('allow-same-origin', $html, 'de blok-HTML mag niet bij de cookies/DOM van de site');
        // De site-CSS en de blok-CSS mogen elkaar niet raken: niets staat los in de pagina.
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('srcdoc="<', $html);
    }

    #[Test]
    public function theIframeSrcdocRoundTripsTheOriginalDocumentExactly(): void
    {
        $html = (new HtmlBlock())->render(['content' => self::DOCUMENT]);
        $this->assertSame(1, preg_match('/srcdoc="([^"]*)"/', $html, $m), 'srcdoc moet één onafgebroken attribuut zijn');

        $decoded = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringStartsWith(self::DOCUMENT, $decoded);
        // Alles wat het blok toevoegt staat NA de inhoud, zodat <!DOCTYPE> het eerste blijft (geen quirks mode).
        $this->assertStringStartsWith('<!DOCTYPE html>', $decoded);
        $this->assertStringContainsString('<base target="_blank">', $decoded);
    }

    #[Test]
    public function autoHeightReportsViaPostMessageAndTheParentListensForIt(): void
    {
        $html = (new HtmlBlock())->render(['content' => self::DOCUMENT]);
        $this->assertStringContainsString('postMessage', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $this->assertStringContainsString('addEventListener("message"', $html);
        $this->assertStringContainsString('e.source!==f.contentWindow', $html, 'alleen berichten van het eigen iframe');

        preg_match('/id="cf-html-([0-9a-f]{8})"/', $html, $m);
        $this->assertNotEmpty($m, 'iframe krijgt een eigen id');
        $this->assertStringContainsString('"' . $m[1] . '"', $html);
    }

    #[Test]
    public function aFixedHeightSkipsTheResizeScripts(): void
    {
        $html = (new HtmlBlock())->render(['content' => self::DOCUMENT, 'height' => 720]);
        $this->assertStringContainsString('height:720px', $html);
        $this->assertStringNotContainsString('addEventListener("message"', $html);
        $this->assertStringNotContainsString('postMessage', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
    }

    #[Test]
    public function modeForcesTheChoice(): void
    {
        $fragment = '<p>los</p>';
        $this->assertStringStartsWith('<iframe', (new HtmlBlock())->render(['content' => $fragment, 'mode' => 'iframe']));
        $this->assertSame(self::DOCUMENT, (new HtmlBlock())->render(['content' => self::DOCUMENT, 'mode' => 'inline']));
        // Onbekende waarde valt terug op auto.
        $this->assertSame($fragment, (new HtmlBlock())->render(['content' => $fragment, 'mode' => 'nonsens']));
    }

    #[Test]
    public function emptyContentRendersNothing(): void
    {
        $this->assertSame('', (new HtmlBlock())->render([]));
        $this->assertSame('', (new HtmlBlock())->render(['content' => "  \n "]));
    }

    #[Test]
    public function documentDetection(): void
    {
        $this->assertTrue(HtmlBlock::looksLikeDocument('<!doctype html><p>x</p>'));
        $this->assertTrue(HtmlBlock::looksLikeDocument('  <HTML><body></body></HTML>'));
        $this->assertTrue(HtmlBlock::looksLikeDocument('<body class="x">hoi</body>'));
        $this->assertFalse(HtmlBlock::looksLikeDocument('<div class="hero"><h1>Hoi</h1><style>.a{}</style></div>'));
        $this->assertFalse(HtmlBlock::looksLikeDocument('<header>kop</header><p>tekst over een html-pagina</p>'));
    }

    #[Test]
    public function theSchemaOffersModeAndHeightWithBackwardsCompatibleDefaults(): void
    {
        $schema = (new HtmlBlock())->getConfigSchema();
        $this->assertSame('auto', $schema['mode']['default']);
        $this->assertSame(0, $schema['height']['default']);
        $this->assertSame(['auto', 'inline', 'iframe'], $schema['mode']['options']);
    }
}
