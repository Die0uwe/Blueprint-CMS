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

namespace CommunityFusion\Modules\Media;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Storage\UploadManager;

/**
 * MediaController — serveert bestanden uit storage/uploads/ (buiten webroot).
 *
 * Route: GET /media/{path}
 *
 * Bestaat omdat uploads bewust NIET in public/ staan (SD v1.0 §7.1: "opslag
 * buiten webroot"). UploadManager::resolve() garandeert dat het opgevraagde
 * pad nooit buiten storage/uploads/ uitkomt (path-traversal-bescherming).
 */
final class MediaController
{
    /** @var array<string, string> extensie => Content-Type */
    private const CONTENT_TYPES = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        // S11 (Media-galerij): video-items uit storage/uploads/gallery/
        // lopen via deze zelfde /media/{path}-route (resolve() checkt alleen
        // padcontainment, geen MIME-whitelist — zie UploadManager::resolve()),
        // dus de twee door UploadManager::forGallery() toegestane
        // videoformaten moeten ook hier een Content-Type krijgen, anders
        // serveert dit ze als generieke download i.p.v. inline af te spelen
        // in een <video>-tag.
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
    ];

    public function __construct(
        private readonly UploadManager $uploads,
    ) {}

    /** Max. bytes per 206-antwoord; browsers vragen de rest vanzelf bij ("bytes=N-"). */
    private const MAX_RANGE_BYTES = 8 * 1024 * 1024;

    public function show(Request $request): Response
    {
        $path = $request->param('path', '');
        $full = $this->uploads->resolve($path);

        if ($full === null || !is_file($full)) {
            return new Response('Bestand niet gevonden.', 404, ['Content-Type' => 'text/plain']);
        }

        $extension   = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $contentType = self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';
        $size        = (int) filesize($full);

        $headers = [
            'Content-Type'  => $contentType,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=86400, immutable',
            // Bestandsnamen zijn willekeurige hex-tokens (zie UploadManager) —
            // veilig om langdurig te cachen, een nieuwe upload krijgt altijd
            // een nieuwe naam.
        ];

        $range = self::parseRange((string) $request->header('Range', ''), $size);
        if ($range === false) {
            return new Response('', 416, $headers + ['Content-Range' => 'bytes */' . $size]);
        }

        // Video heeft Range-ondersteuning nodig (spoelen, Safari/iOS spelen zonder 206 niet af)
        // en mag niet in zijn geheel in het geheugen: lees alleen het gevraagde stuk.
        if ($range !== null) {
            [$start, $end] = $range;
            $end = min($end, $start + self::MAX_RANGE_BYTES - 1);
            $body = self::readSlice($full, $start, $end - $start + 1);
            return new Response($body, 206, $headers + [
                'Content-Range'  => "bytes {$start}-{$end}/{$size}",
                'Content-Length' => (string) strlen($body),
            ]);
        }

        if ($size > self::MAX_RANGE_BYTES && str_starts_with($contentType, 'video/')) {
            // Zonder Range-header: eerste stuk als 206 (geldig; spelers vragen zelf door).
            $end  = self::MAX_RANGE_BYTES - 1;
            $body = self::readSlice($full, 0, $end + 1);
            return new Response($body, 206, $headers + [
                'Content-Range'  => "bytes 0-{$end}/{$size}",
                'Content-Length' => (string) strlen($body),
            ]);
        }

        return new Response((string) file_get_contents($full), 200, $headers + ['Content-Length' => (string) $size]);
    }

    /**
     * "bytes=a-b" | "bytes=a-" | "bytes=-n" → [start, end] (inclusief),
     * null = geen/onbruikbare Range-header (volledig antwoord), false = niet te voldoen (416).
     *
     * @return array{0:int,1:int}|false|null
     */
    public static function parseRange(string $header, int $size): array|false|null
    {
        if ($header === '' || $size <= 0 || preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $m) !== 1) {
            return null;
        }
        if ($m[1] === '' && $m[2] === '') {
            return null;
        }
        if ($m[1] === '') {                       // laatste n bytes
            $n = (int) $m[2];
            if ($n <= 0) {
                return false;
            }
            return [max(0, $size - $n), $size - 1];
        }
        $start = (int) $m[1];
        $end   = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start >= $size || $end < $start) {
            return false;
        }
        return [$start, $end];
    }

    private static function readSlice(string $file, int $offset, int $length): string
    {
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return '';
        }
        fseek($h, $offset);
        $data = (string) fread($h, max(0, $length));
        fclose($h);
        return $data;
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: MediaController.php | Role: Core | Version: 1.0.0            ║
// ║  Created: 2026-09-28 | Status: New — Wave 0 gap-fix                 ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
