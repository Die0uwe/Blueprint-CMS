<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Modules;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Modules\GitHub\GitHubOAuth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** GitHub-client met nep-HTTP: geen netwerk, geen database nodig. */
final class GitHubOAuthTest extends TestCase
{
    /** @var list<array{string,string,array,?array}> */
    private array $calls = [];

    /** @param array<string,array{0:int,1:string}> $responses sleutel "METHOD url" */
    private function client(array $responses): GitHubOAuth
    {
        $this->calls = [];
        $http = function (string $method, string $url, array $headers, ?array $form) use ($responses): array {
            $this->calls[] = [$method, $url, $headers, $form];
            return $responses["{$method} {$url}"] ?? [404, '{"message":"Not Found"}'];
        };
        // Connection wordt in dit pad niet gebruikt (alleen saveConnection/getConnection).
        $db = (new \ReflectionClass(Connection::class))->newInstanceWithoutConstructor();

        return new GitHubOAuth($db, 'cid', 'secret', 'https://site.test/auth/github/callback', ['read:user', 'user:email'], $http);
    }

    private const TOKEN = 'POST https://github.com/login/oauth/access_token';
    private const USER  = 'GET https://api.github.com/user';
    private const MAIL  = 'GET https://api.github.com/user/emails';

    private function callback(GitHubOAuth $c): array
    {
        $_SESSION['oauth_state_github'] = 'st4te';
        return $c->handleCallback('code123', 'st4te');
    }

    #[Test]
    public function authorizeUrlBevatScopeStateEnRedirect(): void
    {
        $url = $this->client([])->getAuthorizationUrl('abc');

        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('cid', $q['client_id']);
        $this->assertSame('read:user user:email', $q['scope']);
        $this->assertSame('abc', $q['state']);
        $this->assertSame('https://site.test/auth/github/callback', $q['redirect_uri']);
    }

    #[Test]
    public function volledigeFlowHaaltGeverifieerdPrimairEmailOp(): void
    {
        $c = $this->client([
            self::TOKEN => [200, '{"access_token":"gho_abc","token_type":"bearer","scope":"read:user,user:email"}'],
            self::USER  => [200, '{"id":4242,"login":"octocat","name":"Octo","avatar_url":"https://a/x.png"}'],
            self::MAIL  => [200, '[{"email":"oud@x.nl","primary":false,"verified":true},{"email":"octo@x.nl","primary":true,"verified":true}]'],
        ]);

        $r = $this->callback($c);

        $this->assertSame('gho_abc', $r['tokens']['access_token']);
        $this->assertSame(4242, $r['user']['id']);
        $this->assertSame('octo@x.nl', $r['user']['email']);
        $this->assertTrue($r['user']['email_verified']);

        // Headers: Accept JSON op token-call; Bearer + User-Agent + API-versie op API-calls.
        [$m, $u, $h, $form] = $this->calls[0];
        $this->assertContains('Accept: application/json', $h);
        $this->assertSame('code123', $form['code']);
        $this->assertSame('secret', $form['client_secret']);
        $apiHeaders = $this->calls[1][2];
        $this->assertContains('Authorization: Bearer gho_abc', $apiHeaders);
        $this->assertContains('Accept: application/vnd.github+json', $apiHeaders);
        $this->assertContains('X-GitHub-Api-Version: 2022-11-28', $apiHeaders);
        $this->assertNotSame([], array_filter($apiHeaders, fn($x) => str_starts_with($x, 'User-Agent: ')));
    }

    #[Test]
    public function foutInHttp200BodyWordtException(): void
    {
        $c = $this->client([
            self::TOKEN => [200, '{"error":"bad_verification_code","error_description":"The code passed is incorrect or expired."}'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bad_verification_code');
        $this->callback($c);
    }

    #[Test]
    public function tokenAntwoordZonderAccessTokenIsFout(): void
    {
        $c = $this->client([self::TOKEN => [200, '{"token_type":"bearer"}']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('access_token');
        $this->callback($c);
    }

    #[Test]
    public function nietGeverifieerdEmailWordtNooitGebruikt(): void
    {
        $c = $this->client([
            self::TOKEN => [200, '{"access_token":"t"}'],
            self::USER  => [200, '{"id":1,"login":"a","email":"publiek@x.nl"}'],
            self::MAIL  => [200, '[{"email":"claim@x.nl","primary":true,"verified":false}]'],
        ]);

        $u = $this->callback($c)['user'];
        $this->assertNull($u['email']);
        $this->assertFalse($u['email_verified']);
    }

    #[Test]
    public function ontbrekendEmailEnFalendeEmailEndpointGevenGeenFout(): void
    {
        // lege lijst
        $c = $this->client([
            self::TOKEN => [200, '{"access_token":"t"}'],
            self::USER  => [200, '{"id":1,"login":"a"}'],
            self::MAIL  => [200, '[]'],
        ]);
        $this->assertNull($this->callback($c)['user']['email']);

        // /user/emails geeft 403 (scope geweigerd): inloggen kan gewoon zonder e-mail
        $c = $this->client([
            self::TOKEN => [200, '{"access_token":"t"}'],
            self::USER  => [200, '{"id":1,"login":"a"}'],
            self::MAIL  => [403, '{"message":"Forbidden"}'],
        ]);
        $u = $this->callback($c)['user'];
        $this->assertNull($u['email']);
        $this->assertFalse($u['email_verified']);
    }

    #[Test]
    public function geverifieerdMaarNietPrimairEmailIsFallback(): void
    {
        $this->assertSame('b@x.nl', GitHubOAuth::pickVerifiedEmail([
            ['email' => 'a@x.nl', 'primary' => true,  'verified' => false],
            ['email' => 'b@x.nl', 'primary' => false, 'verified' => true],
        ]));
        $this->assertNull(GitHubOAuth::pickVerifiedEmail([]));
        $this->assertNull(GitHubOAuth::pickVerifiedEmail([['rommel'], ['email' => '', 'verified' => true]]));
    }

    #[Test]
    public function userEndpointFoutEnOntbrekendIdZijnFatal(): void
    {
        $c = $this->client([
            self::TOKEN => [200, '{"access_token":"t"}'],
            self::USER  => [401, '{"message":"Bad credentials"}'],
        ]);
        try {
            $this->callback($c);
            $this->fail('verwachtte exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('401', $e->getMessage());
        }

        $c = $this->client([
            self::TOKEN => [200, '{"access_token":"t"}'],
            self::USER  => [200, '{"login":"zonder-id"}'],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->callback($c);
    }

    #[Test]
    public function verkeerdeStateWordtGeweigerdZonderHttpCalls(): void
    {
        $c = $this->client([]);
        $_SESSION['oauth_state_github'] = 'goed';
        try {
            $c->handleCallback('code', 'fout');
            $this->fail('verwachtte exception');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('state', $e->getMessage());
        }
        $this->assertSame([], $this->calls);
    }

    #[Test]
    public function legeStateZonderLopendeFlowWordtGeweigerd(): void
    {
        // Regressie (review): hash_equals('', '') was true → een gekaapte callback zonder state kwam door.
        $c = $this->client([]);
        unset($_SESSION['oauth_state_github']);
        foreach (['', 'iets'] as $state) {
            try {
                $c->handleCallback('code', $state);
                $this->fail('verwachtte exception');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('state', $e->getMessage());
            }
        }
        $_SESSION['oauth_state_github'] = 'echt';
        try {
            $c->handleCallback('code', '');
            $this->fail('verwachtte exception');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], $this->calls);
    }
}
