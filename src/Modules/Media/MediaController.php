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
    ];

    public function __construct(
        private readonly UploadManager $uploads,
    ) {}

    public function show(Request $request): Response
    {
        $path = $request->param('path', '');
        $full = $this->uploads->resolve($path);

        if ($full === null || !is_file($full)) {
            return new Response('Bestand niet gevonden.', 404, ['Content-Type' => 'text/plain']);
        }

        $extension   = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $contentType = self::CONTENT_TYPES[$extension] ?? 'application/octet-stream';

        return new Response(file_get_contents($full), 200, [
            'Content-Type'  => $contentType,
            'Cache-Control' => 'public, max-age=86400, immutable',
            // Bestandsnamen zijn willekeurige hex-tokens (zie UploadManager) —
            // veilig om langdurig te cachen, een nieuwe upload krijgt altijd
            // een nieuwe naam.
        ]);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: MediaController.php | Role: Core | Version: 1.0.0            ║
// ║  Created: 2026-09-28 | Status: New — Wave 0 gap-fix                 ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ        ║
// ╚══════════════════════════════════════════════════════════════════════╝
