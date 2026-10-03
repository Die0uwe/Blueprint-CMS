<?php
// Copyright (C) 2026 DieOuwe — GPL-3.0-or-later
// Eigen editor: een gewone <textarea> met een gesynchroniseerde highlight-laag
// erachter (assets/editor.js). Geen externe editor-library.
?>
<section class="studio-col studio-editor" aria-label="Editor">
  <div class="studio-editor-bar">
    <label for="studio-lang">Taal</label>
    <select id="studio-lang">
      <option value="html">HTML</option>
      <option value="php">PHP</option>
      <option value="twig">Twig</option>
      <option value="js">JavaScript</option>
      <option value="css">CSS</option>
      <option value="text">Tekst</option>
    </select>
    <button type="button" class="cf-btn-sm" id="studio-editor-clear">Leegmaken</button>
    <span id="studio-editor-info" class="studio-info" aria-live="polite"></span>
  </div>
  <div id="studio-editor" class="ed" data-language="html"></div>
</section>
