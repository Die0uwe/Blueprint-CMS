<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Downloads;

use CommunityFusion\Core\Database\Connection;

/**
 * Downloadlog + statistieken (cf_download_log).
 *
 * "Uniek" = per download één keer per ingelogde gebruiker, of per IP-adres voor
 * bezoekers. Bandbreedte = som van de verstuurde bytes.
 */
final class DownloadStats
{
    public function __construct(private readonly Connection $db) {}

    /** Logt één geserveerde download. Mag nooit de download zelf laten falen. */
    public function record(array $download, ?int $userId, string $ip, int $bytes): void
    {
        try {
            $this->db->insert('download_log', [
                'download_id' => (int) $download['id'],
                'title'       => mb_substr((string) $download['title'], 0, 255),
                'version'     => ($download['version'] ?? '') !== '' ? (string) $download['version'] : null,
                'user_id'     => $userId,
                'ip_address'  => mb_substr($ip, 0, 45),
                'bytes_sent'  => max(0, $bytes),
            ]);
        } catch (\Throwable) {
            // Statistiek is bijzaak; de gebruiker krijgt zijn bestand.
        }
    }

    /** @return array{total:int,unique:int,bytes:int,last30:int,last30_bytes:int,files:int} */
    public function totals(): array
    {
        $all = $this->db->fetchOne(
            "SELECT COUNT(*) AS c, COALESCE(SUM(bytes_sent),0) AS b FROM cf_download_log"
        ) ?? [];
        $uniq = $this->db->fetchOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT DISTINCT COALESCE(download_id, 0) AS did, COALESCE(CAST(user_id AS CHAR), ip_address) AS who FROM cf_download_log
             ) t"
        ) ?? [];
        $recent = $this->db->fetchOne(
            "SELECT COUNT(*) AS c, COALESCE(SUM(bytes_sent),0) AS b FROM cf_download_log WHERE created_at >= ?",
            [$this->since(30 * 86400)]
        ) ?? [];
        $files = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_downloads WHERE deleted_at IS NULL") ?? [];

        return [
            'total'        => (int) ($all['c'] ?? 0),
            'unique'       => (int) ($uniq['c'] ?? 0),
            'bytes'        => (int) ($all['b'] ?? 0),
            'last30'       => (int) ($recent['c'] ?? 0),
            'last30_bytes' => (int) ($recent['b'] ?? 0),
            'files'        => (int) ($files['c'] ?? 0),
        ];
    }

    /** Per bestand: totaal, uniek en bandbreedte, populairste eerst. */
    public function perDownload(int $limit = 20): array
    {
        // Titel/versie komen van de LAATSTE logregel (MAX(title) zou een oude titel kunnen tonen);
        // logregels van verwijderde downloads (download_id NULL) groeperen op titel i.p.v. samen te vallen.
        return $this->db->fetchAll(
            "SELECT g.download_id, latest.title, latest.version, g.total, g.uniq, g.bytes, g.last_at
             FROM (
                SELECT COALESCE(CAST(l.download_id AS CHAR), CONCAT('t:', l.title)) AS gkey,
                       MAX(l.download_id) AS download_id,
                       MAX(l.id) AS last_id,
                       COUNT(*) AS total,
                       COUNT(DISTINCT COALESCE(CAST(l.user_id AS CHAR), l.ip_address)) AS uniq,
                       COALESCE(SUM(l.bytes_sent),0) AS bytes,
                       MAX(l.created_at) AS last_at
                FROM cf_download_log l
                GROUP BY gkey
             ) g
             JOIN cf_download_log latest ON latest.id = g.last_id
             ORDER BY g.total DESC
             LIMIT ?",
            [$limit]
        );
    }

    /** Laatste activiteit incl. gebruikersnaam (indien ingelogd). */
    public function recent(int $limit = 25): array
    {
        return $this->db->fetchAll(
            "SELECT l.*, u.username
             FROM cf_download_log l
             LEFT JOIN cf_users u ON u.id = l.user_id
             ORDER BY l.id DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Aantal downloads per dag, laatste $days dagen (ontbrekende dagen = 0).
     * @return array<string,int> 'Y-m-d' => aantal
     */
    public function perDay(int $days = 30): array
    {
        $days  = max(1, min(365, $days));
        $since = date('Y-m-d 00:00:00', strtotime($this->dbNow()) - ($days - 1) * 86400);
        $rows  = $this->db->fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM cf_download_log
             WHERE created_at >= ? GROUP BY DATE(created_at)",
            [$since]
        );
        $map = [];
        foreach ($rows as $r) { $map[(string) $r['d']] = (int) $r['c']; }

        $out = [];
        $now = strtotime($this->dbNow());
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', $now - $i * 86400);
            $out[$d] = $map[$d] ?? 0;
        }
        return $out;
    }

    /** Huidige tijd volgens de DATABASE (created_at is een DB-default; PHP-tijdzone kan afwijken). */
    private function dbNow(): string
    {
        $row = $this->db->fetchOne('SELECT NOW() AS n');
        return (string) ($row['n'] ?? date('Y-m-d H:i:s'));
    }

    private function since(int $seconds): string
    {
        return date('Y-m-d H:i:s', strtotime($this->dbNow()) - $seconds);
    }

    public static function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0; $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
        return number_format($v, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
    }
}
