<?php
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Chat-kolom. AI-antwoorden worden uitsluitend door studio.js als tekst
// (textContent) in #studio-messages gezet; code komt in <pre><code>.
?>
<section class="studio-col studio-chat" aria-label="Chat">
  <?php include __DIR__ . '/provider-picker.php'; ?>
  <div id="studio-messages" class="studio-messages" role="log" aria-live="polite"></div>
  <form id="studio-form" class="studio-form" autocomplete="off">
    <label for="studio-input" class="sr-only">Bericht</label>
    <textarea id="studio-input" rows="3" maxlength="32000" placeholder="Vraag iets over je code… (Ctrl+Enter om te versturen)" required></textarea>
    <div class="studio-form-row">
      <label class="studio-check"><input type="checkbox" id="studio-ctx" checked> Editorinhoud meesturen</label>
      <button type="submit" class="cf-btn" id="studio-send">Verstuur</button>
      <button type="button" class="cf-btn-sm" id="studio-stop" hidden>Stop</button>
    </div>
  </form>
</section>
