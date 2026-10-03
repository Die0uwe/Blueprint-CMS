<?php
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Variabelen (AiStudioController::renderSettings): $fields, $errors, $saved, $csrf.
// API-keys worden NOOIT teruggestuurd naar de browser: een geheim veld toont alleen
// of er een key is ingesteld; leeg laten betekent "behouden".
use CommunityFusion\Core\I18n\Trans;
use CommunityFusion\Core\Security\ContentSanitizer as S;

$activeNav = 'ai-studio';
?>
<!DOCTYPE html>
<html lang="<?= S::escape(Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Studio — Instellingen — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include CF_ROOT . '/src/Modules/Shared/views/admin_styles.php'; ?>
<style>.form-wrap{max-width:720px}.cf-field-help{color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0}</style>
</head>
<body>
<div class="admin-wrap">
  <?php include CF_ROOT . '/src/Modules/Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar">
      <h1>⚙️ AI Studio — Instellingen</h1>
      <a href="/admin/ai-studio" class="cf-btn-sm">← Naar AI Studio</a>
    </header>
    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($saved && $errors === []): ?><div class="cf-alert cf-alert-success">Instellingen opgeslagen.</div><?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="cf-alert cf-alert-error" role="alert"><?= S::escape($err) ?></div><?php endforeach; ?>

        <p class="cf-field-help">API-keys zijn site-breed en worden versleuteld opgeslagen (AES-256-GCM). Een nieuwe key wordt eerst bij de provider gecontroleerd en pas daarna bewaard. Ollama gebruikt de host-instellingen van de Ollama-module.</p>

        <form method="post" action="/admin/ai-studio/settings" autocomplete="off">
          <input type="hidden" name="_csrf_token" value="<?= S::escape($csrf) ?>">
          <?php foreach ($fields as $f): $name = 'f[' . $f['key'] . ']'; ?>
            <div class="cf-form-group">
              <label class="cf-label" for="f-<?= S::escape($f['key']) ?>"><?= S::escape($f['label']) ?></label>
              <?php if ($f['secret']): ?>
                <input type="password" id="f-<?= S::escape($f['key']) ?>" name="<?= S::escape($name) ?>" class="cf-input"
                       autocomplete="new-password" spellcheck="false"
                       placeholder="<?= $f['isSet'] ? '•••••••• (ingesteld — leeg laten om te behouden)' : 'Nog niet ingesteld' ?>">
                <?php if ($f['isSet']): ?>
                  <label class="cf-field-help"><input type="checkbox" name="clear[<?= S::escape($f['key']) ?>]" value="1"> Deze key verwijderen</label>
                <?php endif; ?>
              <?php else: ?>
                <input type="text" id="f-<?= S::escape($f['key']) ?>" name="<?= S::escape($name) ?>" class="cf-input"
                       value="<?= S::escape($f['value']) ?>" placeholder="<?= S::escape($f['default']) ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <button type="submit" class="cf-btn">Opslaan</button>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
