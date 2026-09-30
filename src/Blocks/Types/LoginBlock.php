<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Auth\OAuth\ProviderRegistry;
use CommunityFusion\Core\Security\CsrfProtection;

/** Login formulier / welkom-bericht als ingelogd */
final class LoginBlock extends AbstractBlock
{
    public function __construct(private readonly ?ProviderRegistry $providers = null) {}

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
        // Compacte OAuth-iconenrij onder het formulier (het blok staat meestal
        // in een smalle sidebar, dus zonder tekst). Alleen providers die aan +
        // geconfigureerd zijn (ProviderRegistry) — geen dode knoppen.
        $oauthRow = '';
        foreach ($this->providers?->available() ?? [] as $p) {
            $url   = htmlspecialchars($p['login_url'], ENT_QUOTES);
            $title = htmlspecialchars('Inloggen met ' . $p['label'], ENT_QUOTES);
            $bg    = htmlspecialchars($p['color'], ENT_QUOTES);
            $fg    = htmlspecialchars($p['text_color'], ENT_QUOTES);
            $icon  = htmlspecialchars($p['icon'], ENT_QUOTES);
            $oauthRow .= "<a href=\"{$url}\" title=\"{$title}\" style=\"background:{$bg};color:{$fg};border:1px solid var(--border);width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;text-decoration:none;\">{$icon}</a>";
        }
        if ($oauthRow !== '') {
            $oauthRow = '<div class="cf-block-login-oauth" style="display:flex;gap:.4rem;justify-content:center;margin-top:.6rem;">' . $oauthRow . '</div>';
        }

        return <<<HTML
        <form class="cf-block-login" method="POST" action="/login">
            {$csrf}
            <input class="cf-input" type="text" name="identifier" placeholder="Gebruikersnaam / e-mail">
            <input class="cf-input" type="password" name="password" placeholder="Wachtwoord">
            <button type="submit" class="cf-btn" style="width:100%;justify-content:center;margin-top:.5rem;">Inloggen</button>
        </form>
        {$oauthRow}
        <a href="/register" class="cf-block-login-register">Nog geen account? Registreer hier</a>
        HTML;
    }

    public function getCacheTtl(): int { return 0; } // Nooit cachen — user-afhankelijk
}
