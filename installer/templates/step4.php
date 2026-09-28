<?php
// See step2.php for why this file has no bare, unclosed <?php tag anymore —
// same fatal parse-error bug (installer white-screened on this step), same fix.
?>
<form method="POST">
  <div class="form-group">
    <label>Gebruikersnaam</label>
    <input type="text" name="username" placeholder="admin" minlength="3" required>
  </div>
  <div class="form-group">
    <label>E-mailadres</label>
    <input type="email" name="email" placeholder="admin@mijnsite.nl" required>
  </div>
  <div class="form-row">
    <div class="form-group">
      <label>Wachtwoord</label>
      <input type="password" name="password" minlength="8" required>
    </div>
    <div class="form-group">
      <label>Bevestig Wachtwoord</label>
      <input type="password" name="password_confirm" required>
    </div>
  </div>
  <p style="font-size:.8rem;color:var(--muted);margin-top:-.5rem;">
    Minimaal 8 tekens. Wachtwoord wordt beveiligd met Argon2id hashing.
  </p>
  <div class="btn-row">
    <button class="btn" type="submit">Account Aanmaken →</button>
  </div>
</form>
