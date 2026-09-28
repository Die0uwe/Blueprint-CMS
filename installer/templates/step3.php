<?php
// See step2.php for why this file has no bare, unclosed <?php tag anymore —
// same fatal parse-error bug (installer white-screened on this step), same fix.
?>
<form method="POST">
  <div class="form-group">
    <label>Sitenaam</label>
    <input type="text" name="site_name" placeholder="Mijn Gaming Community" required>
  </div>
  <div class="form-group">
    <label>Site URL</label>
    <input type="url" name="site_url" placeholder="https://mijnsite.nl" required>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label>Taal</label>
      <select name="locale">
        <option value="nl">🇳🇱 Nederlands</option>
        <option value="en">🇬🇧 English</option>
        <option value="de">🇩🇪 Deutsch</option>
      </select>
    </div>
    <div class="form-group">
      <label>Tijdzone</label>
      <select name="timezone">
        <option value="Europe/Amsterdam">Europe/Amsterdam</option>
        <option value="Europe/London">Europe/London</option>
        <option value="America/New_York">America/New_York</option>
        <option value="UTC">UTC</option>
      </select>
    </div>
  </div>
  <div class="form-group">
    <label>Afzender e-mail</label>
    <input type="email" name="mail_from" placeholder="noreply@mijnsite.nl">
  </div>
  <div class="btn-row">
    <button class="btn" type="submit">Opslaan & Doorgaan →</button>
  </div>
</form>
