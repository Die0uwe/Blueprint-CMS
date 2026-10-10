<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\System;

use CommunityFusion\Core\Audit\AuditLogger;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Database\Migrator;
use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;
use CommunityFusion\Modules\Backup\BackupService;

/**
 * /admin/database — openstaande database-migraties uitvoeren vanuit de browser.
 *
 * Voor hosting zonder SSH (Strato): vervangt `php cli/console.php migrate` en het
 * plakken van SQL in phpMyAdmin. Vooraf wordt (indien mogelijk) een back-up gemaakt.
 * Permissie system.update (alleen super_admin via het '*'-wildcard).
 */
final class DatabaseUpdateController
{
    public function __construct(
        private readonly Connection    $db,
        private readonly BackupService $backups,
        private readonly AuthManager   $auth,
        private readonly AuditLogger   $audit,
    ) {}

    private function migrator(): Migrator
    {
        return new Migrator($this->db->getPdo(), CF_ROOT . '/database/migrations', $this->db->getPrefix());
    }

    /** GET /admin/database */
    public function index(Request $request): Response
    {
        $rows  = $this->migrator()->status();
        $pending = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
        $flash = (string) $request->query('ok', '');
        $error = (string) $request->query('error', '');
        $count = (int) $request->query('n', 0);
        $warn  = (string) $request->query('w', '');

        ob_start();
        include __DIR__ . '/views/admin_database.php';
        return Response::html(ob_get_clean());
    }

    /** POST /admin/database/bijwerken */
    public function run(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $warn = '';
        $migrator = $this->migrator();
        if ($migrator->pending() === []) {
            return Response::redirect('/admin/database?ok=actueel');
        }
        try {
            $this->backups->create('manual');
        } catch (\Throwable $e) {
            $warn = 'Geen back-up vooraf kunnen maken: ' . mb_substr($e->getMessage(), 0, 160);
        }
        try {
            // Ontbrekende basistabellen aanvullen als de installer nog aanwezig is (idempotent), net als de CLI.
            $installer = CF_ROOT . '/installer/InstallerCore.php';
            if (is_file($installer)) {
                if (!class_exists('InstallerCore', false)) {
                    require_once $installer;
                }
                \InstallerCore::importSchema($this->db->getPdo());
            }
            $ran = $migrator->run();
        } catch (\Throwable $e) {
            try {
                $this->audit->log('database.migrate_failed', $this->auth->id(), $this->auth->user()['username'] ?? null, ['error' => mb_substr($e->getMessage(), 0, 200)]);
            } catch (\Throwable) {}
            return Response::redirect('/admin/database?error=' . urlencode('Migratie mislukt: ' . $e->getMessage()));
        }
        try {
            $this->audit->log('database.migrate', $this->auth->id(), $this->auth->user()['username'] ?? null, ['ran' => $ran]);
        } catch (\Throwable) {}
        $q = '/admin/database?ok=bijgewerkt&n=' . count($ran);
        return Response::redirect($warn !== '' ? $q . '&w=' . urlencode($warn) : $q);
    }
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: DatabaseUpdateController.php | Role: Core | Version: 1.0.0   ║
// ║  Created: 2026-10-10 | Status: New — migraties via de browser       ║
// ╚══════════════════════════════════════════════════════════════════════╝
