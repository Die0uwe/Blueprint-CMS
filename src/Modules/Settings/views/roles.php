<?php
declare(strict_types=1);
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later — zie _placeholder.php
$activeNav        = 'roles';
$placeholderIcon  = '🔑';
$placeholderTitle = 'Rollenbeheer';
$placeholderNote  = 'RBAC zelf draait volledig (cf_roles/cf_permissions/cf_role_permissions, AuthManager::can()), '
    . 'maar rollen en rechten toewijzen kan nu alleen via SQL — er is nog geen admin-UI voor.';
include __DIR__ . '/_placeholder.php';
