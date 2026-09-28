<?php
declare(strict_types=1);
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later — zie _placeholder.php
$activeNav        = 'logs';
$placeholderIcon  = '📋';
$placeholderTitle = 'Logs';
$placeholderNote  = 'Er wordt al weggeschreven naar storage/logs/auth.log (AuthManager) en naar de PHP error-log '
    . '(Mailer bij een mislukte verzending), maar er is nog geen admin-scherm om die logbestanden in te zien zonder shell-toegang tot de server.';
include __DIR__ . '/_placeholder.php';
