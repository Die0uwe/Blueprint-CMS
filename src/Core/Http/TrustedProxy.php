<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Http;

/**
 * Vertrouwde reverse-proxy's (Cloudflare, nginx, …).
 *
 * Achter Cloudflare is REMOTE_ADDR het adres van Cloudflare zelf en ziet PHP
 * een gewone http-verbinding (Cloudflare → server). Gevolgen zonder dit: alle
 * bezoekers delen één IP (inlog-rem, rate-limit en auditlog kloppen niet) en de
 * site weet niet dat de bezoeker op https zit (cookies, links).
 *
 * `apply()` herschrijft REMOTE_ADDR en HTTPS ALLEEN als de verbinding van een
 * vertrouwde proxy komt. Headers van iedereen anders worden genegeerd, dus een
 * bezoeker kan zijn IP niet vervalsen met een eigen `CF-Connecting-IP`.
 *
 * Standaard vertrouwd: Cloudflare (bron: cloudflare.com/ips) en loopback. Extra
 * proxy's: env `TRUSTED_PROXIES=10.0.0.5,192.168.1.0/24`.
 */
final class TrustedProxy
{
    /** https://www.cloudflare.com/ips-v4 en /ips-v6 (okt 2026). */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private const LOOPBACK = ['127.0.0.0/8', '::1/128'];

    /**
     * @param array<string,mixed> $server  meestal $_SERVER (by reference)
     * @param string[]            $extra   extra vertrouwde proxy's (IP of CIDR)
     */
    public static function apply(array &$server, array $extra = []): void
    {
        $remote = filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
        if ($remote === false) {
            return;
        }
        $viaCloudflare = self::inRanges($remote, self::CLOUDFLARE);
        $trusted       = [...self::CLOUDFLARE, ...self::LOOPBACK, ...$extra];
        if (!$viaCloudflare && !self::inRanges($remote, $trusted)) {
            return;
        }

        // ── Echte bezoekers-IP ──────────────────────────────────────────
        $client = null;
        if ($viaCloudflare) {
            $cf = filter_var($server['HTTP_CF_CONNECTING_IP'] ?? '', FILTER_VALIDATE_IP);
            $client = $cf !== false ? $cf : null;
        }
        if ($client === null && !empty($server['HTTP_X_FORWARDED_FOR'])) {
            // Van rechts naar links: de eerste die géén vertrouwde proxy is, is de bezoeker.
            $hops = array_reverse(array_map('trim', explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])));
            foreach ($hops as $hop) {
                $ip = filter_var($hop, FILTER_VALIDATE_IP);
                if ($ip === false) {
                    break;
                }
                if (!self::inRanges($ip, $trusted)) {
                    $client = $ip;
                    break;
                }
            }
        }
        if ($client !== null) {
            $server['PROXY_REMOTE_ADDR'] = $remote;
            $server['REMOTE_ADDR']       = $client;
        }

        // ── https? ──────────────────────────────────────────────────────
        $proto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $visitor = json_decode((string) ($server['HTTP_CF_VISITOR'] ?? ''), true);
        $cfScheme = is_array($visitor) ? strtolower((string) ($visitor['scheme'] ?? '')) : '';
        if (str_starts_with($proto, 'https') || ($viaCloudflare && $cfScheme === 'https')) {
            $server['HTTPS']       = 'on';
            $server['SERVER_PORT'] = 443;
        }
    }

    public static function isHttps(array $server): bool
    {
        $h = $server['HTTPS'] ?? '';
        return $h !== '' && strtolower((string) $h) !== 'off';
    }

    /**
     * Zet een http://-APP_URL om naar https:// als de bezoeker op https zit en het
     * om dezelfde host gaat — voorkomt "mixed content" (links/CSS die geblokkeerd
     * worden) als de URL ooit als http:// is ingesteld.
     */
    public static function upgradeUrl(string $url, array $server): string
    {
        if (!self::isHttps($server) || !preg_match('#^http://([^/:]+)#i', $url, $m)) {
            return $url;
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')));
        return strtolower($m[1]) === $host ? 'https://' . substr($url, 7) : $url;
    }

    /** @param string[] $ranges */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
            $netBin = @inet_pton((string) $net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            if ($bytes > 0 && substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }
}
