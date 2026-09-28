<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Auth;

use CommunityFusion\Core\Auth\JWTManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JWTManagerTest extends TestCase
{
    #[Test]
    public function generateProducesThreeBase64UrlSegments(): void
    {
        $jwt   = new JWTManager('test-secret-key-please-rotate-me');
        $token = $jwt->generate(['sub' => 1]);

        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'Een JWT bestaat uit header.payload.signature.');
        foreach ($parts as $part) {
            self::assertDoesNotMatchRegularExpression('/[+\/=]/', $part, 'base64url mag geen +, / of = bevatten.');
        }
    }

    #[Test]
    public function verifyReturnsTheOriginalPayloadFields(): void
    {
        $jwt = new JWTManager('test-secret-key-please-rotate-me');
        $token = $jwt->generate(['sub' => 42, 'roles' => ['admin', 'member']]);

        $payload = $jwt->verify($token);

        self::assertSame(42, $payload['sub']);
        self::assertSame(['admin', 'member'], $payload['roles']);
        self::assertArrayHasKey('iat', $payload);
        self::assertArrayHasKey('exp', $payload);
    }

    #[Test]
    public function verifyRejectsATokenSignedWithADifferentSecret(): void
    {
        $token = (new JWTManager('secret-a'))->generate(['sub' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handtekening ongeldig');
        (new JWTManager('secret-b'))->verify($token);
    }

    #[Test]
    public function verifyRejectsATamperedPayload(): void
    {
        $jwt   = new JWTManager('test-secret-key-please-rotate-me');
        $token = $jwt->generate(['sub' => 1, 'roles' => ['member']]);

        [$header, $payload, $signature] = explode('.', $token);
        $tamperedPayload = strtr(base64_encode(
            (string) json_encode(['sub' => 1, 'roles' => ['super_admin'], 'iat' => time(), 'exp' => time() + 3600])
        ), '+/', '-_');
        $tamperedPayload = rtrim($tamperedPayload, '=');
        $tampered = "{$header}.{$tamperedPayload}.{$signature}";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handtekening ongeldig');
        $jwt->verify($tampered);
    }

    #[Test]
    public function verifyRejectsAMalformedToken(): void
    {
        $jwt = new JWTManager('test-secret-key-please-rotate-me');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Ongeldig JWT formaat');
        $jwt->verify('niet.een.geldig.jwt.formaat');
    }

    #[Test]
    public function verifyRejectsAnExpiredToken(): void
    {
        $jwt   = new JWTManager('test-secret-key-please-rotate-me', ttl: -1);
        $token = $jwt->generate(['sub' => 1]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verlopen');
        $jwt->verify($token);
    }
}
