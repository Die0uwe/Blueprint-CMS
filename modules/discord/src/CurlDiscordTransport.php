<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

/**
 * Echte transport via cURL. Bewust strak ingesteld:
 *  - alleen HTTPS, redirects worden NIET gevolgd (ook een token in een header mag nooit naar een
 *    ander adres meereizen);
 *  - korte timeouts, zodat een hangende Discord geen PHP-worker vastzet;
 *  - antwoord begrensd op 1 MB.
 */
final class CurlDiscordTransport implements DiscordTransport
{
    private const MAX_BODY = 1048576;

    public function send(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new DiscordTransportException('Alleen HTTPS-adressen zijn toegestaan.');
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new DiscordTransportException('cURL kon niet worden gestart.');
        }
        $respHeaders = [];
        $buf = '';
        $opts = [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT  => min(5, $timeout),
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HEADERFUNCTION  => static function ($c, string $line) use (&$respHeaders): int {
                $p = strpos($line, ':');
                if ($p !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => static function ($c, string $chunk) use (&$buf): int {
                $buf .= $chunk;
                return strlen($buf) > self::MAX_BODY ? 0 : strlen($chunk); // 0 = afbreken
            },
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);
        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($ok === false && $status === 0) {
            // curl_error kan de URL bevatten (webhook-token!): alleen een generieke melding doorgeven.
            error_log('Discord-transport mislukt: ' . preg_replace('#https://\S+#', '[url]', $err));
            throw new DiscordTransportException('Discord is niet bereikbaar (verbinding mislukt of time-out).');
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $buf];
    }
}
