<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Auth;

use CommunityFusion\Core\Auth\OAuth\ProviderRegistry;
use CommunityFusion\Core\Hook\HookManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    private HookManager $hooks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hooks = new HookManager();
    }

    private function registry(array $enabled, array $settings): ProviderRegistry
    {
        return new ProviderRegistry($this->hooks, fn() => ['enabled' => $enabled, 'settings' => $settings]);
    }

    private function slugs(array $providers): array
    {
        return array_map(fn(array $p) => $p['slug'], $providers);
    }

    private function creds(): array
    {
        return ['client_id' => 'abc', 'client_secret' => 'enc:xyz'];
    }

    #[Test]
    public function moduleUitIsVerborgenOokAlsGeconfigureerd(): void
    {
        $r = $this->registry(['github' => false], ['github' => $this->creds()]);

        $this->assertFalse(in_array('github', $this->slugs($r->available()), true));
        $p = $r->find('github');
        $this->assertFalse($p['enabled']);
        $this->assertTrue($p['configured']);
    }

    #[Test]
    public function moduleAanMaarOngeconfigureerdIsVerborgen(): void
    {
        $r = $this->registry(['github' => true, 'google' => true], [
            'github' => ['client_id' => 'abc', 'client_secret' => ''],   // secret leeg
            'google' => ['client_id' => '  ', 'client_secret' => 'x'],   // id leeg
        ]);

        $this->assertSame([], $this->slugs($r->available()));
        $this->assertFalse($r->find('github')['configured']);
        $this->assertFalse($r->find('google')['configured']);
    }

    #[Test]
    public function geenModulerijOfInstellingenBetekentVerborgen(): void
    {
        $r = $this->registry([], []);
        $this->assertSame([], $r->available());
        $this->assertCount(5, $r->all());
    }

    #[Test]
    public function aanEnGeconfigureerdIsZichtbaarMetJuisteVelden(): void
    {
        $r = $this->registry(
            ['github' => true, 'twitch' => true, 'discord' => false],
            ['github' => $this->creds(), 'twitch' => $this->creds(), 'discord' => $this->creds()],
        );

        $this->assertSame(['github', 'twitch'], $this->slugs($r->available()));

        $gh = $r->find('github');
        $this->assertSame('GitHub', $gh['label']);
        $this->assertSame('/auth/github/login', $gh['login_url']);
        $this->assertSame('/auth/github', $gh['link_url']);
        $this->assertTrue($gh['enabled']);
        $this->assertTrue($gh['configured']);
        $this->assertNotSame('', $gh['icon']);
    }

    #[Test]
    public function filterKanProviderToevoegen(): void
    {
        $this->hooks->addFilter(ProviderRegistry::FILTER, function (array $defs): array {
            $defs[] = ['slug' => 'facebook', 'label' => 'Facebook', 'icon' => 'f', 'color' => '#1877F2'];
            return $defs;
        });

        // Facebook: via module-status/instellingen afgeleid, net als ingebouwde providers.
        $r = $this->registry(['facebook' => true], ['facebook' => $this->creds()]);
        $this->assertContains('facebook', $this->slugs($r->available()));
        $this->assertSame('/auth/facebook/login', $r->find('facebook')['login_url']);

        // Zonder instellingen blijft hij verborgen, wel bekend in all().
        $r2 = $this->registry(['facebook' => true], []);
        $this->assertFalse(in_array('facebook', $this->slugs($r2->available()), true));
        $this->assertNotNull($r2->find('facebook'));
    }

    #[Test]
    public function filterKanEnabledEnConfiguredZelfBepalen(): void
    {
        $this->hooks->addFilter(ProviderRegistry::FILTER, function (array $defs): array {
            $defs[] = ['slug' => 'youtube', 'label' => 'YouTube', 'enabled' => true, 'configured' => true];
            return $defs;
        });

        $r = $this->registry([], []);
        $this->assertSame(['youtube'], $this->slugs($r->available()));
    }

    #[Test]
    public function filterKanProviderVerwijderenEnOngeldigeDefinitiesWordenGenegeerd(): void
    {
        $this->hooks->addFilter(ProviderRegistry::FILTER, function (array $defs): array {
            $defs = array_values(array_filter($defs, fn($d) => $d['slug'] !== 'battlenet'));
            $defs[] = ['slug' => 'Bad Slug/../', 'label' => 'Evil'];   // onveilige slug
            $defs[] = ['slug' => 'nolabel'];                            // geen label
            $defs[] = 'rommel';
            return $defs;
        });

        $r = $this->registry([], []);
        $slugs = $this->slugs($r->all());
        $this->assertFalse(in_array('battlenet', $slugs, true));
        $this->assertFalse(in_array('nolabel', $slugs, true));
        $this->assertCount(4, $slugs);
    }

    #[Test]
    public function filterDieGeenArrayTeruggeeftLaatIngebouwdeLijstIntact(): void
    {
        $this->hooks->addFilter(ProviderRegistry::FILTER, fn() => null);
        $this->assertCount(5, $this->registry([], [])->all());
    }

    #[Test]
    public function fromDbLeestModuleStatusEnInstellingen(): void
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $db = new \CommunityFusion\Core\Database\Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string) getenv('BP_TEST_USER'), 'password' => (string) getenv('BP_TEST_PASS'),
        ]);

        $db->execute("DELETE FROM cf_settings WHERE `group` = 'github'");
        $db->execute("DELETE FROM cf_modules WHERE slug = 'github'");
        try {
            $reg = fn() => ProviderRegistry::fromDb($db, $this->hooks)->find('github');

            $this->assertFalse($reg()['enabled']);

            $db->execute("INSERT INTO cf_modules (slug, name, version, is_enabled) VALUES ('github', 'GitHub', '1.0.0', 1)");
            $this->assertTrue($reg()['enabled']);
            $this->assertFalse($reg()['configured']);

            $db->execute("INSERT INTO cf_settings (`group`, `key`, `value`, `type`) VALUES ('github', 'client_id', 'id', 'string'), ('github', 'client_secret', 'geheim', 'encrypted')");
            $this->assertTrue($reg()['configured']);
            $this->assertCount(1, array_filter(
                ProviderRegistry::fromDb($db, $this->hooks)->available(),
                fn($p) => $p['slug'] === 'github'
            ));

            $db->execute("UPDATE cf_modules SET is_enabled = 0 WHERE slug = 'github'");
            $this->assertFalse($reg()['enabled']);
        } finally {
            $db->execute("DELETE FROM cf_settings WHERE `group` = 'github'");
            $db->execute("DELETE FROM cf_modules WHERE slug = 'github'");
        }
    }

    #[Test]
    public function pluginCannotInjectUnsafeUrlsColorsOrOverrideBuiltIns(): void
    {
        $this->hooks->addFilter(ProviderRegistry::FILTER, function (array $defs): array {
            $defs[] = ['slug' => 'discord', 'label' => 'Phish', 'login_url' => 'https://evil.example/phish'];
            $defs[] = ['slug' => 'extra', 'label' => 'Extra', 'login_url' => 'javascript:alert(1)', 'link_url' => '//evil.example', 'color' => 'red;position:fixed', 'text_color' => 'url(x)'];
            $defs[] = ['slug' => "evil\n", 'label' => 'Newline'];
            return $defs;
        });
        $all = $this->registry(['discord' => true, 'extra' => true], ['discord' => $this->creds(), 'extra' => $this->creds()])->all();
        $by = [];
        foreach ($all as $p) { $by[$p['slug']] = $p; }
        $this->assertSame('/auth/discord/login', $by['discord']['login_url']);
        $this->assertSame('Discord', $by['discord']['label']);
        $this->assertSame('/auth/extra/login', $by['extra']['login_url']);
        $this->assertSame('/auth/extra', $by['extra']['link_url']);
        $this->assertSame('#444444', $by['extra']['color']);
        $this->assertSame('#ffffff', $by['extra']['text_color']);
        $this->assertFalse(isset($by["evil\n"]));
    }
}
