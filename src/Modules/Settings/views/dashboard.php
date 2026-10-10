<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// S13 (Multi-language/i18n) — sidebar was hier tot nu toe handmatig
// gedupliceerd i.p.v. de gedeelde Shared/views/admin_sidebar.php partial te
// includen (zie het kop-commentaar in dat bestand, dat dit al als goede
// vervolgstap noemde). Nu wél de partial: dat scheelt de dubbele markup EN
// levert de i18n-conversie van de sidebar hier gratis mee.
use CommunityFusion\Core\I18n\Trans;

$activeNav = 'dashboard';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Trans::locale(), ENT_QUOTES) ?>">
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

  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <!-- Main -->
  <div class="admin-main">
    <header class="admin-topbar">
      <h1><?= htmlspecialchars(Trans::get('admin.dashboard.title')) ?></h1>
      <div style="display:flex;gap:.8rem;align-items:center;">
        <span style="font-size:.8rem;color:var(--muted);">Blueprint CMS v1.0.0</span>
        <a href="/admin/settings" class="cf-btn-sm">⚙️ <?= htmlspecialchars(Trans::get('admin.dashboard.settings_btn')) ?></a>
      </div>
    </header>

    <div class="admin-content">

      <!-- Stat cards -->
      <div class="stat-grid">
        <div class="stat-card">
          <div>
            <div class="stat-label"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_users')) ?></div>
            <div class="stat-value">—</div>
            <div class="stat-change"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_users_sub')) ?></div>
          </div>
          <div class="stat-icon">👥</div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_news')) ?></div>
            <div class="stat-value">—</div>
            <div class="stat-change"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_news_sub')) ?></div>
          </div>
          <div class="stat-icon">📰</div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_pages')) ?></div>
            <div class="stat-value">—</div>
            <div class="stat-change"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_pages_sub')) ?></div>
          </div>
          <div class="stat-icon">📄</div>
        </div>
        <div class="stat-card">
          <div>
            <div class="stat-label"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_modules')) ?></div>
            <div class="stat-value">4</div>
            <div class="stat-change"><?= htmlspecialchars(Trans::get('admin.dashboard.stat_modules_sub')) ?></div>
          </div>
          <div class="stat-icon">🧩</div>
        </div>
      </div>

      <!-- Quick actions -->
      <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--muted);">⚡ <?= htmlspecialchars(Trans::get('admin.dashboard.quick_actions')) ?></h2>
      <div class="quick-actions">
        <a href="/admin/news/create" class="quick-btn">
          <span class="qb-icon">✍️</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_new_article')) ?>
        </a>
        <a href="/admin/pages/create" class="quick-btn">
          <span class="qb-icon">📄</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_new_page')) ?>
        </a>
        <a href="/admin/users" class="quick-btn">
          <span class="qb-icon">👤</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_users')) ?>
        </a>
        <a href="/admin/blocks" class="quick-btn">
          <span class="qb-icon">🧩</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_blocks')) ?>
        </a>
        <a href="/admin/modules" class="quick-btn">
          <span class="qb-icon">⚙️</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_modules')) ?>
        </a>
        <a href="/admin/themes" class="quick-btn">
          <span class="qb-icon">🎨</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_themes')) ?>
        </a>
        <a href="/admin/marketplace" class="quick-btn">
          <span class="qb-icon">🏪</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_marketplace')) ?>
        </a>
        <a href="/admin/logs" class="quick-btn">
          <span class="qb-icon">📋</span> <?= htmlspecialchars(Trans::get('admin.dashboard.action_logs')) ?>
        </a>
        <a href="/admin/backup" class="quick-btn">
          <span class="qb-icon">💾</span> <?= htmlspecialchars(Trans::get('admin.sidebar.backup')) ?>
        </a>
        <a href="/admin/database" class="quick-btn">
          <span class="qb-icon">🗄️</span> <?= htmlspecialchars(Trans::get('admin.sidebar.database')) ?>
        </a>
      </div>

      <!-- 2-kolom: Recente activiteit + systeem status -->
      <div class="two-col">
        <div>
          <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--muted);">📋 <?= htmlspecialchars(Trans::get('admin.dashboard.recent_activity')) ?></h2>
          <div class="activity-feed">
            <div class="activity-item">
              <span class="activity-icon">🔮</span>
              <span><?= htmlspecialchars(Trans::get('admin.dashboard.activity_installed')) ?></span>
              <span class="activity-time"><?= htmlspecialchars(Trans::get('admin.dashboard.activity_now')) ?></span>
            </div>
            <div class="activity-item">
              <span class="activity-icon">👤</span>
              <span><?= htmlspecialchars(Trans::get('admin.dashboard.activity_admin_created')) ?></span>
              <span class="activity-time"><?= htmlspecialchars(Trans::get('admin.dashboard.activity_just_now')) ?></span>
            </div>
            <div class="activity-item">
              <span class="activity-icon">🗄️</span>
              <span><?= htmlspecialchars(Trans::get('admin.dashboard.activity_schema_imported')) ?></span>
              <span class="activity-time"><?= htmlspecialchars(Trans::get('admin.dashboard.activity_just_now')) ?></span>
            </div>
          </div>
        </div>

        <div>
          <h2 style="font-size:1rem;font-weight:700;margin-bottom:1rem;color:var(--muted);">💻 <?= htmlspecialchars(Trans::get('admin.dashboard.system_status')) ?></h2>
          <div class="cf-card">
            <div class="cf-card-body">
              <?php
              $checks = [
                [Trans::get('admin.dashboard.check_php_version'), PHP_VERSION, true],
                [Trans::get('admin.dashboard.check_database'),    Trans::get('admin.dashboard.check_database_val'), true],
                [Trans::get('admin.dashboard.check_cache'),       Trans::get('admin.dashboard.check_cache_val'),    true],
                [Trans::get('admin.dashboard.check_queue'),       Trans::get('admin.dashboard.check_queue_val'),    true],
                [
                    Trans::get('admin.dashboard.check_debug_mode'),
                    ini_get('display_errors') ? Trans::get('admin.dashboard.check_debug_on') : Trans::get('admin.dashboard.check_debug_off'),
                    !ini_get('display_errors'),
                ],
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
