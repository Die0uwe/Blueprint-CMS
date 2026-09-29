<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Gallery;

/**
 * GalleryThumbnailer — genereert een miniatuur van een geüploade afbeelding.
 *
 * S11 (Media-galerij). Onderzocht en bevestigd: er bestaat in dit project
 * NERGENS al thumbnail-/beeldbewerkingscode om te hergebruiken — UploadManager
 * doet uitsluitend ruwe bestandsopslag (zie zijn klassecommentaar). Dit is dus
 * vanaf nul geschreven, met de kale `ext/gd` die de installer al sinds
 * Sprint 1 als serververeiste controleert (`extension_loaded('gd')` in
 * installer/steps/Step1.php) maar tot deze wave nooit daadwerkelijk gebruikte.
 *
 * BEWUSTE SCOPE-BEPERKING: alleen afbeeldingen krijgen een miniatuur. Voor
 * video zou een frame-thumbnail ffmpeg (of een vergelijkbare decoder) vereisen
 * — een externe procesafhankelijkheid die dit project nergens anders heeft en
 * die niet betrouwbaar te garanderen is op een gedeelde hostingomgeving.
 * Video-items tonen daarom een vaste play-icoon-placeholder i.p.v. een echte
 * miniatuur (zie gallery/album.twig en GalleryLatestBlock).
 */
final class GalleryThumbnailer
{
    public function __construct(private readonly int $maxDimension = 480) {}

    /**
     * Genereer een JPEG-miniatuur van $sourcePath naar $destPath (max
     * self::$maxDimension breed/hoog, beeldverhouding behouden).
     *
     * @return array{width:int,height:int}|null De afmetingen van het
     *         ORIGINEEL (voor cf_gallery_items.width/height), of null als
     *         $sourcePath geen door getimagesize() herkende afbeelding is.
     *         Geeft ook null terug (zonder foutmelding) als GD de opgegeven
     *         afbeeldingssoort niet ondersteunt op deze server (bv. geen
     *         WebP-ondersteuning gecompileerd) — de upload zelf blijft dan
     *         gewoon staan, alleen zonder miniatuur.
     */
    public function generate(string $sourcePath, string $destPath): ?array
    {
        $info = @getimagesize($sourcePath);
        if ($info === false) {
            return null;
        }

        [$width, $height, $type] = $info;
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_GIF  => @imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default        => false,
        };

        if ($source === false) {
            // Afmetingen konden wel gelezen worden, maar GD kan dit
            // specifieke formaat niet decoderen op deze server — geef de
            // afmetingen alsnog terug (nuttig voor de DB), maar sla geen
            // miniatuurbestand op.
            return ['width' => $width, 'height' => $height];
        }

        $ratio  = min(1.0, $this->maxDimension / max($width, $height));
        $thumbW = max(1, (int) round($width * $ratio));
        $thumbH = max(1, (int) round($height * $ratio));

        $thumb = imagecreatetruecolor($thumbW, $thumbH);
        // Witte ondergrond i.p.v. zwart — anders krijgt een PNG/GIF met
        // transparantie een lelijke zwarte rand zodra die als JPEG (geen
        // alphakanaal) wordt opgeslagen.
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbW, $thumbH, $width, $height);

        $destDir = dirname($destPath);
        if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            imagedestroy($thumb);
            imagedestroy($source);
            return ['width' => $width, 'height' => $height];
        }

        imagejpeg($thumb, $destPath, 82);
        chmod($destPath, 0644);

        imagedestroy($thumb);
        imagedestroy($source);

        return ['width' => $width, 'height' => $height];
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: GalleryThumbnailer.php | Role: Core | Version: 1.0.0         ║
// ║  Created: 2026-09-29 | Status: New — S11 (Media-galerij)            ║
// ╚══════════════════════════════════════════════════════════════════════╝
