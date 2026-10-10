<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

/**
 * GalleryTaxonomy — gestandaardiseerde galerij-indeling (concept
 * "Gallery Taxonomie & Structuur", Scriptbase & Blueprint CMS).
 *
 *  1. Maximaal 5 hoofdcategorieën (= top-level albums, parent_id NULL).
 *  2. Stijl-tags per hoofdcategorie i.p.v. extra subcategorieën.
 *  3. Bestandsnaam-conventie [categorie]_[stijl]_[onderwerp]_[nn].ext
 *  4. Subalbums via parent_id (één niveau diep).
 *
 * Pure, statische hulpklasse zonder database- of request-afhankelijkheid,
 * zodat migratie, repository, controller en tests dezelfde regels delen.
 */
final class GalleryTaxonomy
{
    /**
     * slug => [name, description, position, styles(slug => label)]
     *
     * @var array<string, array{name:string, description:string, position:int, styles:array<string,string>}>
     */
    public const MAIN = [
        '3d-art' => [
            'name' => '3D-Art', 'position' => 10,
            'description' => '3D-renders, avatars en isometrische scènes.',
            'styles' => [
                'chibi' => 'Chibi', 'pixar' => 'Pixar', 'isometric' => 'Isometric', 'low-poly' => 'Low-Poly',
                'voxel' => 'Voxel', 'claymation' => 'Claymation', 'unreal-engine' => 'Unreal-Engine',
            ],
        ],
        'digital-paintings' => [
            'name' => 'Digital-Paintings', 'position' => 20,
            'description' => 'Concept art, olieverf, aquarel en fantasy art.',
            'styles' => [
                'fantasy' => 'Fantasy', 'cyberpunk' => 'Cyberpunk', 'steampunk' => 'Steampunk', 'anime' => 'Anime',
                'oil-painting' => 'Oil-Painting', 'watercolor' => 'Watercolor', 'concept-art' => 'Concept-Art',
            ],
        ],
        'illustrations' => [
            'name' => 'Illustrations', 'position' => 30,
            'description' => 'Cartoons, vector, line art en comics.',
            'styles' => [
                'flat-design' => 'Flat-Design', 'line-art' => 'Line-Art', 'comic' => 'Comic',
                'vector' => 'Vector', 'retro' => 'Retro', 'doodle' => 'Doodle',
            ],
        ],
        'photorealistic' => [
            'name' => 'Photorealistic', 'position' => 40,
            'description' => 'Foto-stijl: landschappen, portretten en stadsbeeld.',
            'styles' => [
                'cinematic' => 'Cinematic', 'macro' => 'Macro', 'portrait' => 'Portrait', 'landscape' => 'Landscape',
                'urban' => 'Urban', 'studio' => 'Studio', 'hdr' => 'HDR',
            ],
        ],
        'ui-graphics' => [
            'name' => 'UI-Graphics', 'position' => 50,
            'description' => 'Logo\'s, banners, iconen en website-elementen.',
            'styles' => [
                'ui-icons' => 'UI-Icons', 'logo' => 'Logo', 'banner' => 'Banner', 'texture' => 'Texture', 'hud' => 'HUD',
            ],
        ],
    ];

    /** Maximaal aantal tags per item en maximale lengte per tag. */
    public const MAX_TAGS    = 12;
    public const MAX_TAG_LEN = 40;

    /** Maximaal aantal hoofdcategorieën (concept: "maximaal 5"). */
    public const MAX_MAIN = 5;

    /** Normaliseert een naam voor duplicaat-detectie: "3D Art", "3d-art" en "3D_ART" zijn gelijk. */
    public static function normalizeName(string $name): string
    {
        $n = self::fold($name);
        return preg_replace('/[^a-z0-9]+/', '', $n) ?? '';
    }

    /** URL-/bestandsnaam-veilige slug ("Orc Warrior!" => "orc-warrior"). */
    public static function slugify(string $text): string
    {
        $s = preg_replace('/[^a-z0-9]+/', '-', self::fold($text)) ?? '';
        return trim($s, '-');
    }

    /**
     * Staat deze slug in de toegestane stijl-lijst van de (hoofd)categorie?
     * Onbekende categorieën (eigen albums) hebben geen vaste lijst: daar is
     * elke geldige slug toegestaan.
     */
    public static function isAllowedStyle(string $categorySlug, string $style): bool
    {
        $style = self::slugify($style);
        if ($style === '') {
            return true;
        }
        if (!isset(self::MAIN[$categorySlug])) {
            return true;
        }
        return isset(self::MAIN[$categorySlug]['styles'][$style]);
    }

    /** @return array<string,string> slug => label (leeg voor eigen albums) */
    public static function stylesFor(string $categorySlug): array
    {
        return self::MAIN[$categorySlug]['styles'] ?? [];
    }

    /**
     * Tags uit vrije invoer ("orc, Avatar;character green") naar een
     * ontdubbelde lijst slugs, begrensd op MAX_TAGS/MAX_TAG_LEN.
     *
     * @return string[]
     */
    public static function parseTags(string $input): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $input) ?: [] as $raw) {
            $tag = mb_substr(self::slugify($raw), 0, self::MAX_TAG_LEN);
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
            if (count($out) >= self::MAX_TAGS) {
                break;
            }
        }
        return $out;
    }

    /** Opslagvorm in de database: ",orc,avatar," zodat LIKE '%,orc,%' exact op één tag matcht. */
    public static function packTags(array $tags): ?string
    {
        $tags = array_values(array_filter(array_map('strval', $tags), static fn(string $t) => $t !== ''));
        return $tags === [] ? null : ',' . implode(',', $tags) . ',';
    }

    /** @return string[] */
    public static function unpackTags(?string $packed): array
    {
        if ($packed === null || $packed === '') {
            return [];
        }
        return array_values(array_filter(explode(',', $packed), static fn(string $t) => $t !== ''));
    }

    /**
     * Bestandsnaam volgens de conventie [categorie]_[stijl]_[onderwerp]_[nn].ext,
     * bv. "3d_pixar_orc-warrior_01.jpg". Ontbrekende delen worden overgeslagen;
     * de categorie wordt ingekort tot het eerste woord ("3d-art" => "3d").
     */
    public static function conventionalFilename(
        string $categorySlug,
        string $style,
        string $subject,
        int $sequence,
        string $extension,
    ): string {
        $cat   = explode('-', self::slugify($categorySlug))[0] ?? '';
        $parts = array_values(array_filter([
            $cat,
            self::slugify($style),
            self::slugify($subject),
            str_pad((string) max(1, $sequence), 2, '0', STR_PAD_LEFT),
        ], static fn(string $p) => $p !== ''));

        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');
        return implode('_', $parts) . ($ext !== '' ? '.' . $ext : '');
    }

    private static function fold(string $text): string
    {
        $t = mb_strtolower(trim($text));
        // Accenten wegvouwen (é => e) zodat "Café" en "Cafe" als duplicaat gelden.
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) : false;
        return $ascii !== false && $ascii !== '' ? strtolower($ascii) : $t;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryTaxonomy.php | Role: Core | Version: 1.0.0            ║
// ║  Created: 2026-10-10 | Status: New — Galerij-taxonomie              ║
// ╚══════════════════════════════════════════════════════════════════════╝
