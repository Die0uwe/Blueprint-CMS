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
        $headers = self::readHeaders();
        $body    = $_POST;

        // PHP vult $_POST NOOIT voor een `Content-Type: application/json`
        // request — dat gebeurt alleen voor application/x-www-form-urlencoded
        // en multipart/form-data. Zonder deze stap kwam iedere fetch()-aanroep
        // met een JSON-body (Blokken-admin drag & drop, Marketplace-admin,
        // de Ollama-chatwidget) hier altijd met een lege $body binnen:
        // Request::input() gaf dan overal de default terug — en
        // CsrfProtection::validateRequest(), die rechtstreeks $_POST leest,
        // wees de request daardoor ook altijd af. $_POST wordt hieronder ook
        // gevuld zodat die (en andere) rechtstreekse $_POST-lezers hetzelfde
        // zien als Request::input(). Gevonden tijdens de S13-inventarisatiepas
        // — zie CHANGELOG.
        $contentType = '';
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'Content-Type') === 0) {
                $contentType = (string) $value;
                break;
            }
        }
        if (str_contains($contentType, 'application/json')) {
            $raw     = file_get_contents('php://input');
            $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $body  = [...$body, ...$decoded];
                $_POST = $body;
            }
        }

        return new self(
            method:  strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            uri:     parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            query:   $_GET,
            body:    $body,
            headers: $headers,
            cookies: $_COOKIE,
            files:   $_FILES,
            server:  $_SERVER,
        );
    }

    /**
     * Portable vervanging voor getallheaders().
     *
     * v1.26.3 — KRITIEK: getallheaders() bestaat alléén onder Apache mod_php
     * en (sinds PHP 7.3) onder php-fpm — niet onder de CLI-SAPI, niet onder
     * de PHP-ingebouwde server (`php -S`, waarmee dit hele project steeds
     * lokaal is getest — zie CHANGELOG voor de eerdere "live getest"-claims
     * die daardoor deze crash nooit raakten), en op gedeelde/budget-hosting
     * (CGI, suPHP, bepaalde FastCGI-pools zonder fpm-SAPI) evenmin. Waar de
     * functie ontbreekt geeft PHP een "Call to undefined function
     * getallheaders()" — een Error, geen Exception, en dus alleen op te
     * vangen door de \Throwable-catch in Application::handleRequest() als
     * die al draait; hier, in Request::fromGlobals(), gebeurde dat nog
     * vóór de router ooit een route kon proberen, dus ELKE request op zo'n
     * omgeving crashte, inclusief de allereerste homepage-load direct na
     * een geslaagde installatie. Precies dit patroon leverde het door de
     * gebruiker gemelde "stap 6 → HTTP 500" op: de installer zelf gebruikt
     * Request niet, dus die liep altijd door tot en met de voltooiingsstap;
     * de eerste pagina die Request::fromGlobals() wél aanroept is de
     * homepage die na de installer-redirect (?step=6) laadt.
     *
     * Fix: bouw de headers zelf op uit $_SERVER (de universele, SAPI-
     * onafhankelijke bron — elke webserver-SAPI vult $_SERVER['HTTP_*']),
     * en gebruik getallheaders() alleen nog als snelle voorkeursroute wanneer
     * die toevallig wél bestaat. $_SERVER['HTTP_AUTHORIZATION'] wordt door
     * sommige CGI/FastCGI-configuraties nooit gevuld uit veiligheidsoverwe-
     * gingen — REDIRECT_HTTP_AUTHORIZATION is dan de enige plek waar de
     * Authorization-header nog te vinden is; zonder deze fallback zouden
     * JWT/Bearer-API-calls (zie Request::bearerToken(), kernprincipe
     * "API-first") op zulke hosting altijd stilzwijgend falen.
     *
     * @return array<string, string>
     */
    private static function readHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return $headers;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $key))));
                $headers[$name] = $value;
            }
        }

        if (!isset($headers['Authorization'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if (is_string($auth) && $auth !== '') {
                $headers['Authorization'] = $auth;
            }
        }

        return $headers;
    }

    public function getMethod(): string { return $this->method; }
    public function getPath(): string   { return $this->uri; }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Paginanummer uit ?page=: altijd een geheel getal 1..$max (nooit een float/overflow in LIMIT/OFFSET,
     * en begrensde cache-sleutels).
     */
    public function page(string $key = 'page', int $max = 10000): int
    {
        $v = $this->query[$key] ?? 1;
        $n = is_string($v) && preg_match('/^\d{1,9}$/', $v) === 1 ? (int) $v : 1;
        return max(1, min($max, $n));
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
        // Bewust alleen REMOTE_ADDR: TrustedProxy::apply() zet dat al goed als de
        // verbinding van Cloudflare/een vertrouwde proxy komt. X-Forwarded-For
        // blind vertrouwen liet iedereen z'n IP vervalsen (rate-limit omzeilen).
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
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
