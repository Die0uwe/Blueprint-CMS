<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Blocks\Support\BlockHtml as H;
use CommunityFusion\Core\Database\Connection;

/**
 * Laatste nieuws in drie weergaven:
 *  - list    compacte lijst (standaard, ongewijzigd t.o.v. eerdere versies)
 *  - ticker  doorlopende nieuwsbalk (marquee, pauzeert bij hover)
 *  - ticket  "ticket"-kaarten met datumstrook en korte samenvatting
 */
final class NewsBlock extends AbstractBlock
{
    public const STYLES = ['list', 'ticker', 'ticket'];

    public function __construct(private readonly Connection $db) {}

    public function getSlug(): string { return 'news-latest'; }
    public function getName(): string { return 'Laatste Nieuws'; }

    public function getConfigSchema(): array
    {
        return [
            'count'      => ['type' => 'integer', 'label' => 'Aantal artikelen', 'default' => 5, 'min' => 1, 'max' => 20],
            'style'      => ['type' => 'select',  'label' => 'Weergave (list, ticker, ticket)', 'default' => 'list', 'options' => self::STYLES],
            'show_image' => ['type' => 'boolean', 'label' => 'Afbeelding tonen (ticket)', 'default' => false],
        ];
    }

    public function render(array $config, array $context = []): string
    {
        $count = H::clampInt($config['count'] ?? 5, 1, 20, 5);
        $style = H::pick($config['style'] ?? 'list', self::STYLES, 'list');
        $image = !empty($config['show_image']);

        $items = $this->db->fetchAll(
            "SELECT slug, title, summary, featured_image, published_at FROM cf_news
             WHERE status = 'published' AND deleted_at IS NULL
             ORDER BY is_sticky DESC, published_at DESC
             LIMIT ?",
            [$count]
        );

        if (empty($items)) {
            return '<p class="cf-block-empty">Geen nieuws beschikbaar.</p>';
        }

        return match ($style) {
            'ticker' => $this->ticker($items),
            'ticket' => $this->ticket($items, $image),
            default  => $this->list($items),
        };
    }

    private function list(array $items): string
    {
        $html = '<ul class="cf-block-news-list">';
        foreach ($items as $item) {
            $date = $item['published_at'] ? date('d M', strtotime((string) $item['published_at'])) : '';
            $html .= '<li class="cf-block-news-item"><a href="/news/' . H::e($item['slug']) . '">' . H::e($item['title'])
                . '</a><span class="cf-block-news-date">' . H::e($date) . '</span></li>';
        }
        return $html . '</ul><a href="/news" class="cf-block-more">Alle artikelen →</a>';
    }

    private function ticker(array $items): string
    {
        $one = '';
        foreach ($items as $item) {
            $one .= '<a class="cf-ticker-item" href="/news/' . H::e($item['slug']) . '">📰 ' . H::e($item['title']) . '</a>';
        }
        // Twee keer dezelfde reeks: de animatie schuift precies 50% op en begint dan naadloos opnieuw.
        $seconds = max(12, count($items) * 7);
        return '<div class="cf-ticker" role="region" aria-label="Nieuwsticker" style="--cf-ticker-dur:' . $seconds . 's">'
            . '<div class="cf-ticker-track">' . $one . '<span aria-hidden="true" class="cf-ticker-dup">' . $one . '</span></div></div>'
            . '<a href="/news" class="cf-block-more">Alle artikelen →</a>';
    }

    private function ticket(array $items, bool $image): string
    {
        $html = '<div class="cf-ticket-list">';
        foreach ($items as $item) {
            [$day, $mon] = H::dayMonth($item['published_at'] ?? null);
            $img = ($image && !empty($item['featured_image']) && str_starts_with((string) $item['featured_image'], '/media/'))
                ? '<img class="cf-ticket-img" src="' . H::e($item['featured_image']) . '" alt="" loading="lazy">' : '';
            $sum = H::excerpt($item['summary'] ?? '', 90);
            $html .= '<a class="cf-ticket" href="/news/' . H::e($item['slug']) . '">'
                . '<span class="cf-ticket-stub"><b>' . H::e($day) . '</b><small>' . H::e($mon) . '</small></span>'
                . '<span class="cf-ticket-body">' . $img . '<strong>' . H::e($item['title']) . '</strong>'
                . ($sum !== '' ? '<small>' . H::e($sum) . '</small>' : '') . '</span></a>';
        }
        return $html . '</div><a href="/news" class="cf-block-more">Alle artikelen →</a>';
    }

    public function getCacheTtl(): int { return 300; }
}
