<?php
// $sections (ApiSettingsController::sections()), $values (ontsleutelde groep 'api'), $flash (bool)
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'apisettings';
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?= $h(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>API-instellingen — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
.form-wrap { max-width: 820px; }
.api-card { margin-bottom: 1.25rem; padding: 1rem 1.25rem; }
.api-card h2 { margin: 0 0 .25rem; font-size: 1.05rem; }
.api-card .hint { color: var(--text-dim); font-size: .8rem; margin: 0 0 .75rem; }
.api-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: .75rem 1rem; }
.api-badge { font-size: .7rem; padding: .1rem .4rem; border-radius: 6px; border: 1px solid var(--border); color: var(--text-dim); }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>
  <div class="admin-main">
    <header class="admin-topbar"><h1>🔌 API-instellingen</h1></header>
    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($flash): ?><div class="cf-alert cf-alert-success">Opgeslagen.</div><?php endif; ?>
        <p class="hint" style="color:var(--text-dim);">Voorbereide plekken voor extra koppelingen. Sleutels worden versleuteld opgeslagen en nooit teruggetoond. Modules lezen ze uit groep <code>api</code>. Discord, Twitch, GitHub e.d. stel je in via Marketplace → module-instellingen.</p>
        <form method="post" action="/admin/api-instellingen" autocomplete="off">
          <?= CsrfProtection::field() ?>
          <?php foreach ($sections as $sec): ?>
            <section class="cf-card api-card">
              <h2><?= $h($sec['title']) ?></h2>
              <p class="hint"><?= $h($sec['hint']) ?></p>
              <div class="api-grid">
                <?php foreach ($sec['fields'] as $f):
                    $val = (string) ($values[$f['key']] ?? ''); ?>
                  <div class="cf-form-group">
                    <label class="cf-label" for="f-<?= $h($f['key']) ?>"><?= $h($f['label']) ?></label>
                    <?php if ($f['type'] === 'encrypted'): ?>
                      <input id="f-<?= $h($f['key']) ?>" type="password" name="<?= $h($f['key']) ?>" class="cf-input" autocomplete="new-password"
                             placeholder="<?= $val !== '' ? '•••••••• (ingesteld — leeg laten = behouden)' : 'Nog niet ingesteld' ?>">
                      <?php if ($val !== ''): ?>
                        <label style="font-weight:400;font-size:.78rem;"><input type="checkbox" name="<?= $h($f['key']) ?>__clear" value="1" style="width:auto;"> Sleutel wissen</label>
                      <?php endif; ?>
                    <?php else: ?>
                      <input id="f-<?= $h($f['key']) ?>" type="<?= $f['type'] === 'url' ? 'url' : 'text' ?>" name="<?= $h($f['key']) ?>" class="cf-input"
                             value="<?= $h($val) ?>" placeholder="<?= $h($f['placeholder'] ?? '') ?>" maxlength="300">
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
          <button type="submit" class="cf-btn">Opslaan</button>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
