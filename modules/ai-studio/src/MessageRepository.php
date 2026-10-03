<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\AiStudio;

use CommunityFusion\Core\Database\Connection;

/**
 * Berichten van een gesprek. De aanroeper controleert eigenaarschap van het
 * gesprek via ConversationRepository::find(); findOwned() doet die controle
 * zelf via een JOIN (gebruikt door apply-diff, dat alleen een message_id krijgt).
 */
final class MessageRepository
{
    public const CONTENT_MAX_BYTES = 200000;

    public function __construct(private readonly Connection $db)
    {
    }

    public function add(int $conversationId, string $role, string $content, string $provider = '', string $model = ''): int
    {
        if (!in_array($role, ['user', 'assistant'], true)) {
            throw new \InvalidArgumentException('Ongeldige rol.');
        }
        return (int) $this->db->insert('ai_messages', [
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => mb_strcut($content, 0, self::CONTENT_MAX_BYTES),
            'provider' => mb_substr($provider, 0, 32),
            'model' => mb_substr($model, 0, 100),
        ]);
    }

    /**
     * De laatste $limit berichten, oud -> nieuw.
     *
     * @return list<array{id: int, role: string, content: string, provider: string, model: string, has_proposal: bool, applied: bool, created_at: string}>
     */
    public function recent(int $conversationId, int $limit = 20): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, role, content, provider, model, proposal_diff, proposal_applied_at, created_at FROM cf_ai_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?',
            [$conversationId, max(1, min($limit, 200))]
        );
        $out = [];
        foreach (array_reverse($rows) as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'role' => (string) $r['role'],
                'content' => (string) $r['content'],
                'provider' => (string) $r['provider'],
                'model' => (string) $r['model'],
                'has_proposal' => isset($r['proposal_diff']) && $r['proposal_diff'] !== '',
                'applied' => isset($r['proposal_applied_at']) && $r['proposal_applied_at'] !== null,
                'created_at' => (string) $r['created_at'],
            ];
        }
        return $out;
    }

    public function setProposal(int $messageId, string $diff, string $baseSha256): void
    {
        $this->db->update(
            'ai_messages',
            ['proposal_diff' => $diff, 'proposal_base_sha256' => $baseSha256],
            'id = ?',
            [$messageId]
        );
    }

    /**
     * Bericht + voorstel, alleen als het gesprek van $userId is.
     *
     * @return array{id: int, conversation_id: int, proposal_diff: string, proposal_base_sha256: string}|null
     */
    public function findOwnedProposal(int $messageId, int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT m.id, m.conversation_id, m.proposal_diff, m.proposal_base_sha256
             FROM cf_ai_messages m
             JOIN cf_ai_conversations c ON c.id = m.conversation_id
             WHERE m.id = ? AND c.user_id = ? AND m.role = ?',
            [$messageId, $userId, 'assistant']
        );
        if (
            $row === null
            || !is_string($row['proposal_diff'] ?? null)
            || $row['proposal_diff'] === ''
            || !is_string($row['proposal_base_sha256'] ?? null)
        ) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'conversation_id' => (int) $row['conversation_id'],
            'proposal_diff' => $row['proposal_diff'],
            'proposal_base_sha256' => $row['proposal_base_sha256'],
        ];
    }

    public function markApplied(int $messageId): void
    {
        $this->db->update('ai_messages', ['proposal_applied_at' => date('Y-m-d H:i:s')], 'id = ?', [$messageId]);
    }
}
