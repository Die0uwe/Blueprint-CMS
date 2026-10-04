<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);

namespace CommunityFusion\Core\Block;

/**
 * Zet ruwe formulierinvoer om naar een schone blok-config volgens het schema
 * uit BlockInterface::getConfigSchema().
 *
 * Alleen sleutels uit het schema worden overgenomen (geen willekeurige keys in
 * de config-JSON), waarden worden naar het juiste type gebracht en een
 * ontbrekende/uitgevinkte boolean wordt false. Ontbrekende velden krijgen hun
 * 'default' als die bestaat.
 */
final class BlockConfigNormalizer
{
    /**
     * Standaardconfig volgens het schema (voor een nieuw blok zonder invoer).
     *
     * @param array<string, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    public static function defaults(array $schema): array
    {
        $out = [];
        foreach ($schema as $key => $field) {
            if (array_key_exists('default', $field)) {
                $out[$key] = $field['default'];
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $schema
     * @param array<string, mixed>                $input
     * @return array<string, mixed>
     */
    public static function normalize(array $schema, array $input): array
    {
        $out = [];
        foreach ($schema as $key => $field) {
            $type = (string) ($field['type'] ?? 'string');
            $has  = array_key_exists($key, $input);
            $raw  = $has ? $input[$key] : null;

            switch ($type) {
                case 'boolean':
                    $out[$key] = $has
                        ? in_array($raw, [true, 1, '1', 'on', 'true'], true)
                        : false;
                    break;

                case 'integer':
                    if (!$has || $raw === '' || $raw === null) {
                        if (array_key_exists('default', $field)) {
                            $out[$key] = (int) $field['default'];
                        }
                        break;
                    }
                    $out[$key] = (int) $raw;
                    break;

                case 'number':
                    if (!$has || $raw === '' || $raw === null) {
                        if (array_key_exists('default', $field)) {
                            $out[$key] = (float) $field['default'];
                        }
                        break;
                    }
                    $out[$key] = (float) $raw;
                    break;

                case 'select':
                    $options = array_map('strval', (array) ($field['options'] ?? []));
                    $value   = is_scalar($raw) ? (string) $raw : '';
                    if (in_array($value, $options, true)) {
                        $out[$key] = $value;
                    } elseif (array_key_exists('default', $field)) {
                        $out[$key] = $field['default'];
                    }
                    break;

                default: // string, textarea, code, richtext, …
                    if (!$has || !is_scalar($raw)) {
                        if (array_key_exists('default', $field)) {
                            $out[$key] = $field['default'];
                        }
                        break;
                    }
                    $value = (string) $raw;
                    // Platte velden: trimmen. Tekst-/code-velden: ongemoeid laten.
                    if ($type === 'richtext') {
                        // Editor-HTML: bij opslaan door de whitelist-sanitizer.
                        $out[$key] = \CommunityFusion\Core\Security\ContentSanitizer::cleanForStorage($value);
                    } else {
                        $out[$key] = in_array($type, ['textarea', 'code'], true) ? $value : trim($value);
                    }
            }
        }
        return $out;
    }
}
