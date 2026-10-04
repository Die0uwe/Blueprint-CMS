<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Template;

use CommunityFusion\Core\Template\ThemeCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThemeCatalogTest extends TestCase
{
    private function theme(array $over = []): array
    {
        return ['evil' => $over + [
            'name' => 'x', 'mode' => 'dark',
            'colors' => ['bg' => '#000', 'surface' => '#111111', 'accent' => '#ff0000', 'text' => '#fff'],
            'colors_alt' => ['bg' => '#ffffff', 'text' => '#000000'],
        ]];
    }

    #[Test]
    public function builds_selectors_for_both_modes_and_rgb_tokens(): void
    {
        $css = ThemeCatalog::css($this->theme());
        $this->assertStringContainsString('html[data-theme="evil"]{', $css);
        $this->assertStringContainsString('html[data-theme="evil"][data-mode="alt"]{', $css);
        $this->assertStringContainsString('--accent-rgb:255,0,0;', $css);
        $this->assertStringContainsString('--bg:#000;', $css);
        $this->assertStringContainsString('color-scheme:light', $css);
    }

    #[Test]
    public function light_mode_gets_dark_tint_and_readable_message_colours(): void
    {
        $css = ThemeCatalog::css(['l' => ['mode' => 'light', 'colors' => ['bg' => '#ffffff']]]);
        $this->assertStringContainsString('--fg-rgb:15,23,42;', $css);
        $this->assertStringContainsString('--error-text:#b91c1c;', $css);
    }

    #[Test]
    public function rejects_non_hex_colours(): void
    {
        $css = ThemeCatalog::css($this->theme(['colors' => ['bg' => 'red;}</style><script>alert(1)</script>', 'accent' => 'url(x)', 'text' => '#abc']]));
        $this->assertStringNotContainsString('<script', $css);
        $this->assertStringNotContainsString('red', $css);
        $this->assertStringNotContainsString('url(', $css);
        $this->assertStringContainsString('--text:#abc;', $css);
    }

    #[Test]
    public function rejects_dangerous_style_values(): void
    {
        $css = ThemeCatalog::css($this->theme(['style' => [
            'body_image' => 'url(https://evil.example/x.png)',
            'font'       => 'Arial;} body{display:none',
            'radius'     => '10px;}x{',
            'body_size'  => '100% 6px',
        ]]));
        $this->assertStringNotContainsString('evil.example', $css);
        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString('--radius:', $css);
        $this->assertStringContainsString('--body-size:100% 6px;', $css);
    }

    #[Test]
    public function allows_gradients_and_valid_fonts(): void
    {
        $css = ThemeCatalog::css($this->theme(['style' => [
            'body_image' => 'radial-gradient(1px 1px at 20% 30%, #fff, transparent)',
            'font'       => 'Trebuchet MS, Verdana, sans-serif',
            'radius'     => '22px',
        ]]));
        $this->assertStringContainsString('--body-image:radial-gradient(1px 1px at 20% 30%, #fff, transparent);', $css);
        $this->assertStringContainsString("--font:Trebuchet MS, Verdana, sans-serif;", $css);
        $this->assertStringContainsString('--radius:22px;', $css);
    }

    #[Test]
    public function skips_invalid_slugs(): void
    {
        $css = ThemeCatalog::css(['Bad Slug"' => ['colors' => ['bg' => '#000']]]);
        $this->assertSame('', $css);
    }

    #[Test]
    public function picker_lists_mode_alt_and_swatch(): void
    {
        $p = ThemeCatalog::picker($this->theme());
        $this->assertSame('evil', $p[0]['slug']);
        $this->assertTrue($p[0]['hasAlt']);
        $this->assertSame('dark', $p[0]['mode']);
        $this->assertSame(['#000', '#ff0000'], $p[0]['swatch']);
    }

    #[Test]
    public function bundled_themes_are_complete_and_readable(): void
    {
        $themes = ThemeCatalog::scan(dirname(__DIR__, 4) . '/themes');
        $this->assertTrue(count($themes) >= 6, 'minimaal 6 thema\'s');
        $lum = static function (string $h): float {
            $h = ltrim($h, '#');
            if (strlen($h) === 3) { $h = $h[0].$h[0].$h[1].$h[1].$h[2].$h[2]; }
            $c = array_map(static fn($i) => hexdec(substr($h, $i, 2)) / 255, [0, 2, 4]);
            $c = array_map(static fn($v) => $v <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4, $c);
            return .2126 * $c[0] + .7152 * $c[1] + .0722 * $c[2];
        };
        $ratio = static function (string $a, string $b) use ($lum): float {
            [$x, $y] = [$lum($a), $lum($b)];
            return (max($x, $y) + .05) / (min($x, $y) + .05);
        };
        foreach ($themes as $slug => $t) {
            $this->assertTrue(isset($t['colors'], $t['colors_alt']), "{$slug}: beide modi");
            foreach (['colors', 'colors_alt'] as $set) {
                $c = $t[$set];
                foreach (['text', 'text_dim', 'link'] as $k) {
                    $this->assertTrue($ratio($c[$k], $c['bg']) >= 4.5 && $ratio($c[$k], $c['surface']) >= 4.5, "{$slug}/{$set}: {$k} contrast");
                }
                $this->assertTrue($ratio($c['on_accent'], $c['accent']) >= 4.5, "{$slug}/{$set}: knoptekst op accent");
            }
        }
    }
}
