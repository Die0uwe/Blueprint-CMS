<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Template;

use CommunityFusion\Core\Template\ThemeSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ThemeSettingsTest extends TestCase
{
    #[Test]
    public function emptyInputGivesTheDefaults(): void
    {
        $s = ThemeSettings::load([]);
        $this->assertSame('wide', $s['layout_mode']);
        $this->assertSame(1600, $s['layout_width']);
        $this->assertSame('', $s['logo']);
        $this->assertSame('', $s['color_primary']);
    }

    #[Test]
    public function widthsAreClampedAndGarbageFallsBack(): void
    {
        $s = ThemeSettings::load(['layout_width' => '99999', 'sidebar_width' => '5', 'banner_height' => 'abc']);
        $this->assertSame(3840, $s['layout_width']);
        $this->assertSame(180, $s['sidebar_width']);
        $this->assertSame(500, ThemeSettings::load(['sidebar_width' => '9999'])['sidebar_width']);
        $this->assertSame(220, $s['banner_height']);
    }

    #[Test]
    public function layoutModeOnlyAcceptsKnownModes(): void
    {
        $this->assertSame('boxed', ThemeSettings::load(['layout_mode' => 'boxed'])['layout_mode']);
        $this->assertSame('full', ThemeSettings::load(['layout_mode' => 'full'])['layout_mode']);
        $this->assertSame('fluid', ThemeSettings::load(['layout_mode' => 'fluid'])['layout_mode']);
        $this->assertSame('wide', ThemeSettings::load(['layout_mode' => 'weird'])['layout_mode']);
    }

    #[Test]
    public function cssMaxWidthFollowsMode(): void
    {
        $this->assertStringContainsString('--cf-max-w:1600px', ThemeSettings::css(['layout_mode' => 'wide']));
        $this->assertStringContainsString('--cf-max-w:100%', ThemeSettings::css(['layout_mode' => 'full']));
        $this->assertStringContainsString('--cf-max-w:75vw', ThemeSettings::css(['layout_mode' => 'fluid', 'layout_fluid' => '75']));
        $this->assertStringContainsString('--cf-max-w:100vw', ThemeSettings::css(['layout_mode' => 'fluid', 'layout_fluid' => '999']));
        $this->assertStringContainsString('--cf-max-w:50vw', ThemeSettings::css(['layout_mode' => 'fluid', 'layout_fluid' => '1']));
    }

    #[Test]
    public function hexColorsAreNormalisedAndInvalidOnesDropped(): void
    {
        $this->assertSame('#aabbcc', ThemeSettings::hex('#ABC'));
        $this->assertSame('#112233', ThemeSettings::hex(' #112233 '));
        $this->assertNull(ThemeSettings::hex('red'));
        $this->assertNull(ThemeSettings::hex('#12345'));
        $this->assertNull(ThemeSettings::hex('#fff;}body{display:none'));
        $this->assertNull(ThemeSettings::hex(['#fff']));
        $this->assertSame('', ThemeSettings::load(['color_primary' => 'url(x)'])['color_primary']);
    }

    #[Test]
    public function onlyOwnMediaThemePathsAreAcceptedForImages(): void
    {
        $this->assertSame('/media/theme/ab12.png', ThemeSettings::load(['logo' => '/media/theme/ab12.png'])['logo']);
        foreach (['http://evil/x.png', '/media/theme/../../etc/passwd', '/media/branding/x.png', '/media/theme/a b.png', 'javascript:alert(1)'] as $bad) {
            $this->assertSame('', ThemeSettings::load(['logo' => $bad, 'banner' => $bad])['logo'], $bad);
        }
    }

    #[Test]
    public function cssAlwaysCarriesLayoutVariablesButNoColorsByDefault(): void
    {
        $css = ThemeSettings::css([]);
        $this->assertStringContainsString('--cf-max-w:1600px', $css);
        $this->assertStringContainsString('--cf-sidebar-w:280px', $css);
        $this->assertStringNotContainsString('--accent', $css);
    }

    #[Test]
    public function colorOverridesBecomeCssVariablesWithRgbTriplets(): void
    {
        $css = ThemeSettings::css(['color_primary' => '#ff0000', 'color_secondary' => '#00ff00', 'color_background' => '#101010', 'color_accent' => '#0000ff']);
        $this->assertStringContainsString('--accent:#ff0000', $css);
        $this->assertStringContainsString('--accent-rgb:255,0,0', $css);
        $this->assertStringContainsString('--accent2:#00ff00', $css);
        $this->assertStringContainsString('--bg:#101010', $css);
        $this->assertStringContainsString('--gold:#0000ff', $css);
        $this->assertStringContainsString('html[data-theme][data-theme][data-theme]{', $css);
    }

    #[Test]
    public function textOnThePrimaryColorStaysReadable(): void
    {
        $this->assertStringContainsString('--on-accent:#0b0b0f', ThemeSettings::css(['color_primary' => '#ffff00']));
        $this->assertStringContainsString('--on-accent:#ffffff', ThemeSettings::css(['color_primary' => '#000080']));
    }

    #[Test]
    public function cssCannotBeBrokenOutOfByInput(): void
    {
        $css = ThemeSettings::css(['color_primary' => 'red;}</style><script>alert(1)</script>', 'layout_width' => '1200px;}']);
        $this->assertStringNotContainsString('<', $css);
        $this->assertStringNotContainsString('script', $css);
        $this->assertStringContainsString('--cf-max-w:1600px', $css); // ongeldige breedte → default
    }

    #[Test]
    public function presetsSetModeAndWidthsAndUnknownMeansCustom(): void
    {
        $s = ThemeSettings::applyPreset(ThemeSettings::load([]), 'compact');
        $this->assertSame('boxed', $s['layout_mode']);
        $this->assertSame(1200, $s['layout_width']);
        $this->assertSame(260, $s['sidebar_width']);
        $this->assertSame('compact', $s['layout_preset']);

        $c = ThemeSettings::applyPreset(ThemeSettings::load(['layout_width' => 1000]), 'bestaat-niet');
        $this->assertSame('aangepast', $c['layout_preset']);
        $this->assertSame(1000, $c['layout_width']);
    }

    #[Test]
    public function everyPresetIsWithinTheValidRanges(): void
    {
        foreach (ThemeSettings::PRESETS as $id => $p) {
            $this->assertTrue(in_array($p['mode'], ['wide', 'boxed', 'full'], true), $id);
            $this->assertTrue($p['width'] >= 900 && $p['width'] <= 3840, $id);
            $this->assertTrue($p['sidebar'] >= 180 && $p['sidebar'] <= 500, $id);
        }
    }

    #[Test]
    public function eachSidebarCanHaveItsOwnWidthAndZeroMeansSameAsTheGeneralOne(): void
    {
        $css = ThemeSettings::css(['sidebar_width' => 260]);
        $this->assertStringContainsString('--cf-sidebar-l-w:260px', $css);
        $this->assertStringContainsString('--cf-sidebar-r-w:260px', $css);

        $css = ThemeSettings::css(['sidebar_width' => 260, 'sidebar_left' => '320', 'sidebar_right' => '9999']);
        $this->assertStringContainsString('--cf-sidebar-l-w:320px', $css);
        $this->assertStringContainsString('--cf-sidebar-r-w:500px', $css, 'begrensd op 500');

        // Een slider die op de laagste stand (170) staat betekent "zelfde als de gewone breedte".
        $s = ThemeSettings::load(['sidebar_left' => '170', 'sidebar_right' => 'abc']);
        $this->assertSame(0, $s['sidebar_left']);
        $this->assertSame(0, $s['sidebar_right']);
    }

    #[Test]
    public function ultrawidePresetsExistAndStayWithinTheSliderRange(): void
    {
        foreach (['ultrawide', 'superwide', 'scherm'] as $id) {
            $this->assertArrayHasKey($id, ThemeSettings::PRESETS);
        }
        $s = ThemeSettings::applyPreset(ThemeSettings::load([]), 'ultrawide');
        $this->assertSame(2560, $s['layout_width']);
        $this->assertStringContainsString('--cf-max-w:2560px', ThemeSettings::css($s));
        $this->assertStringContainsString('--cf-max-w:100%', ThemeSettings::css(ThemeSettings::applyPreset(ThemeSettings::load([]), 'scherm')));
    }

    #[Test]
    public function aPresetResetsTheSeparateSidebarWidths(): void
    {
        $s = ThemeSettings::applyPreset(ThemeSettings::load(['sidebar_left' => 400, 'sidebar_right' => 300]), 'ruim');
        $this->assertSame(0, $s['sidebar_left']);
        $this->assertSame(0, $s['sidebar_right']);
    }

    #[Test]
    public function textLinkSurfaceAndBorderColorsCanBeOverridden(): void
    {
        $css = ThemeSettings::css([
            'color_text' => '#111111', 'color_link' => '#0000ff', 'color_surface' => '#ffffff', 'color_border' => '#cccccc',
        ]);
        $this->assertStringContainsString('--text:#111111;', $css);
        $this->assertStringContainsString('--link:#0000ff;', $css);
        $this->assertStringContainsString('--surface:#ffffff;--surface-rgb:255,255,255;', $css);
        $this->assertStringContainsString('--border:#cccccc;', $css);
        $this->assertSame('', ThemeSettings::load(['color_text' => 'url(x)'])['color_text']);
    }
}
