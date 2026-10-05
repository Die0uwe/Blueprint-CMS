<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Core\Ai;

/**
 * Haalt het "denkwerk" van redeneermodellen (DeepSeek-R1, Qwen3, QwQ, …) uit een
 * antwoord: het blok <think> … </think> aan het begin van het antwoord. Een model dat
 * midden in een gewoon antwoord over "<think>" praat, houdt zijn tekst. Werkt ook op een stroom (streaming):
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
    private bool   $atStart  = true;   // een <think>-blok telt alleen aan het BEGIN van het antwoord
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
                $this->atStart  = true;
                $this->trimLead = true;
                continue;
            }
            if (!$this->atStart) {
                $out .= $this->buf;
                $this->buf = '';
                return $out;
            }
            // Begin van het antwoord: is dit een denkblok, gewone tekst, of nog onduidelijk?
            $t = ltrim($this->buf);
            if ($t === '') {
                return $out;
            }
            if (stripos($t, self::OPEN) === 0) {
                $this->buf = substr($t, strlen(self::OPEN));
                $this->in  = true;
                continue;
            }
            if (strlen($t) < strlen(self::OPEN) && strncasecmp(self::OPEN, $t, strlen($t)) === 0) {
                return $out;           // kan nog "<think>" worden: even vasthouden
            }
            $this->atStart = false;    // gewone tekst; een latere "<think>" is dan gewoon tekst
            if ($this->trimLead) {
                $this->buf      = $t;
                $this->trimLead = false;
            }
        }
    }

    /** Einde van de stroom: een half tag dat nooit afgemaakt werd is gewone tekst; een niet-afgesloten denkblok vervalt. */
    public function flush(): string
    {
        $rest = $this->in ? '' : $this->buf;
        $this->buf     = '';
        $this->in      = false;
        $this->atStart = true;
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
