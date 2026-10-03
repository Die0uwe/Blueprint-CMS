<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Auth;

use CommunityFusion\Core\Auth\PasswordResetService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordResetRulesTest extends TestCase
{
    #[Test]
    #[DataProvider('passwords')]
    public function passwordRules(string $password, string $confirm, int $expectedErrors): void
    {
        self::assertCount($expectedErrors, PasswordResetService::validatePassword($password, $confirm));
    }

    /** @return array<string, array{string, string, int}> */
    public static function passwords(): array
    {
        return [
            'goed'                    => ['voldoende-lang', 'voldoende-lang', 0],
            'precies 8 tekens'        => ['12345678', '12345678', 0],
            'te kort'                 => ['kort', 'kort', 1],
            'komt niet overeen'       => ['voldoende-lang', 'voldoende-lanx', 1],
            'te kort en afwijkend'    => ['kort', 'korter', 2],
            'meerbytetekens tellen als tekens' => ['ééééééé', 'ééééééé', 1],
            'te lang'                 => [str_repeat('a', PasswordResetService::MAX_PASSWORD_LENGTH + 1), str_repeat('a', PasswordResetService::MAX_PASSWORD_LENGTH + 1), 1],
        ];
    }

    #[Test]
    public function tokenFormat(): void
    {
        self::assertTrue(PasswordResetService::isWellFormedToken(bin2hex(random_bytes(32))));
        foreach (['', 'abc', str_repeat('g', 64), str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 65), str_repeat('a', 64) . "\n"] as $bad) {
            self::assertFalse(PasswordResetService::isWellFormedToken($bad), json_encode($bad));
        }
    }

    #[Test]
    public function hashIsDeterministicSha256(): void
    {
        $t = str_repeat('ab', 32);
        self::assertSame(hash('sha256', $t), PasswordResetService::hashToken($t));
        self::assertSame(64, strlen(PasswordResetService::hashToken($t)));
    }
}
