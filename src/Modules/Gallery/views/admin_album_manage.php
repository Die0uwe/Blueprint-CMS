<?php
// ============================================================================
// Copyright (C) 2026  DieOuwe — GPL-3.0-or-later
// ============================================================================
// $album, $items, $flash, $error beschikbaar vanuit GalleryAdminController::manage()
//
// Combineert album-metadata bewerken + item-upload + item-beheer in één
// scherm (i.p.v. drie aparte schermen) — een album zonder items is
// zelden interessant om te bezoeken, dus dit is het enige scherm dat een
// beheerder na het aanmaken van een album nog nodig heeft.

use CommunityFusion\Core\Security\CsrfProtection;

$activeNav   = 'gallery';
$flashLabels = ['bijgewerkt' => 'Album bijgewerkt.', 'geupload' => "Bestand geüpload.", 'geupload_n' => ((int) ($_GET['n'] ?? 0)) . ' bestanden geüpload.', 'verwijderd' => 'Item verwijderd.'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\CommunityFusion\Core\I18n\Trans::locale(), ENT_QUOTES) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Album beheren: <?= htmlspecialchars($album['name']) ?> — Blueprint CMS Admin</title>
<link rel="stylesheet" href="/assets/css/blueprint.css">
<?php include __DIR__ . '/../../Shared/views/admin_styles.php'; ?>
<style>
  /* Overige .cf-gallery-admin-* regels staan in blueprint.css (S11-sectie) */
  .form-wrap { max-width: 640px; }
</style>
</head>
<body>
<div class="admin-wrap">
  <?php include __DIR__ . '/../../Shared/views/admin_sidebar.php'; ?>

  <div class="admin-main">
    <header class="admin-topbar">
      <h1>📷 Album beheren: <?= htmlspecialchars($album['name']) ?></h1>
      <a href="/admin/gallery" class="cf-btn-sm">← Terug naar overzicht</a>
    </header>

    <div class="admin-content">
      <?php if ($flash && isset($flashLabels[$flash])): ?>
        <div class="cf-alert cf-alert-success"><?= htmlspecialchars($flashLabels[$flash]) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="cf-alert cf-alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="cf-card">
        <h2 style="margin-top:0;">Albumgegevens</h2>
        <div class="form-wrap">
          <form method="post" action="/admin/gallery/<?= (int) $album['id'] ?>/bewerk">
            <?= CsrfProtection::field() ?>

            <div class="cf-form-group">
              <label class="cf-label">Naam</label>
              <input type="text" name="name" class="cf-input" required maxlength="200"
                     value="<?= htmlspecialchars($album['name']) ?>">
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Slug (URL)</label>
              <input type="text" name="slug" class="cf-input" maxlength="150"
                     value="<?= htmlspecialchars($album['slug']) ?>">
              <p style="color:var(--text-dim);font-size:.78rem;margin:.35rem 0 0;">
                Let op: de slug wijzigen breekt bestaande links naar dit album (<code>/galerij/<?= htmlspecialchars($album['slug']) ?></code>).
              </p>
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Omschrijving</label>
              <textarea name="description" class="cf-input" rows="3"><?= htmlspecialchars($album['description'] ?? '') ?></textarea>
            </div>

            <div class="cf-form-group">
              <label class="cf-label">Positie <span style="color:var(--text-dim);font-weight:400;">— lager = hoger in de lijst</span></label>
              <input type="number" name="position" class="cf-input" style="max-width:120px;"
                     value="<?= (int) $album['position'] ?>">
            </div>

            <button type="submit" class="cf-btn">Wijzigingen opslaan</button>
          </form>
        </div>
      </div>

      <div class="cf-card">
        <h2 style="margin-top:0;">Foto's/video's uploaden</h2>
        <div class="form-wrap" style="max-width:none;">
          <form method="post" action="/admin/gallery/<?= (int) $album['id'] ?>/upload" enctype="multipart/form-data" id="gal-form">
            <?= CsrfProtection::field() ?>

            <div class="cf-form-group">
              <label class="cf-label" for="gal-multi">Snel meerdere bestanden kiezen <span style="color:var(--text-dim);font-weight:400;">— verdeelt ze over de rijen hieronder (max. 20 per keer)</span></label>
              <input type="file" id="gal-multi" class="cf-input" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm">
              <small style="color:var(--text-dim);">jpg, png, gif, webp, mp4, webm (max. 25MB per bestand). Serverlimiet: upload_max_filesize <?= htmlspecialchars((string) ini_get('upload_max_filesize')) ?>, post_max_size <?= htmlspecialchars((string) ini_get('post_max_size')) ?> — dat geldt voor alle bestanden samen.</small>
            </div>

            <div id="gal-rows"></div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.75rem;">
              <button type="button" class="cf-btn-ghost" id="gal-more">+ 5 rijen</button>
              <button type="submit" class="cf-btn" id="gal-submit">Uploaden</button>
            </div>
          </form>
          <style>
            .gal-row{display:grid;grid-template-columns:minmax(180px,1.2fr) minmax(140px,1fr) minmax(160px,1.4fr);gap:.6rem;align-items:start;padding:.6rem 0;border-bottom:1px solid var(--border)}
            .gal-row img.gal-prev{display:none;max-width:120px;margin-top:.35rem;border-radius:6px;border:1px solid var(--border)}
            @media(max-width:760px){.gal-row{grid-template-columns:1fr}}
          </style>
          <script>
          (function(){
            var MAX=<?= (int) \CommunityFusion\Modules\Gallery\GalleryAdminController::MAX_BATCH ?>, rows=document.getElementById('gal-rows'), n=0;
            function addRows(k){
              for(var i=0;i<k && n<MAX;i++,n++){
                var d=document.createElement('div'); d.className='gal-row'; d.dataset.i=n;
                d.innerHTML='<div><input type="file" name="file_'+n+'" class="cf-input gal-file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm"><input type="hidden" name="poster_data_'+n+'" class="gal-poster"><img class="gal-prev" alt=""></div>'
                  +'<input type="text" name="title_'+n+'" class="cf-input gal-title" maxlength="255" placeholder="Titel (optioneel)">'
                  +'<input type="text" name="description_'+n+'" class="cf-input" maxlength="500" placeholder="Omschrijving (optioneel)">';
                rows.appendChild(d); bind(d);
              }
              document.getElementById('gal-more').style.display = n>=MAX ? 'none' : '';
            }
            function poster(row,file){
              var p=row.querySelector('.gal-poster'), pv=row.querySelector('.gal-prev'); p.value=''; pv.style.display='none';
              if(!file) return;
              if(/^image\//.test(file.type)){ pv.src=URL.createObjectURL(file); pv.style.display='block'; return; }
              if(!/^video\//.test(file.type)) return;
              var v=document.createElement('video'); v.muted=true; v.playsInline=true; v.preload='metadata'; var url=URL.createObjectURL(file); v.src=url;
              v.addEventListener('loadedmetadata',function(){ v.currentTime=Math.min(1,(v.duration||2)*0.1); });
              v.addEventListener('seeked',function(){
                try{
                  var w=Math.min(640,v.videoWidth||640), h=Math.round(w*(v.videoHeight||360)/(v.videoWidth||640));
                  var c=document.createElement('canvas'); c.width=w; c.height=h; c.getContext('2d').drawImage(v,0,0,w,h);
                  p.value=c.toDataURL('image/jpeg',0.8); pv.src=p.value; pv.style.display='block';
                }catch(e){} URL.revokeObjectURL(url);
              },{once:true});
            }
            function bind(row){
              var f=row.querySelector('.gal-file');
              f.addEventListener('change',function(){
                var file=f.files[0], t=row.querySelector('.gal-title');
                if(file && !t.value) t.value=file.name.replace(/\.[^.]+$/,'').replace(/[_-]+/g,' ');
                poster(row,file);
              });
            }
            document.getElementById('gal-more').addEventListener('click',function(){ addRows(5); });
            // Meerdere bestanden in één keer: verdeel over de rijen (rijen worden zo nodig bijgemaakt)
            document.getElementById('gal-multi').addEventListener('change',function(e){
              var list=[].slice.call(e.target.files).slice(0,MAX);
              while(n<list.length) addRows(5);
              var inputs=rows.querySelectorAll('.gal-file');
              list.forEach(function(file,i){
                try{ var dt=new DataTransfer(); dt.items.add(file); inputs[i].files=dt.files; inputs[i].dispatchEvent(new Event('change')); }catch(err){}
              });
            });
            document.getElementById('gal-form').addEventListener('submit',function(ev){
              var any=[].some.call(rows.querySelectorAll('.gal-file'),function(f){return f.files.length;});
              if(!any){ ev.preventDefault(); alert('Kies minstens één bestand.'); return; }
              var b=document.getElementById('gal-submit'); setTimeout(function(){b.disabled=true;b.textContent='Bezig met uploaden…';},0);
            });
            addRows(5);
          })();
          </script>
        </div>
      </div>

      <div class="cf-card">
        <h2 style="margin-top:0;">Inhoud (<?= count($items) ?>)</h2>
        <?php if (empty($items)): ?>
          <div class="cf-table-empty">Nog geen foto's of video's in dit album.</div>
        <?php else: ?>
          <div class="cf-gallery-admin-grid">
            <?php foreach ($items as $item): ?>
              <div class="cf-gallery-admin-item">
                <?php if (!empty($item['thumbnail_path']) || $item['media_type'] === 'image'): ?>
                  <img src="/media/<?= htmlspecialchars($item['thumbnail_path'] ?: $item['file_path']) ?>" alt="<?= htmlspecialchars($item['title'] ?? $item['original_filename']) ?>" loading="lazy">
                <?php else: ?>
                  <div class="cf-gallery-video-placeholder">▶️</div>
                <?php endif; ?>
                <div class="cf-gallery-item-meta" title="<?= htmlspecialchars($item['title'] ?? $item['original_filename']) ?>">
                  <?= htmlspecialchars($item['title'] ?: $item['original_filename']) ?>
                </div>
                <form method="post" action="/admin/gallery/items/<?= (int) $item['id'] ?>/verwijder"
                      onsubmit="return confirm('Dit item definitief verwijderen?');">
                  <?= CsrfProtection::field() ?>
                  <button type="submit" class="cf-btn-sm cf-btn-danger">🗑️ Verwijder</button>
                </form>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
