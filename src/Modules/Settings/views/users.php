<?php
declare(strict_types=1);
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later — zie _placeholder.php
$activeNav        = 'users';
$placeholderIcon  = '👥';
$placeholderTitle = 'Gebruikersbeheer';
$placeholderNote  = 'De REST API (GET /api/v1/users, permissie users.view) werkt al, maar er is nog geen '
    . 'admin-scherm om gebruikers te doorzoeken, te bannen of rollen toe te kennen — dat gaat nu alleen via directe database-toegang.';
include __DIR__ . '/_placeholder.php';
