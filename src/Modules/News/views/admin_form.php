<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $article (array|null) en $error (string|null) zijn beschikbaar vanuit
// NewsController::createForm() / editForm()
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'news';
$isEdit    = $article !== null;
$action    = $isEdit ? '/admin/news/' . (int) $article['id'] . '/bewerk' : '/admin/news';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isEdit ? 'Artikel bewerken' : 'Nieuw artikel' ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>.form-wrap { max-width: 720px; }</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1><?= $isEdit ? '✏️ Artikel bewerken' : '✍️ Nieuw artikel' ?></h1>
      <a href="/admin/news" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <div class="form-wrap">
        <?php if ($error): ?>
          <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= htmlspecialchars($action, ENT_QUOTES) ?>">
          <?= CsrfProtection::field() ?>
          <?php if ($isEdit): ?>
            <input type="hidden" name="_current_published_at" value="<?= htmlspecialchars((string) ($article['published_at'] ?? ''), ENT_QUOTES) ?>">
          <?php endif; ?>

          <div class="cf-form-group">
            <label class="cf-label" for="title">Titel</label>
            <input type="text" id="title" name="title" class="cf-input" required maxlength="300"
                   value="<?= htmlspecialchars((string) ($article['title'] ?? ''), ENT_QUOTES) ?>">
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="summary">Samenvatting (optioneel)</label>
            <textarea id="summary" name="summary" class="cf-textarea" style="min-height:70px;"><?= htmlspecialchars((string) ($article['summary'] ?? '')) ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="content">Inhoud (HTML toegestaan)</label>
            <textarea id="content" name="content" class="cf-textarea" required style="min-height:280px;"><?= htmlspecialchars((string) ($article['content'] ?? '')) ?></textarea>
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="featured_image">Uitgelichte afbeelding — URL (optioneel)</label>
            <input type="url" id="featured_image" name="featured_image" class="cf-input"
                   placeholder="https://…"
                   value="<?= htmlspecialchars((string) ($article['featured_image'] ?? ''), ENT_QUOTES) ?>">
          </div>

          <div class="cf-form-group">
            <label class="cf-label" for="status">Status</label>
            <select id="status" name="status" class="cf-select">
              <?php $currentStatus = $article['status'] ?? 'draft'; ?>
              <option value="draft"     <?= $currentStatus === 'draft' ? 'selected' : '' ?>>Concept</option>
              <option value="published" <?= $currentStatus === 'published' ? 'selected' : '' ?>>Gepubliceerd</option>
              <option value="archived"  <?= $currentStatus === 'archived' ? 'selected' : '' ?>>Gearchiveerd</option>
            </select>
          </div>

          <div class="cf-form-group">
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.875rem;color:var(--text-dim);">
              <input type="checkbox" name="is_sticky" value="1" <?= (int) ($article['is_sticky'] ?? 0) === 1 ? 'checked' : '' ?>>
              Bovenaan vastzetten (sticky)
            </label>
          </div>

          <button type="submit" class="cf-btn"><?= $isEdit ? 'Wijzigingen opslaan' : 'Artikel aanmaken' ?></button>
          <a href="/admin/news" class="cf-btn-ghost">Annuleren</a>
        </form>
      </div>
    </div>
  </div>
</div>
</body>
</html>
