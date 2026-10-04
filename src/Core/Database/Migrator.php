<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Database;

/**
 * Versie-gebaseerde database-migraties.
 *
 * Naast het idempotente basis-schema (schema.sql, voor nieuwe installaties)
 * kunnen wijzigingen aan bestaande installaties hier als bestandje worden
 * toegevoegd: `database/migrations/YYYYMMDD_NN_omschrijving.php`, dat een
 * closure teruggeeft: `return function (\PDO $pdo, string $prefix): void {...};`
 *
 * - Volgorde = alfabetisch op bestandsnaam.
 * - Uitgevoerde migraties staan in `{prefix}migrations` en draaien nooit twee keer.
 * - Een mislukte migratie stopt de run; eerdere blijven geregistreerd.
 *   (DDL is in MySQL/MariaDB niet transactioneel, dus schrijf migraties idempotent.)
 */
final class Migrator
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $dir,
        private readonly string $prefix = 'cf_',
    ) {}

    private function table(): string
    {
        return $this->prefix . 'migrations';
    }

    public function ensureTable(): void
    {
        $engine = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$this->table()}` (
                `migration`  VARCHAR(190) NOT NULL,
                `batch`      INT NOT NULL,
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`migration`)
            ){$engine}"
        );
    }

    /** @return string[] alle migratienamen (zonder .php), op volgorde */
    public function available(): array
    {
        $files = glob(rtrim($this->dir, '/') . '/*.php') ?: [];
        $names = array_map(static fn(string $f) => basename($f, '.php'), $files);
        sort($names, SORT_STRING);
        return $names;
    }

    /** @return array<string,int> naam => batch */
    public function applied(): array
    {
        $this->ensureTable();
        $out = [];
        foreach ($this->pdo->query("SELECT `migration`, `batch` FROM `{$this->table()}`")->fetchAll() as $r) {
            $out[(string) $r['migration']] = (int) $r['batch'];
        }
        return $out;
    }

    /** @return string[] */
    public function pending(): array
    {
        $done = $this->applied();
        return array_values(array_filter($this->available(), static fn($n) => !isset($done[$n])));
    }

    /** @return array<int,array{migration:string,status:string,batch:?int}> */
    public function status(): array
    {
        $done = $this->applied();
        $rows = [];
        foreach ($this->available() as $n) {
            $rows[] = ['migration' => $n, 'status' => isset($done[$n]) ? 'applied' : 'pending', 'batch' => $done[$n] ?? null];
        }
        return $rows;
    }

    /**
     * Voer alle openstaande migraties uit.
     *
     * @return string[] de uitgevoerde migraties
     * @throws \RuntimeException bij een ongeldig bestand; \Throwable van de migratie zelf
     */
    public function run(): array
    {
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }
        $batch = ($this->applied() ? max($this->applied()) : 0) + 1;
        $ran = [];
        foreach ($pending as $name) {
            $fn = require rtrim($this->dir, '/') . '/' . $name . '.php';
            if (!is_callable($fn)) {
                throw new \RuntimeException("Migratie {$name} geeft geen closure terug.");
            }
            $fn($this->pdo, $this->prefix);
            $this->pdo->prepare("INSERT INTO `{$this->table()}` (`migration`, `batch`) VALUES (?, ?)")
                ->execute([$name, $batch]);
            $ran[] = $name;
        }
        return $ran;
    }
}
