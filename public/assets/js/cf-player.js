/*!
 * Blueprint CMS — ingebouwde videospeler (geen externe library).
 *
 *   CFPlayer.create(src, {poster, autoplay, title})  → HTMLElement (voeg zelf toe aan de DOM)
 *   <video data-cf-player src="…" poster="…">         → wordt automatisch omgebouwd
 *
 * Bediening: spatie/K afspelen, ←/→ ±5 s, ↑/↓ volume, M dempen, F volledig scherm, 0-9 spring naar %.
 * Besturing verbergt zich tijdens afspelen; alle knoppen hebben aria-labels (NL).
 */
(function (root) {
  'use strict';

  var SPEEDS = [0.75, 1, 1.25, 1.5, 2];

  function el(tag, cls, attrs) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (attrs) for (var k in attrs) if (Object.prototype.hasOwnProperty.call(attrs, k)) n.setAttribute(k, attrs[k]);
    return n;
  }

  function fmt(t) {
    if (!isFinite(t) || t < 0) t = 0;
    var s = Math.floor(t % 60), m = Math.floor(t / 60) % 60, h = Math.floor(t / 3600);
    var two = function (x) { return (x < 10 ? '0' : '') + x; };
    return (h ? h + ':' + two(m) : m) + ':' + two(s);
  }

  function btn(label, icon) {
    var b = el('button', 'cf-pl-btn', { type: 'button', 'aria-label': label, title: label });
    b.textContent = icon;
    return b;
  }

  function enhance(video, opts) {
    opts = opts || {};
    if (video.__cfPlayer) return video.__cfPlayer;

    var wrap  = el('div', 'cf-player', { tabindex: '0', role: 'group', 'aria-label': opts.title || 'Videospeler' });
    var parent = video.parentNode;
    if (parent) parent.insertBefore(wrap, video);
    wrap.appendChild(video);
    video.controls = false;
    video.removeAttribute('controls');
    video.setAttribute('playsinline', '');
    video.preload = video.preload && video.preload !== 'none' ? video.preload : 'metadata';
    if (opts.poster) video.poster = opts.poster;

    var big    = btn('Afspelen', '▶'); big.className = 'cf-pl-big';
    var bar    = el('div', 'cf-pl-bar');
    var play   = btn('Afspelen', '▶');
    var time   = el('span', 'cf-pl-time'); time.textContent = '0:00 / 0:00';
    var seekW  = el('div', 'cf-pl-seekwrap');
    var buf    = el('div', 'cf-pl-buffer');
    var seek   = el('input', 'cf-pl-seek', { type: 'range', min: '0', max: '1000', value: '0', step: '1', 'aria-label': 'Voortgang' });
    var mute   = btn('Dempen', '🔊');
    var vol    = el('input', 'cf-pl-vol', { type: 'range', min: '0', max: '1', step: '0.05', value: '1', 'aria-label': 'Volume' });
    var speed  = btn('Afspeelsnelheid', '1×'); speed.className += ' cf-pl-speed';
    var full   = btn('Volledig scherm', '⛶');
    var err    = el('div', 'cf-pl-error', { role: 'alert' }); err.hidden = true;

    seekW.appendChild(buf); seekW.appendChild(seek);
    [play, time, seekW, mute, vol, speed, full].forEach(function (n) { bar.appendChild(n); });
    wrap.appendChild(big); wrap.appendChild(bar); wrap.appendChild(err);

    var hideTimer = null, speedIdx = 1, seeking = false;

    function setPlayUi() {
      var playing = !video.paused && !video.ended;
      play.textContent = playing ? '⏸' : '▶';
      play.setAttribute('aria-label', playing ? 'Pauzeren' : 'Afspelen');
      play.title = play.getAttribute('aria-label');
      big.hidden = playing;
      wrap.classList.toggle('is-playing', playing);
    }
    function showBar() {
      wrap.classList.add('show-bar');
      clearTimeout(hideTimer);
      if (!video.paused) hideTimer = setTimeout(function () { wrap.classList.remove('show-bar'); }, 2500);
    }
    function toggle() {
      if (video.paused || video.ended) { var p = video.play(); if (p && p.catch) p.catch(function () {}); }
      else video.pause();
    }
    function updateTime() {
      var d = video.duration;
      time.textContent = fmt(video.currentTime) + ' / ' + fmt(d);
      seek.setAttribute('aria-valuetext', fmt(video.currentTime) + ' van ' + fmt(d));
      if (!seeking && isFinite(d) && d > 0) seek.value = String(Math.round(video.currentTime / d * 1000));
      seek.style.setProperty('--cf-pl-pos', (seek.value / 10) + '%');
    }
    function updateBuffer() {
      var d = video.duration, b = video.buffered;
      if (!isFinite(d) || d <= 0 || !b || !b.length) { buf.style.width = '0'; return; }
      buf.style.width = Math.min(100, b.end(b.length - 1) / d * 100) + '%';
    }
    function updateVol() {
      mute.textContent = video.muted || video.volume === 0 ? '🔇' : '🔊';
      mute.setAttribute('aria-label', video.muted ? 'Geluid aan' : 'Dempen');
      vol.value = String(video.muted ? 0 : video.volume);
    }
    function isFs() { return document.fullscreenElement === wrap || document.webkitFullscreenElement === wrap; }
    function toggleFs() {
      if (isFs()) { (document.exitFullscreen || document.webkitExitFullscreen).call(document); return; }
      var req = wrap.requestFullscreen || wrap.webkitRequestFullscreen;
      if (req) req.call(wrap);
      else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen(); // iOS Safari
    }
    function skip(s) { if (isFinite(video.duration)) video.currentTime = Math.max(0, Math.min(video.duration, video.currentTime + s)); }

    play.addEventListener('click', toggle);
    big.addEventListener('click', toggle);
    video.addEventListener('click', toggle);
    video.addEventListener('dblclick', toggleFs);
    full.addEventListener('click', toggleFs);
    mute.addEventListener('click', function () { video.muted = !video.muted; if (!video.muted && video.volume === 0) video.volume = 0.5; });
    vol.addEventListener('input', function () { video.volume = parseFloat(vol.value); video.muted = video.volume === 0; });
    speed.addEventListener('click', function () {
      speedIdx = (speedIdx + 1) % SPEEDS.length;
      video.playbackRate = SPEEDS[speedIdx];
      speed.textContent = SPEEDS[speedIdx] + '×';
      speed.setAttribute('aria-label', 'Afspeelsnelheid ' + SPEEDS[speedIdx] + ' keer');
      speed.title = speed.getAttribute('aria-label');
    });
    seek.addEventListener('input', function () {
      seeking = true;
      if (isFinite(video.duration)) video.currentTime = seek.value / 1000 * video.duration;
      seek.style.setProperty('--cf-pl-pos', (seek.value / 10) + '%');
    });
    seek.addEventListener('change', function () { seeking = false; });

    ['play', 'pause', 'ended'].forEach(function (e) { video.addEventListener(e, function () { setPlayUi(); showBar(); }); });
    ['timeupdate', 'loadedmetadata', 'durationchange'].forEach(function (e) { video.addEventListener(e, updateTime); });
    ['progress', 'loadedmetadata'].forEach(function (e) { video.addEventListener(e, updateBuffer); });
    video.addEventListener('volumechange', updateVol);
    video.addEventListener('waiting', function () { wrap.classList.add('is-loading'); });
    ['playing', 'canplay'].forEach(function (e) { video.addEventListener(e, function () { wrap.classList.remove('is-loading'); }); });
    video.addEventListener('error', function () {
      var src = video.currentSrc || video.getAttribute('src') || '';
      err.hidden = false;
      err.textContent = '';
      err.appendChild(document.createTextNode('Deze video kan niet worden afgespeeld. '));
      if (src) { var a = el('a', null, { href: src, download: '' }); a.textContent = 'Download het bestand'; err.appendChild(a); }
      big.hidden = true;
    });

    wrap.addEventListener('mousemove', showBar);
    wrap.addEventListener('touchstart', showBar, { passive: true });
    wrap.addEventListener('focusin', showBar);
    wrap.addEventListener('keydown', function (e) {
      if (e.target && e.target.tagName === 'INPUT' && e.target.type === 'range' && (e.key === 'ArrowLeft' || e.key === 'ArrowRight' || e.key === 'ArrowUp' || e.key === 'ArrowDown')) return;
      var k = e.key, handled = true;
      if (k === ' ' || k === 'k' || k === 'K') { if (e.target && e.target.tagName === 'BUTTON' && k === ' ') return; toggle(); }
      else if (k === 'ArrowLeft') skip(-5);
      else if (k === 'ArrowRight') skip(5);
      else if (k === 'ArrowUp') { video.volume = Math.min(1, video.volume + 0.1); video.muted = false; }
      else if (k === 'ArrowDown') video.volume = Math.max(0, video.volume - 0.1);
      else if (k === 'm' || k === 'M') video.muted = !video.muted;
      else if (k === 'f' || k === 'F') toggleFs();
      else if (/^[0-9]$/.test(k) && isFinite(video.duration)) video.currentTime = video.duration * (parseInt(k, 10) / 10);
      else handled = false;
      if (handled) { e.preventDefault(); showBar(); }
    });
    document.addEventListener('fullscreenchange', function () { wrap.classList.toggle('is-fs', isFs()); });

    setPlayUi(); updateVol(); updateTime(); showBar();
    var api = { element: wrap, video: video, toggle: toggle, destroy: function () { video.pause(); video.removeAttribute('src'); video.load(); wrap.remove(); } };
    video.__cfPlayer = api;
    return api;
  }

  function create(src, opts) {
    opts = opts || {};
    var holder = el('div', 'cf-player-holder');
    var v = document.createElement('video');
    v.src = src;
    if (opts.autoplay) v.autoplay = true;
    holder.appendChild(v);
    var api = enhance(v, opts);
    api.holder = holder;
    return api;
  }

  function auto() {
    var list = document.querySelectorAll('video[data-cf-player]');
    for (var i = 0; i < list.length; i++) enhance(list[i], { poster: list[i].getAttribute('poster') || '', title: list[i].getAttribute('aria-label') || '' });
  }

  root.CFPlayer = { create: create, enhance: enhance, format: fmt };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', auto); else auto();
})(window);
