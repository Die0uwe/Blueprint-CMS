<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Settings\SettingsRepository;

/**
 * /admin/discord — beheer van de Discord-koppeling. Alles vereist de permissie `discord.admin`;
 * elke POST is CSRF-beschermd.
 *
 * Schermen: Status (verbinding testen), Widget (aan/uit + kanaal), Meldingen (webhook voor nieuws),
 * Rolkoppeling (Discord-rol → CMS-rol).
 *
 *   GET  /admin/discord                          status
 *   POST /admin/discord/test                     verbinding testen
 *   GET  /admin/discord/widget                   widget-stand + kanaalkeuze
 *   POST /admin/discord/widget                   widget in-/uitschakelen
 *   GET  /admin/discord/meldingen                webhook-instellingen
 *   POST /admin/discord/meldingen/opslaan        webhook + "nieuws melden" bewaren
 *   POST /admin/discord/meldingen/verwijderen    webhook wissen
 *   POST /admin/discord/meldingen/test           testbericht sturen
 *   GET  /admin/discord/rollen                   rolkoppelingen
 *   POST /admin/discord/rollen/toevoegen         koppeling maken
 *   POST /admin/discord/rollen/{id}/bewerk       koppeling wijzigen
 *   POST /admin/discord/rollen/{id}/verwijderen  koppeling verwijderen
 */
final class DiscordAdminController
{
    private readonly DiscordStore $store;
    private ?DiscordTransport $transport = null;

    public function __construct(
        private readonly AuthManager $auth,
        private readonly Connection $db,
        private readonly SettingsRepository $settings,
        private readonly AuditLogger $auditLog,
    ) {
        $this->store = new DiscordStore($db);
    }

    /** Voor tests: een nep-transport i.p.v. echte HTTP. */
    public function useTransport(DiscordTransport $t): self
    {
        $this->transport = $t;
        return $this;
    }

    // ─── Status ───────────────────────────────────────────────────────────

    public function status(Request $request): Response
    {
        $this->read();
        $test = $_SESSION['discord_test'] ?? null;
        unset($_SESSION['discord_test']);
        return $this->view('status', [
            'test'      => is_array($test) ? DiscordConnectionStatus::fromArray($test) : null,
            'inviteUrl' => DiscordApi::inviteUrl($this->store->clientId()),
        ]);
    }

    public function testConnection(Request $request): Response
    {
        $this->write();
        $api = $this->api();
        if ($api === null) {
            return $this->back('/admin/discord', 'error', 'Er is nog geen Bot Token ingesteld. Vul het in via de moduleinstellingen.');
        }
        $res = $api->testConnection($this->store->guildId());
        $_SESSION['discord_test'] = $res->toArray();
        $this->audit('discord.connection.test', ['ok' => $res->problems === []]);
        return Response::redirect('/admin/discord');
    }

    // ─── Widget ───────────────────────────────────────────────────────────

    public function widget(Request $request): Response
    {
        $this->read();
        $vars = ['widget' => null, 'channels' => [], 'apiError' => null, 'inviteUrl' => DiscordApi::inviteUrl($this->store->clientId())];
        $api  = $this->api();
        $gid  = $this->store->guildId();
        if ($api === null || !DiscordApi::isSnowflake($gid)) {
            $vars['apiError'] = 'Vul eerst het Guild/Server ID en het Bot Token in bij de moduleinstellingen.';
        } else {
            try {
                $vars['widget']   = $api->getWidgetSettings($gid);
                $vars['channels'] = $api->getChannels($gid);
            } catch (DiscordApiException $e) {
                $vars['apiError'] = $e->getMessage();
            }
        }
        return $this->view('widget', $vars);
    }

    public function widgetUpdate(Request $request): Response
    {
        $this->write();
        $api = $this->api();
        $gid = $this->store->guildId();
        if ($api === null || !DiscordApi::isSnowflake($gid)) {
            return $this->back('/admin/discord/widget', 'error', 'Vul eerst het Guild/Server ID en het Bot Token in.');
        }
        $enable = (string) $request->input('action', 'enable') !== 'disable';
        $channel = trim((string) $request->input('channel_id', ''));
        try {
            if ($enable) {
                if (!DiscordApi::isSnowflake($channel) || !in_array($channel, array_column($api->getChannels($gid), 'id'), true)) {
                    return $this->back('/admin/discord/widget', 'error', 'Kies een tekst- of aankondigingskanaal uit de lijst.');
                }
                $api->setWidget($gid, true, $channel);
                $this->audit('discord.widget.enable', ['channel_id' => $channel]);
                return $this->back('/admin/discord/widget', 'ok', 'De Discord-widget is ingeschakeld. Het kan even duren voor de widget-blokken dit tonen (max. 1 minuut).');
            }
            $api->setWidget($gid, false, null);
            $this->audit('discord.widget.disable', []);
            return $this->back('/admin/discord/widget', 'ok', 'De Discord-widget is uitgeschakeld.');
        } catch (DiscordApiException $e) {
            return $this->back('/admin/discord/widget', 'error', 'Mislukt: ' . $e->getMessage());
        }
    }

    // ─── Meldingen (webhook) ──────────────────────────────────────────────

    public function notifications(Request $request): Response
    {
        $this->read();
        $url = $this->store->webhookUrl();
        return $this->view('notifications', [
            'webhookSet'  => $url !== '',
            'webhookMask' => $url !== '' ? DiscordWebhook::mask($url) : '',
            'announce'    => $this->store->announceNews(),
            'moduleOn'    => $this->store->moduleEnabled(),
        ]);
    }

    public function saveNotifications(Request $request): Response
    {
        $this->write();
        $input = trim((string) $request->input('webhook_url', ''));
        $announce = $request->input('announce_news', null) !== null;

        if ($input !== '') {
            $safe = DiscordWebhook::normalize($input);
            if ($safe === null) {
                return $this->back('/admin/discord/meldingen', 'error', 'Dat is geen geldige Discord-webhook-URL. Verwacht: https://discord.com/api/webhooks/<id>/<token> (Discord → Kanaalinstellingen → Integraties → Webhooks).');
            }
            $this->settings->set('discord', 'webhook_url', $safe, 'encrypted');
            $this->audit('discord.webhook.set', []);
        }
        $this->settings->set('discord', 'announce_news', $announce ? '1' : '0', 'bool');
        return $this->back('/admin/discord/meldingen', 'ok', $input !== '' ? 'Webhook opgeslagen (versleuteld).' : 'Instellingen opgeslagen.');
    }

    public function deleteWebhook(Request $request): Response
    {
        $this->write();
        $this->settings->set('discord', 'webhook_url', '', 'encrypted');
        $this->settings->set('discord', 'announce_news', '0', 'bool');
        $this->audit('discord.webhook.delete', []);
        return $this->back('/admin/discord/meldingen', 'ok', 'Webhook verwijderd; nieuwsmeldingen staan uit.');
    }

    public function testWebhook(Request $request): Response
    {
        $this->write();
        $url = $this->store->webhookUrl();
        if ($url === '') {
            return $this->back('/admin/discord/meldingen', 'error', 'Er is nog geen webhook ingesteld.');
        }
        $res = (new DiscordWebhook($this->transport))->send($url, DiscordWebhook::buildPayload('✅ Testbericht: de koppeling tussen de website en dit Discord-kanaal werkt.'));
        $this->audit('discord.webhook.test', ['ok' => $res['ok'], 'status' => $res['status']]);
        return $this->back('/admin/discord/meldingen', $res['ok'] ? 'ok' : 'error', $res['ok'] ? 'Testbericht verstuurd. Kijk in het Discord-kanaal.' : 'Testbericht mislukt: ' . $res['message']);
    }

    // ─── Rolkoppeling ─────────────────────────────────────────────────────

    public function roles(Request $request): Response
    {
        $this->read();
        $this->ensureSchema();
        $discordRoles = [];
        $apiError = null;
        $api = $this->api();
        $gid = $this->store->guildId();
        if ($api !== null && DiscordApi::isSnowflake($gid)) {
            try {
                $discordRoles = $api->getRoles($gid);
            } catch (DiscordApiException $e) {
                $apiError = 'Discord-rollen konden niet worden opgehaald (je kunt het rol-ID ook zelf invullen): ' . $e->getMessage();
            }
        } else {
            $apiError = 'Zonder Bot Token/Server ID kun je Discord-rollen niet uit een lijst kiezen; vul het rol-ID zelf in.';
        }
        return $this->view('roles', [
            'mappings'     => $this->store->mappings(),
            'cmsRoles'     => $this->store->cmsRoles(),
            'discordRoles' => $discordRoles,
            'apiError'     => $apiError,
            'syncLog'      => $this->store->recentSyncLog(15),
        ]);
    }

    public function addRole(Request $request): Response
    {
        $this->write();
        $this->ensureSchema();
        $manual   = trim((string) $request->input('discord_role_manual', ''));
        $discord  = $manual !== '' ? $manual : trim((string) $request->input('discord_role_id', ''));
        $cms      = $this->intInput($request, 'cms_role_id');
        $auto     = $request->input('auto_remove', null) !== null;
        $err = $cms === null ? 'Kies een CMS-rol.' : $this->store->addMapping($discord, $cms, $auto);
        if ($err !== null) {
            return $this->back('/admin/discord/rollen', 'error', $err);
        }
        $this->audit('discord.role.add', ['discord_role_id' => $discord, 'cms_role_id' => $cms, 'auto_remove' => $auto]);
        return $this->back('/admin/discord/rollen', 'ok', 'Koppeling toegevoegd.');
    }

    public function updateRole(Request $request): Response
    {
        $this->write();
        $id  = (int) $request->param('id', 0);
        $cms = $this->intInput($request, 'cms_role_id');
        $auto = $request->input('auto_remove', null) !== null;
        $err = $cms === null ? 'Kies een CMS-rol.' : $this->store->updateMapping($id, $cms, $auto);
        if ($err !== null) {
            return $this->back('/admin/discord/rollen', 'error', $err);
        }
        $this->audit('discord.role.update', ['id' => $id, 'cms_role_id' => $cms, 'auto_remove' => $auto]);
        return $this->back('/admin/discord/rollen', 'ok', 'Koppeling bijgewerkt.');
    }

    public function deleteRole(Request $request): Response
    {
        $this->write();
        $id = (int) $request->param('id', 0);
        if (!$this->store->deleteMapping($id)) {
            return $this->back('/admin/discord/rollen', 'error', 'Deze koppeling bestaat niet (meer).');
        }
        $this->audit('discord.role.delete', ['id' => $id]);
        return $this->back('/admin/discord/rollen', 'ok', 'Koppeling verwijderd. Bestaande CMS-rollen van gebruikers blijven staan.');
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    private function read(): void
    {
        $this->auth->authorize('discord.admin');
    }

    private function write(): void
    {
        CsrfProtection::validateRequest();
        $this->auth->authorize('discord.admin');
    }

    private function api(): ?DiscordApi
    {
        $token = $this->store->botToken();
        return $token === '' ? null : new DiscordApi($token, $this->transport);
    }

    private function ensureSchema(): void
    {
        try {
            DiscordStore::ensureSchema($this->db);
        } catch (\Throwable) {
            // tabellen bestaan al, of het DB-account mag geen tabellen maken: dan meldt de query zelf iets
        }
    }

    private function intInput(Request $request, string $key): ?int
    {
        $v = $request->input($key, '');
        return is_string($v) && ctype_digit($v) && $v !== '' && strlen($v) <= 9 ? (int) $v : null;
    }

    private function audit(string $action, array $ctx): void
    {
        try {
            $this->auditLog->log($action, $this->auth->id(), (string) ($this->auth->user()['username'] ?? ''), $ctx);
        } catch (\Throwable) {
            // auditlog mag de actie nooit breken
        }
    }

    private function back(string $to, string $type, string $msg): Response
    {
        $_SESSION['discord_flash'] = ['type' => $type, 'msg' => $msg];
        return Response::redirect($to);
    }

    /** @param array<string,mixed> $vars */
    private function view(string $tab, array $vars): Response
    {
        $flash = $_SESSION['discord_flash'] ?? null;
        unset($_SESSION['discord_flash']);
        $vars += [
            'tab'      => $tab,
            'flash'    => is_array($flash) ? $flash : null,
            'moduleOn' => $this->store->moduleEnabled(),
            'guildId'  => $this->store->guildId(),
            'hasToken' => $this->store->botToken() !== '',
            'callback' => self::callbackInfo(
                (string) ($_ENV['APP_URL'] ?? ''),
                $this->store->get('redirect_uri')
            ),
        ];
        extract($vars, EXTR_SKIP);
        ob_start();
        include __DIR__ . '/views/admin.php';
        return Response::html((string) ob_get_clean());
    }

    /**
     * De callback-URL die in het Discord Developer Portal bij "Redirects" moet staan.
     * @return array{default:string,effective:string,warnings:list<string>}
     */
    public static function callbackInfo(string $appUrl, string $configured = ''): array
    {
        $appUrl   = rtrim(trim($appUrl), '/');
        $default  = $appUrl . '/auth/discord/callback';
        $effective = trim($configured) !== '' ? trim($configured) : $default;
        $warn = [];
        if ($appUrl === '') {
            $warn[] = 'APP_URL is leeg in .env: zonder die waarde is de callback-URL onvolledig en werkt inloggen met Discord niet. Zet APP_URL=https://jouwdomein.nl.';
        } elseif (str_starts_with(strtolower($appUrl), 'http://')) {
            $warn[] = 'APP_URL begint met http://. Discord en de meeste browsers verwachten https; controleer dat dit exact overeenkomt met de Redirect-URI in het Developer Portal.';
        }
        if (trim($configured) !== '' && $appUrl !== '' && trim($configured) !== $default) {
            $warn[] = 'De ingestelde Redirect URI wijkt af van APP_URL + /auth/discord/callback. Zorg dat exact de URI hierboven in het Developer Portal staat.';
        }
        return ['default' => $default, 'effective' => $effective, 'warnings' => $warn];
    }
}
