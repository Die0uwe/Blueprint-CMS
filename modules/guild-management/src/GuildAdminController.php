<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Modules\Guild;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Database\Connection;
use CommunityFusion\Core\Auth\AuthManager;
use CommunityFusion\Core\Security\CsrfProtection;

final class GuildAdminController
{
    public function __construct(
        private readonly Connection  $db,
        private readonly AuthManager $auth,
    ) {}

    public function index(Request $request): Response
    {
        if (!$this->installed()) return $this->notInstalledResponse();

        $applications = $this->db->fetchAll(
            "SELECT ga.*, gt.name as team_name
             FROM cf_guild_applications ga
             LEFT JOIN cf_guild_teams gt ON gt.id = ga.team_id
             ORDER BY FIELD(ga.status,'pending','approved','rejected'), ga.created_at DESC"
        );
        $members = $this->db->fetchAll(
            "SELECT gm.*, gr.display_name as rank_name, gr.color as rank_color
             FROM cf_guild_members gm
             LEFT JOIN cf_guild_ranks gr ON gr.id = gm.rank_id
             WHERE gm.is_active = 1 ORDER BY gr.priority DESC, gm.character_name"
        );
        $ranks = $this->db->fetchAll("SELECT * FROM cf_guild_ranks ORDER BY priority DESC");
        ob_start();
        include __DIR__ . '/../templates/admin.php';
        return Response::html(ob_get_clean());
    }

    public function approve(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id = (int)$request->param('id');
        $this->db->execute(
            "UPDATE cf_guild_applications SET status='approved', reviewed_by=?, review_note=? WHERE id=?",
            [$this->auth->id(), $request->input('note',''), $id]
        );
        // Voeg toe als guild lid
        $app = $this->db->fetchOne("SELECT * FROM cf_guild_applications WHERE id = ?", [$id]);
        if ($app) {
            $trialRank = $this->db->fetchOne("SELECT id FROM cf_guild_ranks WHERE name='trial'");
            $this->db->execute(
                "INSERT IGNORE INTO cf_guild_members (user_id, rank_id, character_name, class, spec, item_level)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$app['user_id'], $trialRank['id'] ?? null, $app['character_name'],
                 $app['class'], $app['spec'], $app['item_level']]
            );
        }
        return $request->isAjax()
            ? Response::json(['success' => true])
            : Response::redirect('/admin/guild');
    }

    public function reject(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $id = (int)$request->param('id');
        $this->db->execute(
            "UPDATE cf_guild_applications SET status='rejected', reviewed_by=?, review_note=? WHERE id=?",
            [$this->auth->id(), $request->input('note',''), $id]
        );
        return $request->isAjax()
            ? Response::json(['success' => true])
            : Response::redirect('/admin/guild');
    }

    /** Zie de uitleg bij GuildController::installed(). */
    private function installed(): bool
    {
        try {
            $this->db->fetchOne("SELECT 1 FROM cf_guild_teams LIMIT 1");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function notInstalledResponse(): Response
    {
        return Response::html(
            '<h1>Guild-module nog niet geïnstalleerd</h1>' .
            '<p>Deze module is ingeschakeld maar moet nog eenmalig geïnstalleerd worden ' .
            '(tabellen aanmaken) via <a href="/admin/marketplace">/admin/marketplace</a>.</p>',
            503
        );
    }
}
