<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Template;

use CommunityFusion\Core\Template\MarkupException;
use CommunityFusion\Core\Template\MarkupRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkupRendererTest extends TestCase
{
    private function r(): MarkupRenderer
    {
        return new MarkupRenderer(require __DIR__ . '/../../../../config/markup-block.php');
    }

    #[Test]
    public function rendersAllowedTwigWithAutoescape(): void
    {
        $out = $this->r()->render('<p>{{ title|upper }}</p>{% if true %}ja{% endif %}', ['title' => '<b>x</b>']);
        $this->assertStringContainsString('&lt;B&gt;X&lt;/B&gt;', $out);
        $this->assertStringContainsString('ja', $out);
    }

    #[Test]
    public function dangerousFunctionsAreRejected(): void
    {
        foreach (["{{ system('rm -rf /') }}", "{{ _self.env }}", "{% include 'x' %}", "{{ 'a'|raw }}", "{{ 'a'|filter('system') }}", "{{ range(1,9)|length }}", "{{ 1..5 }}"] as $m) {
            try {
                $this->r()->render($m, []);
                $this->fail('Had moeten falen: ' . $m);
            } catch (MarkupException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function phpTagsAreNeverExecuted(): void
    {
        $r = $this->r();
        $this->assertTrue($r->containsPhp('<?php echo 1; ?>'));
        $out = $r->render('a <?php echo "PWNED"; ?> b', []);
        $this->assertStringContainsString('&lt;?php', $out);
        $this->assertStringNotContainsString('<?php', $out);
    }

    #[Test]
    public function oversizedInputIsRejected(): void
    {
        $this->expectException(MarkupException::class);
        $this->r()->render(str_repeat('a', $this->r()->maxBytes() + 1), []);
    }

    #[Test]
    public function tooManyForTagsAreRejected(): void
    {
        $this->expectException(MarkupException::class);
        $this->r()->render(str_repeat('{% for i in [1] %}x{% endfor %}', 10), []);
    }
}
