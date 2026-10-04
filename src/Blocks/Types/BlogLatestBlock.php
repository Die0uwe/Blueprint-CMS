<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Blocks\Support\BlockHtml as H;
use CommunityFusion\Core\Database\Connection;

/** Laatste blogberichten (gepubliceerd) als lijst of "ticket"-kaarten. */
final class BlogLatestBlock extends AbstractBlock
{
    public const STYLES = ['list', 'ticket'];

    public function __construct(private readonly Connection $db) {}

    public function getSlug(): string { return 'blog-latest'; }
    public function getName(): string { return 'Laatste Blogberichten'; }

    public function getConfigSchema(): array
    {
        return [
            'count' => ['type' => 'integer', 'label' => 'Aantal berichten', 'default' => 5, 'min' => 1, 'max' => 20],
            'style' => ['type' => 'select',  'label' => 'Weergave (list, ticket)', 'default' => 'list', 'options' => self::STYLES],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $count = H::clampInt($config['count'] ?? 5, 1, 20, 5);
        $style = H::pick($config['style'] ?? 'list', self::STYLES, 'list');

        $items = $this->db->fetchAll(
            "SELECT b.slug, b.title, b.summary, b.published_at, u.username, u.display_name
             FROM cf_blog_posts b
             JOIN cf_users u ON u.id = b.author_id
             WHERE b.status = 'published' AND b.deleted_at IS NULL
             ORDER BY b.published_at DESC
             LIMIT ?",
            [$count]
        );
        if (empty($items)) {
            return '<p class="cf-block-empty">Nog geen blogberichten.</p>';
        }

        $html = $style === 'ticket' ? '<div class="cf-ticket-list">' : '<ul class="cf-block-news-list">';
        foreach ($items as $it) {
            $url    = '/blog/' . rawurlencode((string) $it['username']) . '/' . rawurlencode((string) $it['slug']);
            $author = (string) ($it['display_name'] ?: $it['username']);
            if ($style === 'ticket') {
                [$day, $mon] = H::dayMonth($it['published_at'] ?? null);
                $sum = H::excerpt($it['summary'] ?? '', 90);
                $html .= '<a class="cf-ticket" href="' . H::e($url) . '"><span class="cf-ticket-stub"><b>' . H::e($day) . '</b><small>' . H::e($mon)
                    . '</small></span><span class="cf-ticket-body"><strong>' . H::e($it['title']) . '</strong><small>✍️ ' . H::e($author)
                    . ($sum !== '' ? ' · ' . H::e($sum) : '') . '</small></span></a>';
            } else {
                $html .= '<li class="cf-block-news-item"><a href="' . H::e($url) . '">' . H::e($it['title'])
                    . '</a><span class="cf-block-news-date">' . H::e($author) . '</span></li>';
            }
        }
        return $html . ($style === 'ticket' ? '</div>' : '</ul>') . '<a href="/blog" class="cf-block-more">Alle blogs →</a>';
    }

    public function getCacheTtl(): int { return 300; }
}
