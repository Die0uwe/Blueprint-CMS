<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Database\Connection;

/**
 * AccountService — extra e-mailadressen (max. 3, één hoofdadres) en het
 * samenvoegen van twee accounts van dezelfde persoon.
 *
 * Ontwerp (naar ScriptSpace `includes/accounts.php`):
 *  - je behoudt het account waarmee je bent ingelogd ($keep);
 *  - je bewijst dat het andere ($drop) van jou is, via zijn gekoppelde
 *    provider-login of zijn gebruikersnaam + wachtwoord;
 *  - het bewijs geldt 10 minuten, alleen voor die sessie, en één keer;
 *  - alles gebeurt in één transactie: bij een fout is er niets veranderd;
 *  - bij een dubbele provider wint het behouden account.
 */
final class AccountService
{
    public const MAX_EMAILS     = 3;
    public const PROOF_SECONDS  = 600;
    public const TOKEN_TTL_SECS = 86400;

    /** Tabellen met een eigenaar-kolom die bij samenvoegen mee verhuist. */
    private const OWNED = [
        ['news', 'author_id'], ['pages', 'author_id'], ['forum_topics', 'author_id'],
        ['forum_posts', 'author_id'], ['blog_posts', 'author_id'], ['downloads', 'author_id'],
        ['gallery_items', 'author_id'], ['contact_messages', 'user_id'],
        ['guild_members', 'user_id'], ['ai_conversations', 'user_id'],
    ];

    public function __construct(
        private readonly Connection  $db,
        private readonly AuditLogger $audit,
    ) {}

    // ─── E-MAILADRESSEN ──────────────────────────────────────────────────

    /** Zorgt dat het huidige cf_users.email als hoofdadres in cf_user_emails staat. */
    public function ensurePrimary(int $userId): void
    {
        $u = $this->db->fetchOne("SELECT email, email_verified_at, created_at FROM cf_users WHERE id = ?", [$userId]);
        if ($u === null || str_ends_with((string) $u['email'], '.invalid')) {
            return;
        }
        $row = $this->db->fetchOne("SELECT id FROM cf_user_emails WHERE email = ?", [$u['email']]);
        if ($row !== null) {
            return;
        }
        $has = $this->db->fetchOne("SELECT COUNT(*) AS n FROM cf_user_emails WHERE user_id = ? AND is_primary = 1", [$userId]);
        $this->db->insert('user_emails', [
            'user_id'     => $userId,
            'email'       => $u['email'],
            'is_primary'  => (int) ($has['n'] ?? 0) === 0 ? 1 : 0,
            'verified_at' => $u['email_verified_at'],
            'created_at'  => $u['created_at'],
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function emails(int $userId): array
    {
        $this->ensurePrimary($userId);
        return $this->db->fetchAll(
            "SELECT id, email, is_primary, verified_at, token_expires_at FROM cf_user_emails WHERE user_id = ? ORDER BY is_primary DESC, id ASC",
            [$userId]
        );
    }

    /**
     * Voeg een adres toe (nog niet bevestigd). Geeft ['error'=>code] of ['token'=>..., 'email'=>...].
     * Codes: invalid, limit, taken
     * @return array{error?:string,token?:string,email?:string}
     */
    public function addEmail(int $userId, string $email): array
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with($email, '.invalid')) {
            return ['error' => 'invalid'];
        }
        $this->ensurePrimary($userId);
        $n = $this->db->fetchOne("SELECT COUNT(*) AS n FROM cf_user_emails WHERE user_id = ?", [$userId]);
        if ((int) ($n['n'] ?? 0) >= self::MAX_EMAILS) {
            return ['error' => 'limit'];
        }
        if ($this->db->fetchOne("SELECT 1 AS x FROM cf_user_emails WHERE email = ?", [$email]) !== null
            || $this->db->fetchOne("SELECT 1 AS x FROM cf_users WHERE email = ?", [$email]) !== null) {
            return ['error' => 'taken'];
        }
        $token = bin2hex(random_bytes(32));
        try {
            $this->db->insert('user_emails', [
                'user_id'          => $userId,
                'email'            => $email,
                'is_primary'       => 0,
                'token_hash'       => hash('sha256', $token),
                'token_expires_at' => date('Y-m-d H:i:s', time() + self::TOKEN_TTL_SECS),
            ]);
        } catch (\PDOException) {
            return ['error' => 'taken']; // race op de unieke sleutel
        }
        $this->audit->log('account.email_added', $userId, null, []);
        return ['token' => $token, 'email' => $email];
    }

    /** Bevestig een adres via de mailtoken. Geeft het user-id of null. */
    public function verifyEmail(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = $this->db->fetchOne(
            "SELECT id, user_id, token_expires_at FROM cf_user_emails WHERE token_hash = ? AND verified_at IS NULL",
            [hash('sha256', $token)]
        );
        if ($row === null || strtotime((string) $row['token_expires_at']) < time()) {
            return null;
        }
        $this->db->execute(
            "UPDATE cf_user_emails SET verified_at = ?, token_hash = NULL, token_expires_at = NULL WHERE id = ?",
            [date('Y-m-d H:i:s'), $row['id']]
        );
        $this->audit->log('account.email_verified', (int) $row['user_id'], null, []);
        return (int) $row['user_id'];
    }

    /** Kies het hoofdadres (moet bevestigd zijn en van dit account). */
    public function setPrimary(int $userId, int $emailId): bool
    {
        $row = $this->db->fetchOne(
            "SELECT id, email, verified_at FROM cf_user_emails WHERE id = ? AND user_id = ?",
            [$emailId, $userId]
        );
        if ($row === null || $row['verified_at'] === null) {
            return false;
        }
        $this->db->transaction(function () use ($userId, $row): void {
            $this->db->execute("UPDATE cf_user_emails SET is_primary = 0 WHERE user_id = ?", [$userId]);
            $this->db->execute("UPDATE cf_user_emails SET is_primary = 1 WHERE id = ?", [$row['id']]);
            $this->db->execute(
                "UPDATE cf_users SET email = ?, email_verified_at = COALESCE(email_verified_at, ?), is_verified = 1 WHERE id = ?",
                [$row['email'], date('Y-m-d H:i:s'), $userId]
            );
        });
        $this->audit->log('account.email_primary', $userId, null, []);
        return true;
    }

    /** Verwijder een niet-hoofdadres. */
    public function removeEmail(int $userId, int $emailId): bool
    {
        $n = $this->db->execute(
            "DELETE FROM cf_user_emails WHERE id = ? AND user_id = ? AND is_primary = 0",
            [$emailId, $userId]
        )->rowCount();
        if ($n > 0) {
            $this->audit->log('account.email_removed', $userId, null, []);
        }
        return $n > 0;
    }

    // ─── SAMENVOEGEN ─────────────────────────────────────────────────────

    /** Foutcode of '' als $drop in $keep mag opgaan. */
    public function mergeCheck(int $keep, int $drop): string
    {
        if ($keep === $drop) {
            return 'same';
        }
        foreach ([$keep, $drop] as $id) {
            $u = $this->db->fetchOne("SELECT is_active, deleted_at FROM cf_users WHERE id = ?", [$id]);
            if ($u === null || $u['deleted_at'] !== null) {
                return 'missing';
            }
            if ((int) $u['is_active'] !== 1) {
                return 'blocked';
            }
        }
        return '';
    }

    /** @return array<string,mixed> wat er bij het samenvoegen van $id zou verhuizen */
    public function preview(int $id): array
    {
        $u = $this->db->fetchOne("SELECT id, username, email, created_at FROM cf_users WHERE id = ?", [$id]) ?? [];
        $u['providers'] = array_column($this->db->fetchAll("SELECT provider FROM cf_user_oauth WHERE user_id = ? ORDER BY provider", [$id]), 'provider');
        $u['roles'] = array_column($this->db->fetchAll(
            "SELECT r.name FROM cf_roles r JOIN cf_user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ?", [$id]), 'name');
        $u['emails'] = array_column($this->emails($id), 'email');
        $u['content'] = 0;
        foreach (self::OWNED as [$table, $col]) {
            $u['content'] += $this->countOwned($table, $col, $id);
        }
        return $u;
    }

    /**
     * Voeg $drop samen in $keep. Gooit \RuntimeException (nette melding) bij een fout; dan is er niets veranderd.
     * @return array<string,mixed> samenvatting
     */
    public function merge(int $keep, int $drop): array
    {
        $err = $this->mergeCheck($keep, $drop);
        if ($err !== '') {
            throw new \RuntimeException('merge_' . $err);
        }
        $this->ensurePrimary($keep);
        $this->ensurePrimary($drop);
        $gone = $this->db->fetchOne("SELECT username, email FROM cf_users WHERE id = ?", [$drop]) ?? [];
        $kept = $this->db->fetchOne("SELECT username, email FROM cf_users WHERE id = ?", [$keep]) ?? [];
        $out  = ['providers' => 0, 'roles' => 0, 'emails' => 0, 'content' => 0];

        try {
            $this->db->transaction(function () use ($keep, $drop, &$out): void {
                // providers: per dienst wint het behouden account
                $have = array_column($this->db->fetchAll("SELECT provider FROM cf_user_oauth WHERE user_id = ?", [$keep]), 'provider');
                foreach ($this->db->fetchAll("SELECT id, provider FROM cf_user_oauth WHERE user_id = ?", [$drop]) as $o) {
                    if (!in_array($o['provider'], $have, true)) {
                        $this->db->execute("UPDATE cf_user_oauth SET user_id = ? WHERE id = ?", [$keep, $o['id']]);
                        $have[] = $o['provider'];
                        $out['providers']++;
                    }
                }
                // rollen
                $haveRoles = array_column($this->db->fetchAll("SELECT role_id FROM cf_user_roles WHERE user_id = ?", [$keep]), 'role_id');
                foreach ($this->db->fetchAll("SELECT role_id FROM cf_user_roles WHERE user_id = ?", [$drop]) as $r) {
                    if (!in_array($r['role_id'], $haveRoles, true)) {
                        $this->db->execute("INSERT INTO cf_user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)", [$keep, $r['role_id'], $keep]);
                        $out['roles']++;
                    }
                }
                // e-mailadressen: tot het maximum; hoofdadres van $keep blijft hoofdadres
                $count = (int) ($this->db->fetchOne("SELECT COUNT(*) AS n FROM cf_user_emails WHERE user_id = ?", [$keep])['n'] ?? 0);
                foreach ($this->db->fetchAll("SELECT id FROM cf_user_emails WHERE user_id = ? ORDER BY is_primary DESC, id ASC", [$drop]) as $e) {
                    if ($count >= self::MAX_EMAILS) {
                        break;
                    }
                    $this->db->execute("UPDATE cf_user_emails SET user_id = ?, is_primary = 0 WHERE id = ?", [$keep, $e['id']]);
                    $count++;
                    $out['emails']++;
                }
                // inhoud (nieuws, forum, downloads, …)
                foreach (self::OWNED as [$table, $col]) {
                    $out['content'] += $this->moveOwned($table, $col, $drop, $keep);
                }
                // wat overblijft (dubbele dienst, te veel adressen) verdwijnt via ON DELETE CASCADE
                $this->db->execute("DELETE FROM cf_users WHERE id = ?", [$drop]);
            });
        } catch (\Throwable $e) {
            error_log('account merge: ' . $e->getMessage());
            throw new \RuntimeException('merge_failed');
        }

        $this->audit->log('account.merged', $keep, (string) ($kept['username'] ?? ''), ['dropped_id' => $drop, 'dropped_username' => $gone['username'] ?? ''] + $out);
        $out['dropped'] = (string) ($gone['username'] ?? '');
        $out['mail']    = array_values(array_unique(array_filter([(string) ($kept['email'] ?? ''), (string) ($gone['email'] ?? '')])));
        $out['kept']    = (string) ($kept['username'] ?? '');
        return $out;
    }

    // ─── intern ──────────────────────────────────────────────────────────

    private function countOwned(string $table, string $col, int $id): int
    {
        try {
            return (int) ($this->db->fetchOne("SELECT COUNT(*) AS n FROM cf_{$table} WHERE {$col} = ?", [$id])['n'] ?? 0);
        } catch (\PDOException) {
            return 0; // module niet geïnstalleerd
        }
    }

    private function moveOwned(string $table, string $col, int $from, int $to): int
    {
        // Naam komt uit de vaste lijst OWNED, nooit uit invoer.
        try {
            return $this->db->execute("UPDATE cf_{$table} SET {$col} = ? WHERE {$col} = ?", [$to, $from])->rowCount();
        } catch (\PDOException $e) {
            // Tabel ontbreekt (module niet geïnstalleerd) → overslaan. Elke andere fout breekt de transactie.
            $m = $e->getMessage();
            if (($e->errorInfo[1] ?? null) === 1146 || str_contains($m, 'no such table') || str_contains($m, "doesn't exist")) {
                return 0;
            }
            throw $e;
        }
    }
}
