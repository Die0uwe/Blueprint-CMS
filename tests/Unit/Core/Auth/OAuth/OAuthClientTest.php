<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Auth\OAuth;

use CommunityFusion\Core\Auth\OAuth\OAuthClient;
use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OAuthClientTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SESSION['oauth_state_demo']);
        putenv('OAUTH_MOCK_BASE');
        putenv('APP_ENV');
        unset($_ENV['OAUTH_MOCK_BASE'], $_ENV['APP_ENV']);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function client(string $id = 'id', string $secret = 'secret'): OAuthClient
    {
        // Connection is final en heeft een PDO-constructor; voor deze tests is geen database nodig.
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        return new class($db, $id, $secret, 'https://cms.example/auth/demo/callback') extends OAuthClient {
            public function getProviderSlug(): string { return 'demo'; }
            public function getAuthorizationUrl(string $state): string { return 'https://p.example/auth?state=' . $state; }
            protected function getTokenEndpoint(): string { return 'https://p.example/token'; }
            protected function getUserEndpoint(): string { return 'https://p.example/user'; }
            protected function getGrantType(): string { return 'authorization_code'; }
            protected function extractUserId(array $user): string|int { return $user['id']; }
        };
    }

    #[Test]
    public function emptyExpectedAndEmptyGivenStateNeverMatches(): void
    {
        // hash_equals('', '') is true: zonder opgeslagen state mocht een lege state er vroeger door.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('state mismatch');
        $this->client()->handleCallback('code', '');
    }

    #[Test]
    public function missingSessionStateWithAGivenStateIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('state mismatch');
        $this->client()->handleCallback('code', 'abc');
    }

    #[Test]
    public function wrongStateIsRejectedAndTheStoredStateIsConsumed(): void
    {
        $client = $this->client();
        $client->buildRedirectUrl();
        self::assertNotSame('', $_SESSION['oauth_state_demo']);

        try {
            $client->handleCallback('code', 'niet-de-juiste-state');
            self::fail('Een verkeerde state moet geweigerd worden.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('state mismatch', $e->getMessage());
        }
        // Eenmalig: ook na een mislukte poging is de state weg (geen herhaalde pogingen).
        self::assertArrayNotHasKey('oauth_state_demo', $_SESSION);
    }

    #[Test]
    public function buildRedirectUrlStoresA32CharStateAndPutsItInTheUrl(): void
    {
        $url   = $this->client()->buildRedirectUrl();
        $state = $_SESSION['oauth_state_demo'];

        self::assertSame(32, strlen($state));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $state);
        self::assertStringEndsWith('state=' . $state, $url);
    }

    #[Test]
    public function isConfiguredNeedsBothIdAndSecret(): void
    {
        self::assertTrue($this->client('a', 'b')->isConfigured());
        self::assertFalse($this->client('', 'b')->isConfigured());
        self::assertFalse($this->client('a', '')->isConfigured());
    }

    #[Test]
    public function testSeamDoesNothingUnlessAppEnvIsTesting(): void
    {
        putenv('OAUTH_MOCK_BASE=http://127.0.0.1:9100');

        // Geen APP_ENV: geen herschrijving.
        self::assertSame('https://github.com/x', OAuthClient::rewriteForTests('https://github.com/x'));

        putenv('APP_ENV=production');
        self::assertSame('https://github.com/x', OAuthClient::rewriteForTests('https://github.com/x'));

        putenv('APP_ENV=testing');
        self::assertSame('http://127.0.0.1:9100/github.com/x', OAuthClient::rewriteForTests('https://github.com/x'));
    }

    #[Test]
    public function testSeamLeavesNonHttpsUrlsAlone(): void
    {
        putenv('OAUTH_MOCK_BASE=http://127.0.0.1:9100');
        putenv('APP_ENV=testing');
        self::assertSame('http://elsewhere.example/x', OAuthClient::rewriteForTests('http://elsewhere.example/x'));
    }
}
