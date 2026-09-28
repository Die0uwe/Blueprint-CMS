<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Menus;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Pages\PageRepository;

/**
 * /admin/menus — Sitenavigatie (Wave 5).
 * Bouwt op cf_pages.menu_position (bestond al sinds Sprint 2, alleen
 * bewerkbaar als los getalveld per pagina). Dit scherm geeft er knoppen
 * op: toevoegen/verwijderen uit het menu, omhoog/omlaag herordenen.
 * Permissie: menus.manage.
 */
final class MenuAdminController
{
    public function __construct(private readonly PageRepository $pages) {}

    public function index(Request $request): Response
    {
        $all       = $this->pages->getPublishedPagesForMenuScreen();
        $menuItems = array_values(array_filter($all, fn($p) => $p['menu_position'] !== null));
        $otherPages = array_values(array_filter($all, fn($p) => $p['menu_position'] === null));
        $error     = $request->query('error');

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }

    public function add(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->pages->addToMenu((int) $request->param('id'));
        return Response::redirect('/admin/menus');
    }

    public function remove(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->pages->removeFromMenu((int) $request->param('id'));
        return Response::redirect('/admin/menus');
    }

    public function moveUp(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->pages->swapMenuPosition((int) $request->param('id'), -1);
        return Response::redirect('/admin/menus');
    }

    public function moveDown(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $this->pages->swapMenuPosition((int) $request->param('id'), 1);
        return Response::redirect('/admin/menus');
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: MenuAdminController.php | Role: Core | Version: 1.0.0         ║
// ║  Created: 2026-09-29 — Wave 5 (admin/menus)                          ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
