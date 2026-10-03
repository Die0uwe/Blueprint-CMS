<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests;

require_once __DIR__ . '/Support/bootstrap.php';

use CommunityFusion\Core\Request;
use CommunityFusion\Modules\AiStudio\CsrfGuard;
use CommunityFusion\Modules\AiStudio\SchemaMigrator;
use CommunityFusion\Modules\AiStudio\SettingsStore;
use CommunityFusion\Modules\AiStudio\Tests\Support\ArrayCache;
use CommunityFusion\Modules\AiStudio\Tests\Support\TestDb;
use CommunityFusion\Modules\AiStudio\UserRateLimiter;
use PHPUnit\Framework\TestCase;

final class SmallClassesTest extends TestCase
{
    public function testSplitStatementsDropsCommentsAndBlankParts(): void
    {
        $sql = "-- commentaar\nCREATE TABLE a (id INT);\n\n-- nog een\nDO 0;\n";
        self::assertSame(['CREATE TABLE a (id INT)', 'DO 0'], SchemaMigrator::splitStatements($sql));
        self::assertSame([], SchemaMigrator::splitStatements("-- alleen commentaar\n"));
    }

    public function testMigrationFileIsSplittableAndGuarded(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/migrations/001_create_tables.sql');
        $statements = SchemaMigrator::splitStatements($sql);
        self::assertGreaterThan(5, count($statements));
        self::assertStringContainsString('INFORMATION_SCHEMA', $sql);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($sql));
    }

    public function testCsrfGuard(): void
    {
        $_SESSION['_csrf_token'] = 'tok-1234567890abcdef';
        $mk = static fn (array $q, array $b, array $h): Request => new Request('POST', '/x', $q, $b, $h, [], [], []);

        self::assertTrue(CsrfGuard::valid($mk([], [], ['X-CSRF-Token' => 'tok-1234567890abcdef'])));
        self::assertTrue(CsrfGuard::valid($mk([], ['_csrf_token' => 'tok-1234567890abcdef'], [])));
        self::assertFalse(CsrfGuard::valid($mk([], [], [])));
        self::assertFalse(CsrfGuard::valid($mk([], [], ['X-CSRF-Token' => 'tok-1234567890abcdeX'])));
        self::assertFalse(CsrfGuard::valid($mk(['_csrf_token' => 'tok-1234567890abcdef'], [], [])), 'Query-token is nooit geldig.');
        unset($_SESSION['_csrf_token']);
        self::assertFalse(CsrfGuard::valid($mk([], [], ['X-CSRF-Token' => ''])), 'Geen sessietoken = nooit geldig.');
    }

    public function testRateLimiterWindowAndIsolationPerKey(): void
    {
        $l = new UserRateLimiter(new ArrayCache());
        self::assertTrue($l->allow('a', 2, 60));
        self::assertTrue($l->allow('a', 2, 60));
        self::assertFalse($l->allow('a', 2, 60));
        self::assertTrue($l->allow('b', 2, 60));
    }

    public function testSettingsStoreEncryptsSecretsAndRoundTrips(): void
    {
        $pdo = TestDb::pdo();
        $store = new SettingsStore(TestDb::connection($pdo));
        $store->set('aistudio', 'k', 'geheim-waarde-123', 'encrypted');
        $raw = (string) $pdo->query("SELECT value FROM cf_settings WHERE \"key\" = 'k'")->fetchColumn();

        self::assertStringNotContainsString('geheim-waarde', $raw);
        self::assertSame('geheim-waarde-123', $store->get('aistudio', 'k'));
        self::assertTrue($store->has('aistudio', 'k'));

        $store->set('aistudio', 'k', 'nieuw', 'encrypted');
        self::assertSame('nieuw', $store->get('aistudio', 'k'), 'Upsert, geen cache.');
        self::assertSame('x', $store->get('aistudio', 'bestaatniet', 'x'));
    }
}
