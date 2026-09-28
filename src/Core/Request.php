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
 * HTTP Request wrapper (PSR-7 geïnspireerd, vereenvoudigd).
 */
final class Request
{
    private array $params = [];

    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array  $query,
        private readonly array  $body,
        private readonly array  $headers,
        private readonly array  $cookies,
        private readonly array  $files,
        private readonly array  $server,
    ) {}

    public static function fromGlobals(): self
    {
        return new self(
            method:  strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            uri:     parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            query:   $_GET,
            body:    $_POST,
            headers: getallheaders() ?: [],
            cookies: $_COOKIE,
            files:   $_FILES,
            server:  $_SERVER,
        );
    }

    public function getMethod(): string { return $this->method; }
    public function getPath(): string   { return $this->uri; }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return [...$this->query, ...$this->body];
    }

    /**
     * Geef de $_FILES-array terug (bv. `$request->files()['avatar']`).
     * Toegevoegd voor UploadManager-consumers — was ongebruikt sinds v1.0.0
     * ondanks dat de property al bestond.
     */
    public function files(): array
    {
        return $this->files;
    }

    public function header(string $key, mixed $default = null): mixed
    {
        return $this->headers[$key] ?? $this->headers[strtolower($key)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization', '');
        return str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : null;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json');
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    public function params(): array
    {
        return $this->params;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : Request.php                                          ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : HTTP request wrapper                                 ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
