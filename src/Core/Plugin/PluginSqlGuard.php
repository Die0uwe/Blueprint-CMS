<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Plugin;

use CommunityFusion\Core\Marketplace\PackageException;

/**
 * PluginSqlGuard — dwingt af dat plugin-migraties alleen eigen tabellen aanraken.
 *
 * Regel: een plugin met slug "mijn-plugin" mag alleen tabellen `cf_plg_mijn_plugin_*`
 * aanmaken, wijzigen, vullen en verwijderen. Verwijzen naar een kerntabel kan
 * uitsluitend via FOREIGN KEY ... REFERENCES. Alles daarbuiten wordt geweigerd.
 *
 * Dit is een vangnet voor vergissingen en misbruik via migratiebestanden, geen
 * sandbox: plugin-PHP-code zelf draait met de rechten van de site.
 */
final class PluginSqlGuard
{
    public const MAX_FILE_BYTES = 262144;

    public static function tablePrefix(string $slug): string
    {
        return 'cf_plg_' . str_replace('-', '_', $slug) . '_';
    }

    /**
     * Controleer een migratiebestand en geef de afzonderlijke statements terug.
     *
     * @return list<string>
     * @throws PackageException
     */
    public function check(string $sql, string $slug): array
    {
        if (strlen($sql) > self::MAX_FILE_BYTES) {
            throw new PackageException('Migratiebestand is te groot.');
        }
        if (str_contains($sql, '/*') || str_contains($sql, "\0")) {
            throw new PackageException('Blokcommentaar (/* */) en null-bytes zijn niet toegestaan in migraties.');
        }
        $prefix = self::tablePrefix($slug);
        $out = [];
        foreach ($this->split($sql) as $statement) {
            $this->checkStatement($statement, $prefix);
            $out[] = $statement;
        }
        return $out;
    }

    /** Splits op ; buiten quotes en haal -- en #-regelcommentaar weg. @return list<string> */
    public function split(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== null) {
                $buf .= $c;
                if ($c === '\\' && $quote !== '`' && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                } elseif ($c === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $buf .= $sql[++$i];   // verdubbelde quote
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($c === '\'' || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
            } elseif (($c === '-' && ($sql[$i + 1] ?? '') === '-' && in_array($sql[$i + 2] ?? ' ', [' ', "\t", "\n", "\r"], true)) || $c === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $buf .= "\n";
            } elseif ($c === ';') {
                if (trim($buf) !== '') {
                    $statements[] = trim($buf);
                }
                $buf = '';
            } else {
                $buf .= $c;
            }
        }
        if ($quote !== null) {
            throw new PackageException('Migratie bevat een niet-afgesloten tekenreeks.');
        }
        if (trim($buf) !== '') {
            $statements[] = trim($buf);
        }
        return $statements;
    }

    private function checkStatement(string $stmt, string $prefix): void
    {
        // Stringliteralen leeg maken, zodat woorden in data niet meetellen
        $bare = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'|\"(?:[^\"\\\\]|\\\\.|\"\")*\"/s", "''", $stmt) ?? '';

        $table = '`?(' . preg_quote($prefix, '/') . '[a-z0-9_]{1,40})`?';
        $allowed = [
            '/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?' . $table . '\s*\(/i',
            '/^ALTER\s+TABLE\s+' . $table . '\s+/i',
            '/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?' . $table . '\s*$/i',
            '/^TRUNCATE\s+(?:TABLE\s+)?' . $table . '\s*$/i',
            '/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?[A-Za-z0-9_]+`?\s+ON\s+' . $table . '\s*\(/i',
            '/^INSERT\s+(?:IGNORE\s+)?INTO\s+' . $table . '[\s(]/i',
            '/^REPLACE\s+INTO\s+' . $table . '[\s(]/i',
            '/^UPDATE\s+' . $table . '\s+SET\s+/i',
            '/^DELETE\s+FROM\s+' . $table . '(?:\s|$)/i',
        ];
        $ok = false;
        foreach ($allowed as $re) {
            if (preg_match($re, $bare)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            throw new PackageException('Niet-toegestaan SQL-statement (alleen CREATE/ALTER/DROP/INSERT/UPDATE/DELETE op eigen tabellen): ' . mb_substr(preg_replace('/\s+/', ' ', $stmt) ?? '', 0, 60));
        }

        if (preg_match('/\b(?:outfile|dumpfile|load_file|load\s+data|sleep|benchmark|information_schema|performance_schema|grant|revoke|handler|call|prepare|execute|deallocate|lock\s+tables|unlock|set\s+(?:global|session|password)|@@|into\s+@|system_user|current_user)\b|@@|mysql\s*\.|sys\s*\./i', $bare)) {
            throw new PackageException('Migratie bevat een niet-toegestaan SQL-onderdeel.');
        }

        // Elke cf_-tabel: eigen prefix, of uitsluitend als FOREIGN KEY-doel (REFERENCES)
        if (preg_match_all('/`?\bcf_[A-Za-z0-9_]*`?/', $bare, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$token, $offset]) {
                $name = trim($token, '`');
                if (str_starts_with($name, $prefix)) {
                    continue;
                }
                $before = substr($bare, 0, (int)$offset);
                if (str_starts_with($name, 'cf_plg_') || !preg_match('/REFERENCES\s*$/i', $before)) {
                    throw new PackageException("Migratie raakt een tabel buiten de eigen prefix ({$prefix}): {$name}");
                }
            }
        }
        // Elke tabel na FROM/JOIN moet eigen tabel zijn (INSERT ... SELECT)
        if (preg_match_all('/\b(?:FROM|JOIN)\s+`?([A-Za-z0-9_.]+)`?/i', $bare, $fm)) {
            foreach ($fm[1] as $name) {
                if (!str_starts_with($name, $prefix) || str_contains($name, '.')) {
                    throw new PackageException("Migratie leest uit een tabel buiten de eigen prefix: {$name}");
                }
            }
        }
        // Geen database-kwalificatie (db.tabel) op het doel
        if (preg_match('/`?[A-Za-z0-9_]+`?\s*\.\s*`?' . preg_quote($prefix, '/') . '/', $bare)) {
            throw new PackageException('Database-kwalificatie (db.tabel) is niet toegestaan.');
        }
    }
}
