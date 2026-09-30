<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Database;

/**
 * ColumnMigrator — voegt ontbrekende kolommen idempotent toe.
 *
 * schema.sql kan alleen nieuwe TABELLEN aanmaken (CREATE TABLE wordt overgeslagen
 * als de tabel al bestaat). Nieuwe KOLOMMEN op bestaande installaties komen daarom
 * via deze klasse: per kolom een controle in INFORMATION_SCHEMA en pas dan een
 * ALTER TABLE. Zonder afhankelijkheden, zodat ook de installer hem kan gebruiken.
 */
final class ColumnMigrator
{
    /**
     * Kolommen die de CMS verwacht: tabel => [kolom => definitie].
     * Definities zijn vaste, door ons geschreven SQL-fragmenten (nooit gebruikersinvoer).
     */
    public const COLUMNS = [
        'cf_pages'      => ['content_markup' => 'TEXT NULL'],
        'cf_news'       => ['content_markup' => 'TEXT NULL'],
        'cf_blog_posts' => ['content_markup' => 'TEXT NULL'],
    ];

    /**
     * Voer alle ontbrekende kolomwijzigingen uit.
     *
     * @param array<string,array<string,string>>|null $columns Alleen voor tests; standaard self::COLUMNS
     * @return list<string> Beschrijving van wat is toegevoegd, bijv. "cf_pages.content_markup"
     */
    public static function run(\PDO $pdo, ?array $columns = null): array
    {
        $applied = [];
        foreach ($columns ?? self::COLUMNS as $table => $cols) {
            if (!self::tableExists($pdo, $table)) {
                continue; // tabel hoort bij een module die niet is geïnstalleerd
            }
            foreach ($cols as $column => $definition) {
                if (self::ensureColumn($pdo, $table, $column, $definition)) {
                    $applied[] = "{$table}.{$column}";
                }
            }
        }
        return $applied;
    }

    /** @return bool true als de kolom zojuist is toegevoegd */
    public static function ensureColumn(\PDO $pdo, string $table, string $column, string $definition): bool
    {
        self::assertIdentifier($table);
        self::assertIdentifier($column);
        if (str_contains($definition, ';') || str_contains($definition, '`')) {
            throw new \InvalidArgumentException('Ongeldige kolomdefinitie.');
        }
        if (self::columnExists($pdo, $table, $column)) {
            return false;
        }
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        return true;
    }

    public static function tableExists(\PDO $pdo, string $table): bool
    {
        self::assertIdentifier($table);
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $st->execute([$table]);
        return (int)$st->fetchColumn() > 0;
    }

    public static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    }

    private static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $name)) {
            throw new \InvalidArgumentException('Ongeldige tabel- of kolomnaam.');
        }
    }
}
