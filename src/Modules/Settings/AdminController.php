<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

declare(strict_types=1);

namespace CommunityFusion\Modules\Settings;

use CommunityFusion\Core\Request;
use CommunityFusion\Core\Response;

final class AdminController
{
    public function dashboard(Request $request): Response
    {
        ob_start();
        include __DIR__ . '/views/dashboard.php';
        return Response::html(ob_get_clean());
    }

    public function settings(Request $request): Response
    {
        ob_start();
        include __DIR__ . '/views/settings.php';
        return Response::html(ob_get_clean());
    }

    // This class used to declare handle() twice — a fatal "Cannot redeclare"
    // error that meant the class could never even be loaded, so EVERY /admin
    // route (dashboard, settings, this one included) was completely broken.
    // The two versions also weren't equivalent: the first took $path straight
    // into the include path with no sanitization at all (a path-traversal /
    // local-file-inclusion hole — e.g. path=../../../../etc/passwd), while
    // this second one whitelists to [a-z0-9/-] first. Kept the sanitized one.
    public function handle(Request $request): Response
    {
        $path = $request->param('path', '');
        $view = __DIR__ . '/views/' . preg_replace('/[^a-z0-9\/\-]/', '', $path) . '.php';

        if (!file_exists($view)) {
            return Response::redirect('/admin');
        }

        ob_start();
        include $view;
        return Response::html(ob_get_clean());
    }
}
