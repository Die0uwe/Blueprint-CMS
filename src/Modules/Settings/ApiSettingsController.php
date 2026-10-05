<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Settings;

use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * ApiSettingsController — voorbereide API-instellingen (cf_settings, groep 'api').
 *
 * Alleen opslag: Steam en drie lege "Custom API"-slots (titel, basis-URL,
 * header, sleutel). Er is nog geen module die deze waarden gebruikt; ze staan
 * klaar zodat modules ze via SettingsRepository::get('api', '<sleutel>') kunnen
 * lezen. Sleutels ('encrypted') worden versleuteld opgeslagen en nooit teruggetoond.
 *
 *   GET  /admin/api-instellingen
 *   POST /admin/api-instellingen
 */
final class ApiSettingsController
{
    public const GROUP = 'api';
    public const CUSTOM_SLOTS = 3;

    public function __construct(
        private readonly AuthManager        $auth,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Schema in weergavevolgorde.
     * @return list<array{id:string,title:string,hint:string,fields:list<array{key:string,label:string,type:string,placeholder?:string}>}>
     */
    public static function sections(): array
    {
        $sections = [[
            'id' => 'steam', 'title' => 'Steam',
            'hint' => 'Web API-sleutel aanvragen op steamcommunity.com/dev/apikey. Steam-ID (64-bit) vind je in het profiel-URL of via steamid.io.',
            'fields' => [
                ['key' => 'steam_api_key', 'label' => 'Steam Web API-sleutel', 'type' => 'encrypted'],
                ['key' => 'steam_id',      'label' => 'Steam-ID (64-bit)',     'type' => 'string', 'placeholder' => '7656119…'],
                ['key' => 'steam_app_id',  'label' => 'Standaard App-ID (optioneel)', 'type' => 'string'],
            ],
        ]];
        for ($i = 1; $i <= self::CUSTOM_SLOTS; $i++) {
            $sections[] = [
                'id' => "custom{$i}", 'title' => "Custom API {$i}",
                'hint' => 'Lege plek voor een eigen koppeling. Geef hem een titel; de rest is optioneel.',
                'fields' => [
                    ['key' => "custom{$i}_title",       'label' => 'Titel',                     'type' => 'string', 'placeholder' => "Custom API {$i}"],
                    ['key' => "custom{$i}_base_url",    'label' => 'Basis-URL',                 'type' => 'url',    'placeholder' => 'https://api.example.com/v1'],
                    ['key' => "custom{$i}_header_name", 'label' => 'Header voor de sleutel',    'type' => 'string', 'placeholder' => 'Authorization'],
                    ['key' => "custom{$i}_api_key",     'label' => 'API-sleutel / token',       'type' => 'encrypted'],
                    ['key' => "custom{$i}_notes",       'label' => 'Notities',                  'type' => 'string'],
                ],
            ];
        }
        return $sections;
    }

    public function edit(Request $request): Response
    {
        $this->auth->authorize('settings.edit');
        $sections = self::sections();
        $values   = $this->settings->getGroup(self::GROUP);
        $flash    = $request->query('saved', '') === '1';

        ob_start();
        include __DIR__ . '/views/api_settings.php';
        return Response::html((string) ob_get_clean());
    }

    public function update(Request $request): Response
    {
        $this->auth->authorize('settings.edit');
        CsrfProtection::validateRequest();

        foreach (self::sections() as $section) {
            foreach ($section['fields'] as $f) {
                $input = $request->input($f['key'], null);
                if ($f['type'] === 'encrypted') {
                    if (is_string($input) && $input !== '') {
                        $this->settings->set(self::GROUP, $f['key'], mb_substr($input, 0, 500), 'encrypted');
                    }
                    if ($request->input($f['key'] . '__clear', null) === '1') {
                        $this->settings->set(self::GROUP, $f['key'], '', 'encrypted');
                    }
                    continue;
                }
                $v = trim((string) ($input ?? ''));
                if ($f['type'] === 'url' && $v !== '' && !preg_match('#^https://[^\s<>"\']+$#i', $v)) {
                    $v = ''; // alleen https
                }
                $this->settings->set(self::GROUP, $f['key'], mb_substr($v, 0, 300), 'string');
            }
        }
        return Response::redirect('/admin/api-instellingen?saved=1');
    }
}
