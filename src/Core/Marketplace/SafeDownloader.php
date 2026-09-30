<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Marketplace;

use CommunityFusion\Core\Security\SsrfGuard;

/**
 * SafeDownloader — haalt een ZIP op zonder SSRF.
 *
 * - alleen https, poort 443, alleen publieke IP's (SsrfGuard)
 * - verbinding vastgezet op het gecontroleerde IP (CURLOPT_RESOLVE), dus geen DNS-rebinding
 * - redirects handmatig, maximaal 3, elke hop opnieuw gecontroleerd
 * - harde limiet op bestandsgrootte (stopt tijdens het downloaden)
 */
final class SafeDownloader
{
    public function __construct(
        private readonly SsrfGuard $guard = new SsrfGuard(),
        private readonly int $maxBytes = 52428800,
        private readonly int $maxRedirects = 3,
    ) {}

    /** @throws PackageException */
    public function fetch(string $url, string $destFile): void
    {
        for ($hop = 0; $hop <= $this->maxRedirects; $hop++) {
            try {
                $target = $this->guard->assertPublicHttps($url);
            } catch (\InvalidArgumentException $e) {
                throw new PackageException('Download geweigerd: ' . $e->getMessage());
            }

            $fh = fopen($destFile, 'wb');
            if ($fh === false) {
                throw new PackageException('Kan downloadbestand niet aanmaken.');
            }
            $written = 0;
            $location = null;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => [$target['host'] . ':443:' . $target['ips'][0]],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$location): int {
                    if (stripos($line, 'location:') === 0) {
                        $location = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use ($fh, &$written): int {
                    $written += strlen($chunk);
                    if ($written > $this->maxBytes) {
                        return 0; // afbreken
                    }
                    return (int)fwrite($fh, $chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);
            fclose($fh);

            if ($status >= 300 && $status < 400 && $location !== null && $location !== '') {
                @unlink($destFile);
                $url = $this->absolute($url, $location);
                continue;
            }
            if ($written > $this->maxBytes) {
                @unlink($destFile);
                throw new PackageException('Download is groter dan toegestaan (50 MB).');
            }
            if ($errno !== 0 || $status !== 200) {
                @unlink($destFile);
                throw new PackageException("Download mislukt (HTTP {$status}).");
            }
            return;
        }
        throw new PackageException('Te veel redirects bij het downloaden.');
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
        if (str_starts_with($location, '/')) {
            return $root . $location;
        }
        return $root . rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $location;
    }
}
