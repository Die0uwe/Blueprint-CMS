/* Blueprint CMS — thema-init. Blokkerend in <head>: past de keuze van de bezoeker
   toe vóór de eerste verf. Ongeldige of onbekende waarden worden genegeerd. */
(function () {
  try {
    var d = document.documentElement;
    if (d.getAttribute('data-choice') === '0') return;
    var el = document.getElementById('cf-themes');
    var list = el ? JSON.parse(el.textContent) : [];
    var t = localStorage.getItem('cf_theme');
    var m = localStorage.getItem('cf_mode');
    var th = null, i;
    for (i = 0; i < list.length; i++) { if (list[i].slug === t) th = list[i]; }
    if (!th) {
      for (i = 0; i < list.length; i++) { if (list[i].slug === d.getAttribute('data-theme')) th = list[i]; }
    } else {
      d.setAttribute('data-theme', th.slug);
    }
    if (th && (m === 'light' || m === 'dark') && th.hasAlt && m !== th.mode) {
      d.setAttribute('data-mode', 'alt');
    }
  } catch (e) { /* localStorage geblokkeerd: server-standaard blijft staan */ }
})();
