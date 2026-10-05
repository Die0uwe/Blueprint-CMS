<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// Vanuit ThemeSettingsController::index():
// $tab, $tabs, $s (gevalideerde instellingen), $icon, $presets, $colorFields, $layout, $flash, $error
use CommunityFusion\Core\Security\CsrfProtection;

$activeNav = 'themes';
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$flashText = ['opgeslagen' => 'Instellingen opgeslagen.', 'hersteld' => 'Kleuren hersteld naar de themakleuren.'][$flash ?? ''] ?? null;
$themeColorVarFallback = ['color_primary' => '#a855f7', 'color_secondary' => '#38bdf8', 'color_background' => '#0f0f1a', 'color_accent' => '#ccaa00',
    'color_text' => '#e2e8f0', 'color_link' => '#a855f7', 'color_surface' => '#111827', 'color_border' => '#1e2940'];
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
  .lb-cell{border:1px solid var(--border);border-radius:10px;padding:.7rem;background:var(--surface);cursor:grab;}
  .lb-cell.dragging{opacity:.4;}
  .lb-cell.over{outline:2px dashed var(--accent);}
  .lb-head{display:flex;gap:.4rem;align-items:center;margin-bottom:.5rem;}
  .lb-head select{flex:1;}
  .lb-cell input[type=text],.lb-cell textarea{width:100%;box-sizing:border-box;margin-bottom:.4rem;}
  .lb-grid{display:grid;gap:.75rem;margin:.75rem 0;}
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
          <a class="ts-tab<?= $n === $tab ? ' active' : '' ?><?= $n >= 5 ? ' reserved' : '' ?>"
             href="/admin/themes/instellingen?tab=<?= (int) $n ?>"><?= $n ?>. <?= $e($t['label']) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($tab === 1): ?>
      <!-- TAB 1: Algemeen & layout -->
      <form method="post" action="/admin/themes/instellingen/algemeen" class="cf-card" style="padding:1.25rem;">
        <?= CsrfProtection::field() ?>
        <h2 style="margin-top:0;">Layout &amp; weergave</h2>
        <div class="ts-grid" id="ts-modes">
          <?php foreach ([
              'wide'  => ['Wide', 'Inhoud tot een maximale breedte (px)'],
              'boxed' => ['Boxed', 'De hele site in een gecentreerd vak (px)'],
              'fluid' => ['Fluid / auto', 'Breedte schaalt mee: % van het scherm (slider)'],
              'full'  => ['Full screen', 'Van schermrand tot schermrand'],
          ] as $m => [$lbl, $desc]): ?>
            <label class="ts-preset"><input type="radio" name="layout_mode" value="<?= $m ?>" <?= $s['layout_mode'] === $m ? 'checked' : '' ?>>
              <strong><?= $e($lbl) ?></strong><small><?= $e($desc) ?></small></label>
          <?php endforeach; ?>
        </div>

        <div class="ts-row" style="margin-top:1rem;flex-direction:column;align-items:stretch;gap:1rem;">
          <label id="row-width">Inhoudsbreedte (Wide/Boxed): <output id="out-width"><?= (int) $s['layout_width'] ?></output> px
            <input type="range" name="layout_width" id="layout_width" min="900" max="3840" step="10" value="<?= (int) $s['layout_width'] ?>" style="width:100%"></label>
          <label id="row-fluid">Breedte (Fluid): <output id="out-fluid"><?= (int) $s['layout_fluid'] ?></output> % van het scherm
            <input type="range" name="layout_fluid" id="layout_fluid" min="50" max="100" step="1" value="<?= (int) $s['layout_fluid'] ?>" style="width:100%"></label>
          <label>Zijbalkbreedte (beide zijden): <output id="out-sidebar"><?= (int) $s['sidebar_width'] ?></output> px
            <input type="range" name="sidebar_width" id="sidebar_width" min="180" max="360" step="10" value="<?= (int) $s['sidebar_width'] ?>" style="width:100%"></label>
          <label>Linker zijbalk: <output id="out-left"><?= (int) $s['sidebar_left'] > 0 ? (int) $s['sidebar_left'] . ' px' : 'zelfde als hierboven' ?></output>
            <input type="range" name="sidebar_left" id="sidebar_left" min="170" max="480" step="10" value="<?= (int) $s['sidebar_left'] > 0 ? (int) $s['sidebar_left'] : 170 ?>" style="width:100%"></label>
          <label>Rechter zijbalk: <output id="out-right"><?= (int) $s['sidebar_right'] > 0 ? (int) $s['sidebar_right'] . ' px' : 'zelfde als hierboven' ?></output>
            <input type="range" name="sidebar_right" id="sidebar_right" min="170" max="480" step="10" value="<?= (int) $s['sidebar_right'] > 0 ? (int) $s['sidebar_right'] : 170 ?>" style="width:100%"></label>
        </div>
        <div id="ts-preview" aria-hidden="true" style="margin:1rem 0;border:1px dashed var(--border);border-radius:8px;padding:.5rem;background:var(--bg2,transparent);">
          <div id="ts-preview-bar" style="height:28px;margin:0 auto;border-radius:6px;background:var(--accent);opacity:.65;"></div>
          <small style="color:var(--text-dim);">Schematisch (breedte t.o.v. een scherm van 1920 px)</small>
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
            <strong>Aangepast</strong><small>Eigen modus en breedtes</small>
          </label>
        </div>

        <button type="submit" class="cf-btn">Opslaan</button>
      </form>
      <script>
      (function(){
        var $=function(id){return document.getElementById(id);};
        function mode(){var r=document.querySelector('input[name=layout_mode]:checked');return r?r.value:'wide';}
        function custom(){var c=document.querySelector('input[name=layout_preset][value=aangepast]'); if(c) c.checked=true;}
        function sync(){
          var m=mode();
          $('out-width').textContent=$('layout_width').value;
          $('out-fluid').textContent=$('layout_fluid').value;
          $('out-sidebar').textContent=$('sidebar_width').value;
          $('out-left').textContent=(+$('sidebar_left').value<180)?'zelfde als hierboven':$('sidebar_left').value+' px';
          $('out-right').textContent=(+$('sidebar_right').value<180)?'zelfde als hierboven':$('sidebar_right').value+' px';
          $('row-width').style.display=(m==='wide'||m==='boxed')?'':'none';
          $('row-fluid').style.display=(m==='fluid')?'':'none';
          var pct=m==='full'?100:(m==='fluid'?+$('layout_fluid').value:Math.min(100,+$('layout_width').value/1920*100));
          $('ts-preview-bar').style.width=pct+'%';
        }
        var f=$('ts-presets');
        f.addEventListener('change',function(e){
          var r=e.target; if(r.name!=='layout_preset'||!r.dataset.mode) return;
          document.querySelector('input[name=layout_mode][value='+r.dataset.mode+']').checked=true;
          $('layout_width').value=r.dataset.width; $('sidebar_width').value=r.dataset.sidebar;
          $('sidebar_left').value=170; $('sidebar_right').value=170; sync();
        });
        ['layout_width','layout_fluid','sidebar_width','sidebar_left','sidebar_right'].forEach(function(id){
          $(id).addEventListener('input',function(){ custom(); sync(); });
        });
        document.querySelectorAll('input[name=layout_mode]').forEach(function(r){
          r.addEventListener('change',function(){ custom(); sync(); });
        });
        sync();
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

      <?php elseif ($tab === 4): ?>
      <!-- TAB 4: Header & footer — grid-builder -->
      <?php $cellTypes = ['text' => '📝 Tekst', 'links' => '🔗 Links', 'siteinfo' => 'ℹ️ Site-info', 'blocks' => '🧱 Footer-blokken', 'copyright' => '© Copyright']; ?>
      <form method="post" action="/admin/themes/instellingen/layout" id="lb-form">
        <?= CsrfProtection::field() ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;">
          <div class="cf-card" style="padding:1.25rem;">
            <h2 style="margin-top:0;">Header</h2>
            <div class="ts-row">
              <label>Logo-uitlijning
                <select name="logo_align">
                  <?php foreach (['left' => 'Links', 'center' => 'Midden (eigen rij)', 'right' => 'Rechts'] as $v => $l): ?>
                    <option value="<?= $e($v) ?>"<?= $layout['header']['logo_align'] === $v ? ' selected' : '' ?>><?= $e($l) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label>Menu-uitlijning
                <select name="nav_align">
                  <?php foreach (['left' => 'Links', 'center' => 'Midden', 'right' => 'Rechts'] as $v => $l): ?>
                    <option value="<?= $e($v) ?>"<?= $layout['header']['nav_align'] === $v ? ' selected' : '' ?>><?= $e($l) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <label class="ts-row"><input type="checkbox" name="show_motd"<?= $layout['header']['show_motd'] ? ' checked' : '' ?>> Site-info (motto) onder de sitenaam tonen</label>
            <label class="ts-row"><input type="checkbox" name="sticky"<?= $layout['header']['sticky'] ? ' checked' : '' ?>> Header blijft bovenaan staan bij scrollen</label>
            <p style="color:var(--text-dim);font-size:.8rem;">Uitlijning werkt vanaf tablet/desktop; op mobiel blijft het hamburgermenu.</p>
          </div>
          <div class="cf-card" style="padding:1.25rem;">
            <h2 style="margin-top:0;">Footer-grid</h2>
            <label>Kolommen
              <select name="footer_columns" id="lb-cols">
                <?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"<?= (int) $layout['footer']['columns'] === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?>
              </select>
            </label>
            <p style="color:var(--text-dim);font-size:.85rem;">Sleep de cellen om de volgorde te wijzigen (of gebruik ▲ ▼). Zonder cellen blijft de klassieke footer staan.</p>
          </div>
        </div>

        <div id="lb-list" class="lb-grid" style="grid-template-columns:repeat(<?= (int) $layout['footer']['columns'] ?>,minmax(0,1fr));">
          <?php foreach ($layout['footer']['cells'] as $c): ?>
            <div class="lb-cell" draggable="true">
              <div class="lb-head">
                <select name="cell_type[]"><?php foreach ($cellTypes as $v => $l): ?><option value="<?= $e($v) ?>"<?= $c['type'] === $v ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select>
                <button type="button" class="cf-btn-sm cf-btn-ghost" data-lb="up" aria-label="Omhoog">▲</button>
                <button type="button" class="cf-btn-sm cf-btn-ghost" data-lb="down" aria-label="Omlaag">▼</button>
                <button type="button" class="cf-btn-sm cf-btn-ghost" data-lb="del" aria-label="Verwijderen">✕</button>
              </div>
              <input type="text" name="cell_title[]" maxlength="80" placeholder="Titel (optioneel)" value="<?= $e($c['title']) ?>">
              <textarea name="cell_text[]" rows="3" maxlength="1000" placeholder="Tekst"><?= $e($c['text']) ?></textarea>
              <textarea name="cell_links[]" rows="3" placeholder="Eén link per regel: Label|/pad"><?= $e(implode("\n", array_map(static fn($l) => $l['label'] . '|' . $l['url'], $c['links']))) ?></textarea>
            </div>
          <?php endforeach; ?>
        </div>
        <p><button type="button" class="cf-btn-sm cf-btn-ghost" id="lb-add">+ Cel toevoegen</button>
           <button type="submit" class="cf-btn">Opslaan</button></p>
        <p style="color:var(--text-dim);font-size:.8rem;">Maximaal 8 cellen. Tekst wordt als platte tekst getoond; links mogen alleen naar een eigen pad (/…) of https://.</p>
      </form>

      <template id="lb-tpl">
        <div class="lb-cell" draggable="true">
          <div class="lb-head">
            <select name="cell_type[]"><?php foreach ($cellTypes as $v => $l): ?><option value="<?= $e($v) ?>"><?= $e($l) ?></option><?php endforeach; ?></select>
            <button type="button" class="cf-btn-sm cf-btn-ghost" data-lb="up" aria-label="Omhoog">▲</button>
            <button type="button" class="cf-btn-sm cf-btn-ghost" data-lb="down" aria-label="Omlaag">▼</button>
            <button type="button" class="cf-btn-sm cf-btn-ghost" data-lb="del" aria-label="Verwijderen">✕</button>
          </div>
          <input type="text" name="cell_title[]" maxlength="80" placeholder="Titel (optioneel)">
          <textarea name="cell_text[]" rows="3" maxlength="1000" placeholder="Tekst"></textarea>
          <textarea name="cell_links[]" rows="3" placeholder="Eén link per regel: Label|/pad"></textarea>
        </div>
      </template>
      <script>
      (function(){
        var list=document.getElementById('lb-list'), cols=document.getElementById('lb-cols'), drag=null;
        function sync(cell){
          var t=cell.querySelector('[name="cell_type[]"]').value;
          cell.querySelector('[name="cell_text[]"]').style.display = t==='text' ? '' : 'none';
          cell.querySelector('[name="cell_links[]"]').style.display = t==='links' ? '' : 'none';
        }
        [].forEach.call(list.children,sync);
        list.addEventListener('change',function(ev){ var c=ev.target.closest('.lb-cell'); if(c) sync(c); });
        function grid(){ list.style.gridTemplateColumns='repeat('+cols.value+',minmax(0,1fr))'; }
        cols.addEventListener('change',grid);
        document.getElementById('lb-add').addEventListener('click',function(){
          if(list.children.length>=8) return;
          var n=document.getElementById('lb-tpl').content.firstElementChild.cloneNode(true); list.appendChild(n); sync(n);
        });
        list.addEventListener('click',function(ev){
          var b=ev.target.closest('[data-lb]'); if(!b) return;
          var cell=b.closest('.lb-cell'), a=b.dataset.lb;
          if(a==='del') cell.remove();
          if(a==='up' && cell.previousElementSibling) list.insertBefore(cell,cell.previousElementSibling);
          if(a==='down' && cell.nextElementSibling) list.insertBefore(cell.nextElementSibling,cell);
        });
        list.addEventListener('dragstart',function(ev){ var c=ev.target.closest('.lb-cell'); if(!c) return; drag=c; c.classList.add('dragging'); ev.dataTransfer.effectAllowed='move'; try{ev.dataTransfer.setData('text/plain','cell');}catch(e){} });
        list.addEventListener('dragend',function(){ if(drag) drag.classList.remove('dragging'); drag=null; [].forEach.call(list.children,function(c){c.classList.remove('over');}); });
        list.addEventListener('dragover',function(ev){ var c=ev.target.closest('.lb-cell'); if(!drag||!c||c===drag) return; ev.preventDefault(); [].forEach.call(list.children,function(x){x.classList.toggle('over',x===c);}); });
        list.addEventListener('drop',function(ev){
          var c=ev.target.closest('.lb-cell'); if(!drag||!c||c===drag) return; ev.preventDefault();
          var kids=[].slice.call(list.children);
          if(kids.indexOf(drag)<kids.indexOf(c)) list.insertBefore(drag,c.nextSibling); else list.insertBefore(drag,c);
        });
      })();
      </script>

      <?php else: ?>
      <!-- TAB 5: bewust leeg, gereserveerd voor uitbreiding -->
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
