<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $core (array — 'core'-instellingengroep via SettingsRepository::getGroup()),
// $contact (array — 'contact'-instellingengroep, idem), $error, $flash
// beschikbaar — vanuit Settings\AdminController::settings().
// Tot v1.18.0 was dit scherm 100% statische HTML zonder <form>, die de
// beheerder doorstuurde naar config/config.php (buiten webroot) en
// /installer/ (bestaat na installatie niet meer). Dit is nu een echt,
// werkend formulier op de 'core'- en 'contact'-instellingengroepen in
// cf_settings.
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav    = 'settings';
$siteName     = $core['site_name'] ?? '';
$siteMotd     = $core['site_motd'] ?? '';
$siteDesc     = $core['site_description'] ?? '';
$siteIcon     = $core['site_icon'] ?? '';
$locale       = $core['default_locale'] ?? 'nl';
$timezone     = $core['timezone'] ?? 'Europe/Amsterdam';
$notifyEmail  = $contact['notify_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Site-instellingen — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
.form-wrap { max-width: 720px; }
.cf-icon-preview {
  width: 64px; height: 64px; border-radius: 12px; object-fit: cover;
  border: 1px solid var(--border); background: rgba(255,255,255,.04);
}
.cf-icon-row { display: flex; align-items: center; gap: 1rem; margin-bottom: .5rem; }
.cf-field-help { color: var(--text-dim); font-size: .78rem; margin: .35rem 0 0; }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>⚙️ Site-instellingen</h1>
      <a href="/admin" class="cf-btn-sm">← Terug naar dashboard</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($flash): ?>
          <div class="cf-alert cf-alert-success">Instellingen opgeslagen.</div>
        <?php endif; ?>

        <form method="post" action="/admin/settings" enctype="multipart/form-data">
          <?= CsrfProtection::field() ?>

          <div class="cf-form-group">
            <label class="cf-label">Sitenaam <span style="color:var(--error-text)">*</span></label>
            <input type="text" name="site_name" class="cf-input" required maxlength="200"
                   value="<?= htmlspecialchars($siteName) ?>">
            <p class="cf-field-help">Verschijnt in de titelbalk van elke pagina en in het header-logo.</p>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">MOTD / slogan</label>
            <input type="text" name="site_motd" class="cf-input" maxlength="255"
                   value="<?= htmlspecialchars($siteMotd) ?>"
                   placeholder="Bijv. &quot;Welkom bij onze gaming community&quot;">
            <p class="cf-field-help">Kleine tekstregel die direct onder de sitetitel in de header wordt getoond.</p>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Omschrijving <span style="color:var(--text-dim);font-weight:400;">— SEO</span></label>
            <textarea name="site_description" class="cf-input" rows="3" maxlength="500"
                      placeholder="Korte omschrijving voor zoekmachines en social-media previews"><?= htmlspecialchars($siteDesc) ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label">Website-icoon (favicon)</label>
            <div class="cf-icon-row">
              <?php if ($siteIcon !== ''): ?>
                <img src="<?= htmlspecialchars($siteIcon) ?>" alt="Huidig icoon" class="cf-icon-preview">
              <?php else: ?>
                <div class="cf-icon-preview" style="display:flex;align-items:center;justify-content:center;font-size:1.4rem;">🌐</div>
              <?php endif; ?>
              <div>
                <input type="file" name="site_icon" accept="image/png,image/jpeg,image/gif,image/webp">
                <p class="cf-field-help">PNG, JPG, GIF of WebP — max. 5MB. Vierkant beeld raden we aan.</p>
              </div>
            </div>
            <?php if ($siteIcon !== ''): ?>
              <label style="font-weight:400;display:flex;align-items:center;gap:.4rem;margin-top:.35rem;font-size:.87rem;">
                <input type="checkbox" name="remove_icon" value="1" style="width:auto;">
                Huidig icoon verwijderen
              </label>
            <?php endif; ?>
          </div>

          <div style="display:flex;gap:1rem;">
            <div class="cf-form-group" style="flex:1;">
              <label class="cf-label"><?= \CommunityFusion\Core\I18n\Trans::get('admin.settings.language_label') ?></label>
              <select name="default_locale" class="cf-input">
                <option value="nl" <?= $locale === 'nl' ? 'selected' : '' ?>>Nederlands</option>
                <option value="en" <?= $locale === 'en' ? 'selected' : '' ?>>English</option>
                <option value="de" <?= $locale === 'de' ? 'selected' : '' ?>>Deutsch</option>
              </select>
              <p style="color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0;">
                <?= \CommunityFusion\Core\I18n\Trans::get('admin.settings.language_hint') ?>
              </p>
            </div>
            <div class="cf-form-group" style="flex:1;">
              <label class="cf-label">Tijdzone</label>
              <input type="text" name="timezone" class="cf-input" maxlength="60"
                     value="<?= htmlspecialchars($timezone) ?>" placeholder="Europe/Amsterdam">
            </div>
          </div>

          <h2 style="margin:2rem 0 .75rem;font-size:1.05rem;">Contactformulier</h2>
          <div class="cf-form-group">
            <label class="cf-label">Meldingen naar e-mailadres</label>
            <input type="email" name="contact_notify_email" class="cf-input" maxlength="255"
                   value="<?= htmlspecialchars($notifyEmail) ?>"
                   placeholder="meldingen@voorbeeld.nl">
            <p class="cf-field-help">Optioneel. Hierheen gaat de meldingsmail bij een nieuw contactformulier-bericht. Leeg = val terug op het standaard afzenderadres uit de mailconfiguratie.</p>
          </div>

          <button type="submit" class="cf-btn">Instellingen opslaan</button>
          <a href="/admin" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
