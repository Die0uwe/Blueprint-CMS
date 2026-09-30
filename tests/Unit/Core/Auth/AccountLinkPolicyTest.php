<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Tests\Unit\Core\Auth;

use CommunityFusion\Core\Auth\OAuth\AccountLinkPolicy;
use CommunityFusion\Core\Database\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AccountLinkPolicyTest extends TestCase
{
    #[Test]
    public function ontkoppelenMagAlsErEenAndereKoppelingIs(): void
    {
        $this->assertTrue(AccountLinkPolicy::decide(true, 1, false));
    }

    #[Test]
    public function ontkoppelenMagAlsHetAccountEenWachtwoordHeeft(): void
    {
        $this->assertTrue(AccountLinkPolicy::decide(true, 0, true));
    }

    #[Test]
    public function ontkoppelenMagNietAlsHetDeEnigeInlogmanierIs(): void
    {
        $this->assertFalse(AccountLinkPolicy::decide(true, 0, false));
    }

    #[Test]
    public function nietGekoppeldeProviderValtNooitTeOntkoppelen(): void
    {
        $this->assertFalse(AccountLinkPolicy::decide(false, 2, true));
    }

    #[Test]
    public function oauthAangemaaktAccountHeeftGeenEigenWachtwoord(): void
    {
        // koppeling in dezelfde seconde als het account → OAuth-registratie
        $this->assertFalse(AccountLinkPolicy::hasOwnPassword('2026-10-01 10:00:00', '2026-10-01 10:00:01'));
        // pas maanden later gekoppeld → wachtwoord-account
        $this->assertTrue(AccountLinkPolicy::hasOwnPassword('2026-06-01 10:00:00', '2026-10-01 10:00:00'));
        // zonder koppelingen geldt het wachtwoord
        $this->assertTrue(AccountLinkPolicy::hasOwnPassword('2026-06-01 10:00:00', null));
        // onleesbare datum: conservatief blokkeren
        $this->assertFalse(AccountLinkPolicy::hasOwnPassword('rommel', '2026-10-01 10:00:00'));
    }

    private function db(): Connection
    {
        $name = getenv('BP_TEST_DB');
        if ($name === false || $name === '') {
            $this->markTestSkipped('BP_TEST_DB niet gezet.');
        }
        return new Connection([
            'host' => getenv('BP_TEST_HOST') ?: '127.0.0.1', 'name' => $name,
            'user' => (string) getenv('BP_TEST_USER'), 'password' => (string) getenv('BP_TEST_PASS'),
        ]);
    }

    #[Test]
    public function canDisconnectMetEchteDatabase(): void
    {
        $db  = $this->db();
        $tag = bin2hex(random_bytes(4));
        $mk = function (string $created, int $pwSet = 1) use ($db, $tag): int {
            static $n = 0; $n++;
            $db->execute(
                "INSERT INTO cf_users (username, email, password_hash, password_set, created_at) VALUES (?, ?, 'x', ?, ?)",
                ["alp_{$tag}_{$n}", "alp_{$tag}_{$n}@example.test", $pwSet, $created]
            );
            return (int) $db->fetchOne("SELECT id FROM cf_users WHERE username = ?", ["alp_{$tag}_{$n}"])['id'];
        };
        $link = fn(int $uid, string $prov, string $at) => $db->execute(
            "INSERT INTO cf_user_oauth (user_id, provider, provider_user_id, access_token, created_at) VALUES (?, ?, ?, 'x', ?)",
            [$uid, $prov, "{$prov}_{$tag}_{$uid}", $at]
        );

        try {
            // A: OAuth-only (één koppeling, direct bij aanmaak) → geblokkeerd
            $a = $mk('2026-10-01 10:00:00', 0);
            $link($a, 'github', '2026-10-01 10:00:00');
            $this->assertFalse(AccountLinkPolicy::canDisconnect($db, $a, 'github'));

            // …met tweede koppeling → mag
            $link($a, 'google', '2026-10-02 10:00:00');
            $this->assertTrue(AccountLinkPolicy::canDisconnect($db, $a, 'github'));
            $this->assertTrue(AccountLinkPolicy::canDisconnect($db, $a, 'google'));

            // Regressie (review): na ontkoppelen van de eerste koppeling mag de laatste NIET meer weg
            // (vroeger maakte de 120-seconden-heuristiek daar ineens een "wachtwoord-account" van).
            $db->execute("DELETE FROM cf_user_oauth WHERE user_id = ? AND provider = 'github'", [$a]);
            $this->assertFalse(AccountLinkPolicy::canDisconnect($db, $a, 'google'));

            // B: wachtwoord-account dat later één koppeling toevoegt → mag
            $b = $mk('2026-01-01 10:00:00');
            $link($b, 'github', '2026-10-01 10:00:00');
            $this->assertTrue(AccountLinkPolicy::canDisconnect($db, $b, 'github'));

            // Niet gekoppelde provider → nee
            $this->assertFalse(AccountLinkPolicy::canDisconnect($db, $b, 'twitch'));
        } finally {
            $db->execute("DELETE FROM cf_users WHERE username LIKE ?", ["alp\\_{$tag}\\_%"]);
        }
    }
}
