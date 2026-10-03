<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use PDO;

/**
 * Voert migrations/*.sql uit via PDO::exec (tekstprotocol, nodig voor de
 * PREPARE/EXECUTE-constructie waarmee een ALTER pas na een INFORMATION_SCHEMA-
 * controle draait). De bestanden zijn zelf idempotent; deze klasse onthoudt
 * daarnaast per versie dat het gelukt is, zodat gewone requests geen DDL doen.
 */
final class SchemaMigrator
{
    public const SCHEMA_VERSION = '1';
    public const VERSION_KEY = 'schema_version';

    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsStore $settings,
        private readonly string $migrationsDir,
    ) {
    }

    public function isCurrent(): bool
    {
        return $this->settings->get(SettingsStore::GROUP, self::VERSION_KEY) === self::SCHEMA_VERSION;
    }

    /**
     * Draai alle migraties als de opgeslagen versie achterloopt. Geeft true als er gedraaid is.
     */
    public function ensure(): bool
    {
        if ($this->isCurrent()) {
            return false;
        }
        $this->run();
        return true;
    }

    /**
     * @return int aantal uitgevoerde statements
     */
    public function run(): int
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql');
        if ($files === false || $files === []) {
            throw new \RuntimeException('Geen migraties gevonden in ' . $this->migrationsDir);
        }
        sort($files);

        $count = 0;
        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Kan migratie niet lezen: ' . basename($file));
            }
            foreach (self::splitStatements($sql) as $statement) {
                $this->pdo->exec($statement);
                $count++;
            }
        }

        $this->settings->set(SettingsStore::GROUP, self::VERSION_KEY, self::SCHEMA_VERSION);
        return $count;
    }

    /**
     * Knip een SQL-bestand in statements: commentaarregels weg, splitsen op ";"
     * aan het regeleinde (zie de afspraak in de migratiebestanden).
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql);
        if ($parts === false) {
            return [];
        }
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}
