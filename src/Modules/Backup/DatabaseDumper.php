<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Backup;

/**
 * DatabaseDumper — maakt een SQL-dump van alle tabellen met de CMS-prefix en
 * speelt zo'n dump weer terug. Pure PDO: geen mysqldump/exec nodig (shared
 * hosting zonder SSH). MariaDB/MySQL in productie; SQLite alleen voor tests.
 *
 * Dumpformaat (bewust simpel, zodat terugspelen regel voor regel kan):
 *   - één SQL-statement per regel; regels die met `--` beginnen zijn commentaar;
 *   - nieuwe regels in tekstwaarden worden als \n / \r geëscaped, binaire
 *     waarden als 0x… hex-literal, zodat een statement nooit over regels loopt;
 *   - de laatste regel is `-- END OF DUMP …`; zonder die regel is de dump
 *     afgekapt en weigert restore() vóór er iets gewijzigd is.
 */
final class DatabaseDumper
{
    public const END_MARKER = '-- END OF DUMP';

    /** Maximaal aantal rijen / bytes per INSERT-statement. */
    private const BATCH_ROWS  = 200;
    private const BATCH_BYTES = 512 * 1024;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $prefix = 'cf_',
    ) {}

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
    }

    /** @return string[] tabelnamen die met de prefix beginnen */
    public function tables(): array
    {
        $names = [];
        if ($this->driver() === 'sqlite') {
            $rows = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
            foreach ($rows ? $rows->fetchAll(\PDO::FETCH_COLUMN) : [] as $n) {
                $names[] = (string) $n;
            }
        } else {
            $rows = $this->pdo->query('SHOW TABLES');
            foreach ($rows ? $rows->fetchAll(\PDO::FETCH_COLUMN) : [] as $n) {
                $names[] = (string) $n;
            }
        }
        $names = array_values(array_filter($names, fn(string $n) => str_starts_with($n, $this->prefix)));
        sort($names, SORT_STRING);
        return $names;
    }

    /**
     * Schrijf de dump naar $path.
     *
     * @return array{tables:int, rows:int, bytes:int}
     */
    public function dump(string $path): array
    {
        $out = @fopen($path, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Kan het dumpbestand niet aanmaken.');
        }

        $tables = $this->tables();
        $rowsTotal = 0;
        $mysql = $this->driver() === 'mysql';

        // Bij MySQL de rijen streamen i.p.v. alles in het geheugen te laden.
        $prevBuffered = null;
        if ($mysql) {
            $prevBuffered = $this->pdo->getAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        }

        try {
            fwrite($out, "-- Blueprint CMS database dump\n");
            fwrite($out, '-- Aangemaakt: ' . date('c') . "\n");
            fwrite($out, '-- Prefix: ' . $this->prefix . "\n");

            foreach ($tables as $table) {
                $q = $this->ident($table);
                fwrite($out, "\n-- Tabel {$table}\n");
                fwrite($out, "DROP TABLE IF EXISTS {$q};\n");
                fwrite($out, $this->createStatement($table) . ";\n");

                if ($mysql) {
                    $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                }
                $stmt = $this->pdo->query("SELECT * FROM {$q}");
                $batch = [];
                $batchBytes = 0;
                $cols = null;
                while ($stmt !== false && ($row = $stmt->fetch(\PDO::FETCH_NUM)) !== false) {
                    if ($cols === null) {
                        $cols = $this->columnList($stmt);
                    }
                    $tuple = '(' . implode(',', array_map([$this, 'literal'], $row)) . ')';
                    $batch[] = $tuple;
                    $batchBytes += strlen($tuple);
                    $rowsTotal++;
                    if (count($batch) >= self::BATCH_ROWS || $batchBytes >= self::BATCH_BYTES) {
                        fwrite($out, "INSERT INTO {$q} ({$cols}) VALUES " . implode(',', $batch) . ";\n");
                        $batch = [];
                        $batchBytes = 0;
                    }
                }
                if ($batch !== []) {
                    fwrite($out, "INSERT INTO {$q} ({$cols}) VALUES " . implode(',', $batch) . ";\n");
                }
                if ($stmt !== false) {
                    $stmt->closeCursor();
                }
                if ($mysql) {
                    $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, (bool) $prevBuffered);
                }
            }

            fwrite($out, self::END_MARKER . ' tables=' . count($tables) . ' rows=' . $rowsTotal . "\n");
        } finally {
            if ($mysql && $prevBuffered !== null) {
                $this->pdo->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, (bool) $prevBuffered);
            }
            fclose($out);
        }

        return ['tables' => count($tables), 'rows' => $rowsTotal, 'bytes' => (int) filesize($path)];
    }

    /** Is dit een complete dump (eindmarkering aanwezig)? */
    public function isComplete(string $path): bool
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return false;
        }
        $h = @fopen($path, 'rb');
        if ($h === false) {
            return false;
        }
        fseek($h, max(0, $size - 512));
        $tail = (string) fread($h, 512);
        fclose($h);
        return str_contains($tail, self::END_MARKER);
    }

    /**
     * Speel een dump terug. Controleert eerst of de dump compleet is.
     *
     * @return int aantal uitgevoerde statements
     */
    public function restore(string $path): int
    {
        if (!$this->isComplete($path)) {
            throw new \RuntimeException('De dump is onvolledig of beschadigd (eindmarkering ontbreekt). Er is niets gewijzigd.');
        }

        $in = @fopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Kan het dumpbestand niet lezen.');
        }

        $mysql = $this->driver() === 'mysql';
        $count = 0;
        try {
            if ($mysql) {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $this->pdo->exec('SET UNIQUE_CHECKS=0');
                $this->pdo->exec("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
            } else {
                $this->pdo->exec('PRAGMA foreign_keys = OFF');
            }

            while (($line = fgets($in)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '' || str_starts_with($line, '--')) {
                    continue;
                }
                // Alleen statements die wij zelf schrijven; geen willekeurige SQL uit een vreemd bestand.
                if (preg_match('/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) /', $line) !== 1) {
                    throw new \RuntimeException('Onverwachte regel in de dump (alleen DROP/CREATE/INSERT zijn toegestaan).');
                }
                $this->pdo->exec($line);
                $count++;
            }
        } finally {
            fclose($in);
            if ($mysql) {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                $this->pdo->exec('SET UNIQUE_CHECKS=1');
            } else {
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        return $count;
    }

    // ─── intern ─────────────────────────────────────────────────────────────

    private function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function createStatement(string $table): string
    {
        if ($this->driver() === 'sqlite') {
            $st = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
            $st->execute([$table]);
            $sql = (string) $st->fetchColumn();
        } else {
            $row = $this->pdo->query('SHOW CREATE TABLE ' . $this->ident($table))->fetch(\PDO::FETCH_NUM);
            $sql = (string) ($row[1] ?? '');
        }
        if ($sql === '') {
            throw new \RuntimeException("Kan de tabeldefinitie van {$table} niet ophalen.");
        }
        // Eén regel per statement.
        return trim(preg_replace('/\s*[\r\n]+\s*/', ' ', $sql) ?? $sql);
    }

    private function columnList(\PDOStatement $stmt): string
    {
        $names = [];
        for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
            $meta = $stmt->getColumnMeta($i);
            $names[] = $this->ident((string) ($meta['name'] ?? ('c' . $i)));
        }
        return implode(',', $names);
    }

    /** SQL-literal voor één waarde, altijd op één regel. */
    private function literal(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        if (is_float($v)) {
            return rtrim(rtrim(sprintf('%.17g', $v), '0'), '.') ?: '0';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = (string) $v;
        if ($s === '') {
            return "''";
        }

        $binary = str_contains($s, "\0") || preg_match('//u', $s) !== 1;
        $hasNewline = str_contains($s, "\n") || str_contains($s, "\r");

        if ($this->driver() === 'sqlite') {
            if ($binary || $hasNewline) {
                return "CAST(x'" . bin2hex($s) . "' AS TEXT)";
            }
            return $this->pdo->quote($s);
        }

        if ($binary) {
            return '0x' . bin2hex($s);
        }
        $q = $this->pdo->quote($s);
        return $hasNewline ? str_replace(["\r", "\n"], ['\\r', '\\n'], $q) : $q;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: DatabaseDumper.php | Role: Core | Version: 1.0.0             ║
// ║  Created: 2026-10-10 | Status: New — Backup & herstel               ║
// ╚══════════════════════════════════════════════════════════════════════╝
