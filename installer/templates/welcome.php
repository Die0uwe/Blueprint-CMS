<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================

// Welkomstscherm — de allereerste pagina die een verse installatie toont,
// vóór stap 1 van de wizard. Logo wordt als data-URI ingebed (niet als
// <img src="/assets/..."> gelinkt) omdat dit scherm draait vóórdat we
// weten of /assets/ via de actieve document root bereikbaar is — precies
// de klasse fouten die v1.25.1/v1.25.2 al kostte bij de installer zelf.
// Dependency-vrij en inline, zoals de rest van de installer.
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
<title>Blueprint CMS — Welkom</title>
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

  .welcome {
    width: 100%;
    max-width: 560px;
    padding: 2rem 1.5rem;
    text-align: center;
  }

  .logo-mark {
    width: 88px;
    height: 88px;
    margin: 0 auto 1.5rem;
    border-radius: 22px;
    background: rgba(108,61,244,.12);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 40px rgba(108,61,244,.25);
  }
  .logo-mark img { width: 60px; height: 60px; display: block; }

  h1 {
    font-size: 2rem;
    font-weight: 800;
    background: linear-gradient(135deg, #a855f7, #6c3df4, #f59e0b);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: -.5px;
    margin-bottom: .6rem;
  }

  .tagline { color: var(--muted); font-size: .95rem; margin-bottom: 2rem; }

  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.8rem;
    box-shadow: 0 8px 40px rgba(0,0,0,.4);
    text-align: left;
  }
  .card p { color: var(--text); font-size: .92rem; line-height: 1.6; margin-bottom: 1rem; }
  .card p:last-of-type { margin-bottom: 0; }
  .card strong { color: #fff; }

  .feature-list {
    list-style: none;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .5rem .8rem;
    margin: 1.2rem 0;
    font-size: .85rem;
    color: var(--muted);
  }
  .feature-list li::before { content: "✓ "; color: var(--accent2); font-weight: 700; }

  .btn {
    display: inline-flex; align-items: center; gap: .5rem;
    width: 100%;
    justify-content: center;
    padding: .9rem 2rem;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    color: #fff; font-size: 1rem; font-weight: 700;
    border: none; border-radius: 8px; cursor: pointer;
    text-decoration: none;
    transition: opacity .2s, transform .1s, box-shadow .2s;
    box-shadow: 0 4px 20px rgba(108,61,244,.4);
    margin-top: 1.5rem;
  }
  .btn:hover { opacity: .9; transform: translateY(-1px); box-shadow: 0 6px 24px rgba(108,61,244,.5); }
  .btn:active { transform: translateY(0); }

  .footnote { text-align: center; color: var(--muted); font-size: .75rem; margin-top: 1.5rem; }
  .footnote a { color: var(--accent2); text-decoration: none; }
</style>
</head>
<body>
<div class="welcome">

  <?php if ($logoData !== ''): ?>
    <div class="logo-mark"><img src="<?= htmlspecialchars($logoData) ?>" alt="Blueprint CMS"></div>
  <?php endif; ?>

  <h1>Blueprint CMS</h1>
  <p class="tagline">Modulair, gaming- en community-gericht CMS — installeer binnen enkele minuten.</p>

  <div class="card">
    <p>
      Welkom! Dit installatiescript zet in vijf korte stappen een compleet
      werkende website voor je klaar: <strong>servercontrole</strong>,
      <strong>database</strong>, <strong>site-instellingen</strong>, je
      <strong>beheerdersaccount</strong> en de <strong>modules</strong> die je
      wilt gebruiken (Discord, Twitch, Guild Management, en meer).
    </p>
    <ul class="feature-list">
      <li>Blokkensysteem</li>
      <li>Discord / Twitch / YouTube</li>
      <li>Forum &amp; Nieuws</li>
      <li>Guild management</li>
      <li>RBAC-rechtensysteem</li>
      <li>Meertalig (nl/en/de)</li>
    </ul>
    <p>
      Zorg dat je database-gegevens bij de hand zijn — de volgende stap
      controleert eerst of je server aan alle vereisten voldoet.
    </p>
    <a class="btn" href="?step=1">Installatie starten →</a>
  </div>

  <p class="footnote">
    © 2026 <a href="https://www.dieouwe.nl">DieOuwe</a>
    · <a href="https://www.scriptspace.nl">ScriptSpace</a>
    · Blueprint CMS v1.0.0 · GPL-3.0
  </p>

</div>
</body>
</html>
