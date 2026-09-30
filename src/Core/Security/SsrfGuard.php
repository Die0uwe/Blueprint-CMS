<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Security;

/**
 * SsrfGuard — laat alleen https-URL's naar publieke IP-adressen toe.
 *
 * Geeft de gevalideerde IP's terug zodat de aanroeper de verbinding kan vastzetten
 * (CURLOPT_RESOLVE) en DNS-rebinding tussen controle en verbinding uitgesloten is.
 * Ook bedoeld voor latere preview-fetches (Editor, plugins).
 */
final class SsrfGuard
{
    /** @var callable(string):list<string> */
    private $resolver;

    /** @param (callable(string):list<string>)|null $resolver Vervangbaar voor tests */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? [self::class, 'resolveHost'];
    }

    /**
     * @return array{host:string,port:int,ips:list<string>}
     * @throws \InvalidArgumentException bij een onveilige URL
     */
    public function assertPublicHttps(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            throw new \InvalidArgumentException('URL bevat ongeldige tekens.');
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Ongeldige URL.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw new \InvalidArgumentException('Alleen https-URL\'s zijn toegestaan.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Inloggegevens in een URL zijn niet toegestaan.');
        }
        $port = (int)($parts['port'] ?? 443);
        if ($port !== 443) {
            throw new \InvalidArgumentException('Alleen poort 443 is toegestaan.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new \InvalidArgumentException('Deze host is niet toegestaan.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw new \InvalidArgumentException('Host kon niet worden opgelost.');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new \InvalidArgumentException('Deze host verwijst naar een niet-publiek adres.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ips' => array_values($ips)];
    }

    public static function isPublicIp(string $ip): bool
    {
        // IPv4-mapped IPv6 (::ffff:10.0.0.1) terugbrengen naar IPv4
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            // 100.64.0.0/10 (CGNAT), 192.0.0.0/24, 198.18.0.0/15 (benchmark), 169.254.0.0/16 (link-local)
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15], ['169.254.0.0', 16]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
            return true;
        }
        // IPv6: unique-local (fc00::/7), link-local (fe80::/10), loopback, unspecified
        $bin = inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        $first = ord($bin[0]);
        $second = ord($bin[1]);
        if (($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80)) {
            return false;
        }
        return $bin !== str_repeat("\0", 16) && $bin !== str_repeat("\0", 15) . "\1";
    }

    /** @return list<string> */
    public static function resolveHost(string $host): array
    {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $rec) {
            if (isset($rec['ip'])) {
                $ips[] = $rec['ip'];
            } elseif (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            $ips = $v4 === false ? [] : $v4;
        }
        return $ips;
    }
}
