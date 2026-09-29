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

final class GuildController
{
    public function __construct(
        private readonly Connection  $db,
        private readonly AuthManager $auth,
    ) {}

    public function index(Request $request): Response
    {
        if (!$this->installed()) return $this->notInstalledResponse();

        $teams   = $this->db->fetchAll("SELECT * FROM cf_guild_teams ORDER BY type");
        $members = $this->db->fetchAll(
            "SELECT gm.*, gr.display_name as rank_name, gr.color as rank_color
             FROM cf_guild_members gm
             LEFT JOIN cf_guild_ranks gr ON gr.id = gm.rank_id
             WHERE gm.is_active = 1 ORDER BY gr.priority DESC LIMIT 20"
        );
        ob_start();
        include __DIR__ . '/../templates/index.php';
        return Response::html(ob_get_clean());
    }

    public function members(Request $request): Response
    {
        if (!$this->installed()) return $this->notInstalledResponse();

        $rankFilter = $request->query('rank');
        $sql = "SELECT gm.*, gr.display_name as rank_name, gr.color as rank_color
                FROM cf_guild_members gm
                LEFT JOIN cf_guild_ranks gr ON gr.id = gm.rank_id
                WHERE gm.is_active = 1";
        $bindings = [];
        if ($rankFilter) {
            $sql .= " AND gm.rank_id = ?";
            $bindings[] = (int)$rankFilter;
        }
        $sql .= " ORDER BY gr.priority DESC, gm.character_name ASC";
        $members = $this->db->fetchAll($sql, $bindings);
        $ranks   = $this->db->fetchAll("SELECT * FROM cf_guild_ranks ORDER BY priority DESC");
        ob_start();
        include __DIR__ . '/../templates/members.php';
        return Response::html(ob_get_clean());
    }

    public function roster(Request $request): Response
    {
        return Response::redirect('/guild/members');
    }

    public function applyForm(Request $request): Response
    {
        if (!$this->installed()) return $this->notInstalledResponse();

        $teams = $this->db->fetchAll("SELECT * FROM cf_guild_teams WHERE is_recruiting = 1 ORDER BY name");
        ob_start();
        include __DIR__ . '/../templates/apply.php';
        return Response::html(ob_get_clean());
    }

    public function apply(Request $request): Response
    {
        if (!$this->installed()) return $this->notInstalledResponse();

        CsrfProtection::validateRequest();

        $data = $request->all();
        $errors = [];

        if (empty(trim($data['character_name'] ?? ''))) $errors[] = 'Karakternaam is verplicht.';
        if (empty($data['class'] ?? ''))                 $errors[] = 'Klasse is verplicht.';
        if (empty($data['spec'] ?? ''))                  $errors[] = 'Specialisatie is verplicht.';
        if (strlen($data['about'] ?? '') < 20)           $errors[] = 'Vertel minimaal 20 tekens over jezelf.';

        if (!empty($errors)) {
            $teams = $this->db->fetchAll("SELECT * FROM cf_guild_teams WHERE is_recruiting = 1");
            ob_start();
            include __DIR__ . '/../templates/apply.php';
            return Response::html(ob_get_clean(), 422);
        }

        $this->db->insert('guild_applications', [
            'user_id'        => $this->auth->id(),
            'character_name' => trim($data['character_name']),
            'class'          => $data['class'],
            'spec'           => $data['spec'],
            'item_level'     => !empty($data['item_level']) ? (int)$data['item_level'] : null,
            'about'          => trim($data['about']),
            'experience'     => trim($data['experience'] ?? ''),
            'team_id'        => !empty($data['team_id']) ? (int)$data['team_id'] : null,
        ]);

        return Response::redirect('/guild/apply?success=1');
    }

    /**
     * De installer registreert een module wel in cf_modules (is_enabled=1)
     * maar roept bewust NOOIT install() aan — zie de uitleg in
     * installer/steps/Step5.php. Elke module hoort zich daarom netjes te
     * degraderen zolang z'n eigen tabellen nog niet zijn aangemaakt (pas
     * gebeurt via Marketplace-herinstallatie). Guild Management deed dat
     * nog niet en gaf hierdoor een kale 500 op elke /guild*-route direct
     * na installatie — gevonden tijdens de S13-inventarisatiepas.
     */
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
