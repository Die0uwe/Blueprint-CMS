<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Audit;

use CommunityFusion\Core\Database\Connection;

/**
 * AuditLogger — schrijft naar cf_audit_log (Wave 5).
 *
 * Vervangt AuthManager::logFailedAttempt()'s losse
 * storage/logs/auth.log-bestand (nooit ergens uitgelezen, dus in de
 * praktijk write-only) door één doorzoekbare tabel die ook geslaagde
 * logins en admin-acties (rol-/bordwijzigingen, moderatie) bijhoudt —
 * en die /admin/logs daadwerkelijk laat zien.
 */
final class AuditLogger
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $context Vrije extra details, opgeslagen als JSON.
     */
    public function log(string $action, ?int $userId, ?string $username, array $context = [], ?string $ip = null): void
    {
        $this->db->insert('audit_log', [
            'user_id'    => $userId,
            'username'   => $username,
            'action'     => $action,
            'context'    => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
        ]);
    }

    public function getRecent(int $limit, int $offset, ?string $actionFilter = null): array
    {
        if ($actionFilter !== null && $actionFilter !== '') {
            return $this->db->fetchAll(
                "SELECT * FROM cf_audit_log WHERE action = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
                [$actionFilter, $limit, $offset]
            );
        }
        return $this->db->fetchAll(
            "SELECT * FROM cf_audit_log ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function count(?string $actionFilter = null): int
    {
        if ($actionFilter !== null && $actionFilter !== '') {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_audit_log WHERE action = ?", [$actionFilter]);
        } else {
            $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_audit_log");
        }
        return (int) ($row['c'] ?? 0);
    }

    /** Voor het filter-dropdown in de admin-view — alleen acties die echt voorkomen. */
    public function distinctActions(): array
    {
        $rows = $this->db->fetchAll("SELECT DISTINCT action FROM cf_audit_log ORDER BY action ASC");
        return array_map(static fn($r) => $r['action'], $rows);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: AuditLogger.php | Role: Core | Version: 1.0.0                 ║
// ║  Created: 2026-09-29 — Wave 5 (admin/logs)                           ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
