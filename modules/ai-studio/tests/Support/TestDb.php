<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio\Tests\Support;

use CommunityFusion\Core\Database\Connection;
use PDO;

/**
 * In-memory SQLite achter de echte Core\Database\Connection (zonder zijn
 * MySQL-constructor), met de tabellen die AI Studio gebruikt. De SQL van de
 * module is bewust portable, zodat dit de echte queries uitvoert.
 */
final class TestDb
{
    public static function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE cf_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT NOT NULL, "key" TEXT NOT NULL, value TEXT NULL, type TEXT NOT NULL DEFAULT \'string\', updated_at TEXT NULL, UNIQUE ("group", "key"))');
        $pdo->exec('CREATE TABLE cf_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NULL, username TEXT NULL, action TEXT NOT NULL, context TEXT NULL, ip_address TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE cf_ai_conversations (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, title TEXT NOT NULL DEFAULT \'Nieuw gesprek\', provider TEXT NOT NULL DEFAULT \'\', model TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE cf_ai_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL, role TEXT NOT NULL, content TEXT NOT NULL, provider TEXT NOT NULL DEFAULT \'\', model TEXT NOT NULL DEFAULT \'\', proposal_diff TEXT NULL, proposal_base_sha256 TEXT NULL, proposal_applied_at TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        return $pdo;
    }

    public static function connection(?PDO $pdo = null): Connection
    {
        $pdo ??= self::pdo();
        $ref = new \ReflectionClass(Connection::class);
        $db = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('pdo')->setValue($db, $pdo);
        $ref->getProperty('prefix')->setValue($db, 'cf_');
        $ref->getProperty('queryCount')->setValue($db, 0);
        $ref->getProperty('queryLog')->setValue($db, []);
        return $db;
    }
}
