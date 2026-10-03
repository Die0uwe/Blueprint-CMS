<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Security;

/**
 * ContentSanitizer — escaping en HTML-sanitizing zonder externe library.
 *
 * Gebouwd op DOMDocument (geen HTMLPurifier). Drie niveaus, van streng naar
 * soepel:
 *
 *  - escape()       : alles wordt tekst. Standaard voor ELKE view-uitvoer.
 *  - text()         : platte tekst met stuurtekens verwijderd (titels e.d.).
 *  - sanitizeHtml() : whitelist van tags en attributen. Er wordt NIET in het
 *                     geparste document "geschoond" maar een NIEUW document
 *                     opgebouwd uit alleen toegestane knopen. Zo kan een
 *                     parser-eigenaardigheid (mXSS) nooit een niet-toegestane
 *                     knoop doorlaten: wat niet expliciet gekopieerd wordt,
 *                     bestaat in de uitvoer niet.
 */
final class ContentSanitizer
{
    /** @var array<string, list<string>> tag => toegestane attributen */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'del' => [], 'sub' => [], 'sup' => [], 'small' => [],
        'code' => [], 'pre' => [], 'kbd' => [], 'blockquote' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'span' => [], 'div' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    /** Elementen die inclusief hun inhoud worden weggegooid. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'svg', 'math',
        'template', 'noscript', 'noembed', 'noframes', 'xmp', 'plaintext', 'listing',
        'form', 'input', 'button', 'select', 'option', 'textarea', 'link', 'meta', 'base', 'title', 'head',
        'audio', 'video', 'source', 'track', 'canvas', 'map', 'area',
    ];

    private const ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto'];

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Platte tekst: ongeldige UTF-8 en stuurtekens (behalve \n en \t) weg,
     * getrimd en afgekapt op $maxChars tekens.
     */
    public static function text(string $value, int $maxChars = 0): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = trim($value);
        if ($maxChars > 0 && mb_strlen($value) > $maxChars) {
            $value = mb_substr($value, 0, $maxChars);
        }
        return $value;
    }

    /**
     * Whitelist-sanitizer voor HTML-fragmenten.
     */
    public static function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $source = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $source->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $source->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return self::escape($html);
        }

        $target = new \DOMDocument('1.0', 'UTF-8');
        $root   = $target->createElement('div');
        $target->appendChild($root);

        self::copyChildren($body, $root, $target);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= (string) $target->saveHTML($child);
        }
        return $out;
    }

    private static function copyChildren(\DOMNode $from, \DOMNode $to, \DOMDocument $doc): void
    {
        foreach ($from->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $to->appendChild($doc->createTextNode($node->data));
                continue;
            }
            if (!$node instanceof \DOMElement) {
                continue; // commentaar, CDATA, processing instructions: weg
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            if (!isset(self::ALLOWED[$tag])) {
                // Onbekende tag: tag weg, (gesaneerde) kinderen blijven.
                self::copyChildren($node, $to, $doc);
                continue;
            }

            $element = $doc->createElement($tag);
            foreach (self::ALLOWED[$tag] as $attr) {
                if (!$node->hasAttribute($attr)) {
                    continue;
                }
                $value = $node->getAttribute($attr);
                if (in_array($attr, ['href', 'src'], true)) {
                    $value = self::safeUrl($value, $tag === 'img');
                    if ($value === null) {
                        continue;
                    }
                } elseif (in_array($attr, ['colspan', 'rowspan', 'width', 'height'], true)) {
                    if (preg_match('/^\d{1,4}$/', $value) !== 1) {
                        continue;
                    }
                }
                $element->setAttribute($attr, $value);
            }

            if ($tag === 'a') {
                $element->setAttribute('rel', 'noopener noreferrer nofollow');
            }

            $to->appendChild($element);

            if ($tag !== 'br' && $tag !== 'hr' && $tag !== 'img') {
                self::copyChildren($node, $element, $doc);
            }
        }
    }

    /**
     * Alleen http(s)/mailto of een relatieve URL. Whitespace en stuurtekens
     * worden vóór de scheme-check verwijderd, want browsers negeren ze
     * ("java\tscript:" is javascript:). Afbeeldingen mogen alleen http(s).
     */
    private static function safeUrl(string $url, bool $imageOnly): ?string
    {
        $clean = (string) preg_replace('/[\x00-\x20\x7F]+/u', '', $url);
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $clean, $m) === 1) {
            $scheme = strtolower($m[1]);
            $allowed = $imageOnly ? ['http', 'https'] : self::ALLOWED_URL_SCHEMES;
            return in_array($scheme, $allowed, true) ? $url : null;
        }

        // Protocol-relatief (//host) en relatief pad zijn veilig; backslash-trucs niet.
        if (str_contains($clean, '\\')) {
            return null;
        }
        return $url;
    }
}
