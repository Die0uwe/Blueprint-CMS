<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Security\CsrfProtection;

/** Login formulier / welkom-bericht als ingelogd */
final class LoginBlock extends AbstractBlock
{
    public function getSlug(): string { return 'login'; }
    public function getName(): string { return 'Login Blok'; }
    public function getConfigSchema(): array { return []; }

    public function render(array $config, array $context = []): string
    {
        $user = $context['user'] ?? null;

        if ($user) {
            $name = htmlspecialchars($user['display_name'] ?? $user['username']);
            return <<<HTML
            <div class="cf-block-login cf-block-login--logged-in">
                <div class="cf-block-login-avatar">👤</div>
                <div>
                    <strong>{$name}</strong>
                    <div class="cf-block-login-links">
                        <a href="/profiel">Profiel</a> ·
                        <a href="/admin">Admin</a> ·
                        <a href="/logout">Uitloggen</a>
                    </div>
                </div>
            </div>
            HTML;
        }

        $csrf = CsrfProtection::field();
        // Golf 10: compacte OAuth-iconenrij onder het formulier — zelfde vier
        // providers als de volledige /login-pagina (zie themes/*/templates/
        // auth/login.twig), maar zonder tekst omdat dit blok meestal in een
        // smalle sidebar staat.
        return <<<HTML
        <form class="cf-block-login" method="POST" action="/login">
            {$csrf}
            <input class="cf-input" type="text" name="identifier" placeholder="Gebruikersnaam / e-mail">
            <input class="cf-input" type="password" name="password" placeholder="Wachtwoord">
            <button type="submit" class="cf-btn" style="width:100%;justify-content:center;margin-top:.5rem;">Inloggen</button>
        </form>
        <div class="cf-block-login-oauth" style="display:flex;gap:.4rem;justify-content:center;margin-top:.6rem;">
            <a href="/auth/discord/login"   title="Inloggen met Discord"   style="background:#5865F2;color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;text-decoration:none;">🎮</a>
            <a href="/auth/twitch/login"    title="Inloggen met Twitch"    style="background:#9146FF;color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;text-decoration:none;">📺</a>
            <a href="/auth/google/login"    title="Inloggen met Google"    style="background:#fff;color:#1f1f1f;border:1px solid var(--border);width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;text-decoration:none;">🔑</a>
            <a href="/auth/battlenet/login" title="Inloggen met Battle.net" style="background:#148eff;color:#fff;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;text-decoration:none;">🌀</a>
        </div>
        <a href="/register" class="cf-block-login-register">Nog geen account? Registreer hier</a>
        HTML;
    }

    public function getCacheTtl(): int { return 0; } // Nooit cachen — user-afhankelijk
}
