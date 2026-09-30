<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
//
// Whitelist voor gebruikersmarkup (bericht-editor, markup-block). Alles wat hier
// NIET staat wordt door de Twig-sandbox geweigerd. Breid alleen uit als je zeker weet
// dat een tag/filter/functie geen bestanden, netwerk, objecten of code bereikt.
//
// Bewust afwezig: raw, include, source, embed, import, macro, extends, use,
// range en de .. operator (geheugen/CPU-misbruik), map/filter/reduce/sort met
// callbacks, constant, attribute, template_from_string, dump.
// PHP-tags worden NOOIT uitgevoerd; zie MarkupRenderer.

return [
    'tags'      => ['if', 'for', 'set'],
    'filters'   => [
        'escape', 'e', 'upper', 'lower', 'capitalize', 'title', 'length', 'default', 'join',
        'date', 'number_format', 'nl2br', 'trim', 'slice', 'first', 'last', 'reverse', 'keys',
        'abs', 'round', 'striptags', 'url_encode',
    ],
    'functions' => ['max', 'min', 'date'],
    'max_bytes'    => 204800,   // maximale grootte van één stuk markup
    'max_for_tags' => 3,        // maximaal aantal {% for %}-lussen per stuk markup
    'time_limit'   => 3,        // seconden rekentijd voor één render
];
