<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Blocks\Support\BlockHtml as H;
use CommunityFusion\Core\Database\Connection;

/** Community-activiteit: nieuwste topics óf topics met de meest recente reactie. */
final class ForumActivityBlock extends AbstractBlock
{
    public const MODES = ['active', 'newest'];

    public function __construct(private readonly Connection $db) {}

    public function getSlug(): string { return 'forum-activity'; }
    public function getName(): string { return 'Forum-activiteit'; }

    public function getConfigSchema(): array
    {
        return [
            'count' => ['type' => 'integer', 'label' => 'Aantal topics', 'default' => 6, 'min' => 1, 'max' => 20],
            'mode'  => ['type' => 'select',  'label' => 'Sorteer op (active = laatste reactie, newest = nieuwste topic)', 'default' => 'active', 'options' => self::MODES],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $count = H::clampInt($config['count'] ?? 6, 1, 20, 6);
        $mode  = H::pick($config['mode'] ?? 'active', self::MODES, 'active');
        $order = $mode === 'newest' ? 't.created_at DESC' : 'COALESCE(t.last_post_at, t.created_at) DESC';

        $items = $this->db->fetchAll(
            "SELECT t.slug, t.title, t.reply_count, t.created_at, t.last_post_at, t.is_pinned,
                    c.slug AS board_slug, c.name AS board_name
             FROM cf_forum_topics t
             JOIN cf_categories c ON c.id = t.board_id AND c.type = 'forum'
             WHERE t.deleted_at IS NULL
             ORDER BY {$order}
             LIMIT ?",
            [$count]
        );
        if (empty($items)) {
            return '<p class="cf-block-empty">Nog geen forumactiviteit.</p>';
        }

        $html = '<ul class="cf-block-news-list cf-block-forum">';
        foreach ($items as $it) {
            $url   = '/forum/' . rawurlencode((string) $it['board_slug']) . '/' . rawurlencode((string) $it['slug']);
            $when  = $it['last_post_at'] ?: $it['created_at'];
            [$d, $m] = H::dayMonth($when);
            $reps  = (int) $it['reply_count'];
            $html .= '<li class="cf-block-news-item cf-block-forum-item"><span><a href="' . H::e($url) . '">'
                . ((int) $it['is_pinned'] === 1 ? '📌 ' : '') . H::e($it['title']) . '</a>'
                . '<small class="cf-block-forum-meta">' . H::e($it['board_name']) . ' · 💬 ' . $reps . '</small></span>'
                . '<span class="cf-block-news-date">' . H::e(trim($d . ' ' . $m)) . '</span></li>';
        }
        return $html . '</ul><a href="/forum" class="cf-block-more">Naar het forum →</a>';
    }

    public function getCacheTtl(): int { return 120; }
}
