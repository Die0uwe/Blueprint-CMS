<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/**
 * Berichten naar een Discord-kanaal via een webhook-URL.
 *
 * De webhook-URL is een GEHEIM (wie hem kent kan in het kanaal posten) en staat versleuteld in
 * cf_settings. Hier wordt hij (1) strikt gevalideerd — anders zou een beheerder/aanvaller de server
 * naar een willekeurig adres kunnen laten POST'en (SSRF) — en (2) opnieuw opgebouwd uit de
 * gecontroleerde onderdelen. Foutmeldingen bevatten nooit de URL.
 */
final class DiscordWebhook
{
    public const MAX_CONTENT     = 2000;
    public const MAX_TITLE       = 256;
    public const MAX_DESCRIPTION = 4096;

    private const HOSTS   = ['discord.com', 'discordapp.com'];
    private const TIMEOUT = 5; // kort: het versturen gebeurt tijdens het publiceren van een artikel

    private readonly DiscordTransport $transport;

    public function __construct(?DiscordTransport $transport = null)
    {
        $this->transport = $transport ?? new CurlDiscordTransport();
    }

    /**
     * Geeft de opnieuw opgebouwde, veilige URL of null als de invoer niet strikt voldoet.
     * Toegestaan: https://{discord.com|discordapp.com}/api/webhooks/{17–20 cijfers}/{token}[?thread_id={cijfers}]
     * Niet toegestaan: http, andere hosts/subdomeinen, user-info, poort, fragment, andere query, extra pad.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 400) {
            return null;
        }
        $re = '#^https://([A-Za-z.]{11,15})/api/webhooks/(\d{17,20})/([A-Za-z0-9_][A-Za-z0-9_.\-]{0,99})(?:\?thread_id=(\d{15,25}))?$#D';
        if (preg_match($re, $url, $m) !== 1) {
            return null;
        }
        $host = strtolower($m[1]);
        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }
        return "https://{$host}/api/webhooks/{$m[2]}/{$m[3]}" . (($m[4] ?? '') !== '' ? "?thread_id={$m[4]}" : '');
    }

    public static function isValid(string $url): bool
    {
        return self::normalize($url) !== null;
    }

    /** Voor de beheerder: nooit de URL zelf, alleen de laatste 4 tekens van het token. */
    public static function mask(string $url): string
    {
        $n = self::normalize($url);
        if ($n === null) {
            return 'ongeldig';
        }
        $token = explode('/', parse_url($n, PHP_URL_PATH) ?: '')[4] ?? '';
        return 'ingesteld ✔ …' . substr($token, -4);
    }

    /**
     * Bouwt de JSON-payload met limieten en zonder pings (allowed_mentions.parse = []).
     *
     * @param list<array{title?:string,description?:string,url?:string,color?:int}> $embeds
     * @return array<string,mixed>
     */
    public static function buildPayload(string $content = '', array $embeds = []): array
    {
        $payload = ['allowed_mentions' => ['parse' => []]];

        $content = self::cut($content, self::MAX_CONTENT);
        if ($content !== '') {
            $payload['content'] = $content;
        }

        $out = [];
        foreach (array_slice($embeds, 0, 10) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $emb = [];
            $title = self::cut((string) ($e['title'] ?? ''), self::MAX_TITLE);
            $desc  = self::cut((string) ($e['description'] ?? ''), self::MAX_DESCRIPTION);
            if ($title !== '') { $emb['title'] = $title; }
            if ($desc !== '')  { $emb['description'] = $desc; }
            $link = (string) ($e['url'] ?? '');
            if ($link !== '' && strlen($link) <= 2000 && preg_match('#^https?://[^\s]+$#i', $link) === 1) {
                $emb['url'] = $link;
            }
            if (isset($e['color']) && is_int($e['color']) && $e['color'] >= 0 && $e['color'] <= 0xFFFFFF) {
                $emb['color'] = $e['color'];
            }
            if ($emb !== []) {
                $out[] = $emb;
            }
        }
        if ($out !== []) {
            $payload['embeds'] = $out;
        }
        return $payload;
    }

    /**
     * Verstuur een payload (uit buildPayload). Gooit nooit.
     *
     * @return array{ok:bool,status:int,message:string,retry_after:?float}
     */
    public function send(string $webhookUrl, array $payload): array
    {
        $url = self::normalize($webhookUrl);
        if ($url === null) {
            return self::result(false, 0, 'De webhook-URL is ongeldig. Verwacht: https://discord.com/api/webhooks/<id>/<token>.');
        }
        if (!isset($payload['content']) && !isset($payload['embeds'])) {
            return self::result(false, 0, 'Er is niets om te versturen (geen tekst en geen embed).');
        }
        // Zekerheid: nooit pings, wat de aanroeper ook meegaf.
        $payload['allowed_mentions'] = ['parse' => []];

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $res  = $this->transport->send('POST', $url, ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: DiscordBot (https://www.dieouwe.nl, 1.0)'], $json, self::TIMEOUT);
        } catch (DiscordTransportException $e) {
            return self::result(false, 0, $e->getMessage());
        } catch (\Throwable) {
            return self::result(false, 0, 'Het bericht kon niet worden verstuurd.');
        }

        $status = (int) $res['status'];
        if ($status === 204 || $status === 200) {
            return self::result(true, $status, 'Bericht verstuurd.');
        }
        $body = json_decode((string) $res['body'], true);
        $err  = DiscordApi::translateError($status, is_array($body) ? $body : [], $res['headers'] ?? []);
        $msg = match (true) {
            $status === 404 => 'Deze webhook bestaat niet meer (verwijderd in Discord?). Maak een nieuwe webhook aan en stel hem opnieuw in.',
            $status === 401 => 'Discord weigert deze webhook (401): het token klopt niet meer. Maak een nieuwe webhook aan.',
            default         => $err->getMessage(),
        };
        if ($status === 404 || $status === 401) {
            $msg .= $err->discordMessage !== null ? " Discord zegt: \"{$err->discordMessage}\"." : '';
        }
        return self::result(false, $status, $msg, $err->retryAfter);
    }

    /** Embed voor een nieuw nieuwsartikel. @return array{ok:bool,status:int,message:string,retry_after:?float} */
    public function sendNews(string $webhookUrl, string $title, string $url, string $summary = ''): array
    {
        return $this->send($webhookUrl, self::buildPayload('', [[
            'title'       => $title,
            'url'         => $url,
            'description' => $summary,
            'color'       => 0x6C3DF4,
        ]]));
    }

    private static function cut(string $s, int $max): string
    {
        $s = trim($s);
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return rtrim(mb_substr($s, 0, $max - 1)) . '…';
    }

    /** @return array{ok:bool,status:int,message:string,retry_after:?float} */
    private static function result(bool $ok, int $status, string $message, ?float $retry = null): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message, 'retry_after' => $retry];
    }
}
