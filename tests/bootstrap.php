<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

// Test bootstrap: laadt de Composer autoloader en zet een minimale sessie op
// zodat classes die $_SESSION aanspreken (CsrfProtection, OAuthClient, ...)
// niet crashen op "session not started" buiten een echte HTTP request.

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    // CLI heeft geen cookies/headers; we starten de sessie puur voor de
    // in-memory $_SESSION superglobal die de auth/CSRF-klassen gebruiken.
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
