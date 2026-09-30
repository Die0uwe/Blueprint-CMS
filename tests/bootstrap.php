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

// CF_ROOT wijst in tests naar een wegwerpmap, zodat PackageManager nooit echte modules/ raakt.
if (!defined('CF_ROOT')) {
    $cfRoot = sys_get_temp_dir() . '/cf-test-root-' . getmypid();
    foreach (['modules', 'themes', 'storage/marketplace/downloads'] as $sub) {
        if (!is_dir($cfRoot . '/' . $sub)) {
            mkdir($cfRoot . '/' . $sub, 0755, true);
        }
    }
    define('CF_ROOT', $cfRoot);
}

if (session_status() === PHP_SESSION_NONE) {
    // CLI heeft geen cookies/headers; we starten de sessie puur voor de
    // in-memory $_SESSION superglobal die de auth/CSRF-klassen gebruiken.
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
