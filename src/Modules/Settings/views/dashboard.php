<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin — Blueprint CMS</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<style>
  :root { --sidebar-w: 240px; }
  .admin-wrap { display: flex; min-height: 100vh; }

  /* Sidebar */
  .admin-sidebar {
    width: var(--sidebar-w);
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed; top: 0; left: 0; height: 100vh;
    z-index: 50;
  }
  .admin-logo {
    padding: 1.2rem 1.5rem;
    font-size: 1.1rem;
    font-weight: 800;
    background: linear-gradient(135deg, #a855f7, #6c3df4);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    border-bottom: 1px solid var(--border);
  }
  .admin-nav { padding: 1rem; flex: 1; }
  .admin-nav-section {
    font-size: .7rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .1em; color: var(--muted); padding: .8rem .5rem .3rem;
  }
  .admin-nav-link {
    display: flex; align-items: center; gap: .7rem;
    padding: .55rem .9rem;
    border-radius: 8px;
    font-size: .875rem;
    color: var(--text-dim);
    transition: all .15s;
    margin-bottom: 2px;
  }
  .admin-nav-link:hover, .admin-nav-link.active {
    background: rgba(108,61,244,.15);
    color: var(--accent2);
  }
  .admin-nav-link .nav-icon { font-size: 1rem; width: 20px; text-align: center; }

  /* Main */
  .admin-main {
    margin-left: var(--sidebar-w);
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
  }
  .admin-topbar {
    height: 56px;
    background: rgba(17,24,39,.95);
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 1.5rem;
    position: sticky; top: 0; z-index: 40;
    backdrop-filter: blur(8px);
  }
  .admin-topbar h1 { font-size: 1rem; font-weight: 700; }
  .admin-content { padding: 2rem; flex: 1; }

  /* Stat cards */
  .stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 1.2rem;
    margin-bottom: 2rem;
  }
  .stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.4rem;
    display: flex; align-items: flex-start; justify-content: space-between;
    transition: border-color .2s, box-shadow .2s;
  }
  .stat-card:hover { border-color: rgba(108,61,244,.4); box-shadow: var(--glow); }
  .stat-label { font-size: .78rem; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; margin-bottom: .4rem; }
  .stat-value { font-size: 2rem; font-weight: 800; line-height: 1; }
  .stat-icon  { font-size: 1.8rem; opacity: .6; }
  .stat-change { font-size: .78rem; color: var(--success); margin-top: .3rem; }

  /* Recente activiteit */
  .activity-feed { display: flex; flex-direction: column; gap: .6rem; }
  .activity-item {
    display: flex; align-items: center; gap: .8rem;
    padding: .7rem 1rem;
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: .875rem;
  }
  .activity-icon { font-size: 1.1rem; width: 28px; text-align: center; }
  .activity-time { margin-left: auto; color: var(--muted); font-size: .78rem; }

  /* Quick actions */
  .quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: .8rem;
    margin-bottom: 2rem;
  }
  .quick-btn {
    display: flex; flex-direction: column; align-items: center; gap: .5rem;
    padding: 1.2rem;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-dim);
    font-size: .8rem;
    font-weight: 600;
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
  }
  .quick-btn .qb-icon { font-size: 1.5rem; }
  .quick-btn:hover {
    border-color: var(--accent);
    color: var(--accent2);
    background: rgba(108,61,244,.08);
    transform: translateY(-2px);
  }

  .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; }
  @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="admin-wrap">

  <!-- Sidebar -->
  <aside class="admin-sidebar">
    <div class="admin-logo">🔮 Blueprint CMS</div>
    <nav class="admin-nav">

      <div class="admin-nav-section">Content</div>
      <a href="/admin" class="admin-nav-link active">
        <span class="nav-icon">📊</span> Dashboard
      </a>
      <a href="/admin/news" class="admin-nav-link">
        <span class="nav-icon">📰</span> Nieuws
      </a>
      <a href="/admin/pages" class="admin-nav-link">
        <span class="nav-icon">📄</span> Pagina's
      </a>
      <a href="/admin/media" class="admin-nav-link">
        <span class="nav-icon">🖼️</span> Media
      </a>

      <div class="admin-nav-section">Community</div>
      <a href="/admin/users" class="admin-nav-link">
        <span class="nav-icon">👥</span> Gebruikers
      </a>
      <a href="/admin/roles" class="admin-nav-link">
        <span class="nav-icon">🔑</span> Rollen
      </a>
      <a href="/forum" class="admin-nav-link">
        <span class="nav-icon">💬</span> Forum
      </a>
      <a href="/admin/contact" class="admin-nav-link">
        <span class="nav-icon">✉️</span> Contact
      </a>

      <div class="admin-nav-section">Uiterlijk</div>
      <a href="/admin/blocks" class="admin-nav-link">
        <span class="nav-icon">🧩</span> Blokken
      </a>
      <a href="/admin/themes" class="admin-nav-link">
        <span class="nav-icon">🎨</span> Thema's
      </a>
      <a href="/admin/menus" class="admin-nav-link">
        <span class="nav-icon">🔗</span> Menu's
      </a>

      <div class="admin-nav-section">Systeem</div>
      <a href="/admin/modules" class="admin-nav-link">
        <span class="nav-icon">⚙️</span> Modules
      </a>
      <a href="/admin/settings" class="admin-nav-link">
        <span class="nav-icon">🛠️</span> Instellingen
      </a>
      <a href="/admin/logs" class="admin-nav-link">
        <span class="nav-icon">📋</span> Logs
      </a>
      <a href="/admin/marketplace" class="admin-nav-link">
        <span class="nav-icon">🏪</span> Marketplace
      </a>

    </nav>
    <div style="padding:1rem;border-top:1px solid var(--border);">
      <a href="/" class="admin-nav-link">
        <span class="nav-icon">🌐</span> Bekijk Site
      </a>
      <a href="/logout" class="admin-nav-link">
        <span class="nav-icon">👋</span> Uitloggen
      </a>
    </div>
  </aside>

  <!-- Main -->
  <div class="admin-main">
    <header class="admin-topbar">
      <h1>Dashboard</h1>
      <div style="display:flex;gap:.8rem;align-items:center;">
        <span style="font-size:.8rem;color:var(--muted);">Blueprint CMS v1.0.0</span>
        <a href="/admin/settings" class="cf-btn-sm">⚙️ Instellingen</a>
      </div>
    </header>

    <div class="admin-content">

      <!-- Stat cards -->
      <div class="stat-grid">
        <div class="stat-card">
          <div>
            <div class="stat-label">Gebruikers</div>
            <div class="stat-value">—</div>
            <div class="stat-change">Geregistreerd</div>
          </div>
          <div class="stat-icon">👥</div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label">Nieuws Artikelen</div>
            <div class="stat-value">—</div>
            <div class="stat-change">Gepubliceerd</div>
          </div>
          <div class="stat-icon">📰</div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label">Pagina's</div>
            <div class="stat-value">—</div>
            <div class="stat-change">Actief</div>
          </div>
          <div class="stat-icon">📄</div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label">Modules</div>
            <div class="stat-value">4</div>
            <div class="stat-change">Actief</div>
          </div>
          <div class="stat-icon">🧩</div>
        </div>
      </div>

      <!-- Quick actions -->
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--muted);">⚡ Snelle Acties</h2>
      <div class="quick-actions">
        <a href="/admin/news/create" class="quick-btn">
          <span class="qb-icon">✍️</span> Nieuw Artikel
        </a>
        <a href="/admin/pages/create" class="quick-btn">
          <span class="qb-icon">📄</span> Nieuwe Pagina
        </a>
        <a href="/admin/users" class="quick-btn">
          <span class="qb-icon">👤</span> Gebruikers
        </a>
        <a href="/admin/blocks" class="quick-btn">
          <span class="qb-icon">🧩</span> Blokken
        </a>
        <a href="/admin/modules" class="quick-btn">
          <span class="qb-icon">⚙️</span> Modules
        </a>
        <a href="/admin/themes" class="quick-btn">
          <span class="qb-icon">🎨</span> Thema's
        </a>
        <a href="/admin/marketplace" class="quick-btn">
          <span class="qb-icon">🏪</span> Marketplace
        </a>
        <a href="/admin/logs" class="quick-btn">
          <span class="qb-icon">📋</span> Logs
        </a>
      </div>

      <!-- 2-kolom: Recente activiteit + systeem status -->
      <div class="two-col">
        <div>
          <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--muted);">📋 Recente Activiteit</h2>
          <div class="activity-feed">
            <div class="activity-item">
              <span class="activity-icon">🔮</span>
              <span>Blueprint CMS v1.0.0 geïnstalleerd</span>
              <span class="activity-time">Nu</span>
            </div>
            <div class="activity-item">
              <span class="activity-icon">👤</span>
              <span>Admin account aangemaakt</span>
              <span class="activity-time">Zojuist</span>
            </div>
            <div class="activity-item">
              <span class="activity-icon">🗄️</span>
              <span>Database schema geïmporteerd</span>
              <span class="activity-time">Zojuist</span>
            </div>
          </div>
        </div>

        <div>
          <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--muted);">💻 Systeem Status</h2>
          <div class="cf-card">
            <div class="cf-card-body">
              <?php
              $checks = [
                ['PHP Versie',    PHP_VERSION,                         true],
                ['Database',      'MariaDB / MySQL',                   true],
                ['Cache',         'File driver actief',                true],
                ['Queue',         'Database driver actief',            true],
                ['Debug Mode',    ini_get('display_errors') ? 'AAN' : 'UIT', !ini_get('display_errors')],
              ];
              foreach ($checks as [$label, $val, $ok]):
              ?>
              <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.85rem;">
                <span style="color:var(--muted);"><?= htmlspecialchars($label) ?></span>
                <span style="color:<?= $ok ? 'var(--success)' : 'var(--warning)' ?>">
                  <?= htmlspecialchars($val) ?>
                </span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /admin-content -->
  </div><!-- /admin-main -->
</div><!-- /admin-wrap -->
</body>
</html>
