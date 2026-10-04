<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

/**
 * Videoposter uit een data-URL (door de browser gemaakt frame; geen ffmpeg op de server nodig).
 * Alleen een geldige JPEG/PNG/WebP van redelijke omvang wordt geaccepteerd; de afmetingen en het
 * echte type komen uit getimagesizefromstring(), niet uit wat de client beweert.
 */
final class GalleryPoster
{
    private const EXT = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    /** @return array{bytes: string, ext: string}|null */
    public static function decode(string $dataUrl): ?array
    {
        if ($dataUrl === '' || strlen($dataUrl) > 2_000_000
            || preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m) !== 1) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::EXT[$info[2]]) || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096) {
            return null;
        }
        return ['bytes' => $bytes, 'ext' => self::EXT[$info[2]]];
    }

    /** Schrijf het poster naar $absDir/$basename.ext; geeft de bestandsnaam terug of null. */
    public static function save(string $dataUrl, string $absDir, string $basename): ?string
    {
        $p = self::decode($dataUrl);
        if ($p === null) {
            return null;
        }
        if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
            return null;
        }
        $name = $basename . '.' . $p['ext'];
        if (file_put_contents($absDir . '/' . $name, $p['bytes']) === false) {
            return null;
        }
        chmod($absDir . '/' . $name, 0644);
        return $name;
    }
}
