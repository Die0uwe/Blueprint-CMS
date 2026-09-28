<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Core\Storage;

/**
 * Gegooid door UploadManager. De message is altijd veilig om rechtstreeks
 * aan de gebruiker te tonen (nooit interne paden of stack traces).
 */
final class UploadException extends \RuntimeException
{
}
