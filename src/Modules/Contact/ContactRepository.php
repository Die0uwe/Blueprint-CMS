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

namespace CommunityFusion\Modules\Contact;

use CommunityFusion\Core\Database\Connection;

/**
 * ContactRepository
 *
 * Géén e-mailverzending — er bestaat (nog) geen Mailer-klasse in deze
 * codebase, ondanks dat config/config.php al een volledige 'mail'-sectie
 * genereert (SMTP host/poort/gebruiker). Berichten worden alléén
 * opgeslagen; een beheerder leest ze via /admin/contact. Zie CHANGELOG.
 */
final class ContactRepository
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly Connection $db,
    ) {}

    public function perPage(): int { return self::PER_PAGE; }

    public function create(
        ?int   $userId,
        string $name,
        string $email,
        string $subject,
        string $message,
        string $ip,
    ): int {
        return (int) $this->db->insert('contact_messages', [
            'user_id'    => $userId,
            'name'       => trim($name),
            'email'      => trim($email),
            'subject'    => trim($subject) !== '' ? trim($subject) : null,
            'message'    => trim($message),
            'ip_address' => $ip,
        ]);
    }

    public function getInbox(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM cf_contact_messages ORDER BY is_read ASC, created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    public function countAll(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS count FROM cf_contact_messages");
        return (int) ($row['count'] ?? 0);
    }

    public function countUnread(): int
    {
        $row = $this->db->fetchOne("SELECT COUNT(*) AS count FROM cf_contact_messages WHERE is_read = 0");
        return (int) ($row['count'] ?? 0);
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM cf_contact_messages WHERE id = ?", [$id]);
    }

    public function markRead(int $id): void
    {
        $this->db->execute("UPDATE cf_contact_messages SET is_read = 1 WHERE id = ?", [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM cf_contact_messages WHERE id = ?", [$id]);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : ContactRepository.php                                ║
// ║  Role         : Data                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 1 (Contact core-module)                   ║
// ║  Notes        : Geen e-mailverzending — geen Mailer-klasse aanwezig  ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
