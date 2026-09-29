<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $categories, $flash, $unlinked, $error beschikbaar vanuit CategoryAdminController::index()

$activeNav   = 'news';
$flashLabels = ['aangemaakt' => 'aangemaakt', 'bijgewerkt' => 'bijgewerkt', 'verwijderd' => 'verwijderd'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nieuwscategorieën Beheer — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🗂️ Nieuwscategorieën Beheer</h1>
      <div style="display:flex;gap:.5rem;">
        <a href="/admin/news" class="cf-btn-ghost">← Terug naar nieuws</a>
        <a href="/admin/news/categories/nieuw" class="cf-btn-sm">+ Nieuwe categorie</a>
      </div>
    </header>

    <div class="admin-content">
      <?php if ($flash && isset($flashLabels[$flash])): ?>
        <div class="cf-alert cf-alert-success">
          Categorie succesvol <?= htmlspecialchars($flashLabels[$flash]) ?>.
          <?php if ($flash === 'verwijderd' && $unlinked > 0): ?>
            <?= (int) $unlinked ?> artikel<?= $unlinked === 1 ? '' : 'en' ?> <?= $unlinked === 1 ? 'is' : 'zijn' ?> daarbij ontkoppeld
            (geen categorie meer, maar niet verwijderd).
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-card">
        <?php if (empty($categories)): ?>
          <div class="cf-table-empty">Nog geen categorieën — maak er een aan om te starten.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead>
              <tr>
                <th>Positie</th>
                <th>Naam</th>
                <th>Slug</th>
                <th>Bovenliggend</th>
                <th>Artikelen</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php
              // Kleine lookup voor de "Bovenliggend"-kolom — dezelfde $categories-
              // lijst hergebruikt i.p.v. een aparte query per rij.
              $byId = [];
              foreach ($categories as $c) { $byId[(int) $c['id']] = $c['name']; }
              ?>
              <?php foreach ($categories as $cat): ?>
                <tr>
                  <td><?= (int) $cat['position'] ?></td>
                  <td>
                    <strong><?= htmlspecialchars($cat['name']) ?></strong>
                    <?php if (!empty($cat['description'])): ?>
                      <br><span style="color:var(--text-dim);font-size:.8rem;"><?= htmlspecialchars($cat['description']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td><code>/news?categorie=<?= htmlspecialchars($cat['slug']) ?></code></td>
                  <td>
                    <?php if (!empty($cat['parent_id']) && isset($byId[(int) $cat['parent_id']])): ?>
                      <?= htmlspecialchars($byId[(int) $cat['parent_id']]) ?>
                    <?php else: ?>
                      <span style="color:var(--text-dim);">—</span>
                    <?php endif; ?>
                  </td>
                  <td><?= (int) $cat['article_count'] ?></td>
                  <td>
                    <div class="cf-table-actions">
                      <a href="/admin/news/categories/<?= (int) $cat['id'] ?>/bewerk" class="cf-btn-sm">✏️ Bewerk</a>
                      <form method="post" action="/admin/news/categories/<?= (int) $cat['id'] ?>/verwijder" style="display:inline;"
                            onsubmit="<?php if ((int) $cat['article_count'] > 0): ?>return confirm('Deze categorie bevat nog <?= (int) $cat['article_count'] ?> artikel(en). Verwijderen ontkoppelt ze (categorieloos) maar verwijdert ze niet. Doorgaan?');<?php else: ?>return this.dataset.confirmed==='1' || (this.dataset.confirmed='1', document.getElementById('confirm-<?= (int) $cat['id'] ?>').style.display='inline', false);<?php endif; ?>">
                        <?= \CommunityFusion\Core\Security\CsrfProtection::field() ?>
                        <button type="submit" class="cf-btn-sm" style="color:#f87171;border-color:rgba(248,113,113,.4);">🗑️ Verwijder</button>
                        <?php if ((int) $cat['article_count'] === 0): ?>
                          <span id="confirm-<?= (int) $cat['id'] ?>" style="display:none;color:var(--text-dim);font-size:.75rem;">Klik nogmaals om te bevestigen</span>
                        <?php endif; ?>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
