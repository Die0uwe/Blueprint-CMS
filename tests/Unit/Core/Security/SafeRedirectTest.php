<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Security;

use CommunityFusion\Core\Security\SafeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafeRedirectTest extends TestCase
{
    #[Test]
    #[DataProvider('targets')]
    public function onlyLocalPathsPass(mixed $input, string $expected): void
    {
        self::assertSame($expected, SafeRedirect::target($input));
    }

    /** @return array<string, array{mixed, string}> */
    public static function targets(): array
    {
        return [
            'eigen pad'                => ['/profiel', '/profiel'],
            'pad met query'            => ['/forum?page=2', '/forum?page=2'],
            'wortel'                   => ['/', '/'],
            'absolute URL'             => ['https://evil.example/x', '/'],
            'protocol-relatief'        => ['//evil.example', '/'],
            'backslash-truc'           => ['/\\evil.example', '/'],
            'javascript-schema'        => ['javascript:alert(1)', '/'],
            'zonder slash'             => ['profiel', '/'],
            'leeg'                     => ['', '/'],
            'null'                     => [null, '/'],
            'array'                    => [['/x'], '/'],
            'newline (header-injectie)' => ["/ok\r\nSet-Cookie: x=1", '/'],
            'te lang'                  => ['/' . str_repeat('a', 3000), '/'],
        ];
    }

    #[Test]
    public function customDefaultIsUsedForRejectedInput(): void
    {
        self::assertSame('/login', SafeRedirect::target('//evil', '/login'));
    }
}
