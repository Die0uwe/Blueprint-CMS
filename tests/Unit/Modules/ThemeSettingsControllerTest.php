<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Storage\UploadManager;
use CommunityFusion\Core\Template\ThemeSettings;
use CommunityFusion\Modules\Settings\SettingsRepository;
use CommunityFusion\Modules\Themes\ThemeSettingsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Thema-instellingen (5 tabs): opslaan, uploads en opruimen, tegen SQLite in het geheugen. */
final class ThemeSettingsControllerTest extends TestCase
{
    private ThemeSettingsController $ctl;
    private SettingsRepository $settings;
    private string $store;

    protected function setUp(): void
    {
        // SQLite kent geen "ON DUPLICATE KEY UPDATE": herschrijf naar ON CONFLICT.
        $pdo = new class('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]) extends \PDO {
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $query = preg_replace('/ON DUPLICATE KEY UPDATE `value` = VALUES\(`value`\)(, `type` = VALUES\(`type`\))?, updated_at = NOW\(\)/',
                    'ON CONFLICT(`group`,`key`) DO UPDATE SET `value` = excluded.`value`', $query);
                return parent::prepare($query, $options);
            }
        };
        $pdo->sqliteCreateFunction('NOW', static fn() => gmdate('Y-m-d H:i:s'), 0);
        $pdo->exec("CREATE TABLE cf_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `group` TEXT, `key` TEXT, `value` TEXT, `type` TEXT DEFAULT 'string', updated_at TEXT, UNIQUE(`group`,`key`));
            CREATE TABLE cf_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, username TEXT, action TEXT, context TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);");
        $rc = new \ReflectionClass(Connection::class);
        $db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($db, $pdo);
        $rc->getProperty('prefix')->setValue($db, 'cf_');

        $cache = new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_cache_' . bin2hex(random_bytes(4))]);
        $this->settings = new SettingsRepository($db, $cache);

        $arc  = new \ReflectionClass(AuthManager::class);
        $auth = $arc->newInstanceWithoutConstructor();
        $arc->getProperty('currentUser')->setValue($auth, ['id' => 1, 'username' => 'ouwe']);

        $this->store = sys_get_temp_dir() . '/cf_up_' . bin2hex(random_bytes(4));
        $this->ctl   = new ThemeSettingsController($this->settings, $auth, new AuditLogger($db), new UploadManager($this->store, 5 * 1024 * 1024, true));
        $_SESSION['_csrf_token'] = 'tok';
    }

    private function post(array $body = [], array $files = []): Request
    {
        $body['_csrf_token'] = 'tok';
        $_POST = $body;
        return new Request('POST', '/x', [], $body, [], [], $files, []);
    }

    private function location(Response $r): string
    {
        return (string) ((new \ReflectionProperty($r, 'headers'))->getValue($r)['Location'] ?? '');
    }

    private function s(): array
    {
        return ThemeSettings::load($this->settings->getGroup('theme'));
    }

    private function image(): array
    {
        $p = sys_get_temp_dir() . '/cf_img_' . bin2hex(random_bytes(4)) . '.png';
        imagepng(imagecreatetruecolor(8, 8), $p);
        return ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $p, 'error' => 0, 'size' => filesize($p)];
    }

    #[Test]
    public function aPresetOverridesTheLooseFields(): void
    {
        $r = $this->ctl->saveGeneral($this->post(['layout_preset' => 'compact', 'layout_mode' => 'wide', 'layout_width' => '1500', 'sidebar_width' => '300']));
        $this->assertStringContainsString('tab=1&ok=opgeslagen', $this->location($r));
        $s = $this->s();
        $this->assertSame('boxed', $s['layout_mode']);
        $this->assertSame(1100, $s['layout_width']);
        $this->assertSame(240, $s['sidebar_width']);
    }

    #[Test]
    public function customLayoutIsClamped(): void
    {
        $this->ctl->saveGeneral($this->post(['layout_preset' => 'aangepast', 'layout_mode' => 'boxed', 'layout_width' => '1000', 'sidebar_width' => '999']));
        $s = $this->s();
        $this->assertSame('aangepast', $s['layout_preset']);
        $this->assertSame(1000, $s['layout_width']);
        $this->assertSame(500, $s['sidebar_width']);
    }

    #[Test]
    public function colorsAreSavedValidatedAndResettable(): void
    {
        $this->ctl->saveColors($this->post(['color_primary' => '#FF0000', 'color_secondary' => '#00ff00', 'color_background' => 'nonsense', 'color_accent' => '#0000ff', 'use_theme_color_accent' => 'on']));
        $s = $this->s();
        $this->assertSame('#ff0000', $s['color_primary']);
        $this->assertSame('#00ff00', $s['color_secondary']);
        $this->assertSame('', $s['color_background']);
        $this->assertSame('', $s['color_accent']);

        $this->ctl->saveColors($this->post(['reset' => '1', 'color_primary' => '#123456']));
        $s = $this->s();
        $this->assertSame('', $s['color_primary']);
        $this->assertSame('', $s['color_secondary']);
    }

    #[Test]
    public function brandingStoresFilesAndCleansUpReplacedOnes(): void
    {
        $this->ctl->saveBranding($this->post(['banner_height' => '300', 'logo_show_name' => 'on'], ['logo' => $this->image(), 'banner' => $this->image(), 'site_icon' => $this->image()]));
        $s    = $this->s();
        $icon = (string) $this->settings->get('core', 'site_icon', '');
        $this->assertMatchesRegularExpression('#^/media/theme/[0-9a-f]{32}\.png$#', $s['logo']);
        $this->assertMatchesRegularExpression('#^/media/theme/#', $s['banner']);
        $this->assertMatchesRegularExpression('#^/media/branding/#', $icon);
        $this->assertSame(300, $s['banner_height']);
        $this->assertSame('1', $s['logo_show_name']);
        $this->assertFileExists($this->store . '/' . substr($s['logo'], 7));

        $oldLogo = $s['logo'];
        $this->ctl->saveBranding($this->post(['banner_height' => '300'], ['logo' => $this->image()]));
        $s = $this->s();
        $this->assertNotSame($oldLogo, $s['logo']);
        $this->assertFileDoesNotExist($this->store . '/' . substr($oldLogo, 7));
        $this->assertSame('0', $s['logo_show_name']);

        $oldBanner = $s['banner'];
        $this->ctl->saveBranding($this->post(['banner_height' => '300', 'remove_banner' => 'on', 'remove_site_icon' => 'on']));
        $this->assertSame('', $this->s()['banner']);
        $this->assertFileDoesNotExist($this->store . '/' . substr($oldBanner, 7));
        $this->assertSame('', (string) $this->settings->get('core', 'site_icon', ''));
    }

    #[Test]
    public function aPhpFileAsLogoIsRejectedAndKeepsTheCurrentLogo(): void
    {
        $this->ctl->saveBranding($this->post([], ['logo' => $this->image()]));
        $current = $this->s()['logo'];

        $bad = sys_get_temp_dir() . '/cf_bad_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($bad, '<?php echo 1;');
        $r = $this->ctl->saveBranding($this->post([], ['logo' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $bad, 'error' => 0, 'size' => filesize($bad)]]));
        $this->assertStringContainsString('error=', $this->location($r));
        $this->assertSame($current, $this->s()['logo']);
    }

    #[Test]
    public function anExternalIconUrlIsNeverDeleted(): void
    {
        $this->settings->set('core', 'site_icon', 'https://example.com/favicon.ico');
        $this->ctl->saveBranding($this->post(['remove_site_icon' => 'on']));
        $this->assertSame('', (string) $this->settings->get('core', 'site_icon', ''));
    }

    #[Test]
    public function postWithoutCsrfTokenIsRejected(): void
    {
        $_POST = [];
        $this->expectException(\Throwable::class);
        $this->ctl->saveColors(new Request('POST', '/x', [], [], [], [], [], []));
    }

    #[Test]
    public function allFiveTabsRenderAndOnlyTabFiveIsEmpty(): void
    {
        foreach ([1, 2, 3, 4, 5, 99] as $tab) {
            $html = $this->ctl->index(new Request('GET', '/admin/themes/instellingen', ['tab' => (string) $tab], [], [], [], [], []))->getBody();
            $this->assertStringContainsString('Thema-instellingen', $html);
            $this->assertGreaterThanOrEqual(5, substr_count($html, 'ts-tab'));
            if ($tab === 3) {
                $this->assertSame(8, substr_count($html, 'type="color"'));
            }
            if ($tab === 4) {
                $this->assertStringContainsString('action="/admin/themes/instellingen/layout"', $html);
                $this->assertStringContainsString('id="lb-list"', $html);
            }
            if ($tab === 5) {
                $this->assertStringNotContainsString('<form', $html);
            }
        }
    }

    #[Test]
    public function layoutBuilderSavesNormalisedJsonAndRedirectsToTab4(): void
    {
        $r = $this->ctl->saveLayout($this->post([
            'logo_align' => 'center', 'footer_columns' => '2', 'show_motd' => 'on',
            'cell_type' => ['text', 'bogus'], 'cell_title' => ['A', 'B'], 'cell_text' => ['hi', 'x'], 'cell_links' => ['', ''],
        ]));
        $this->assertStringContainsString('tab=4&ok=opgeslagen', $this->location($r));
        $cfg = \CommunityFusion\Core\Template\LayoutConfig::normalize($this->settings->get('theme', 'layout_json', ''));
        $this->assertSame('center', $cfg['header']['logo_align']);
        $this->assertTrue($cfg['header']['show_motd']);
        $this->assertFalse($cfg['header']['sticky']);
        $this->assertSame(2, $cfg['footer']['columns']);
        $this->assertCount(1, $cfg['footer']['cells']); // onbekend type verworpen
    }
}
