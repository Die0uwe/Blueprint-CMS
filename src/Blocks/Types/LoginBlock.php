<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
declare(strict_types=1);
namespace CommunityFusion\Blocks\Types;
use CommunityFusion\Blocks\AbstractBlock;
use CommunityFusion\Core\Application;
use CommunityFusion\Core\Auth\OAuth\OAuthProviders;
use CommunityFusion\Core\Security\CsrfProtection;

/** Login formulier / welkom-bericht als ingelogd */
final class LoginBlock extends AbstractBlock
{
    public function getSlug(): string { return 'login'; }
    public function getName(): string { return 'Login Blok'; }
    public function getConfigSchema(): array { return []; }

    /**
     * Alleen voor uitgelogde bezoekers: eenmaal ingelogd verdwijnt het hele blok
     * (de header toont dan al Admin + Uitloggen).
     */
    public function isVisibleFor(array $config, bool $loggedIn): bool { return !$loggedIn; }

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
        $oauth = $this->oauthButtons();

        return <<<HTML
        <form class="cf-block-login" method="POST" action="/login">
            {$csrf}
            <input class="cf-input" type="text" name="identifier" placeholder="Gebruikersnaam / e-mail">
            <input class="cf-input" type="password" name="password" placeholder="Wachtwoord">
            <button type="submit" class="cf-btn" style="width:100%;justify-content:center;margin-top:.5rem;">Inloggen</button>
        </form>
        {$oauth}
        <a href="/wachtwoord-vergeten" class="cf-block-login-register" style="display:block;margin-top:.4rem;">Wachtwoord vergeten?</a>
        <a href="/register" class="cf-block-login-register">Nog geen account? Registreer hier</a>
        HTML;
    }

    /**
     * Compacte rij "Inloggen met …"-knoppen (smalle sidebar): alleen providers die
     * aanstaan én ingesteld zijn, zodat er geen knop naar een dode link staat.
     */
    private function oauthButtons(): string
    {
        try {
            $providers = Application::getInstance()->make(OAuthProviders::class)->usable();
        } catch (\Throwable) {
            return '';
        }
        if ($providers === []) {
            return '';
        }

        $html = '<div class="cf-block-login-oauth" style="display:flex;gap:.4rem;justify-content:center;flex-wrap:wrap;margin-top:.6rem;">';
        foreach ($providers as $p) {
            $label = htmlspecialchars('Inloggen met ' . $p['label'], ENT_QUOTES);
            $style = 'background:' . $p['color'] . ';color:' . $p['text'] . ';min-width:32px;height:32px;padding:0 .6rem;'
                   . 'border-radius:8px;display:flex;align-items:center;justify-content:center;text-decoration:none;'
                   . 'font-size:.78rem;font-weight:600;' . ($p['slug'] === 'google' ? 'border:1px solid var(--border);' : '');
            $html .= '<a href="' . htmlspecialchars($p['login_url'], ENT_QUOTES) . '" title="' . $label . '" aria-label="' . $label
                   . '" data-provider="' . htmlspecialchars($p['slug'], ENT_QUOTES) . '" style="' . htmlspecialchars($style, ENT_QUOTES) . '">'
                   . htmlspecialchars($p['label']) . '</a>';
        }
        return $html . '</div>';
    }

    public function getCacheTtl(): int { return 0; } // Nooit cachen — user-afhankelijk
}
