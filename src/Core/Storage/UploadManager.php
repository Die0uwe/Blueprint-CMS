<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
//
// This work is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This work is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Storage;

/**
 * UploadManager
 *
 * Verwerkt gebruikersuploads veilig:
 *  - schrijft altijd naar storage/uploads/ — BUITEN de webroot (public/),
 *    conform SD v1.0 §7.1. Bestanden worden nooit direct door de webserver
 *    geserveerd; dat gaat via een controller-route (zie MediaController).
 *  - controleert het ECHTE MIME-type via fileinfo (finfo), niet de
 *    client-aangeleverde Content-Type header — die is triviaal te vervalsen.
 *  - genereert altijd een willekeurige bestandsnaam (nooit de originele
 *    clientnaam), zodat een upload nooit een pad-traversal of een
 *    uitvoerbaar bestand met een misleidende extensie kan worden.
 *
 * Vóór deze klasse bestond er geen enkele upload-functionaliteit in het
 * project: `featured_image`/`avatar_url` waren kolommen zonder schrijfpad.
 */
final class UploadManager
{
    /** @var array<string, string> Standaard MIME-type => toegestane extensie (afbeeldingen) */
    private const ALLOWED_IMAGES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * @var array<string, string> Ruimere whitelist voor het Downloads-bestandsbeheer
     * (naast afbeeldingen ook archieven/documenten — géén uitvoerbare bestandstypen).
     */
    private const ALLOWED_DOWNLOADS = self::ALLOWED_IMAGES + [
        'application/zip'              => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/pdf'              => 'pdf',
        'application/x-rar-compressed' => 'rar',
        'application/x-7z-compressed'  => '7z',
        'application/gzip'             => 'gz',
        'text/plain'                   => 'txt',
    ];

    /**
     * @var array<string, string> Whitelist voor de Media-galerij (S11):
     * dezelfde afbeeldingstypen als hierboven, plus de twee breed
     * ondersteunde web-videoformaten. Bewust GEEN andere videocodecs
     * (avi/mov/mkv) — die spelen niet overal af in een <video>-tag zonder
     * transcodering, wat buiten scope valt (zie GalleryThumbnailer.php).
     */
    private const ALLOWED_GALLERY = self::ALLOWED_IMAGES + [
        'video/mp4'  => 'mp4',
        'video/webm' => 'webm',
    ];

    /** @var array<string, string> */
    private readonly array $allowed;

    public function __construct(
        private readonly string $storagePath,
        private readonly int    $maxBytes = 5 * 1024 * 1024,
        /**
         * Alleen voor gebruik door tests: schakelt de is_uploaded_file()/
         * move_uploaded_file()-check uit, want die geven altijd false buiten
         * een echte HTTP multipart-request — een unit test kan die niet
         * nabootsen. Nooit true zetten buiten een testomgeving.
         */
        private readonly bool $testMode = false,
        /**
         * Optionele MIME-whitelist ter vervanging van de standaard
         * afbeeldingen-only lijst — gebruikt door de Downloads-module
         * (zie self::ALLOWED_DOWNLOADS). Nooit uitvoerbare/scriptbare
         * MIME-types toevoegen (php, html, js, svg+xml).
         *
         * @param array<string, string>|null $allowedMimeTypes
         */
        ?array $allowedMimeTypes = null,
    ) {
        $this->allowed = $allowedMimeTypes ?? self::ALLOWED_IMAGES;

        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0755, true) && !is_dir($this->storagePath)) {
            throw new \RuntimeException("Kan uploadmap niet aanmaken: {$this->storagePath}");
        }
    }

    /**
     * Fabrieksmethode voor de Downloads-module: dezelfde klasse, ruimere
     * (maar nog altijd whitelisted) bestandstypen dan de standaard
     * afbeeldingen-only instantie.
     */
    public static function forDownloads(string $storagePath, int $maxBytes, bool $testMode = false): self
    {
        return new self($storagePath, $maxBytes, $testMode, self::ALLOWED_DOWNLOADS);
    }

    /**
     * Fabrieksmethode voor de Media-galerij (S11): afbeeldingen + mp4/webm.
     */
    public static function forGallery(string $storagePath, int $maxBytes, bool $testMode = false): self
    {
        return new self($storagePath, $maxBytes, $testMode, self::ALLOWED_GALLERY);
    }

    /**
     * Verwerk één entry uit $_FILES (of het equivalente array-formaat).
     *
     * @param array  $file   Eén item uit $request->files (bv. $_FILES['avatar'])
     * @param string $subdir Submap binnen storage/uploads/, bv. 'avatars', 'news'
     * @return string        Het opgeslagen relatieve pad (bv. "avatars/ab12cd34ef56.webp"),
     *                       te bewaren in de database en door te geven aan MediaManager::url().
     *
     * @throws UploadException bij elke afwijzing — de message is veilig om
     *                         rechtstreeks aan de gebruiker te tonen.
     */
    public function store(array $file, string $subdir = ''): string
    {
        $this->assertNoUploadError($file);
        $this->assertWithinSizeLimit($file);

        $realMime = $this->detectRealMimeType($file['tmp_name']);
        if (!isset($this->allowed[$realMime])) {
            throw new UploadException(
                'Bestandstype niet toegestaan. Toegestaan: ' . implode(', ', array_unique($this->allowed)) . '.'
            );
        }

        if (!$this->testMode && !is_uploaded_file($file['tmp_name'])) {
            // Verplichte check: voorkomt dat willekeurige lokale bestanden
            // (via een geknoeide $_FILES-achtige array) als upload gelden.
            throw new UploadException('Ongeldige upload.');
        }

        $extension = $this->allowed[$realMime];
        $subdir    = trim(preg_replace('/[^a-z0-9_-]/', '', strtolower($subdir)), '/');
        $filename  = bin2hex(random_bytes(16)) . '.' . $extension;
        $relative  = ($subdir !== '' ? "{$subdir}/" : '') . $filename;
        $target    = $this->storagePath . '/' . $relative;

        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new UploadException('Kon doelmap niet aanmaken.');
        }

        $moved = $this->testMode
            ? copy($file['tmp_name'], $target)
            : move_uploaded_file($file['tmp_name'], $target);

        if (!$moved) {
            throw new UploadException('Opslaan van de upload is mislukt.');
        }

        chmod($target, 0644);

        return $relative;
    }

    /**
     * Verwijder een eerder opgeslagen bestand (bv. bij het vervangen van een
     * avatar). Faalt stil als het bestand al weg is.
     */
    public function delete(string $relativePath): void
    {
        $full = $this->resolve($relativePath);
        if ($full !== null && is_file($full)) {
            @unlink($full);
        }
    }

    /**
     * Los een opgeslagen relatief pad op naar een absoluut pad, met een
     * harde check dat het resultaat binnen storage/uploads/ blijft —
     * voorkomt path-traversal via een gemanipuleerd pad uit de database
     * of URL (bv. "../../config/config.php").
     */
    public function resolve(string $relativePath): ?string
    {
        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return null;
        }

        $full = realpath($this->storagePath . '/' . $relativePath);
        $base = realpath($this->storagePath);

        if ($full === false || $base === false || !str_starts_with($full, $base)) {
            return null;
        }

        return $full;
    }

    private function assertNoUploadError(array $file): void
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadException('Geen bestand geselecteerd.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadException(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Bestand is te groot (serverlimiet upload_max_filesize = '
                    . (ini_get('upload_max_filesize') ?: '?') . ', post_max_size = ' . (ini_get('post_max_size') ?: '?')
                    . '). Verhoog die in php.ini/.user.ini of kies een kleiner bestand.',
                UPLOAD_ERR_PARTIAL => 'Upload is niet volledig aangekomen — probeer opnieuw.',
                default             => 'Upload mislukt.',
            });
        }
    }

    private function assertWithinSizeLimit(array $file): void
    {
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $this->maxBytes) {
            $maxMb = number_format($this->maxBytes / 1024 / 1024, 1);
            throw new UploadException("Bestand moet tussen 0 en {$maxMb}MB zijn.");
        }
    }

    private function detectRealMimeType(string $tmpPath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new UploadException('Kon bestandstype niet controleren.');
        }

        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║                         FILE CARD                                    ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  File         : UploadManager.php                                    ║
// ║  Role         : Core                                                 ║
// ║  Version      : 1.0.0                                                ║
// ║  Created      : 2026-09-28                                           ║
// ║  Status       : New — Wave 0 gap-fix (geen upload-functionaliteit)   ║
// ║  Notes        : MIME-whitelist via finfo, opslag buiten webroot      ║
// ╠══════════════════════════════════════════════════════════════════════╣
// ║  Created by Dieouwe                                                  ║
// ║  🌐 www.dieouwe.nl          ⚔️  www.slayeralliance.com              ║
// ║  📦 curseforge.com/members/dieouwe/projects                         ║
// ║  💬 discord.gg/y8Pu5qsEbQ                                           ║
// ╚══════════════════════════════════════════════════════════════════════╝
