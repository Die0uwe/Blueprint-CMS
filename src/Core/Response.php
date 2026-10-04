<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core;

/**
 * HTTP Response
 */
final class Response
{
    public function __construct(
        private string $body       = '',
        private int    $statusCode = 200,
        private array  $headers    = [],
    ) {}

    /** @var array{path:string,start:int,length:int}|null */
    private ?array $stream = null;

    /**
     * Bestand in blokken uitsturen i.p.v. in het geheugen te laden (grote downloads, Range).
     * Content-Length wordt gezet op $length.
     */
    public static function stream(string $path, int $start, int $length, int $status = 200, array $headers = []): self
    {
        $r = new self('', $status, $headers + ['Content-Length' => (string) $length]);
        $r->stream = ['path' => $path, 'start' => max(0, $start), 'length' => max(0, $length)];
        return $r;
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function send(): void
    {
        // Security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($https) {
            header('Strict-Transport-Security: max-age=15552000');   // 180 dagen, zonder includeSubDomains/preload
        }

        http_response_code($this->statusCode);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->stream !== null) {
            $this->sendStream($this->stream);
            return;
        }

        echo $this->body;
    }

    /** @param array{path:string,start:int,length:int} $s */
    private function sendStream(array $s): void
    {
        $h = @fopen($s['path'], 'rb');
        if ($h === false) {
            return;
        }
        fseek($h, $s['start']);
        $left = $s['length'];
        while ($left > 0 && !feof($h) && connection_status() === CONNECTION_NORMAL) {
            $chunk = fread($h, min(65536, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
        fclose($h);
    }

    public function withHeader(string $name, string $value): self
    {
        $new = clone $this;
        $new->headers[$name] = $value;
        return $new;
    }

    public function getBody(): string   { return $this->body; }
    public function isStream(): bool    { return $this->stream !== null; }
    public function getHeader(string $name): ?string { return $this->headers[$name] ?? null; }
    public function getStatus(): int    { return $this->statusCode; }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : Response.php                                         ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : HTTP response + security headers                     ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
