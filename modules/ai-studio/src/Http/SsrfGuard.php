<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Http;

/**
 * SSRF-bescherming voor door beheerders ingestelde URL's (Ollama / Open WebUI).
 *
 * Bewuste afweging: Ollama draait normaal op localhost of in het LAN, dus
 * loopback en private ranges zijn TOEGESTAAN. Geblokkeerd wordt wat nooit een
 * legitiem doel is en wél een klassieke SSRF-prooi:
 *  - alles behalve http(s), en URL's met gebruikersnaam/wachtwoord;
 *  - link-local / cloud-metadata (169.254.0.0/16, fe80::/10, AWS fd00:ec2::254,
 *    100.100.100.200) en bekende metadata-hostnamen;
 *  - 0.0.0.0/8, multicast en broadcast;
 *  - onduidelijke IP-notaties (decimaal, hex, octaal) die een filter omzeilen;
 *  - IPv4-mapped IPv6 (::ffff:169.254.169.254).
 * Hostnamen worden zelf opgelost en ALLE resultaten gecontroleerd; het eerste
 * IP wordt teruggegeven zodat de transportlaag de verbinding daaraan vastpint
 * (geen DNS-rebinding tussen controle en gebruik). Redirects worden door de
 * transportlaag niet gevolgd.
 */
final class SsrfGuard
{
    private const BLOCKED_HOSTS = ['metadata.google.internal', 'metadata', 'instance-data', 'instance-data.ec2.internal'];

    /** @var list<array{0: string, 1: int}> */
    private const BLOCKED_V4 = [
        ['169.254.0.0', 16],
        ['0.0.0.0', 8],
        ['224.0.0.0', 4],
        ['240.0.0.0', 4],
        ['100.100.100.200', 32],
        ['192.0.0.192', 32],
    ];

    /** @var callable(string): list<string> */
    private $resolver;

    /**
     * @param (callable(string): list<string>)|null $resolver hostnaam -> lijst IP's (injecteerbaar voor tests)
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = $v4;
            }
            $v6 = function_exists('dns_get_record') ? @dns_get_record($host, DNS_AAAA) : false;
            if (is_array($v6)) {
                foreach ($v6 as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
            }
            return $ips;
        };
    }

    /**
     * @return array{url: string, host: string, port: int, ip: string}
     * @throws SsrfException
     */
    public function check(string $url): array
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            throw new SsrfException('Ongeldige URL.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('Ongeldige URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new SsrfException('Alleen http(s)-URL\'s zijn toegestaan.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfException('URL\'s met inloggegevens zijn niet toegestaan.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $host = rtrim($host, '.');
        if ($host === '' || in_array($host, self::BLOCKED_HOSTS, true)) {
            throw new SsrfException('Dit doel is niet toegestaan.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new SsrfException('Ongeldige poort.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } elseif (preg_match('/^[0-9a-fx.]+$/i', $host) === 1) {
            throw new SsrfException('Onduidelijke IP-notatie; gebruik een gewoon IPv4/IPv6-adres of hostnaam.');
        } else {
            $ips = ($this->resolver)($host);
            if ($ips === []) {
                throw new SsrfException('Hostnaam kon niet worden opgelost.');
            }
        }

        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new SsrfException('Dit doeladres is niet toegestaan.');
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $ips[0]];
    }

    public function isBlockedIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true; // niet te begrijpen = niet vertrouwen
        }

        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d) terugvertalen naar het IPv4-adres.
            if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
                return $this->isBlockedIp((string) inet_ntop(substr($packed, 12)));
            }
            $first = ord($packed[0]);
            if ($first === 0xff) {
                return true; // multicast
            }
            if ($first === 0xfe && (ord($packed[1]) & 0xc0) === 0x80) {
                return true; // fe80::/10 link-local
            }
            return strtolower((string) inet_ntop($packed)) === 'fd00:ec2::254';
        }

        $long = unpack('N', $packed);
        if (!is_array($long)) {
            return true;
        }
        $addr = (int) $long[1];
        if ($addr === 0xFFFFFFFF) {
            return true;
        }
        foreach (self::BLOCKED_V4 as [$net, $bits]) {
            $netLong = ip2long($net);
            if ($netLong === false) {
                continue;
            }
            $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;
            if (($addr & $mask) === ($netLong & $mask)) {
                return true;
            }
        }
        return false;
    }
}
