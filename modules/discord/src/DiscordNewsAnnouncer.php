<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Discord;

use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Hook\HookManager;

/**
 * Hook 'news.published' → embed in het Discord-kanaal van de webhook.
 *
 * Wordt alleen geregistreerd als de module aan staat (DiscordModule::boot) en doet alleen iets als
 * een webhook is ingesteld én "nieuws melden" aan staat. Fouten worden gelogd en NOOIT doorgegeven:
 * het publiceren van een artikel mag door Discord nooit mislukken of vertragen (korte time-out).
 */
final class DiscordNewsAnnouncer
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?DiscordTransport $transport = null,
    ) {}

    public static function register(HookManager $hooks, Connection $db, ?DiscordTransport $transport = null): void
    {
        $hooks->addAction('news.published', static function (mixed $payload = null) use ($db, $transport): void {
            try {
                (new self($db, $transport))->announce(is_array($payload) ? $payload : []);
            } catch (\Throwable $e) {
                error_log('Discord-nieuwsmelding mislukt: ' . $e->getMessage());
            }
        });
    }

    /**
     * @param array{id?:mixed,title?:mixed,slug?:mixed,url?:mixed} $payload
     * @return array{sent:bool,reason:string}
     */
    public function announce(array $payload): array
    {
        $store = new DiscordStore($this->db);
        if (!$store->announceNews()) {
            return ['sent' => false, 'reason' => 'uit'];
        }
        $webhook = $store->webhookUrl();
        if ($webhook === '' || !DiscordWebhook::isValid($webhook)) {
            return ['sent' => false, 'reason' => 'geen_webhook'];
        }
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return ['sent' => false, 'reason' => 'geen_titel'];
        }

        $summary = '';
        $id = (int) ($payload['id'] ?? 0);
        if ($id > 0) {
            try {
                $row = $this->db->fetchOne("SELECT summary FROM cf_news WHERE id = ?", [$id]);
                $summary = self::plain((string) ($row['summary'] ?? ''));
            } catch (\Throwable) {
                // samenvatting is optioneel
            }
        }

        $res = (new DiscordWebhook($this->transport))->sendNews($webhook, $title, (string) ($payload['url'] ?? ''), $summary);
        if (!$res['ok']) {
            error_log('Discord-nieuwsmelding mislukt (HTTP ' . $res['status'] . '): ' . $res['message']);
            return ['sent' => false, 'reason' => 'fout'];
        }
        return ['sent' => true, 'reason' => 'ok'];
    }

    private static function plain(string $s): string
    {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim(preg_replace('/\s+/', ' ', $s) ?? ''), 0, 400);
    }
}
