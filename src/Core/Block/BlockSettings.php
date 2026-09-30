<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Block;

/**
 * Zet de ruwe invoer van het blok-instellingenscherm om naar een schone config,
 * uitsluitend op basis van het getConfigSchema() van het blocktype:
 * onbekende sleutels vallen weg, waarden worden naar het juiste type gebracht en begrensd.
 *
 * Ondersteunde types: string, textarea, code, video, url, integer, boolean, select.
 */
final class BlockSettings
{
    public const MAX_TEXT = 20000;

    /** @param array<string,array<string,mixed>> $schema */
    public static function defaults(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $field) {
            if (is_array($field) && array_key_exists('default', $field)) {
                $out[(string)$key] = $field['default'];
            }
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $schema
     * @param array<string,mixed>               $input
     * @return array{config:array<string,mixed>,errors:array<string,string>}
     */
    public static function normalize(array $schema, array $input): array
    {
        $config = [];
        $errors = [];
        foreach ($schema as $key => $field) {
            $key = (string)$key;
            if (!is_array($field) || !preg_match('/^[a-z0-9_]{1,64}$/i', $key)) {
                continue;
            }
            $type = (string)($field['type'] ?? 'string');
            $has = array_key_exists($key, $input);
            $raw = $has ? $input[$key] : null;

            if ($type === 'boolean') {
                $config[$key] = $has
                    ? in_array($raw, [true, 1, '1', 'true', 'on'], true)
                    : (bool)($field['default'] ?? false);
                continue;
            }
            if (!$has || $raw === null || $raw === '') {
                if (!empty($field['required'])) {
                    $errors[$key] = 'Verplicht veld.';
                } elseif (array_key_exists('default', $field)) {
                    $config[$key] = $field['default'];
                } else {
                    $config[$key] = $type === 'integer' ? null : '';
                }
                continue;
            }

            switch ($type) {
                case 'integer':
                    if (!is_numeric($raw) || (string)(int)$raw !== (string)(int)(float)$raw) {
                        $errors[$key] = 'Geen geheel getal.';
                        break;
                    }
                    $n = (int)$raw;
                    if (isset($field['min'])) { $n = max((int)$field['min'], $n); }
                    if (isset($field['max'])) { $n = min((int)$field['max'], $n); }
                    $config[$key] = $n;
                    break;

                case 'select':
                    $allowed = array_map('strval', array_is_list($field['options'] ?? []) ? ($field['options'] ?? []) : array_keys($field['options'] ?? []));
                    if (!is_scalar($raw) || !in_array((string)$raw, $allowed, true)) {
                        $errors[$key] = 'Ongeldige keuze.';
                        break;
                    }
                    $config[$key] = (string)$raw;
                    break;

                case 'url':
                    $v = is_string($raw) ? trim($raw) : '';
                    $p = parse_url($v);
                    if ($v === '' || !is_array($p) || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host']) || strlen($v) > 2000) {
                        $errors[$key] = 'Geen geldige http(s)-URL.';
                        break;
                    }
                    $config[$key] = $v;
                    break;

                default: // string, textarea, code, video, onbekend → tekst
                    if (!is_string($raw)) {
                        $errors[$key] = 'Ongeldige invoer.';
                        break;
                    }
                    $max = $type === 'string' ? 500 : self::MAX_TEXT;
                    if (mb_strlen($raw) > $max) {
                        $errors[$key] = "Te lang (max {$max} tekens).";
                        break;
                    }
                    if ($type === 'string' && isset($field['pattern']) && trim($raw) !== '' && @preg_match((string)$field['pattern'], trim($raw)) !== 1) {
                        $errors[$key] = (string)($field['pattern_msg'] ?? 'Ongeldige waarde.');
                        break;
                    }
                    $config[$key] = $type === 'string' && isset($field['pattern']) ? trim($raw) : $raw;
            }
        }
        return ['config' => $config, 'errors' => $errors];
    }

    /** Schema in een vorm die de browser veilig kan tonen. @return list<array<string,mixed>> */
    public static function describe(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $field) {
            if (!is_array($field)) { continue; }
            $opts = [];
            foreach ((array)($field['options'] ?? []) as $k => $v) {
                $opts[] = ['value' => (string)(array_is_list((array)$field['options']) ? $v : $k), 'label' => (string)$v];
            }
            $out[] = [
                'key' => (string)$key, 'type' => (string)($field['type'] ?? 'string'),
                'label' => (string)($field['label'] ?? $key), 'required' => !empty($field['required']),
                'default' => $field['default'] ?? null, 'min' => $field['min'] ?? null, 'max' => $field['max'] ?? null,
                'options' => $opts, 'help' => (string)($field['help'] ?? ''),
            ];
        }
        return $out;
    }
}
