<?php
/*
 * This file — like step3.php and step4.php — used to open a bare <?php tag
 * on line 1 with no closing ?>, immediately followed by raw HTML. layout.php
 * already includes these step templates from INSIDE an open <?php block
 * (see `<?php include __DIR__ . '/step' . $currentStep . '.php'; ?>`), so
 * this second, unclosed <?php tried to parse the HTML below as PHP code —
 * a hard parse error. Every real install hit a white screen on step 2.
 * Fixed to match step1.php/step5.php's pattern: no opening tag needed here.
 */
?>
<form method="POST">
  <div class="form-row-3">
    <div class="form-group">
      <label>Database Host</label>
      <input type="text" name="db_host" value="127.0.0.1" required>
    </div>
    <div class="form-group">
      <label>Poort</label>
      <input type="number" name="db_port" value="3306" required>
    </div>
  </div>
  <div class="form-group">
    <label>Database Naam</label>
    <input type="text" name="db_name" placeholder="blueprint_cms" required>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label>Gebruiker</label>
      <input type="text" name="db_user" placeholder="root" required>
    </div>
    <div class="form-group">
      <label>Wachtwoord</label>
      <input type="password" name="db_pass" placeholder="(leeg = geen wachtwoord)">
    </div>
  </div>
  <div class="btn-row">
    <button class="btn" type="submit">Verbinden & Schema Importeren →</button>
  </div>
</form>
