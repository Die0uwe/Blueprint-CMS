<?php
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Variabelen: $providers (alleen providers met een ingestelde key), $defaultProvider.
// Er worden nooit keys of key-fragmenten getoond.
use CommunityFusion\Core\Security\ContentSanitizer as S;
?>
<div class="studio-picker">
  <label for="studio-provider">Provider</label>
  <select id="studio-provider">
    <?php foreach ($providers as $p): ?>
      <option value="<?= S::escape($p['slug']) ?>" data-model="<?= S::escape($p['model']) ?>"
        <?= $p['slug'] === $defaultProvider ? 'selected' : '' ?>><?= S::escape($p['label']) ?></option>
    <?php endforeach; ?>
  </select>
  <label for="studio-model">Model</label>
  <input id="studio-model" type="text" maxlength="100" spellcheck="false" autocomplete="off" pattern="[A-Za-z0-9][A-Za-z0-9._:\/\-]*">
</div>
