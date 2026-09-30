<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/**
 * Discord REST-client met bot-token (Authorization: Bot …), API v10.
 *
 * Alle aanroepen gaan via een injecteerbaar DiscordTransport, zodat dit zonder netwerk getest kan
 * worden. Fouten worden vertaald naar DiscordApiException met een Nederlandse melding die ook
 * Discord's eigen `message` bevat.
 *
 * Permissiebits (Discord-docs): ADMINISTRATOR 1<<3, MANAGE_GUILD 1<<5, VIEW_CHANNEL 1<<10,
 * SEND_MESSAGES 1<<11, EMBED_LINKS 1<<14, MANAGE_ROLES 1<<28.
 */
final class DiscordApi
{
    public const BASE_URL = 'https://discord.com/api/v10';
    public const PERM_ADMINISTRATOR = 1 << 3;
    public const PERM_MANAGE_GUILD  = 1 << 5;
    public const PERM_VIEW_CHANNEL  = 1 << 10;
    public const PERM_SEND_MESSAGES = 1 << 11;
    public const PERM_EMBED_LINKS   = 1 << 14;
    public const PERM_MANAGE_ROLES  = 1 << 28;

    /** Permissions-getal voor de bot-invite-URL: Kanalen bekijken (1024) + Serverbeheer (32). */
    public const INVITE_PERMISSIONS = 1056;

    private const TIMEOUT = 8;
    private readonly DiscordTransport $transport;

    public function __construct(
        private readonly string $botToken,
        ?DiscordTransport $transport = null,
        private readonly string $baseUrl = self::BASE_URL,
    ) {
        $this->transport = $transport ?? new CurlDiscordTransport();
    }

    public static function isSnowflake(string $id): bool
    {
        return preg_match('/^\d{15,25}$/D', $id) === 1;
    }

    /** Bot-invite-URL (scope=bot, permissions=1056). Leeg als het client-id geen snowflake is. */
    public static function inviteUrl(string $clientId): ?string
    {
        $clientId = trim($clientId);
        if (!self::isSnowflake($clientId)) {
            return null;
        }
        return 'https://discord.com/oauth2/authorize?' . http_build_query([
            'client_id'   => $clientId,
            'permissions' => self::INVITE_PERMISSIONS,
            'scope'       => 'bot',
        ]);
    }

    // ─── Endpoints ────────────────────────────────────────────────────────

    /** GET /guilds/{id}?with_counts=true → o.a. name, approximate_member_count, approximate_presence_count */
    public function getGuild(string $guildId): array
    {
        return $this->request('GET', '/guilds/' . $this->id($guildId), null, ['with_counts' => 'true']);
    }

    /** GET /guilds/{id}/widget → ['enabled' => bool, 'channel_id' => ?string] */
    public function getWidgetSettings(string $guildId): array
    {
        return $this->request('GET', '/guilds/' . $this->id($guildId) . '/widget');
    }

    /** PATCH /guilds/{id}/widget — vereist MANAGE_GUILD. */
    public function setWidget(string $guildId, bool $enabled, ?string $channelId): array
    {
        $body = ['enabled' => $enabled, 'channel_id' => $channelId === null ? null : $this->id($channelId)];
        return $this->request('PATCH', '/guilds/' . $this->id($guildId) . '/widget', $body);
    }

    /**
     * GET /guilds/{id}/channels — alleen tekst- (0) en aankondigingskanalen (5), op positie.
     * @return list<array{id:string,name:string,type:int}>
     */
    public function getChannels(string $guildId): array
    {
        $out = [];
        foreach ($this->request('GET', '/guilds/' . $this->id($guildId) . '/channels') as $c) {
            if (is_array($c) && in_array($c['type'] ?? null, [0, 5], true) && isset($c['id'], $c['name'])) {
                $out[] = ['id' => (string) $c['id'], 'name' => (string) $c['name'], 'type' => (int) $c['type'], 'position' => (int) ($c['position'] ?? 0)];
            }
        }
        usort($out, static fn($a, $b) => [$a['position'], $a['name']] <=> [$b['position'], $b['name']]);
        return array_map(static fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'type' => $c['type']], $out);
    }

    /**
     * GET /guilds/{id}/roles — zonder @everyone (heeft hetzelfde id als de server), hoogste rol eerst.
     * @return list<array{id:string,name:string,managed:bool}>
     */
    public function getRoles(string $guildId): array
    {
        $out = [];
        foreach ($this->request('GET', '/guilds/' . $this->id($guildId) . '/roles') as $r) {
            if (is_array($r) && isset($r['id'], $r['name']) && (string) $r['id'] !== $guildId) {
                $out[] = ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'managed' => (bool) ($r['managed'] ?? false), 'position' => (int) ($r['position'] ?? 0)];
            }
        }
        usort($out, static fn($a, $b) => $b['position'] <=> $a['position']);
        return array_map(static fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'managed' => $r['managed']], $out);
    }

    /** GET /guilds/{id}/members/{user} — null als de gebruiker geen lid is (404). */
    public function getGuildMember(string $guildId, string $discordUserId): ?array
    {
        try {
            return $this->request('GET', '/guilds/' . $this->id($guildId) . '/members/' . $this->id($discordUserId));
        } catch (DiscordApiException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    /** GET /users/@me — de bot zelf (controleert of het token geldig is). */
    public function getCurrentUser(): array
    {
        return $this->request('GET', '/users/@me');
    }

    /** GET /users/@me/guilds — bij een bot-token incl. `permissions` van de bot per server. */
    public function getCurrentUserGuilds(): array
    {
        return $this->request('GET', '/users/@me/guilds', null, ['limit' => '200']);
    }

    /** Bitveld (string of int) → leesbare namen van de relevante rechten. @return list<string> */
    public static function permissionLabels(int $bits): array
    {
        $map = [
            self::PERM_ADMINISTRATOR => 'Beheerder (ADMINISTRATOR)',
            self::PERM_MANAGE_GUILD  => 'Serverbeheer (MANAGE_GUILD)',
            self::PERM_MANAGE_ROLES  => 'Rollen beheren (MANAGE_ROLES)',
            self::PERM_VIEW_CHANNEL  => 'Kanalen bekijken (VIEW_CHANNEL)',
            self::PERM_SEND_MESSAGES => 'Berichten sturen (SEND_MESSAGES)',
            self::PERM_EMBED_LINKS   => 'Links insluiten (EMBED_LINKS)',
        ];
        $out = [];
        foreach ($map as $bit => $label) {
            if (($bits & $bit) === $bit) {
                $out[] = $label;
            }
        }
        return $out;
    }

    /**
     * Doorloopt alle controles voor het scherm "Test verbinding". Gooit nooit: elke stap vult
     * een veld of een probleem-melding.
     */
    public function testConnection(string $guildId): DiscordConnectionStatus
    {
        $s = new DiscordConnectionStatus();

        if (!self::isSnowflake($guildId)) {
            $s->problems[] = 'Het Guild/Server ID ontbreekt of is ongeldig (15–25 cijfers).';
            return $s;
        }

        // 1. Token geldig?
        try {
            $me = $this->getCurrentUser();
            $s->tokenValid = true;
            $s->botName    = (string) ($me['username'] ?? '');
        } catch (DiscordApiException $e) {
            $s->tokenValid = $e->status === 401 ? false : null;
            $s->problems[] = $e->getMessage();
            if ($e->status === 401 || $e->status === 0) {
                return $s; // zonder geldig token/verbinding zijn de rest zinloos
            }
        }

        // 2. Bot in de server?
        try {
            $g = $this->getGuild($guildId);
            $s->inGuild    = true;
            $s->guildName  = (string) ($g['name'] ?? '');
            $s->memberCount = isset($g['approximate_member_count']) ? (int) $g['approximate_member_count'] : null;
        } catch (DiscordApiException $e) {
            if (in_array($e->status, [403, 404], true)) {
                $s->inGuild    = false;
                $s->problems[] = 'De bot zit niet in deze server (of het Server ID klopt niet). Nodig de bot uit met de link op het Status-scherm. ' . $e->getMessage();
                return $s;
            }
            $s->problems[] = $e->getMessage();
        }

        // 3. Widget-stand (leest alleen; MANAGE_GUILD kan nodig zijn)
        try {
            $w = $this->getWidgetSettings($guildId);
            $s->widgetEnabled   = (bool) ($w['enabled'] ?? false);
            $s->widgetChannelId = isset($w['channel_id']) && $w['channel_id'] !== null ? (string) $w['channel_id'] : null;
        } catch (DiscordApiException $e) {
            $s->problems[] = 'Widget-status niet te lezen: ' . $e->getMessage();
        }

        // 4. Kanaalnaam bij het widget-kanaal
        if ($s->widgetChannelId !== null) {
            try {
                foreach ($this->getChannels($guildId) as $c) {
                    if ($c['id'] === $s->widgetChannelId) {
                        $s->widgetChannelName = $c['name'];
                        break;
                    }
                }
            } catch (DiscordApiException) {
                // kanaalnaam is cosmetisch
            }
        }

        // 5. Rechten van de bot (best effort: bij een bot-token geeft /users/@me/guilds een `permissions`-veld)
        try {
            foreach ($this->getCurrentUserGuilds() as $row) {
                if (is_array($row) && (string) ($row['id'] ?? '') === $guildId && isset($row['permissions'])) {
                    $bits = (int) $row['permissions'];
                    $s->permissions     = self::permissionLabels($bits);
                    $s->canManageGuild  = ($bits & (self::PERM_MANAGE_GUILD | self::PERM_ADMINISTRATOR)) !== 0;
                    break;
                }
            }
        } catch (DiscordApiException) {
            // onbekend laten
        }

        return $s;
    }

    // ─── Intern ───────────────────────────────────────────────────────────

    private function id(string $id): string
    {
        if (!self::isSnowflake($id)) {
            throw new DiscordApiException('Ongeldig Discord-ID (alleen cijfers, 15–25 lang).');
        }
        return $id;
    }

    /**
     * @param array<string,mixed>|null  $json
     * @param array<string,string>      $query
     */
    private function request(string $method, string $path, ?array $json = null, array $query = []): array
    {
        if (preg_match('/^[A-Za-z0-9._\-]{8,200}$/D', $this->botToken) !== 1) {
            throw new DiscordApiException('Er is geen (geldig) bot-token ingesteld. Vul het Bot Token in bij de Discord-instellingen.', 401);
        }
        $url = rtrim($this->baseUrl, '/') . $path . ($query !== [] ? '?' . http_build_query($query) : '');
        $headers = [
            'Authorization: Bot ' . $this->botToken,
            'Accept: application/json',
            'User-Agent: DiscordBot (https://www.dieouwe.nl, 1.0)',
        ];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        try {
            $res = $this->transport->send($method, $url, $headers, $body, self::TIMEOUT);
        } catch (DiscordTransportException $e) {
            throw new DiscordApiException($e->getMessage());
        }

        $status  = (int) $res['status'];
        $decoded = json_decode((string) $res['body'], true);

        if ($status >= 200 && $status < 300) {
            if (!is_array($decoded)) {
                throw new DiscordApiException('Discord gaf een onverwacht antwoord (geen JSON).', $status);
            }
            return $decoded;
        }
        throw self::translateError($status, is_array($decoded) ? $decoded : [], $res['headers'] ?? []);
    }

    /** @param array<string,mixed> $body @param array<string,string> $headers */
    public static function translateError(int $status, array $body, array $headers = []): DiscordApiException
    {
        $dMsg  = isset($body['message']) && is_string($body['message']) ? self::clean($body['message']) : null;
        $dCode = isset($body['code']) && is_int($body['code']) ? $body['code'] : null;
        $retry = null;
        if ($status === 429) {
            $retry = isset($body['retry_after']) && is_numeric($body['retry_after']) ? (float) $body['retry_after']
                   : (isset($headers['retry-after']) && is_numeric($headers['retry-after']) ? (float) $headers['retry-after'] : null);
        }

        $text = match (true) {
            $status === 401 => 'Discord weigert het bot-token (401). Controleer of het Bot Token klopt (Developer Portal → Bot → Reset Token).',
            $status === 403 => 'Geen toestemming (403): de bot mist een recht in deze server. Voor widget-beheer is "Serverbeheer" (MANAGE_GUILD) nodig.',
            $status === 404 => 'Niet gevonden (404): controleer het Server-/Kanaal-ID en of de bot in de server zit.',
            $status === 429 => 'Discord geeft tijdelijk te veel verzoeken terug (429)' . ($retry !== null ? '; probeer het over ' . max(1, (int) ceil($retry)) . ' seconde(n) opnieuw.' : '; probeer het zo opnieuw.'),
            $status >= 500  => "Discord heeft een storing (HTTP {$status}); probeer het later opnieuw.",
            $status >= 300 && $status < 400 => "Discord stuurde een onverwachte omleiding (HTTP {$status}); die wordt om veiligheidsredenen niet gevolgd.",
            default         => "Discord weigerde het verzoek (HTTP {$status}).",
        };
        if ($dMsg !== null && $dMsg !== '') {
            $text .= " Discord zegt: \"{$dMsg}\"" . ($dCode !== null ? " (code {$dCode})" : '') . '.';
        }
        return new DiscordApiException($text, $status, $dMsg, $dCode, $retry);
    }

    private static function clean(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
        return mb_substr(trim($s), 0, 200);
    }
}
