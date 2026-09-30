<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth\OAuth;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;

/**
 * ProviderRegistry — één bron van waarheid voor "welke externe login-
 * providers bestaat er, en welke mag een bezoeker nu echt gebruiken?".
 *
 * Een provider is bruikbaar (`available()`) als:
 *   (a) de bijbehorende module is ingeschakeld (cf_modules.is_enabled = 1), en
 *   (b) hij is geconfigureerd: client_id ÉN client_secret zijn ingevuld in
 *       cf_settings (group = module-slug).
 * De loginpagina en het login-blok tonen alleen die providers, zodat een klik
 * nooit op een foutredirect uitkomt. De profielpagina gebruikt `all()` /
 * `find()` voor het "Gekoppelde accounts"-overzicht.
 *
 * Uitbreiden (bv. YouTube, Facebook): registreer een filter op `auth.providers`
 * dat extra definities toevoegt:
 *
 *   $hooks->addFilter('auth.providers', function (array $defs): array {
 *       $defs[] = ['slug' => 'facebook', 'label' => 'Facebook', 'icon' => 'f',
 *                  'color' => '#1877F2'];
 *       return $defs;
 *   });
 *
 * Per definitie zijn alleen `slug` en `label` verplicht. `enabled` en
 * `configured` (bool) mag een definitie zelf meegeven (bv. voor een provider
 * zonder eigen module); anders worden ze uit de database afgeleid.
 */
final class ProviderRegistry
{
    public const FILTER = 'auth.providers';

    /** Sleutels in cf_settings die samen "geconfigureerd" betekenen. */
    private const REQUIRED_SETTINGS = ['client_id', 'client_secret'];

    /** @var list<array<string,mixed>>|null */
    private ?array $cache = null;

    /**
     * @param callable():array{enabled: array<string,bool>, settings: array<string,array<string,string>>} $stateLoader
     *        Levert de module-status en instellingen; apart gehouden zodat
     *        de registry zonder database te testen is.
     */
    public function __construct(
        private readonly HookManager $hooks,
        private $stateLoader,
    ) {}

    /** Productiefabriek: leest de status uit cf_modules en cf_settings. */
    public static function fromDb(Connection $db, HookManager $hooks): self
    {
        return new self($hooks, static function () use ($db): array {
            $enabled = [];
            foreach ($db->fetchAll("SELECT slug, is_enabled FROM cf_modules") as $row) {
                $enabled[(string) $row['slug']] = (int) $row['is_enabled'] === 1;
            }

            $settings = [];
            $rows = $db->fetchAll(
                "SELECT `group`, `key`, `value` FROM cf_settings WHERE `key` IN ('client_id', 'client_secret')"
            );
            foreach ($rows as $row) {
                $settings[(string) $row['group']][(string) $row['key']] = (string) ($row['value'] ?? '');
            }

            return ['enabled' => $enabled, 'settings' => $settings];
        });
    }

    /** Ingebouwde providers; uitbreidbaar via de `auth.providers`-filter. */
    public static function builtIn(): array
    {
        return [
            ['slug' => 'discord',   'label' => 'Discord',    'icon' => '🎮', 'color' => '#5865F2', 'text_color' => '#fff',     'login_label_key' => 'auth.login.with_discord'],
            ['slug' => 'google',    'label' => 'Google',     'icon' => '🔑', 'color' => '#ffffff', 'text_color' => '#1f1f1f',  'login_label_key' => 'auth.login.with_google'],
            ['slug' => 'github',    'label' => 'GitHub',     'icon' => '🐙', 'color' => '#24292f', 'text_color' => '#fff',     'login_label_key' => 'auth.login.with_github'],
            ['slug' => 'twitch',    'label' => 'Twitch',     'icon' => '📺', 'color' => '#9146FF', 'text_color' => '#fff',     'login_label_key' => 'auth.login.with_twitch'],
            ['slug' => 'battlenet', 'label' => 'Battle.net', 'icon' => '🌀', 'color' => '#148eff', 'text_color' => '#fff',     'login_label_key' => 'auth.login.with_battlenet'],
        ];
    }

    /**
     * Alle bekende providers (ook uitgeschakelde/ongeconfigureerde), elk met
     * de vlaggen `enabled` en `configured`.
     *
     * @return list<array{slug:string,label:string,icon:string,color:string,text_color:string,login_label_key:?string,login_url:string,link_url:string,disconnect_url:string,enabled:bool,configured:bool}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $state = ($this->stateLoader)();

        $defs = $this->hooks->applyFilters(self::FILTER, self::builtIn());
        if (!is_array($defs)) {
            $defs = self::builtIn();
        }

        $builtinSlugs = array_column(self::builtIn(), 'slug');
        $result = [];
        foreach ($defs as $def) {
            $provider = $this->normalize($def, $state);
            if ($provider !== null) {
                // Ingebouwde providers kan een plugin niet overschrijven (bv. login_url omleiden naar phishing)
                if (isset($result[$provider['slug']]) && in_array($provider['slug'], $builtinSlugs, true)) {
                    continue;
                }
                $result[$provider['slug']] = $provider; // dubbele slug (niet ingebouwd): laatste wint
            }
        }

        return $this->cache = array_values($result);
    }

    /**
     * Alleen de providers die aan staan én geconfigureerd zijn — dit is wat
     * bezoekers als loginknop te zien krijgen.
     *
     * @return list<array<string,mixed>>
     */
    public function available(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $p): bool => $p['enabled'] && $p['configured']
        ));
    }

    public function find(string $slug): ?array
    {
        foreach ($this->all() as $p) {
            if ($p['slug'] === $slug) {
                return $p;
            }
        }
        return null;
    }

    // ─── intern ───────────────────────────────────────────────────────────

    private function normalize(mixed $def, array $state): ?array
    {
        if (!is_array($def)) {
            return null;
        }

        $slug  = $def['slug'] ?? null;
        $label = $def['label'] ?? null;
        // Slug komt in URL's terecht: alleen veilige tekens toestaan.
        if (!is_string($slug) || preg_match('/^[a-z0-9-]{1,40}$/D', $slug) !== 1
            || !is_string($label) || $label === '') {
            return null;
        }

        $group = is_string($def['settings_group'] ?? null) ? $def['settings_group'] : $slug;

        $enabled = array_key_exists('enabled', $def)
            ? (bool) $def['enabled']
            : (bool) ($state['enabled'][$group] ?? false);

        if (array_key_exists('configured', $def)) {
            $configured = (bool) $def['configured'];
        } else {
            $configured = true;
            foreach (self::REQUIRED_SETTINGS as $key) {
                if (trim((string) ($state['settings'][$group][$key] ?? '')) === '') {
                    $configured = false;
                    break;
                }
            }
        }

        return [
            'slug'            => $slug,
            'label'           => $label,
            'icon'            => is_string($def['icon'] ?? null) ? $def['icon'] : '🔗',
            'color'           => self::cssColor($def['color'] ?? null, '#444444'),
            'text_color'      => self::cssColor($def['text_color'] ?? null, '#ffffff'),
            'login_label_key' => is_string($def['login_label_key'] ?? null) ? $def['login_label_key'] : null,
            'login_url'       => self::localPath($def['login_url'] ?? null, "/auth/{$slug}/login"),
            'link_url'        => self::localPath($def['link_url'] ?? null, "/auth/{$slug}"),
            'disconnect_url'  => "/profiel/koppelingen/{$slug}/ontkoppelen",
            'enabled'         => $enabled,
            'configured'      => $configured,
        ];
    }

    /** Alleen een hex-kleur; alles anders (bv. CSS-injectie) valt terug op de standaard. */
    private static function cssColor(mixed $v, string $default): string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/D', $v) === 1 ? $v : $default;
    }

    /** Alleen een lokaal pad (geen javascript:, geen //extern, geen schema). */
    private static function localPath(mixed $v, string $default): string
    {
        return is_string($v) && preg_match('#^/(?!/)[A-Za-z0-9/_\-.?=&%]{0,200}$#D', $v) === 1 ? $v : $default;
    }
}


// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: ProviderRegistry.php | Role: Core | Version: 1.0.0           ║
// ║  Notes: login/profiel tonen alleen providers die aan + geconfigureerd║
// ╚══════════════════════════════════════════════════════════════════════╝
