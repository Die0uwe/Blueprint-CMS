<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Security;

use CommunityFusion\Core\Security\CsrfProtection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsrfProtectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SESSION['_csrf_token'], $_POST['_csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    #[Test]
    public function getTokenGeneratesA64CharacterHexToken(): void
    {
        $token = CsrfProtection::getToken();

        self::assertSame(64, strlen($token), 'bin2hex(random_bytes(32)) moet 64 hex-tekens opleveren.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function getTokenIsStablePerSession(): void
    {
        $first  = CsrfProtection::getToken();
        $second = CsrfProtection::getToken();

        self::assertSame($first, $second, 'Een tweede aanroep binnen dezelfde sessie moet hetzelfde token geven.');
    }

    #[Test]
    public function verifyAcceptsTheCorrectToken(): void
    {
        $token = CsrfProtection::getToken();

        self::assertTrue(CsrfProtection::verify($token));
    }

    #[Test]
    public function verifyRejectsAWrongToken(): void
    {
        CsrfProtection::getToken();

        self::assertFalse(CsrfProtection::verify('deze-token-klopt-niet'));
    }

    #[Test]
    public function verifyRejectsAnEmptyTokenWhenNoneIsSet(): void
    {
        self::assertFalse(CsrfProtection::verify(''));
    }

    #[Test]
    public function validateRequestRejectsAnArrayTokenWithA403(): void
    {
        CsrfProtection::getToken();
        $_POST['_csrf_token'] = ['x'];

        try {
            CsrfProtection::validateRequest();
            self::fail('Een array-token had geweigerd moeten worden.');
        } catch (\CommunityFusion\Core\HttpException $e) {
            self::assertSame(403, $e->getCode());
        }
    }

    #[Test]
    public function validateRequestRejectsAnEmptyTokenEvenWhenTheSessionHasNone(): void
    {
        $_POST['_csrf_token'] = '';

        $this->expectException(\CommunityFusion\Core\HttpException::class);
        CsrfProtection::validateRequest();
    }

    #[Test]
    public function fieldRendersAnEscapedHiddenInputContainingTheToken(): void
    {
        $token = CsrfProtection::getToken();
        $field = CsrfProtection::field();

        self::assertStringContainsString('type="hidden"', $field);
        self::assertStringContainsString('name="_csrf_token"', $field);
        self::assertStringContainsString($token, $field);
    }

    #[Test]
    public function validateRequestPassesWhenPostTokenMatches(): void
    {
        $token = CsrfProtection::getToken();
        $_POST['_csrf_token'] = $token;

        $this->expectNotToPerformAssertions();
        CsrfProtection::validateRequest();
    }

    #[Test]
    public function validateRequestPassesWhenHeaderTokenMatches(): void
    {
        $token = CsrfProtection::getToken();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->expectNotToPerformAssertions();
        CsrfProtection::validateRequest();
    }

    #[Test]
    public function validateRequestThrowsOnMissingOrWrongToken(): void
    {
        CsrfProtection::getToken();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(403);
        CsrfProtection::validateRequest();
    }
}
