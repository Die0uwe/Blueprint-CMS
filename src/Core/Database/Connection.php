<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Database;

/**
 * PDO Database Wrapper met fluent query builder ondersteuning.
 * Alle queries gaan via prepared statements — nooit string concatenatie.
 */
final class Connection
{
    private \PDO $pdo;
    private string $prefix;
    private int $queryCount = 0;
    private array $queryLog = [];

    public function __construct(array $config)
    {
        $this->prefix = $config['prefix'] ?? 'cf_';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host']      ?? '127.0.0.1',
            $config['port']      ?? 3306,
            $config['name']      ?? 'fusion_cms',
            $config['charset']   ?? 'utf8mb4',
        );

        $options = array_merge([
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ], $config['options'] ?? []);

        $this->pdo = new \PDO($dsn, $config['user'], $config['password'], $options);
    }

    // ─── QUERY METHODEN ───────────────────────────────────────────────────────

    /**
     * Voer een SELECT query uit en geef alle rijen terug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $bindings = []): array
    {
        $stmt = $this->execute($sql, $bindings);
        return $stmt->fetchAll();
    }

    /**
     * Voer een SELECT query uit en geef één rij terug.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $bindings = []): ?array
    {
        $stmt   = $this->execute($sql, $bindings);
        $result = $stmt->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Voer een INSERT/UPDATE/DELETE query uit.
     */
    public function execute(string $sql, array $bindings = []): \PDOStatement
    {
        $start = microtime(true);

        $stmt = $this->pdo->prepare($sql);

        // Wave 2 gap-fix: `$stmt->execute($bindings)` bindt ELK element als
        // PDO::PARAM_STR, ongeacht het echte PHP-type. Met
        // PDO::ATTR_EMULATE_PREPARES => false hierboven (bewust aan — echte
        // server-side prepared statements) accepteert MySQL/MariaDB geen
        // string-getypeerde parameter op een LIMIT/OFFSET-positie: elke query
        // met `LIMIT ? OFFSET ?` (tien bestanden in deze codebase, van
        // NewsRepository tot de REST API) faalde hierdoor altijd met
        // "You have an error in your SQL syntax ... near ''N' OFFSET 'M''"
        // zodra hij tegen een echte database draaide — nooit gezien tijdens
        // eerdere waves omdat er nooit een echte MySQL/MariaDB-server
        // beschikbaar was om tegen te testen (zie CHANGELOG v1.10.0: dit
        // kwam pas aan het licht doordat de CLI-commando's van deze wave voor
        // het eerst wél tegen een echte, lokaal geïnstalleerde MariaDB
        // getest zijn). Losse `bindValue()`-aanroepen met het juiste
        // PDO::PARAM_*-type lossen dit op voor ALLE aanroepers van deze ene
        // methode in één keer, zonder dat elk van de tien call-sites apart
        // aangepast hoeft te worden.
        foreach (array_values($bindings) as $i => $value) {
            $type = match (true) {
                is_int($value)  => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_BOOL,
                $value === null => \PDO::PARAM_NULL,
                default          => \PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();

        $this->queryCount++;
        $this->queryLog[] = [
            'sql'      => $sql,
            'bindings' => $bindings,
            'time_ms'  => round((microtime(true) - $start) * 1000, 2),
        ];

        return $stmt;
    }

    /**
     * Voer een INSERT uit en geef het nieuw aangemaakte ID terug.
     */
    public function insert(string $table, array $data): int|string
    {
        $table   = $this->prefix . $table;
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $this->execute(
            "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})",
            array_values($data)
        );

        return $this->pdo->lastInsertId();
    }

    /**
     * Update rijen in een tabel.
     */
    public function update(string $table, array $data, string $where, array $whereBindings = []): int
    {
        $table = $this->prefix . $table;
        $set   = implode(', ', array_map(fn($col) => "`{$col}` = ?", array_keys($data)));

        $stmt = $this->execute(
            "UPDATE `{$table}` SET {$set} WHERE {$where}",
            [...array_values($data), ...$whereBindings]
        );

        return $stmt->rowCount();
    }

    /**
     * Verwijder rijen uit een tabel.
     */
    public function delete(string $table, string $where, array $bindings = []): int
    {
        $table = $this->prefix . $table;
        $stmt  = $this->execute("DELETE FROM `{$table}` WHERE {$where}", $bindings);
        return $stmt->rowCount();
    }

    // ─── TRANSACTIES ─────────────────────────────────────────────────────────

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    /**
     * Voer een callable uit binnen een transactie.
     * Rollback automatisch bij een exception.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    // ─── QUERY BUILDER FACTORY ────────────────────────────────────────────────

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $this->prefix . $table);
    }

    // ─── UTILS ────────────────────────────────────────────────────────────────

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function getQueryCount(): int
    {
        return $this->queryCount;
    }

    public function getQueryLog(): array
    {
        return $this->queryLog;
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : Connection.php                                       ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-06-06                                           ║
// ║  Last Updated : 2026-06-06  03:00                                    ║
// ║  Status       : New                                                  ║
// ║  Notes        : PDO wrapper, prepared statements only                ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
