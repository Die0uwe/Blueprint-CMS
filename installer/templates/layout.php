<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

$stepContent = __DIR__ . '/step' . $currentStep . '.php';

// Zelfde data-URI-aanpak als welcome.php — zie het commentaar daar.
$logoFile = CF_ROOT . '/public/assets/img/logo-64.png';
$logoData = is_file($logoFile)
    ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoFile))
    : '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Blueprint CMS — Installatie</title>
<style>
  :root {
    --bg:       #0a0c14;
    --surface:  #111827;
    --border:   #1e2940;
    --accent:   #6c3df4;
    --accent2:  #a855f7;
    --gold:     #f59e0b;
    --text:     #e2e8f0;
    --muted:    #64748b;
    --success:  #10b981;
    --error:    #ef4444;
    --radius:   12px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background-image:
      radial-gradient(ellipse at 20% 20%, rgba(108,61,244,.15) 0%, transparent 60%),
      radial-gradient(ellipse at 80% 80%, rgba(168,85,247,.10) 0%, transparent 60%);
  }

  .installer {
    width: 100%;
    max-width: 760px;
    padding: 2rem 1.5rem;
  }

  /* Logo */
  .logo {
    text-align: center;
    margin-bottom: 2.5rem;
  }
  .logo h1 {
    font-size: 2.2rem;
    font-weight: 800;
    background: linear-gradient(135deg, #a855f7, #6c3df4, #f59e0b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: -.5px;
  }
  .logo p { color: var(--muted); font-size: .9rem; margin-top: .3rem; }

  /* Stap-indicator */
  .steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin-bottom: 2.5rem;
    flex-wrap: wrap;
    gap: .5rem;
  }
  .step-item {
    display: flex;
    align-items: center;
    gap: .4rem;
    font-size: .8rem;
    color: var(--muted);
    transition: color .2s;
  }
  .step-item.done  { color: var(--success); }
  .step-item.active { color: var(--accent2); font-weight: 700; }
  .step-dot {
    width: 30px; height: 30px;
    border-radius: 50%;
    border: 2px solid currentColor;
    display: flex; align-items: center; justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    transition: all .2s;
    background: transparent;
  }
  .step-item.done  .step-dot { background: var(--success); border-color: var(--success); color: #fff; }
  .step-item.active .step-dot { background: var(--accent); border-color: var(--accent); color: #fff; box-shadow: 0 0 16px rgba(108,61,244,.6); }
  .step-connector { width: 30px; height: 2px; background: var(--border); flex-shrink: 0; }

  /* Card */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 2rem;
    box-shadow: 0 8px 40px rgba(0,0,0,.4);
  }
  .card-header {
    display: flex; align-items: center; gap: 1rem;
    margin-bottom: 1.8rem;
    padding-bottom: 1.2rem;
    border-bottom: 1px solid var(--border);
  }
  .card-icon { font-size: 1.8rem; }
  .card-header h2 { font-size: 1.3rem; font-weight: 700; }
  .card-header p  { color: var(--muted); font-size: .85rem; margin-top: .2rem; }

  /* Formulier */
  .form-group { margin-bottom: 1.2rem; }
  label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .4rem; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
  input[type=text], input[type=email], input[type=password], input[type=url],
  input[type=number], select {
    width: 100%; padding: .7rem 1rem;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--text);
    font-size: .95rem;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
  }
  input:focus, select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(108,61,244,.2);
  }
  select option { background: #1a1f2e; }

  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
  .form-row-3 { display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; }

  /* Knop */
  .btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .8rem 2rem;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff; font-size: .95rem; font-weight: 700;
    border: none; border-radius: 8px; cursor: pointer;
    transition: opacity .2s, transform .1s, box-shadow .2s;
    box-shadow: 0 4px 20px rgba(108,61,244,.4);
  }
  .btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 6px 24px rgba(108,61,244,.5); }
  .btn:active { transform: translateY(0); }
  .btn-row { display: flex; justify-content: flex-end; margin-top: 1.5rem; }

  /* Check items (stap 1) */
  .checks { display: flex; flex-direction: column; gap: .6rem; }
  .check-item {
    display: flex; align-items: center; gap: .8rem;
    padding: .6rem 1rem;
    background: rgba(255,255,255,.03);
    border-radius: 8px;
    border: 1px solid var(--border);
    font-size: .9rem;
  }
  .check-ok   { color: var(--success); font-size: 1.1rem; }
  .check-fail { color: var(--error);   font-size: 1.1rem; }

  /* Modules checkboxes */
  .modules-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: .8rem;
    margin-top: .5rem;
  }
  .module-card {
    display: flex; align-items: center; gap: .7rem;
    padding: .8rem 1rem;
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border);
    border-radius: 8px;
    cursor: pointer;
    transition: border-color .2s, background .2s;
  }
  .module-card:has(input:checked) {
    border-color: var(--accent);
    background: rgba(108,61,244,.1);
  }
  .module-card input { display: none; }
  .module-icon { font-size: 1.3rem; }
  .module-name { font-size: .85rem; font-weight: 600; }
  .module-desc { font-size: .75rem; color: var(--muted); }
  .module-core { font-size: .7rem; color: var(--gold); font-weight: 700; text-transform: uppercase; }

  /* Alert */
  .alert {
    padding: .8rem 1rem; border-radius: 8px; margin-bottom: 1.2rem;
    font-size: .875rem;
  }
  .alert-error   { background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.3); color: #fca5a5; }
  .alert-success { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: #6ee7b7; }

  /* Success state (stap 5 klaar) */
  .success-wrap { text-align: center; padding: 2rem 0; }
  .success-icon { font-size: 4rem; margin-bottom: 1rem; }
  .success-wrap h3 { font-size: 1.5rem; font-weight: 800; color: var(--success); }
  .success-wrap p  { color: var(--muted); margin-top: .5rem; }
  .success-wrap .btn { margin-top: 1.5rem; }
</style>
</head>
<body>
<div class="installer">

  <!-- Logo -->
  <div class="logo">
    <h1>
      <?php if ($logoData !== ''): ?>
        <img src="<?= htmlspecialchars($logoData) ?>" alt="" style="height:1.4em;width:1.4em;vertical-align:-.28em;margin-right:.15em;">
      <?php else: ?>
        🔮
      <?php endif; ?>
      Blueprint CMS
    </h1>
    <p>Installatie wizard — v1.0.0</p>
  </div>

  <!-- Stap-indicator -->
  <div class="steps">
    <?php foreach (InstallerCore::STEPS as $n => $s): ?>
      <?php
        $cls = '';
        if ($n < $currentStep) $cls = 'done';
        elseif ($n === $currentStep) $cls = 'active';
        if ($n > 1): ?>
          <div class="step-connector"></div>
        <?php endif; ?>
      <div class="step-item <?= $cls ?>">
        <div class="step-dot">
          <?= $n < $currentStep ? '✓' : $n ?>
        </div>
        <span class="step-label"><?= htmlspecialchars($s['label']) ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Foutmeldingen -->
  <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
      <?php foreach ($errors as $err): ?>
        <div>⚠️ <?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Stap-inhoud -->
  <div class="card">
    <div class="card-header">
      <div class="card-icon"><?= InstallerCore::STEPS[$currentStep]['icon'] ?></div>
      <div>
        <h2>Stap <?= $currentStep ?> — <?= InstallerCore::STEPS[$currentStep]['label'] ?></h2>
        <p>
          <?php $descs = [
            1 => 'Controleer of je server aan alle vereisten voldoet.',
            2 => 'Geef je database verbindingsgegevens in.',
            3 => 'Stel je website in.',
            4 => 'Maak je beheerderaccount aan.',
            5 => 'Kies modules en rond de installatie af.',
          ]; echo $descs[$currentStep] ?? ''; ?>
        </p>
      </div>
    </div>

    <?php include __DIR__ . '/step' . $currentStep . '.php'; ?>
  </div>

  <p style="text-align:center;color:var(--muted);font-size:.75rem;margin-top:1.5rem;">
    © 2026 <a href="https://www.dieouwe.nl" style="color:var(--accent2);text-decoration:none;">DieOuwe</a>
    · <a href="https://www.scriptspace.nl" style="color:var(--accent2);text-decoration:none;">ScriptSpace</a>
    · Blueprint CMS v1.0.0 · GPL-3.0
  </p>

</div>
</body>
</html>
