<?php

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Modules\Ollama\OllamaAdminController;
use CommunityFusion\Modules\Ollama\OllamaConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Instellingen van de Ollama-module opslaan/lezen, tegen SQLite in het geheugen. */
final class OllamaAdminControllerTest extends TestCase
{
    private Connection $db;
    private OllamaAdminController $ctl;

    public static function setUpBeforeClass(): void
    {
        spl_autoload_register(static function (string $c): void {
            $pre = 'CommunityFusion\\Modules\\Ollama\\';
            if (str_starts_with($c, $pre)) {
                $f = dirname(__DIR__, 3) . '/modules/ollama/src/' . substr($c, strlen($pre)) . '.php';
                if (is_file($f)) { require_once $f; }
            }
        });
    }

    protected function setUp(): void
    {
        $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec("CREATE TABLE cf_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `group` TEXT, `key` TEXT, `value` TEXT, `type` TEXT DEFAULT 'string', updated_at TEXT, UNIQUE(`group`,`key`))");
        $rc = new \ReflectionClass(Connection::class);
        $this->db = $rc->newInstanceWithoutConstructor();
        $rc->getProperty('pdo')->setValue($this->db, $pdo);
        $rc->getProperty('prefix')->setValue($this->db, 'cf_');
        $this->ctl = new OllamaAdminController($this->db, new CacheManager(['driver' => 'file', 'path' => sys_get_temp_dir() . '/cf_oc_' . bin2hex(random_bytes(4))]));
        $_SESSION['_csrf_token'] = 'tok';
    }

    private function post(array $body): Request
    {
        $body['_csrf_token'] = 'tok';
        $_POST = $body;
        return new Request('POST', '/x', [], $body, [], [], [], []);
    }

    #[Test]
    public function savesSettingsAndEncryptsTheKeyAtRest(): void
    {
        $this->ctl->save($this->post([
            'host' => 'https://ai.example.nl/', 'default_model' => 'deepseek-r1:8b', 'timeout' => '9999', 'num_ctx' => '8192',
            'keep_alive' => '24h', 'open_webui_url' => 'https://webui.example.nl', 'open_webui_key' => 'sk-geheim',
        ]));
        $cfg = OllamaConfig::load($this->db);
        $this->assertSame('https://ai.example.nl', $cfg['host']);
        $this->assertSame('deepseek-r1:8b', $cfg['default_model']);
        $this->assertSame('300', $cfg['timeout']);
        $this->assertSame('sk-geheim', $cfg['open_webui_key']);
        $raw = (string) $this->db->fetchOne("SELECT `value` FROM cf_settings WHERE `key`='open_webui_key'")['value'];
        $this->assertStringNotContainsString('sk-geheim', $raw, 'sleutel staat versleuteld in de database');
    }

    #[Test]
    public function emptyKeyKeepsTheOldOneAndTheCheckboxClearsIt(): void
    {
        $this->ctl->save($this->post(['open_webui_key' => 'sk-1']));
        $this->ctl->save($this->post(['open_webui_key' => '']));
        $this->assertSame('sk-1', OllamaConfig::load($this->db)['open_webui_key']);
        $this->ctl->save($this->post(['open_webui_key' => '', 'clear_open_webui_key' => '1']));
        $this->assertSame('', OllamaConfig::load($this->db)['open_webui_key']);
    }

    #[Test]
    public function invalidOrDangerousAddressesAreNotStoredAndKeepTheOldValue(): void
    {
        $this->ctl->save($this->post(['host' => 'http://localhost:11434']));
        foreach (['javascript:alert(1)', 'ftp://x', 'http://169.254.169.254/latest', 'http://metadata.google.internal', 'niet-een-url'] as $bad) {
            $this->ctl->save($this->post(['host' => $bad]));
            $this->assertSame('http://localhost:11434', OllamaConfig::load($this->db)['host'], $bad);
        }
        $client = OllamaConfig::client(['host' => '', 'default_model' => '']);
        $this->assertFalse($client->usesOpenWebUi());
    }

    #[Test]
    public function fallbackNeedsHttpsAndKeyAndIsStoredEncrypted(): void
    {
        $this->ctl->save($this->post(['fallback_url' => 'http://api.deepseek.com', 'fallback_key' => 'sk-ds']));
        $cfg = OllamaConfig::load($this->db);
        $this->assertSame('', $cfg['fallback_url'] ?? '', 'http wordt geweigerd');
        $this->assertFalse(OllamaConfig::client($cfg)->hasFallback());

        $this->ctl->save($this->post(['fallback_url' => 'https://api.deepseek.com/', 'fallback_model' => 'deepseek-chat', 'fallback_key' => 'sk-ds']));
        $cfg = OllamaConfig::load($this->db);
        $this->assertSame('https://api.deepseek.com', $cfg['fallback_url']);
        $this->assertSame('sk-ds', $cfg['fallback_key']);
        $this->assertTrue(OllamaConfig::client($cfg)->hasFallback());
        $this->assertStringNotContainsString('sk-ds', (string) $this->db->fetchOne("SELECT `value` FROM cf_settings WHERE `key`='fallback_key'")['value']);

        $this->ctl->save($this->post(['fallback_url' => 'https://api.deepseek.com', 'clear_fallback_key' => '1']));
        $this->assertFalse(OllamaConfig::client(OllamaConfig::load($this->db))->hasFallback(), 'zonder sleutel geen reserve-AI');
    }
}
