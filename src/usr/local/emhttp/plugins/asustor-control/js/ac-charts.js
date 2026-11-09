/* ac-charts.js - History tab: two graphs from include/history.php (POST, read-only): temperatures (CPU, hottest drive, Unraid's
 * warning and critical lines) and the fan (PWM %, RPM). Below them the same numbers as a table (last, min, max), so nothing is
 * only in the picture. The x axis is always the chosen range ending now. Colours: a fixed palette checked against Unraid's light
 * and dark backgrounds; text uses the theme's colour. */
(function () {
  'use strict';
  var root = document.getElementById('ac-history');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  var url = root.dataset.endpoint, box = root.querySelector('[data-ac="charts"]'), msg = root.querySelector('[data-ac="msg"]');
  var C = { cpu: '#d62728', drv: '#1f77b4', pwm: '#2ca02c', rpm: '#9467bd', warn: '#e68a00', crit: '#d03b3b' };
  var plots = [];
  function el(t, c, x) { var e = document.createElement(t); if (c) e.className = c; if (x !== undefined) e.textContent = x; return e; }
  function say(t) { msg.textContent = t; }
  function range() { var r = root.querySelector('input[name="ac-range"]:checked'); return r ? r.value : '3600'; }
  function stats(v) {
    var n = v.filter(function (x) { return typeof x === 'number'; }), last = null;
    for (var i = v.length - 1; i >= 0; i--) if (typeof v[i] === 'number') { last = v[i]; break; }
    return [last === null ? '-' : last, n.length ? Math.min.apply(null, n) : '-', n.length ? Math.max.apply(null, n) : '-'];
  }
  function table(j) {
    var t = el('table', 'unraid ac-t ac-hist-t'); t.id = 'ac-hist-t';
    t.appendChild(el('caption', 'ac-cap', 'In this range'));
    var th = el('thead'), tr = el('tr');
    ['Value', 'Last', 'Min', 'Max'].forEach(function (h) { var c = el('th', null, h); c.scope = 'col'; tr.appendChild(c); });
    th.appendChild(tr); t.appendChild(th);
    var tb = el('tbody');
    [['CPU (°C)', j.cpu], ['Hottest drive (°C)', j.drv], ['Fan PWM (%)', j.pwm], ['Fan (RPM)', j.rpm]].forEach(function (r) {
      var row = el('tr'), h = el('th', null, r[0]); h.scope = 'row'; row.appendChild(h);
      stats(r[1]).forEach(function (x) { row.appendChild(el('td', null, String(x))); });
      tb.appendChild(row);
    });
    t.appendChild(tb);
    return t;
  }
  function chart(title, id, opts, data) {
    var sec = el('section', 'ac-hist'), h = el('h3', 'ac-cap', title); h.id = id; sec.appendChild(h);
    var holder = el('div', 'ac-chart'); holder.setAttribute('role', 'img'); holder.setAttribute('aria-labelledby', id); holder.setAttribute('aria-describedby', 'ac-hist-t');
    sec.appendChild(holder); box.appendChild(sec);
    opts.width = Math.max(320, Math.min(holder.clientWidth || 900, 1200)); opts.height = 260;
    plots.push(new window.uPlot(opts, data, holder));
  }
  function draw(j) {
    plots.forEach(function (p) { p.destroy(); }); plots = [];
    box.textContent = '';
    if (!j.t.length) { say('No samples in this range yet. The hardware monitor writes one every 30 seconds while it runs (Overview, Hardware monitor).'); return; }
    say('');
    var fg = getComputedStyle(root).color, grid = { stroke: 'rgba(128,128,128,.25)' };
    var xEnd = typeof j.now === 'number' ? j.now : Date.now() / 1000, xSpan = typeof j.range === 'number' && j.range > 0 ? j.range : Number(range());
    var x = { time: true, range: function () { return [xEnd - xSpan, xEnd]; } };
    var flat = function (v) { return j.t.map(function () { return v; }); };
    var deg = function (s, v) { return v.map(function (a) { return a + ' °C'; }); };
    chart('Temperatures (°C), ' + j.t.length + (j.t.length === 1 ? ' sample' : ' samples'), 'ac-hist-temp', {
      scales: { x: x, y: { range: [20, 100] } },
      axes: [{ stroke: fg, grid: grid }, { stroke: fg, size: 64, grid: grid, values: deg }],
      series: [{},
        { label: 'CPU', stroke: C.cpu, width: 2, value: function (u, v) { return v == null ? '-' : v + ' °C'; } },
        { label: 'Hottest drive', stroke: C.drv, width: 2, value: function (u, v) { return v == null ? '-' : v + ' °C'; } },
        { label: 'Drive warning', stroke: C.warn, width: 1.5, dash: [6, 4], value: function (u, v) { return v == null ? '-' : v + ' °C'; } },
        { label: 'Drive critical', stroke: C.crit, width: 1.5, dash: [6, 4], value: function (u, v) { return v == null ? '-' : v + ' °C'; } }],
      legend: { live: true }
    }, [j.t, j.cpu, j.drv, flat(j.warn), flat(j.crit)]);
    chart('Fan', 'ac-hist-fan', {
      scales: { x: x, pct: { range: [0, 100] }, rpm: { range: function (u, lo, hi) { return [0, Math.max(1000, Math.ceil((hi || 0) / 500) * 500)]; } } },
      axes: [{ stroke: fg, grid: grid }, { scale: 'pct', stroke: fg, size: 56, grid: grid, values: function (s, v) { return v.map(function (a) { return a + ' %'; }); } },
             { scale: 'rpm', side: 1, stroke: fg, size: 72, grid: { show: false }, values: function (s, v) { return v.map(function (a) { return a + ' rpm'; }); } }],
      series: [{},
        { label: 'PWM', scale: 'pct', stroke: C.pwm, width: 2, value: function (u, v) { return v == null ? '-' : v + ' %'; } },
        { label: 'Speed', scale: 'rpm', stroke: C.rpm, width: 2, dash: [4, 3], value: function (u, v) { return v == null ? '-' : v + ' rpm'; } }],
      legend: { live: true }
    }, [j.t, j.pwm, j.rpm]);
    box.appendChild(table(j));
  }
  function load() {
    say('Loading...');
    var b = new URLSearchParams({ action: 'history', range: range() });
    if (typeof csrf_token !== 'undefined') b.append('csrf_token', csrf_token);
    fetch(url, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (j) { if (!j || !j.ok) throw new Error((j && j.error) || 'unexpected reply'); if (!window.uPlot) throw new Error('the graph library did not load'); draw(j); })
      .catch(function (e) { say('The history could not be loaded: ' + e.message + '.'); });
  }
  root.querySelectorAll('input[name="ac-range"]').forEach(function (r) { r.addEventListener('change', load); });
  load();
  setInterval(function () { if (!document.hidden && root.offsetParent !== null) load(); }, 60000);
}());
