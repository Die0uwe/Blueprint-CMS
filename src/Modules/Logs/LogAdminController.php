<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Logs;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Audit\AuditLogger;

/**
 * /admin/logs — Systeem-auditlog (Wave 5).
 * storage/logs/ bestond al sinds Sprint 1 maar was altijd leeg — niets
 * schreef er ooit iets naartoe behalve AuthManager::logFailedAttempt(),
 * en niets las het uit. Dit scherm toont cf_audit_log (nieuw, Wave 5):
 * geslaagde/mislukte logins + de belangrijkste admin-acties uit deze en
 * eerdere waves. Alleen-lezen. Permissie: logs.view.
 */
final class LogAdminController
{
    private const PER_PAGE = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        $page    = max(1, (int) $request->query('page', 1));
        $action  = trim((string) $request->query('action', ''));
        $offset  = ($page - 1) * self::PER_PAGE;

        $items   = $this->audit->getRecent(self::PER_PAGE, $offset, $action ?: null);
        $total   = $this->audit->count($action ?: null);
        $pages   = max(1, (int) ceil($total / self::PER_PAGE));
        $actions = $this->audit->distinctActions();

        ob_start();
        include __DIR__ . '/views/admin_index.php';
        return Response::html(ob_get_clean());
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: LogAdminController.php | Role: Core | Version: 1.0.0          ║
// ║  Created: 2026-09-29 — Wave 5 (admin/logs)                           ║
// ║  Created by Dieouwe — www.dieouwe.nl | discord.gg/y8Pu5qsEbQ         ║
// ╚══════════════════════════════════════════════════════════════════════╝
