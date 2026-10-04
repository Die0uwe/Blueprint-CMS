<?php // ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================ ?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>WoW Instellingen — Blueprint Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  .admin-wrap{display:flex;min-height:100vh;}
  .admin-main{flex:1;display:flex;flex-direction:column;}
  .admin-content{padding:2rem;max-width:700px;}
</style>
</head>
<body>
<div class="admin-wrap">
  <div class="admin-main">
    <div class="admin-content">
      <h1 style="font-size:1.4rem;font-weight:800;margin-bottom:1.5rem;">🐉 World of Warcraft Instellingen</h1>

      <?php if(isset($_GET['saved'])): ?>
      <div class="cf-alert cf-alert-success" style="margin-bottom:1.2rem;">✅ Instellingen opgeslagen!</div>
      <?php endif; ?>

      <div class="cf-card">
        <div class="cf-card-header">Battle.net API</div>
        <div class="cf-card-body">
          <form method="POST">
            <?= \CommunityFusion\Core\Security\CsrfProtection::field() ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
              <div class="cf-form-group">
                <label class="cf-label">Client ID</label>
                <input class="cf-input" type="text" name="client_id"
                       value="<?= htmlspecialchars($settings['client_id'] ?? '',ENT_QUOTES) ?>"
                       placeholder="Battle.net Client ID">
              </div>
              <div class="cf-form-group">
                <label class="cf-label">Client Secret</label>
                <input class="cf-input" type="password" name="client_secret"
                       placeholder="<?= !empty($settings['client_secret']) ? '●●●●●●●● (ingesteld)' : 'Client Secret' ?>">
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;">
              <div class="cf-form-group">
                <label class="cf-label">Regio</label>
                <select class="cf-select" name="region">
                  <?php foreach(['eu'=>'EU','us'=>'US','kr'=>'KR','tw'=>'TW'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= ($settings['region']??'eu')===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="cf-form-group">
                <label class="cf-label">Locale</label>
                <select class="cf-select" name="locale">
                  <?php foreach(['en_GB'=>'EN-GB','nl_NL'=>'NL','de_DE'=>'DE','fr_FR'=>'FR'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= ($settings['locale']??'en_GB')===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="cf-form-group">
                <label class="cf-label">Realm</label>
                <input class="cf-input" type="text" name="realm"
                       value="<?= htmlspecialchars($settings['realm'] ?? '',ENT_QUOTES) ?>"
                       placeholder="bv. Sporeggar">
              </div>
            </div>
            <div class="cf-form-group">
              <label class="cf-label">Guild Naam</label>
              <input class="cf-input" type="text" name="guild_name"
                     value="<?= htmlspecialchars($settings['guild_name'] ?? '',ENT_QUOTES) ?>"
                     placeholder="bv. Slayer Alliance">
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:1rem;">
              <button type="submit" class="cf-btn">💾 Opslaan</button>
            </div>
          </form>
        </div>
      </div>

      <div class="cf-card" style="margin-top:1rem;">
        <div class="cf-card-header">📖 API Informatie</div>
        <div class="cf-card-body" style="font-size:.85rem;color:var(--text-dim);line-height:1.7;">
          <p>Maak een Battle.net API applicatie aan via
            <a href="https://develop.battle.net" target="_blank">develop.battle.net</a>.
          </p>
          <p style="margin-top:.5rem;">Raid progress en Mythic+ scores worden opgehaald via
            <a href="https://raider.io" target="_blank">Raider.IO</a> — geen extra API key vereist.
          </p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include CF_ROOT . '/src/Modules/Shared/views/admin_mobile.php'; ?>
</body>
</html>
