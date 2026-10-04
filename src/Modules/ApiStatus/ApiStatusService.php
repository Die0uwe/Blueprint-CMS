<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\ApiStatus;

use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Cache\CacheManager;
use CommunityFusion\Core\Database\Connection;

/**
 * ApiStatusService — overzicht en live-check van alle externe koppelingen.
 *
 * Statussen:
 *   off          — module niet geïnstalleerd/uitgeschakeld        (grijs)
 *   unconfigured — aan, maar verplichte sleutels ontbreken         (geel)
 *   configured   — sleutels aanwezig, nog niet live getest         (blauw)
 *   ok           — live check geslaagd                             (groen)
 *   error        — live check mislukt (foute sleutel/server uit)   (rood)
 *
 * De HTTP-laag is injecteerbaar zodat de checks zonder netwerk te testen zijn.
 */
final class ApiStatusService
{
    public const OFF = 'off', UNCONFIGURED = 'unconfigured', CONFIGURED = 'configured', OK = 'ok', ERROR = 'error';

    /**
     * slug => [label, icon, required settings, description, check]
     * check: key van de live-check, of null als er geen live-check bestaat.
     */
    private const INTEGRATIONS = [
        'discord'   => ['Discord',        '💬', ['client_id', 'client_secret'], 'Login, serverwidget en online leden', 'discord'],
        'twitch'    => ['Twitch',         '🎮', ['client_id', 'client_secret'], 'Login en livestatus',                  'twitch'],
        'youtube'   => ['YouTube',        '▶️', ['api_key'],                     'Kanaal, video\'s en livestreams',      'youtube'],
        'kick'      => ['Kick',           '🟢', ['channel_slug'],                'Livestatus van een kanaal',            'kick'],
        'warcraft'  => ['Blizzard API',   '⚔️', ['client_id', 'client_secret'],  'Gilde, karakters en Mythic+',          'blizzard'],
        'battlenet' => ['Battle.net',     '🔷', ['client_id', 'client_secret'],  'Login met Battle.net',                 'battlenet'],
        'github'    => ['GitHub',         '🐙', ['client_id', 'client_secret'],  'Login met GitHub',                     null],
        'google'    => ['Google',         '🔎', ['client_id', 'client_secret'],  'Login met Google',                     null],
        'ollama'    => ['Ollama (AI)',    '🤖', ['host'],                        'Lokale AI-assistent',                  'ollama'],
        'minecraft' => ['Minecraft',      '⛏️', ['host'],                        'Serverstatus',                         'minecraft'],
        'fivem'     => ['FiveM',          '🚗', ['server_ip'],                   'Serverstatus',                         'fivem'],
    ];

    /** @var callable(string,string,array,?string):array{status:int,body:string} */
    private $http;

    public function __construct(
        private readonly Connection   $db,
        private readonly CacheManager $cache,
        ?callable $http = null,
    ) {
        $this->http = $http ?? [self::class, 'curl'];
    }

    public static function slugs(): array
    {
        return array_keys(self::INTEGRATIONS);
    }

    /** Snelle status zonder netwerk (voor het laden van de pagina). */
    public function overview(): array
    {
        $enabled = $this->enabledSlugs();
        $out = [];
        foreach (self::INTEGRATIONS as $slug => [$label, $icon, $required, $desc, $check]) {
            $installed = is_file(CF_ROOT . "/modules/{$slug}/module.json");
            $missing   = [];
            foreach ($required as $key) {
                if ($this->setting($slug, $key) === '') {
                    $missing[] = $key;
                }
            }
            if (!$installed || !in_array($slug, $enabled, true)) {
                $status = self::OFF;
            } elseif ($missing !== []) {
                $status = self::UNCONFIGURED;
            } else {
                $status = self::CONFIGURED;
            }
            $out[$slug] = [
                'slug' => $slug, 'label' => $label, 'icon' => $icon, 'description' => $desc,
                'status' => $status, 'enabled' => in_array($slug, $enabled, true),
                'installed' => $installed, 'missing' => $missing,
                'testable' => $check !== null,
                'message' => match ($status) {
                    self::OFF          => $installed ? 'Uitgeschakeld' : 'Niet geïnstalleerd',
                    self::UNCONFIGURED => 'Ontbreekt: ' . implode(', ', $missing),
                    default            => $check !== null ? 'Ingesteld — nog niet getest' : 'Ingesteld (geen live-check mogelijk)',
                },
            ];
        }
        return $out;
    }

    /** Voer de live-check van één koppeling uit. */
    public function test(string $slug): array
    {
        $ov = $this->overview();
        if (!isset($ov[$slug])) {
            return ['status' => self::ERROR, 'message' => 'Onbekende koppeling', 'ms' => 0];
        }
        $row = $ov[$slug];
        if ($row['status'] === self::OFF || $row['status'] === self::UNCONFIGURED) {
            return ['status' => $row['status'], 'message' => $row['message'], 'ms' => 0];
        }
        $check = self::INTEGRATIONS[$slug][4];
        if ($check === null) {
            return ['status' => self::CONFIGURED, 'message' => $row['message'], 'ms' => 0];
        }

        $t0 = microtime(true);
        try {
            [$ok, $msg] = $this->{'check' . ucfirst($check)}($slug);
        } catch (\Throwable $e) {
            [$ok, $msg] = [false, 'Fout: ' . $e->getMessage()];
        }
        return [
            'status'  => $ok ? self::OK : self::ERROR,
            'message' => $msg,
            'ms'      => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /** Zet een module aan/uit. Geeft false terug als er niets te schakelen valt. */
    public function setEnabled(string $slug, bool $enable): bool
    {
        if (!isset(self::INTEGRATIONS[$slug])) {
            return false;
        }
        $manifestPath = CF_ROOT . "/modules/{$slug}/module.json";
        if (!is_file($manifestPath)) {
            return false;
        }
        $m = json_decode((string) file_get_contents($manifestPath), true) ?: [];
        // Portabel (geen ON DUPLICATE KEY): MySQL meldt rowCount()=0 bij een
        // ongewijzigde UPDATE, dus eerst kijken of de rij bestaat.
        if ($this->db->fetchOne("SELECT id FROM cf_modules WHERE slug = ?", [$slug]) !== null) {
            $this->db->execute("UPDATE cf_modules SET is_enabled = ? WHERE slug = ?", [$enable ? 1 : 0, $slug]);
        } else {
            $this->db->execute(
                "INSERT INTO cf_modules (slug, name, version, author, description, is_core, is_enabled)
                 VALUES (?, ?, ?, ?, ?, 0, ?)",
                [$slug, (string) ($m['name'] ?? $slug), (string) ($m['version'] ?? '1.0.0'),
                 (string) ($m['author'] ?? ''), (string) ($m['description'] ?? ''), $enable ? 1 : 0]
            );
        }
        $this->db->execute("UPDATE cf_marketplace_installed SET is_enabled = ? WHERE package_slug = ?", [$enable ? 1 : 0, $slug]);
        $this->cache->delete('modules.all');
        return true;
    }

    // ─── Checks ──────────────────────────────────────────────────────────────

    private function checkDiscord(string $slug): array
    {
        $bot = $this->setting($slug, 'bot_token');
        if ($bot !== '') {
            $r = $this->request('GET', 'https://discord.com/api/v10/users/@me', ['Authorization: Bot ' . $bot]);
            if ($r['status'] === 200) {
                $name = json_decode($r['body'], true)['username'] ?? '?';
                return [true, "Bot-token geldig ({$name})"];
            }
            return [false, 'Bot-token geweigerd (HTTP ' . $r['status'] . ')'];
        }
        return $this->clientCredentials(
            'https://discord.com/api/v10/oauth2/token', $slug,
            'scope=identify', 'Client ID/secret geldig'
        );
    }

    private function checkTwitch(string $slug): array
    {
        return $this->clientCredentials('https://id.twitch.tv/oauth2/token', $slug, '', 'Client ID/secret geldig', true);
    }

    private function checkBlizzard(string $slug): array
    {
        return $this->blizzardToken($slug);
    }

    private function checkBattlenet(string $slug): array
    {
        return $this->blizzardToken($slug);
    }

    private function blizzardToken(string $slug): array
    {
        $region = strtolower($this->setting($slug, 'region') ?: 'eu');
        if (!in_array($region, ['eu', 'us', 'kr', 'tw'], true)) {
            return $region === 'cn'
                ? $this->clientCredentials('https://www.battlenet.com.cn/oauth/token', $slug, '', 'Client ID/secret geldig')
                : [false, "Onbekende regio '{$region}'"];
        }
        return $this->clientCredentials("https://{$region}.battle.net/oauth/token", $slug, '', 'Client ID/secret geldig (' . strtoupper($region) . ')');
    }

    private function checkYoutube(string $slug): array
    {
        $key = $this->setting($slug, 'api_key');
        $r = $this->request('GET', 'https://www.googleapis.com/youtube/v3/videoCategories?part=snippet&regionCode=NL&key=' . rawurlencode($key));
        if ($r['status'] === 200) {
            return [true, 'API-sleutel geldig'];
        }
        $reason = json_decode($r['body'], true)['error']['errors'][0]['reason'] ?? '';
        return [false, 'HTTP ' . $r['status'] . ($reason !== '' ? " ({$reason})" : '')];
    }

    private function checkKick(string $slug): array
    {
        $r = $this->request('GET', 'https://kick.com/api/v2/channels/' . rawurlencode(strtolower($this->setting($slug, 'channel_slug'))), [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
            'Accept: application/json',
        ]);
        return $r['status'] === 200 ? [true, 'Kanaal gevonden'] : [false, 'HTTP ' . $r['status'] . ($r['status'] === 404 ? ' — kanaal bestaat niet' : '')];
    }

    private function checkOllama(string $slug): array
    {
        $host = rtrim($this->setting($slug, 'host'), '/');
        if (!preg_match('#^https?://#i', $host)) {
            return [false, 'Host-URL moet met http:// of https:// beginnen'];
        }
        $r = $this->request('GET', $host . '/api/tags');
        if ($r['status'] !== 200) {
            return [false, $r['status'] === 0 ? 'Server niet bereikbaar' : 'HTTP ' . $r['status']];
        }
        $n = count(json_decode($r['body'], true)['models'] ?? []);
        return [true, "Bereikbaar — {$n} model(len)"];
    }

    private function checkMinecraft(string $slug): array
    {
        $host = $this->setting($slug, 'host');
        $port = (int) ($this->setting($slug, 'port') ?: 25565);
        $r = $this->request('TCP', "{$host}:{$port}");
        return $r['status'] === 200 ? [true, 'Server bereikbaar'] : [false, 'Server niet bereikbaar'];
    }

    private function checkFivem(string $slug): array
    {
        $ip = $this->setting($slug, 'server_ip');
        $url = preg_match('#^https?://#i', $ip) ? rtrim($ip, '/') : 'http://' . (str_contains($ip, ':') ? $ip : $ip . ':30120');
        $r = $this->request('GET', $url . '/info.json');
        return $r['status'] === 200 ? [true, 'Server bereikbaar'] : [false, $r['status'] === 0 ? 'Server niet bereikbaar' : 'HTTP ' . $r['status']];
    }

    private function clientCredentials(string $url, string $slug, string $extra, string $okMsg, bool $inBody = false): array
    {
        $id = $this->setting($slug, 'client_id');
        $secret = $this->setting($slug, 'client_secret');
        $body = 'grant_type=client_credentials' . ($extra !== '' ? '&' . $extra : '');
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        if ($inBody) {
            $body .= '&client_id=' . rawurlencode($id) . '&client_secret=' . rawurlencode($secret);
        } else {
            $headers[] = 'Authorization: Basic ' . base64_encode($id . ':' . $secret);
        }
        $r = $this->request('POST', $url, $headers, $body);
        if ($r['status'] === 200 && isset(json_decode($r['body'], true)['access_token'])) {
            return [true, $okMsg];
        }
        if (in_array($r['status'], [400, 401, 403], true)) {
            return [false, 'Client ID/secret geweigerd (HTTP ' . $r['status'] . ')'];
        }
        return [false, $r['status'] === 0 ? 'Server niet bereikbaar' : 'HTTP ' . $r['status']];
    }

    // ─── Hulpfuncties ────────────────────────────────────────────────────────

    private function setting(string $slug, string $key): string
    {
        try {
            return trim(OAuthProviders::setting($this->db, $slug, $key));
        } catch (\Throwable) {
            return ''; // niet te ontsleutelen (andere APP_KEY) = niet bruikbaar
        }
    }

    /** @return string[] */
    private function enabledSlugs(): array
    {
        try {
            return array_column($this->db->fetchAll("SELECT slug FROM cf_modules WHERE is_enabled = 1"), 'slug');
        } catch (\Throwable) {
            return [];
        }
    }

    private function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        return ($this->http)($method, $url, $headers, $body);
    }

    /** Standaard HTTP-implementatie. Methode 'TCP' = alleen een poortcheck ("host:poort"). */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        if ($method === 'TCP') {
            [$host, $port] = explode(':', $url) + [1 => '25565'];
            $fp = @fsockopen($host, (int) $port, $errno, $errstr, 4);
            if ($fp === false) {
                return ['status' => 0, 'body' => ''];
            }
            fclose($fp);
            return ['status' => 200, 'body' => ''];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $resp === false ? 0 : $status, 'body' => $resp === false ? '' : (string) $resp];
    }
}
