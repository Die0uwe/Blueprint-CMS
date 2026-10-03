<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Auth\OAuth;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\Crypto;

/**
 * OAuthProviders — welke "Inloggen met …"-providers bestaan er, en welke
 * daarvan zijn nu echt bruikbaar?
 *
 * Een provider is bruikbaar als
 *   1. zijn module is ingeschakeld (cf_modules.is_enabled = 1), en
 *   2. er een Client ID én Client Secret is ingesteld (cf_settings, groep = slug).
 *
 * De loginpagina, het login-blok en het profiel tonen alleen bruikbare
 * providers. Voorheen stonden er vier vaste knoppen, ook als de module uit
 * stond of er geen sleutels waren — de knop leidde dan naar een lege
 * Google/Discord-foutpagina of een 404.
 */
final class OAuthProviders
{
    /**
     * slug => [label, kleur, tekstkleur, korte tekst voor het icoon]
     *
     * @var array<string,array{label:string,color:string,text:string,icon:string}>
     */
    public const CATALOG = [
        'github'    => ['label' => 'GitHub',     'color' => '#24292e', 'text' => '#ffffff', 'icon' => 'GH'],
        'google'    => ['label' => 'Google',     'color' => '#ffffff', 'text' => '#1f1f1f', 'icon' => 'G'],
        'discord'   => ['label' => 'Discord',    'color' => '#5865F2', 'text' => '#ffffff', 'icon' => 'D'],
        'twitch'    => ['label' => 'Twitch',     'color' => '#9146FF', 'text' => '#ffffff', 'icon' => 'T'],
        'battlenet' => ['label' => 'Battle.net', 'color' => '#148eff', 'text' => '#ffffff', 'icon' => 'B'],
    ];

    /** @var array<string,array{slug:string,label:string,color:string,text:string,icon:string,login_url:string,link_url:string}>|null */
    private ?array $usable = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * Bruikbare providers, in vaste volgorde.
     *
     * @return array<string,array{slug:string,label:string,color:string,text:string,icon:string,login_url:string,link_url:string}>
     */
    public function usable(): array
    {
        if ($this->usable !== null) {
            return $this->usable;
        }

        $this->usable = [];
        try {
            $enabled = array_column(
                $this->db->fetchAll(
                    "SELECT slug FROM cf_modules WHERE is_enabled = 1 AND slug IN ('github','google','discord','twitch','battlenet')"
                ),
                'slug'
            );
            if ($enabled === []) {
                return $this->usable;
            }

            $rows = $this->db->fetchAll(
                "SELECT `group`, `key`, `value` FROM cf_settings
                 WHERE `group` IN ('github','google','discord','twitch','battlenet')
                   AND `key` IN ('client_id','client_secret')"
            );
            $have = [];
            foreach ($rows as $r) {
                if ((string) $r['value'] !== '') {
                    $have[$r['group']][$r['key']] = true;
                }
            }

            foreach (self::CATALOG as $slug => $meta) {
                $configured = isset($have[$slug]['client_id'], $have[$slug]['client_secret']);
                if (in_array($slug, $enabled, true) && $configured) {
                    $this->usable[$slug] = $meta + [
                        'slug'      => $slug,
                        'login_url' => "/auth/{$slug}/login",
                        'link_url'  => "/auth/{$slug}",
                    ];
                }
            }
        } catch (\Throwable) {
            // Database of tabellen (nog) niet beschikbaar: dan zijn er geen providers.
            $this->usable = [];
        }

        return $this->usable;
    }

    public function isUsable(string $slug): bool
    {
        return isset($this->usable()[$slug]);
    }

    /**
     * Instelling van een provider (ontsleuteld indien 'encrypted').
     */
    public static function setting(Connection $db, string $group, string $key, string $default = ''): string
    {
        $row = $db->fetchOne(
            "SELECT `value`, `type` FROM cf_settings WHERE `group` = ? AND `key` = ?",
            [$group, $key]
        );
        if ($row === null || $row['value'] === null || $row['value'] === '') {
            return $default;
        }
        if (($row['type'] ?? 'string') === 'encrypted') {
            return Crypto::decrypt((string) $row['value']);
        }
        return (string) $row['value'];
    }
}
