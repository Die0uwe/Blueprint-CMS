<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $topics, $posts, $boards, $page, $pages, $flash komen uit ForumModerationController::index()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'forum_moderation';
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$flashLabels = [
    'vastgezet' => 'Topic vastgezet', 'losgemaakt' => 'Topic losgemaakt', 'gesloten' => 'Topic gesloten',
    'heropend' => 'Topic heropend', 'verplaatst' => 'Topic verplaatst', 'verwijderd' => 'Topic verwijderd',
    'reactie-verwijderd' => 'Reactie verwijderd',
];
$flashText = $flashLabels[$flash ?? ''] ?? null;
$btn = static fn(string $url, string $label, string $extra = '', string $style = ''): string =>
    '<form method="post" action="' . $url . '" style="display:inline;"' . $extra . '>'
    . CsrfProtection::field()
    . '<button type="submit" class="cf-btn-sm' . ($style !== '' ? ' ' . $style : '') . '">' . $label . '</button></form>';
$danger = 'cf-btn-danger';
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forum-moderatie — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>🛡️ Forum-moderatie</h1>
      <a href="/admin/forum/boards" class="cf-btn-ghost">💬 Borden beheren</a>
    </header>

    <div class="admin-content">
      <?php if ($flashText): ?>
        <div class="cf-alert cf-alert-success"><?= $e($flashText) ?>.</div>
      <?php elseif (($flash ?? '') === 'mislukt'): ?>
        <div class="cf-alert cf-alert-error">Actie niet uitgevoerd.</div>
      <?php endif; ?>

      <div class="cf-card">
        <div class="cf-card-header"><strong>Topics</strong></div>
        <?php if (empty($topics)): ?>
          <div class="cf-table-empty">Nog geen topics.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Topic</th><th>Bord</th><th>Auteur</th><th>Reacties</th><th>Laatste activiteit</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($topics as $t): $id = (int) $t['id']; ?>
              <tr>
                <td>
                  <a href="/forum/<?= $e($t['board_slug']) ?>/<?= $e($t['slug']) ?>"><strong><?= $e($t['title']) ?></strong></a>
                  <?php if ((int) $t['is_pinned'] === 1): ?><span class="cf-badge cf-badge-gold">📌</span><?php endif; ?>
                  <?php if ((int) $t['is_locked'] === 1): ?><span class="cf-badge cf-badge-purple">🔒</span><?php endif; ?>
                </td>
                <td><?= $e($t['board_name']) ?></td>
                <td><?= $e($t['display_name'] ?? $t['username']) ?></td>
                <td><?= (int) $t['reply_count'] ?></td>
                <td><?= $e(date('d-m-Y H:i', strtotime((string) ($t['last_post_at'] ?? $t['created_at'])))) ?></td>
                <td>
                  <div class="cf-table-actions">
                    <?= $btn("/admin/forum/topics/$id/pin", (int) $t['is_pinned'] === 1 ? '📌 Los' : '📌 Vast') ?>
                    <?= $btn("/admin/forum/topics/$id/lock", (int) $t['is_locked'] === 1 ? '🔓 Open' : '🔒 Sluit') ?>
                    <form method="post" action="/admin/forum/topics/<?= $id ?>/verplaats" style="display:inline;">
                      <?= CsrfProtection::field() ?>
                      <select name="board_id" aria-label="Verplaats naar bord">
                        <?php foreach ($boards as $b): ?>
                          <option value="<?= (int) $b['id'] ?>"<?= (int) $b['id'] === (int) $t['board_id'] ? ' selected' : '' ?>><?= $e($b['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="cf-btn-sm">↪ Verplaats</button>
                    </form>
                    <?= $btn("/admin/forum/topics/$id/verwijder", '🗑️', ' onsubmit="return confirm(\'Dit topic verwijderen?\');"', $danger) ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <?php if ($pages > 1): ?>
        <div class="cf-pagination">
          <?php for ($i = 1; $i <= $pages; $i++): ?>
            <a href="/admin/forum/moderatie?page=<?= $i ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      <?php endif; ?>

      <div class="cf-card" style="margin-top:1.5rem;">
        <div class="cf-card-header"><strong>Laatste reacties</strong></div>
        <?php if (empty($posts)): ?>
          <div class="cf-table-empty">Nog geen reacties.</div>
        <?php else: ?>
          <table class="cf-table">
            <thead><tr><th>Reactie</th><th>Topic</th><th>Auteur</th><th>Geplaatst</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($posts as $p): ?>
              <tr>
                <td><?= $e(mb_substr(trim(strip_tags((string) $p['content'])), 0, 120)) ?></td>
                <td><a href="/forum/<?= $e($p['board_slug']) ?>/<?= $e($p['topic_slug']) ?>#reacties"><?= $e($p['topic_title']) ?></a></td>
                <td><?= $e($p['display_name'] ?? $p['username']) ?></td>
                <td><?= $e(date('d-m-Y H:i', strtotime((string) $p['created_at']))) ?></td>
                <td><?= $btn('/admin/forum/posts/' . (int) $p['id'] . '/verwijder', '🗑️', ' onsubmit="return confirm(\'Deze reactie verwijderen?\');"', $danger) ?></td>
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
