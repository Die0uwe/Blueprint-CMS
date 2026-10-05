<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Ollama;
use CommunityFusion\Blocks\AbstractBlock;

/**
 * AI Chatbox: een chatvenster in zijbalk of content. Berichten gaan via AJAX naar
 * /api/ollama/chat (met rate-limit). Meerdere chatboxen op één pagina werken
 * onafhankelijk; de logica staat in assets/js/cf-aichat.js.
 *
 * Er is bewust geen "is de AI online?"-controle bij het tonen van de pagina:
 * dat kostte bij elke paginaweergave een netwerkaanroep. Een fout toont de
 * chatbox pas als iemand een vraag stelt.
 */
final class OllamaChatBlock extends AbstractBlock
{
    public const MAX_SUGGESTIONS = 4;

    public function __construct(
        private readonly OllamaClient $client,
        private readonly array        $config = [],
    ) {}

    public function getSlug(): string { return 'ollama-chat'; }
    public function getName(): string { return 'AI Chatbox'; }

    public function getConfigSchema(): array
    {
        return [
            'title'       => ['type' => 'string',  'label' => 'Titel', 'default' => '🤖 Community AI'],
            'welcome'     => ['type' => 'string',  'label' => 'Welkomstbericht (leeg = geen)', 'default' => 'Hoi! Stel me een vraag over onze community.'],
            'placeholder' => ['type' => 'string',  'label' => 'Placeholder tekst', 'default' => 'Stel een vraag...'],
            'suggestions' => ['type' => 'textarea', 'label' => 'Snelle vragen (één per regel, max. 4)', 'default' => ''],
            'max_height'  => ['type' => 'range',   'label' => 'Hoogte van het gesprek', 'default' => 400, 'min' => 200, 'max' => 800, 'step' => 20, 'unit' => ' px'],
        ];
    }

    /** @return list<string> */
    public static function parseSuggestions(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = mb_substr($line, 0, 120);
            }
            if (count($out) >= self::MAX_SUGGESTIONS) { break; }
        }
        return $out;
    }

    public function render(array $config, array $context = []): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $title   = $e($config['title'] ?? '🤖 Community AI');
        $welcome = $e($config['welcome'] ?? 'Hoi! Stel me een vraag over onze community.');
        $ph      = $e($config['placeholder'] ?? 'Stel een vraag...');
        $maxH    = max(200, min(800, (int) ($config['max_height'] ?? 400)));

        $chips = '';
        foreach (self::parseSuggestions((string) ($config['suggestions'] ?? '')) as $q) {
            $chips .= '<button type="button" class="cf-aichat-chip">' . $e($q) . '</button>';
        }

        $html = '<div class="cf-aichat" data-welcome="' . $welcome . '">'
            . '<div class="cf-aichat-head">' . $title . '<button type="button" class="cf-aichat-clear" title="Gesprek wissen" aria-label="Gesprek wissen">↺</button></div>'
            . '<div class="cf-aichat-msgs" style="max-height:' . $maxH . 'px" role="log" aria-live="polite"></div>'
            . ($chips !== '' ? '<div class="cf-aichat-chips">' . $chips . '</div>' : '')
            . '<form class="cf-aichat-form" autocomplete="off">'
            . '<input class="cf-input cf-aichat-input" type="text" maxlength="500" placeholder="' . $ph . '" aria-label="' . $ph . '">'
            . '<button class="cf-btn cf-aichat-send" type="submit" aria-label="Verstuur">➤</button>'
            . '</form></div>';

        static $loaded = false;
        if (!$loaded) {
            $loaded = true;
            $html .= '<script src="/assets/js/cf-aichat.js" defer></script>';
        }
        return $html;
    }

    public function getCacheTtl(): int { return 0; }
}
