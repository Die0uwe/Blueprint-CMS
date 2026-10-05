/*! Blueprint CMS — klok-blok (Copyright (C) 2026 DieOuwe, GPL-3.0-or-later) */
(function () {
  'use strict';
  var lang = document.documentElement.lang || undefined;

  function parts(tz, h12) {
    var opt = { hour: 'numeric', minute: 'numeric', second: 'numeric', hourCycle: 'h23' };
    if (tz && tz !== 'visitor') opt.timeZone = tz;
    var out = {};
    try {
      new Intl.DateTimeFormat('en-GB', opt).formatToParts(new Date()).forEach(function (p) { out[p.type] = p.value; });
    } catch (e) { var d = new Date(); out = { hour: d.getHours(), minute: d.getMinutes(), second: d.getSeconds() }; }
    return { h: +out.hour % 24, m: +out.minute, s: +out.second };
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function dateText(tz, style) {
    var opt = style === 'numeric' ? { day: '2-digit', month: '2-digit', year: 'numeric' }
      : style === 'short' ? { weekday: 'short', day: 'numeric', month: 'short' }
      : { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
    if (tz && tz !== 'visitor') opt.timeZone = tz;
    try { return new Intl.DateTimeFormat(lang, opt).format(new Date()); } catch (e) { return new Date().toLocaleDateString(); }
  }

  function tick(el) {
    var tz = el.dataset.tz, h12 = el.dataset.h12 === '1', sec = el.dataset.sec === '1';
    var t = parts(tz, h12);
    var time = el.querySelector('.cf-clock-time');
    if (time) {
      var h = t.h, suffix = '';
      if (h12) { suffix = h >= 12 ? ' PM' : ' AM'; h = h % 12 || 12; }
      time.textContent = (h12 ? h : pad(h)) + ':' + pad(t.m) + (sec ? ':' + pad(t.s) : '') + suffix;
    }
    var face = el.querySelector('.cf-clock-face');
    if (face) {
      var set = function (cls, deg) { var n = face.querySelector(cls); if (n) n.setAttribute('transform', 'rotate(' + deg + ')'); };
      set('.cf-clock-h', (t.h % 12) * 30 + t.m * 0.5);
      set('.cf-clock-m', t.m * 6 + t.s * 0.1);
      set('.cf-clock-s', t.s * 6);
    }
    var dt = el.querySelector('.cf-clock-date');
    if (dt) dt.textContent = dateText(tz, el.dataset.datestyle);
  }

  function boot() {
    var clocks = [].slice.call(document.querySelectorAll('.cf-clock')).filter(function (el) {
      if (el.dataset.init) return false; el.dataset.init = '1'; return true;
    });
    clocks.forEach(tick);
    if (clocks.length) setInterval(function () { clocks.forEach(tick); }, 1000);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
