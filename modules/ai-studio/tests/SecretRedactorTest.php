<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Modules\AiStudio\Http\TransportException;
use CommunityFusion\Modules\AiStudio\Provider\ProviderException;
use CommunityFusion\Modules\AiStudio\SecretRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    #[Test]
    public function testRemovesKnownSecretAndKeyPatterns(): void
    {
        $key = 'my-very-secret-key-123';
        $text = "Fout bij {$key}; Incorrect API key provided: sk-proj-abcdefghijklmnop. Header Authorization: Bearer abcdef1234567890xyz en AIzaSyA1234567890abcdefghijkl x-api-key: topsecret";
        $out = SecretRedactor::redact($text, [$key]);

        foreach ([$key, 'sk-proj-abcdefghijklmnop', 'abcdef1234567890xyz', 'AIzaSyA1234567890abcdefghijkl', 'topsecret'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
        self::assertStringContainsString(SecretRedactor::MASK, $out);
    }

    #[Test]
    public function testProviderExceptionNeverEchoesRawBodyOrKey(): void
    {
        $e = new TransportException('HTTP 401', 401, '{"error":{"message":"Bad key sk-live-1234567890abcd"}}');
        $ex = ProviderException::fromTransport('OpenAI', $e, ['sk-live-1234567890abcd']);

        self::assertSame('OpenAI: HTTP 401 (key geweigerd)', $ex->getMessage());
    }

    #[Test]
    public function testRemoteMessageIsRedactedAndStripped(): void
    {
        $ex = ProviderException::remote('Google', "<b>Key</b> AIzaSyA1234567890abcdefghijkl\nongeldig", []);

        self::assertStringNotContainsString('AIza', $ex->getMessage());
        self::assertStringNotContainsString('<b>', $ex->getMessage());
        self::assertStringNotContainsString("\n", $ex->getMessage());
    }
}
