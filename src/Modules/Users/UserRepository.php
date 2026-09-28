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

namespace CommunityFusion\Modules\Users;

use CommunityFusion\Core\Database\Connection;

/**
 * UserRepository — admin-CRUD voor cf_users + rol-toewijzing (Wave 3).
 * Zelfde patroon als NewsRepository/PageRepository: LIMIT/OFFSET altijd via
 * Connection::execute()'s bindValue()-pad (zie CHANGELOG v1.11.0) — nooit
 * los PDO gebruiken voor paginering.
 */
final class UserRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(int $limit = 20, int $offset = 0, string $search = ''): array
    {
        if ($search !== '') {
            $like = '%' . $search . '%';
            return $this->db->fetchAll(
                "SELECT id, username, email, display_name, is_active, is_verified, last_login_at, created_at
                 FROM cf_users
                 WHERE deleted_at IS NULL AND (username LIKE ? OR email LIKE ? OR display_name LIKE ?)
                 ORDER BY created_at DESC LIMIT ? OFFSET ?",
                [$like, $like, $like, $limit, $offset]
            );
        }

        return $this->db->fetchAll(
            "SELECT id, username, email, display_name, is_active, is_verified, last_login_at, created_at
             FROM cf_users WHERE deleted_at IS NULL
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function countAll(string $search = ''): int
    {
        if ($search !== '') {
            $like = '%' . $search . '%';
            $row = $this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM cf_users
                 WHERE deleted_at IS NULL AND (username LIKE ? OR email LIKE ? OR display_name LIKE ?)",
                [$like, $like, $like]
            );
            return (int) ($row['c'] ?? 0);
        }

        $row = $this->db->fetchOne("SELECT COUNT(*) AS c FROM cf_users WHERE deleted_at IS NULL");
        return (int) ($row['c'] ?? 0);
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM cf_users WHERE id = ? AND deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Alle rollen, gesorteerd op prioriteit (hoog = machtiger) — gebruikt
     * om de checkbox-lijst op het bewerk-scherm te vullen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllRoles(): array
    {
        return $this->db->fetchAll("SELECT id, name, display_name, priority FROM cf_roles ORDER BY priority DESC");
    }

    /**
     * @return int[] role_ids die deze gebruiker momenteel heeft
     */
    public function getUserRoleIds(int $userId): array
    {
        $rows = $this->db->fetchAll("SELECT role_id FROM cf_user_roles WHERE user_id = ?", [$userId]);
        return array_map(static fn(array $r): int => (int) $r['role_id'], $rows);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->update('users', ['is_active' => $active ? 1 : 0], 'id = ?', [$id]);
    }

    /**
     * Vervang de volledige rol-toewijzing van een gebruiker door $roleIds
     * (transactioneel: verwijderen + opnieuw toewijzen mag nooit half lukken).
     *
     * @param int[] $roleIds
     */
    public function syncRoles(int $userId, array $roleIds, ?int $assignedBy = null): void
    {
        $this->db->transaction(function () use ($userId, $roleIds, $assignedBy) {
            $this->db->delete('user_roles', 'user_id = ?', [$userId]);
            foreach ($roleIds as $roleId) {
                $this->db->insert('user_roles', [
                    'user_id'     => $userId,
                    'role_id'     => $roleId,
                    'assigned_by' => $assignedBy,
                ]);
            }
        });
    }

    /**
     * Soft-delete — zelfde patroon als News/Pages: nooit hard DELETE op
     * gebruikersdata (audit trail / FK's naar cf_news.author_id etc. blijven
     * intact via ON DELETE RESTRICT in schema.sql).
     */
    public function softDelete(int $id): void
    {
        $this->db->execute("UPDATE cf_users SET deleted_at = NOW() WHERE id = ?", [$id]);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: UserRepository.php | Role: Data | Version: 1.0.0             ║
// ║  Created: 2026-09-28 — Wave 3 (admin/users)                          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
