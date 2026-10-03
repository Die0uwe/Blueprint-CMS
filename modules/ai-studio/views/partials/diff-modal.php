<?php
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Wijzigingsvoorstel van de AI. De diff wordt door studio.js regel voor regel
// met textContent opgebouwd. Pas na "Toepassen" gaat er een POST naar
// /admin/ai-studio/apply-diff; de editor wordt nooit rechtstreeks door de AI gevuld.
?>
<div id="studio-diff" class="studio-modal" role="dialog" aria-modal="true" aria-labelledby="studio-diff-title" hidden>
  <div class="studio-modal-box">
    <h2 id="studio-diff-title">Voorgestelde wijziging</h2>
    <p id="studio-diff-stats" class="studio-info"></p>
    <pre id="studio-diff-body" class="studio-diff" tabindex="0"></pre>
    <p id="studio-diff-error" class="studio-error" role="alert" hidden></p>
    <div class="studio-modal-actions">
      <button type="button" class="cf-btn-ghost" id="studio-diff-reject">Weigeren</button>
      <button type="button" class="cf-btn" id="studio-diff-apply">Toepassen</button>
    </div>
  </div>
</div>
