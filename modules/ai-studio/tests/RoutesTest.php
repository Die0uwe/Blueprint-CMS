<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use PHPUnit\Framework\TestCase;

final class RoutesTest extends TestCase
{
    /**
     * @return list<array{string, string, string, list<string>}>
     */
    private function routes(): array
    {
        /** @var list<array{string, string, string, list<string>}> $routes */
        $routes = require dirname(__DIR__) . '/routes.php';
        return $routes;
    }

    public function testEveryPostRouteHasAuthPermissionAndRateLimit(): void
    {
        $posts = array_filter($this->routes(), static fn (array $r): bool => $r[0] === 'POST');
        self::assertCount(5, $posts);
        foreach ($posts as [$method, $pattern, , $middleware]) {
            $joined = implode('|', $middleware);
            self::assertStringContainsString('AuthMiddleware', $joined, $pattern);
            self::assertStringContainsString('PermissionMiddleware:aistudio.', $joined, $pattern);
            self::assertStringContainsString('RateLimitMiddleware', $joined, $pattern);
        }
    }

    public function testEveryRouteRequiresAPermission(): void
    {
        foreach ($this->routes() as [, $pattern, , $middleware]) {
            self::assertNotSame([], array_filter($middleware, static fn (string $m): bool => str_contains($m, 'PermissionMiddleware:')), $pattern);
        }
    }

    public function testSettingsRoutesNeedAdminPermission(): void
    {
        foreach ($this->routes() as [, $pattern, , $middleware]) {
            if (str_contains($pattern, '/settings')) {
                self::assertContains('CommunityFusion\Api\Middleware\PermissionMiddleware:aistudio.admin', $middleware, $pattern);
            }
        }
    }

    public function testHandlersExist(): void
    {
        foreach ($this->routes() as [, $pattern, $handler]) {
            [$class, $method] = explode('@', $handler);
            self::assertTrue(method_exists($class, $method), $pattern);
        }
    }
}
