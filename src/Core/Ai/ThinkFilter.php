<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Core\Ai;

/**
 * Haalt het "denkwerk" van redeneermodellen (DeepSeek-R1, Qwen3, QwQ, …) uit een
 * antwoord: alles tussen <think> en </think>. Werkt ook op een stroom (streaming):
 * een tag mag over twee stukjes verdeeld zijn.
 *
 *   $f = new ThinkFilter();
 *   foreach ($chunks as $c) { echo $f->feed($c); }
 *   echo $f->flush();
 *
 *   ThinkFilter::strip($heleTekst)   // voor een compleet antwoord
 */
final class ThinkFilter
{
    private const OPEN  = '<think>';
    private const CLOSE = '</think>';

    private bool   $in       = false;
    private bool   $trimLead = false;
    private string $buf      = '';

    public function feed(string $chunk): string
    {
        $this->buf .= $chunk;
        $out = '';
        while (true) {
            if ($this->in) {
                $p = stripos($this->buf, self::CLOSE);
                if ($p === false) {
                    $this->buf = $this->partialTail($this->buf, self::CLOSE);
                    return $out;
                }
                $this->buf      = substr($this->buf, $p + strlen(self::CLOSE));
                $this->in       = false;
                $this->trimLead = true;
                continue;
            }
            if ($this->trimLead) {
                $this->buf = ltrim($this->buf);
                if ($this->buf !== '') {
                    $this->trimLead = false;
                }
            }
            $p = stripos($this->buf, self::OPEN);
            if ($p === false) {
                $keep = strlen($this->partialTail($this->buf, self::OPEN));
                $out .= substr($this->buf, 0, strlen($this->buf) - $keep);
                $this->buf = $keep > 0 ? substr($this->buf, -$keep) : '';
                return $out;
            }
            $out .= substr($this->buf, 0, $p);
            $this->buf = substr($this->buf, $p + strlen(self::OPEN));
            $this->in  = true;
        }
    }

    /** Einde van de stroom: een half tag dat nooit afgemaakt werd is gewone tekst; een niet-afgesloten denkblok vervalt. */
    public function flush(): string
    {
        $rest = $this->in ? '' : $this->buf;
        $this->buf = '';
        $this->in  = false;
        return $rest;
    }

    public static function strip(string $text): string
    {
        // Sommige distills beginnen "midden in" het denken: alleen </think>, geen <think>.
        if (stripos($text, self::OPEN) === false && ($p = stripos($text, self::CLOSE)) !== false) {
            $text = substr($text, $p + strlen(self::CLOSE));
        }
        $f = new self();
        return trim($f->feed($text) . $f->flush());
    }

    /** Langste staart van $s die het begin van $tag is (zodat die niet te vroeg wordt uitgegeven of weggegooid). */
    private function partialTail(string $s, string $tag): string
    {
        $max = min(strlen($tag) - 1, strlen($s));
        for ($k = $max; $k > 0; $k--) {
            if (strncasecmp(substr($s, -$k), $tag, $k) === 0) {
                return substr($s, -$k);
            }
        }
        return '';
    }
}
