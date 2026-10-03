<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Security\ContentSanitizer;

/**
 * Gesprekken. Elke lees-/schrijfmethode vraagt de eigenaar (user_id) mee en
 * zet die in de WHERE: een gebruiker kan nooit bij het gesprek van een ander,
 * ook niet met een geraden id.
 */
final class ConversationRepository
{
    public const TITLE_MAX = 200;

    public function __construct(private readonly Connection $db)
    {
    }

    public function create(int $userId, string $title, string $provider, string $model): int
    {
        $title = ContentSanitizer::text($title, self::TITLE_MAX);
        if ($title === '') {
            $title = 'Nieuw gesprek';
        }
        return (int) $this->db->insert('ai_conversations', [
            'user_id' => $userId,
            'title' => $title,
            'provider' => mb_substr($provider, 0, 32),
            'model' => mb_substr($model, 0, 100),
        ]);
    }

    /**
     * @return array{id: int, user_id: int, title: string, provider: string, model: string, created_at: string, updated_at: string}|null
     */
    public function find(int $id, int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, user_id, title, provider, model, created_at, updated_at FROM cf_ai_conversations WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        return $row === null ? null : $this->cast($row);
    }

    /**
     * @return list<array{id: int, user_id: int, title: string, provider: string, model: string, created_at: string, updated_at: string}>
     */
    public function listForUser(int $userId, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, user_id, title, provider, model, created_at, updated_at FROM cf_ai_conversations WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT ?',
            [$userId, max(1, min($limit, 200))]
        );
        return array_map(fn (array $r): array => $this->cast($r), $rows);
    }

    public function rename(int $id, int $userId, string $title): bool
    {
        $title = ContentSanitizer::text($title, self::TITLE_MAX);
        if ($title === '') {
            return false;
        }
        return $this->db->update('ai_conversations', ['title' => $title], 'id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    public function touch(int $id, int $userId, string $provider, string $model): void
    {
        $this->db->update(
            'ai_conversations',
            ['provider' => mb_substr($provider, 0, 32), 'model' => mb_substr($model, 0, 100), 'updated_at' => date('Y-m-d H:i:s')],
            'id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * Verwijdert het gesprek en zijn berichten, alleen voor de eigenaar.
     */
    public function delete(int $id, int $userId): bool
    {
        if ($this->find($id, $userId) === null) {
            return false;
        }
        $this->db->delete('ai_messages', 'conversation_id = ?', [$id]);
        return $this->db->delete('ai_conversations', 'id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, user_id: int, title: string, provider: string, model: string, created_at: string, updated_at: string}
     */
    private function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'title' => (string) $row['title'],
            'provider' => (string) $row['provider'],
            'model' => (string) $row['model'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
