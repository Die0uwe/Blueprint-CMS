<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe (https://www.dieouwe.nl / https://www.slayeralliance.com)
// GPL-3.0-or-later
// ============================================================================
// Beschikbaar vanuit PluginAdminController::settings(): $slug, $manifest, $defs, $values, $flash

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'plugins';
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instellingen <?= $e($manifest['name']) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar"><h1>🧰 <?= $e($manifest['name']) ?></h1><a href="/admin/plugins" class="cf-btn-sm">← Terug</a></header>
    <div class="admin-content">
      <?php if ($flash): ?>
        <div class="cf-alert <?= $flash['type'] === 'ok' ? 'cf-alert-success' : 'cf-alert-error' ?>" role="status"><?= $e($flash['msg']) ?></div>
      <?php endif; ?>
      <form method="post" action="/admin/plugins/<?= $e(rawurlencode($slug)) ?>/instellingen" class="cf-card" style="padding:1.2rem;max-width:640px;">
        <input type="hidden" name="_csrf_token" value="<?= $e(CsrfProtection::getToken()) ?>">
        <?php foreach ($defs as $d): $k = $d['key']; $t = $d['type']; $label = $d['label'] ?? $k; $v = $values[$k] ?? ($d['default'] ?? ''); ?>
          <div class="cf-form-group">
            <?php if ($t === 'bool'): ?>
              <label class="cf-label"><input type="checkbox" name="<?= $e($k) ?>" value="1" <?= $v ? 'checked' : '' ?>> <?= $e($label) ?></label>
            <?php else: ?>
              <label class="cf-label" for="s-<?= $e($k) ?>"><?= $e($label) ?></label>
              <?php if ($t === 'encrypted'): ?>
                <input class="cf-input" type="password" id="s-<?= $e($k) ?>" name="<?= $e($k) ?>" autocomplete="new-password"
                       placeholder="<?= ($values[$k] ?? '') !== '' ? '•••••••• — laat leeg om te behouden' : '' ?>">
              <?php elseif ($t === 'json'): ?>
                <textarea class="cf-input" id="s-<?= $e($k) ?>" name="<?= $e($k) ?>" rows="5" style="font-family:monospace;"><?= $e(is_string($v) ? $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
              <?php else: ?>
                <input class="cf-input" type="<?= $t === 'int' ? 'number' : 'text' ?>" id="s-<?= $e($k) ?>" name="<?= $e($k) ?>" value="<?= $e($v) ?>">
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$defs): ?><p style="color:var(--muted);">Deze plugin heeft geen instellingen.</p><?php endif; ?>
        <button class="cf-btn">Opslaan</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
