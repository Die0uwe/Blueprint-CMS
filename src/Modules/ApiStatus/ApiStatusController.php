<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\ApiStatus;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;
use CommunityFusion\Core\Security\CsrfProtection;

/**
 * /admin/api-status — overzicht van alle externe koppelingen met live-check
 * en aan/uit-schakelaar. Permissie: settings.edit (hier staan de sleutels).
 */
final class ApiStatusController
{
    public function __construct(private readonly ApiStatusService $service) {}

    public function index(Request $request): Response
    {
        $items = $this->service->overview();

        ob_start();
        include __DIR__ . '/views/index.php';
        return Response::html(ob_get_clean());
    }

    /** POST /admin/api-status/test  { slug } */
    public function test(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $slug = (string) $request->input('slug', '');
        if (!in_array($slug, ApiStatusService::slugs(), true)) {
            return Response::json(['error' => 'Onbekende koppeling'], 404);
        }
        return Response::json($this->service->test($slug));
    }

    /** POST /admin/api-status/toggle  { slug, enable } */
    public function toggle(Request $request): Response
    {
        CsrfProtection::validateRequest();
        $slug = (string) $request->input('slug', '');
        $raw  = $request->input('enable', false);
        $enable = $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';

        if (!$this->service->setEnabled($slug, $enable)) {
            return Response::json(['error' => 'Onbekende koppeling'], 404);
        }
        return Response::json(['success' => true, 'item' => $this->service->overview()[$slug]]);
    }
}
