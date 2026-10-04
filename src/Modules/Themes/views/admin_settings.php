<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Vanuit ThemeSettingsController::index():
// $tab, $tabs, $s (gevalideerde instellingen), $icon, $presets, $colorFields, $flash, $error
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'themes';
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$flashText = ['opgeslagen' => 'Instellingen opgeslagen.', 'hersteld' => 'Kleuren hersteld naar de themakleuren.'][$flash ?? ''] ?? null;
$themeColorVarFallback = ['color_primary' => '#a855f7', 'color_secondary' => '#38bdf8', 'color_background' => '#0f0f1a', 'color_accent' => '#ccaa00'];
?>
<!DOCTYPE html>
<html lang="<?= $e(\CommunityFusion\Core\I18n\Trans::locale()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Thema-instellingen — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  .ts-tabs{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;border-bottom:1px solid var(--border);}
  .ts-tab{padding:.55rem .9rem;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;color:var(--text-dim);}
  .ts-tab.active{background:var(--surface);border-color:var(--border);color:var(--text);font-weight:700;}
  .ts-tab.reserved{opacity:.55;}
  .ts-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.75rem;}
  .ts-preset{display:block;border:1px solid var(--border);border-radius:10px;padding:.7rem;cursor:pointer;}
  .ts-preset input{margin-right:.4rem;}
  .ts-preset small{display:block;color:var(--text-dim);}
  .ts-row{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;margin-bottom:.6rem;}
  .ts-preview-img{max-width:100%;max-height:120px;border:1px solid var(--border);border-radius:8px;display:block;margin:.4rem 0;}
  #cf-preview{padding:1rem;border-radius:12px;border:1px solid var(--border);background:var(--bg);}
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>⚙️ Thema-instellingen</h1>
      <a href="/admin/themes" class="cf-btn-ghost">← Thema's</a>
    </header>

    <div class="admin-content">
      <?php if ($flashText): ?><div class="cf-alert cf-alert-success"><?= $e($flashText) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="cf-alert cf-alert-error"><?= $e($error) ?></div><?php endif; ?>

      <nav class="ts-tabs" aria-label="Thema-instellingen">
        <?php foreach ($tabs as $n => $t): ?>
          <a class="ts-tab<?= $n === $tab ? ' active' : '' ?><?= $n >= 4 ? ' reserved' : '' ?>"
             href="/admin/themes/instellingen?tab=<?= (int) $n ?>"><?= $n ?>. <?= $e($t['label']) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($tab === 1): ?>
      <!-- TAB 1: Algemeen & layout -->
      <form method="post" action="/admin/themes/instellingen/algemeen" class="cf-card" style="padding:1.25rem;">
        <?= CsrfProtection::field() ?>
        <h2 style="margin-top:0;">Layout</h2>
        <div class="ts-row">
          <label><input type="radio" name="layout_mode" value="wide" <?= $s['layout_mode'] === 'wide' ? 'checked' : '' ?>> <strong>Wide</strong> — volle breedte, inhoud tot de maximale breedte</label>
          <label><input type="radio" name="layout_mode" value="boxed" <?= $s['layout_mode'] === 'boxed' ? 'checked' : '' ?>> <strong>Boxed</strong> — de hele site in een gecentreerd vak</label>
        </div>

        <h3>Presets</h3>
        <div class="ts-grid" id="ts-presets">
          <?php foreach ($presets as $id => $p): ?>
            <label class="ts-preset">
              <input type="radio" name="layout_preset" value="<?= $e($id) ?>"
                     data-mode="<?= $e($p['mode']) ?>" data-width="<?= (int) $p['width'] ?>" data-sidebar="<?= (int) $p['sidebar'] ?>"
                     <?= $s['layout_preset'] === $id ? 'checked' : '' ?>>
              <strong><?= $e($p['label']) ?></strong>
              <small><?= $e(ucfirst($p['mode'])) ?> · <?= (int) $p['width'] ?>px · zijbalk <?= (int) $p['sidebar'] ?>px</small>
            </label>
          <?php endforeach; ?>
          <label class="ts-preset">
            <input type="radio" name="layout_preset" value="aangepast" <?= $s['layout_preset'] === 'aangepast' ? 'checked' : '' ?>>
            <strong>Aangepast</strong><small>Eigen breedtes hieronder</small>
          </label>
        </div>

        <div class="ts-row" style="margin-top:1rem;">
          <label>Inhoudsbreedte (900–1800 px)
            <input type="number" class="cf-input" name="layout_width" id="layout_width" min="900" max="1800" step="10" value="<?= (int) $s['layout_width'] ?>"></label>
          <label>Zijbalkbreedte (180–360 px)
            <input type="number" class="cf-input" name="sidebar_width" id="sidebar_width" min="180" max="360" step="10" value="<?= (int) $s['sidebar_width'] ?>"></label>
        </div>
        <button type="submit" class="cf-btn">Opslaan</button>
      </form>
      <script>
      (function(){
        var f=document.getElementById('ts-presets'); if(!f) return;
        f.addEventListener('change',function(e){
          var r=e.target; if(r.name!=='layout_preset'||!r.dataset.mode) return;
          document.querySelector('input[name=layout_mode][value='+r.dataset.mode+']').checked=true;
          document.getElementById('layout_width').value=r.dataset.width;
          document.getElementById('sidebar_width').value=r.dataset.sidebar;
        });
        ['layout_width','sidebar_width'].forEach(function(id){
          document.getElementById(id).addEventListener('input',function(){
            document.querySelector('input[name=layout_preset][value=aangepast]').checked=true; });
        });
        document.querySelectorAll('input[name=layout_mode]').forEach(function(r){
          r.addEventListener('change',function(){ document.querySelector('input[name=layout_preset][value=aangepast]').checked=true; });
        });
      })();
      </script>

      <?php elseif ($tab === 2): ?>
      <!-- TAB 2: Branding & headers -->
      <form method="post" action="/admin/themes/instellingen/branding" enctype="multipart/form-data" class="cf-card" style="padding:1.25rem;">
        <?= CsrfProtection::field() ?>
        <h2 style="margin-top:0;">Logo</h2>
        <?php if ($s['logo'] !== ''): ?><img class="ts-preview-img" src="<?= $e($s['logo']) ?>" alt="Huidig logo"><?php endif; ?>
        <div class="ts-row">
          <input type="file" name="logo" accept="image/png,image/jpeg,image/gif,image/webp">
          <?php if ($s['logo'] !== ''): ?><label><input type="checkbox" name="remove_logo"> Logo verwijderen</label><?php endif; ?>
        </div>
        <label><input type="checkbox" name="logo_show_name" <?= $s['logo_show_name'] === '1' ? 'checked' : '' ?>> Sitenaam naast het logo blijven tonen</label>

        <h2>Site-icoon (favicon)</h2>
        <p style="color:var(--text-dim);font-size:.85rem;margin-top:0;">Dezelfde instelling als onder Instellingen → Site-icoon.</p>
        <?php if ($icon !== ''): ?><img class="ts-preview-img" style="max-height:48px;" src="<?= $e($icon) ?>" alt="Huidig icoon"><?php endif; ?>
        <div class="ts-row">
          <input type="file" name="site_icon" accept="image/png,image/jpeg,image/gif,image/webp">
          <?php if ($icon !== ''): ?><label><input type="checkbox" name="remove_site_icon"> Icoon verwijderen</label><?php endif; ?>
        </div>

        <h2>Headerbanner</h2>
        <?php if ($s['banner'] !== ''): ?><img class="ts-preview-img" src="<?= $e($s['banner']) ?>" alt="Huidige banner"><?php endif; ?>
        <div class="ts-row">
          <input type="file" name="banner" accept="image/png,image/jpeg,image/gif,image/webp">
          <?php if ($s['banner'] !== ''): ?><label><input type="checkbox" name="remove_banner"> Banner verwijderen</label><?php endif; ?>
        </div>
        <label>Bannerhoogte (80–600 px)
          <input type="number" class="cf-input" name="banner_height" min="80" max="600" step="10" value="<?= (int) $s['banner_height'] ?>" style="max-width:140px;"></label>
        <p style="color:var(--text-dim);font-size:.85rem;">Afbeeldingen: jpg, png, gif of webp. De banner staat onder het hoofdmenu, over de volle breedte van de inhoud.</p>
        <button type="submit" class="cf-btn">Opslaan</button>
      </form>

      <?php elseif ($tab === 3): ?>
      <!-- TAB 3: Kleuren & stijl -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;">
        <form method="post" action="/admin/themes/instellingen/kleuren" class="cf-card" style="padding:1.25rem;" id="ts-colors">
          <?= CsrfProtection::field() ?>
          <h2 style="margin-top:0;">Kleuren</h2>
          <p style="color:var(--text-dim);font-size:.85rem;margin-top:0;">Overschrijft de kleuren van het actieve thema. Zet een vinkje bij “themakleur” om weer de standaardkleur van het thema te gebruiken.</p>
          <?php foreach ($colorFields as $key => $f): $val = $s[$key]; ?>
            <div class="ts-row">
              <label style="min-width:110px;"><strong><?= $e($f['label']) ?></strong></label>
              <input type="color" name="<?= $e($key) ?>" id="<?= $e($key) ?>" data-var="<?= $e($f['var']) ?>" data-rgb="<?= $e($f['rgb'] ?? '') ?>"
                     value="<?= $e($val !== '' ? $val : $themeColorVarFallback[$key]) ?>" <?= $val === '' ? 'disabled' : '' ?>>
              <label><input type="checkbox" name="use_theme_<?= $e($key) ?>" data-for="<?= $e($key) ?>" <?= $val === '' ? 'checked' : '' ?>> themakleur</label>
            </div>
          <?php endforeach; ?>
          <button type="submit" class="cf-btn">Opslaan</button>
          <button type="submit" name="reset" value="1" class="cf-btn cf-btn-ghost">Alles terug naar thema</button>
        </form>

        <div class="cf-card" style="padding:1.25rem;">
          <h2 style="margin-top:0;">Live voorbeeld</h2>
          <div id="cf-preview">
            <div class="cf-card-header" style="margin-bottom:.5rem;">Voorbeeldkaart</div>
            <p>Gewone tekst met een <a href="#" onclick="return false;">link</a>.</p>
            <button type="button" class="cf-btn">Primaire knop</button>
            <span class="cf-badge cf-badge-purple">Badge</span>
          </div>
        </div>
      </div>
      <script>
      (function(){
        var form=document.getElementById('ts-colors'), pv=document.getElementById('cf-preview'); if(!form) return;
        function rgb(h){return parseInt(h.substr(1,2),16)+','+parseInt(h.substr(3,2),16)+','+parseInt(h.substr(5,2),16);}
        function lum(h){var c=[1,3,5].map(function(i){var v=parseInt(h.substr(i,2),16)/255;return v<=.03928?v/12.92:Math.pow((v+.055)/1.055,2.4);});return .2126*c[0]+.7152*c[1]+.0722*c[2];}
        function apply(inp){
          var off=form.querySelector('[data-for='+inp.id+']').checked, v=inp.dataset.var, r=inp.dataset.rgb;
          if(off){ pv.style.removeProperty(v); if(r) pv.style.removeProperty(r); if(inp.id==='color_primary') pv.style.removeProperty('--on-accent'); return; }
          pv.style.setProperty(v,inp.value); if(r) pv.style.setProperty(r,rgb(inp.value));
          if(inp.id==='color_primary') pv.style.setProperty('--on-accent',lum(inp.value)>.5?'#0b0b0f':'#ffffff');
        }
        form.querySelectorAll('input[type=color]').forEach(function(inp){
          inp.addEventListener('input',function(){apply(inp);}); apply(inp);
        });
        form.querySelectorAll('input[data-for]').forEach(function(cb){
          cb.addEventListener('change',function(){ var inp=document.getElementById(cb.dataset.for); inp.disabled=cb.checked; apply(inp); });
        });
      })();
      </script>

      <?php else: ?>
      <!-- TAB 4 / 5: bewust leeg, gereserveerd voor uitbreiding -->
      <div class="cf-card" style="padding:2rem;text-align:center;color:var(--text-dim);">
        <div style="font-size:2rem;">🧩</div>
        <p>Dit tabblad is gereserveerd voor toekomstige uitbreiding van de thema-instellingen.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
