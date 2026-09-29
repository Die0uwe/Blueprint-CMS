<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $slug (string), $schema (array uit module.json 'settings'), $values (array
// huidige, ontsleutelde waarden uit cf_settings via SettingsRepository),
// $flash (bool) — vanuit ModuleSettingsController::edit().
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'marketplace';
$manifest  = json_decode((string) file_get_contents(CF_ROOT . "/modules/{$slug}/module.json"), true) ?? [];
$name      = $manifest['name'] ?? ucfirst($slug);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($name) ?> — Instellingen — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
.form-wrap { max-width: 720px; }
.cf-field-help { color: var(--text-dim); font-size: .78rem; margin: .35rem 0 0; }
.cf-provider-hint {
  background: rgba(108,61,244,.08); border: 1px solid rgba(108,61,244,.25);
  border-radius: 10px; padding: .9rem 1rem; margin-bottom: 1.5rem; font-size: .85rem; line-height: 1.55;
}
.cf-provider-hint code { background: rgba(255,255,255,.08); padding: .1rem .35rem; border-radius: 4px; }
.cf-provider-hint a { color: var(--accent); }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>⚙️ <?= htmlspecialchars($name) ?> — Instellingen</h1>
      <a href="/admin/marketplace?tab=installed" class="cf-btn-sm">← Terug naar modules</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($flash): ?>
          <div class="cf-alert cf-alert-success">Instellingen opgeslagen.</div>
        <?php endif; ?>

        <?php $hint = oauth_provider_hint($slug); if ($hint !== null): ?>
          <div class="cf-provider-hint"><?= $hint ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/marketplace/package/<?= htmlspecialchars($slug, ENT_QUOTES) ?>/instellingen">
          <?= CsrfProtection::field() ?>

          <?php foreach ($schema as $field):
              $key   = $field['key'];
              $label = $field['label'] ?? $key;
              $type  = $field['type']  ?? 'string';
              $val   = (string) ($values[$key] ?? '');
              // Een leeg 'redirect_uri'-veld zou anders bij opslaan de
              // ingebouwde ($_ENV['APP_URL'] . '/auth/.../callback')-fallback
              // in elke *OAuthController::makeOAuthClient() overschrijven met
              // een lege string (cf_settings-rij bestaat dan wél, dus de
              // fallback in getSetting() wordt nooit meer bereikt). Vul het
              // veld daarom voor met de berekende default zolang er nog geen
              // expliciete waarde is opgeslagen — de beheerder ziet zo ook
              // meteen welke exacte URI bij de provider geregistreerd moet
              // worden, en kan 'm nog steeds overschrijven.
              if ($key === 'redirect_uri' && $val === '') {
                  $val = rtrim($_ENV['APP_URL'] ?? '', '/') . "/auth/{$slug}/callback";
              }
          ?>
          <div class="cf-form-group">
            <label class="cf-label"><?= htmlspecialchars($label) ?></label>
            <?php if ($type === 'encrypted'): ?>
              <input type="password" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" class="cf-input"
                     autocomplete="new-password"
                     placeholder="<?= $val !== '' ? '•••••••• (ingesteld — laat leeg om te behouden)' : 'Nog niet ingesteld' ?>">
              <p class="cf-field-help">Wordt versleuteld opgeslagen. Leeg laten = huidige waarde behouden.</p>
            <?php elseif ($type === 'bool'): ?>
              <label style="font-weight:400;display:flex;align-items:center;gap:.4rem;">
                <input type="checkbox" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" value="1" style="width:auto;"
                       <?= ($val === '1' || $val === 'true') ? 'checked' : '' ?>>
                Ingeschakeld
              </label>
            <?php else: ?>
              <input type="text" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>" class="cf-input"
                     value="<?= htmlspecialchars($val) ?>">
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <button type="submit" class="cf-btn">Instellingen opslaan</button>
          <a href="/admin/marketplace?tab=installed" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
<?php
/**
 * Provider-specifieke uitleg: waar haal je de client_id/secret vandaan, en
 * welke redirect-URI moet je bij de provider whitelisten? Dit is precies
 * het "instructie hoe en waar" deel van Golf 10 — nu ín het scherm zelf i.p.v.
 * alleen in README.md, zodat een beheerder niet hoeft te schakelen tussen
 * documentatie en admin-paneel.
 */
function oauth_provider_hint(string $slug): ?string
{
    $appUrl = htmlspecialchars(rtrim($_ENV['APP_URL'] ?? '', '/'), ENT_QUOTES);
    $cb     = fn(string $provider) => "<code>{$appUrl}/auth/{$provider}/callback</code>";

    return match ($slug) {
        'discord' => "
            <strong>Discord</strong> — Client ID + Secret haal je op via de
            <a href=\"https://discord.com/developers/applications\" target=\"_blank\" rel=\"noopener\">Discord Developer Portal</a>:
            maak een applicatie aan → tab <em>OAuth2</em> → <em>Client ID</em> en
            <em>Client Secret</em> staan bovenaan. Voeg bij <em>Redirects</em> exact toe:
            " . $cb('discord') . "<br>
            <strong>Guild/Server ID</strong>: rechtsklik je server in Discord (Ontwikkelaarsmodus aan
            in Discord-instellingen → Geavanceerd) → \"Server-ID kopiëren\".<br>
            <strong>Bot Token</strong> (optioneel, voor betrouwbaardere rol-sync): zelfde applicatie →
            tab <em>Bot</em> → \"Reset Token\".",
        'twitch' => "
            <strong>Twitch</strong> — registreer een app op de
            <a href=\"https://dev.twitch.tv/console/apps\" target=\"_blank\" rel=\"noopener\">Twitch Developer Console</a>.
            Client ID staat direct zichtbaar; klik \"New Secret\" voor de Client Secret.
            Voeg bij <em>OAuth Redirect URLs</em> exact toe: " . $cb('twitch') . "<br>
            <strong>Standaardkanaal</strong>: de Twitch-gebruikersnaam die getoond wordt in het
            live-blok (kleine letters, geen @).",
        'google' => "
            <strong>Google</strong> — maak OAuth 2.0-credentials aan in de
            <a href=\"https://console.cloud.google.com/apis/credentials\" target=\"_blank\" rel=\"noopener\">Google Cloud Console</a>
            (Credentials → Create Credentials → OAuth client ID → Web application).
            Voeg bij <em>Authorized redirect URIs</em> exact toe: " . $cb('google') . "<br>
            Vergeet niet het OAuth-consentscherm (extern, testgebruikers of publicatie) in te stellen,
            anders werkt inloggen alleen voor accounts die je zelf hebt toegevoegd als tester.",
        'battlenet' => "
            <strong>Battle.net</strong> — registreer een client op
            <a href=\"https://develop.battle.net/access/clients\" target=\"_blank\" rel=\"noopener\">develop.battle.net</a>
            (Blizzard Developer Portal). Voeg bij <em>Redirect URIs</em> exact toe: " . $cb('battlenet') . "<br>
            <strong>Regio</strong>: kies de regio waar je community grotendeels speelt
            (<code>eu</code>, <code>us</code>, <code>kr</code> of <code>tw</code>) — dit bepaalt welk
            OAuth-endpoint gebruikt wordt (bv. <code>eu.battle.net</code>). Eén client werkt maar voor
            één regio; voor meerdere regio's heb je losse Battle.net-apps nodig.",
        default => null,
    };
}

// ╔══════════════════════════════════════════════════════════════════════╗
// ║  File: module_settings.php | Role: View | Version: 1.0.0            ║
// ║  Created: 2026-09-29 | Status: New — Golf 10 (OAuth providers)      ║
// ╚══════════════════════════════════════════════════════════════════════╝
