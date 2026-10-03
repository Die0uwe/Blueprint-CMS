<?php

// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
//
// Route-definities van de AI Studio-module. Declaratief, zodat AiStudioModule
// ze kan registreren en een test kan afdwingen dat ELKE POST-route Auth +
// PermissionMiddleware + RateLimitMiddleware heeft (CSRF zit in de controllers,
// zie CsrfGuard).

declare(strict_types=1);

$auth = 'CommunityFusion\Api\Middleware\AuthMiddleware';
$rate = 'CommunityFusion\Api\Middleware\RateLimitMiddleware';
$perm = static fn (string $permission): string => 'CommunityFusion\Api\Middleware\PermissionMiddleware:' . $permission;

$ns = 'CommunityFusion\Modules\AiStudio\\';
$studio = $ns . 'AiStudioController@';
$chat = $ns . 'ChatController@';

return [
    // ── Pagina's en data (GET) ───────────────────────────────────────────
    ['GET',  '/admin/ai-studio',                                 $studio . 'index',        [$auth, $perm('aistudio.use')]],
    ['GET',  '/admin/ai-studio/settings',                        $studio . 'settings',     [$auth, $perm('aistudio.admin')]],
    ['GET',  '/admin/ai-studio/conversation/{id:[0-9]+}',        $studio . 'conversation', [$auth, $perm('aistudio.use')]],
    ['GET',  '/admin/ai-studio/assets/{file:[a-z0-9._-]+}',      $studio . 'asset',        [$auth, $perm('aistudio.use')]],

    // ── Schrijvende routes (POST): Auth + Permission + RateLimit (+ CSRF in de controller) ──
    ['POST', '/admin/ai-studio/settings',                        $studio . 'saveSettings',        [$auth, $perm('aistudio.admin'), $rate]],
    ['POST', '/admin/ai-studio/conversation/new',                $studio . 'newConversation',     [$auth, $perm('aistudio.use'), $rate]],
    ['POST', '/admin/ai-studio/conversation/delete',             $studio . 'deleteConversation',  [$auth, $perm('aistudio.use'), $rate]],
    ['POST', '/admin/ai-studio/chat',                            $chat . 'chat',                  [$auth, $perm('aistudio.use'), $rate]],
    ['POST', '/admin/ai-studio/apply-diff',                      $chat . 'applyDiff',             [$auth, $perm('aistudio.use'), $rate]],
];
