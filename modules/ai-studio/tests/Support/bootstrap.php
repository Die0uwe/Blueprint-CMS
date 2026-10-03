<?php

// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Gedeelde testhulpen voor AI Studio. De tests-map heeft geen eigen PSR-4-mapping
// (vendor/ is gecommit en bevat geen autoload-dev), dus laden we de hulpen hier.

declare(strict_types=1);

require_once __DIR__ . '/../../../../vendor/autoload.php';
require_once __DIR__ . '/TestDb.php';
require_once __DIR__ . '/FakeTransport.php';
require_once __DIR__ . '/FakeAuth.php';
require_once __DIR__ . '/ArrayCache.php';

if (empty($_ENV['APP_KEY'])) {
    $_ENV['APP_KEY'] = 'base64:' . base64_encode(str_repeat('k', 32));
}

if (!defined('CF_ROOT')) {
    define('CF_ROOT', dirname(__DIR__, 4));
}
