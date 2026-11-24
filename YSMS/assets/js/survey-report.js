/* Survey report wizard (survey_report_public.php). Data: ajax/survey_reports.php.
   Steps: 1 Vessel particulars (IMO + Vessel lookup, the 18 particulars) → 2 Survey particulars
   (fuel grades on board, FWE / start / completion, staff, location, drafts) → one step per chosen
   fuel grade (tank cards, one below the other) → ROB summary with the PDF / Excel downloads.
   - V.C.F. / W.C.F. / G.S.V. / metric tons are shown as you type (assets/js/report-calc.js, the
     template's own TABLES 54B formulas). The summary is checked on the server (a=verify) and the
     files are always calculated by the server from the template's formulas.
   - Saves by itself, the current step included. Offline: kept on this device and saved when the
     connection is back (a change made by someone else meanwhile is never overwritten without asking). */
(function () {
  'use strict';
  var P = window.SR_PAGE || {};
  // a report started without internet (?draft=<x>): kept on this device until it is made on the server
  if (P.draft) { var dq = /[?&]draft=([a-z0-9]{4,24})\b/.exec(location.search); if (dq) P.draft = dq[1]; P.id = 'draft-' + P.draft; }
  var DRAFT = P.draft ? 'ysms_srp_draft_' + P.draft : '';
  var LS = 'ysms_sr_' + P.id, CACHE = 'ysms_sr_get_' + P.id, TPL_CACHE = 'ysms-sr-templates';
  var S = { cfg: null, report: null, data: null, version: 0, canEdit: false, errors: {}, files: [], dirty: false, saving: false, timer: null, offlineMode: false,
    ai: {}, lookup: null, lookupMsg: '', manual: false, showErr: {}, open: {}, expand: {}, server: null, serverIssues: null, verifying: false };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function f3(v) { return v === null || v === undefined || v === '' || isNaN(v) ? '' : (+v).toFixed(3); }
  function sgn(v) { return v === null || v === undefined || isNaN(v) ? '—' : (v > 0 ? '+' : v < 0 ? '−' : '') + Math.abs(v).toFixed(3); }
  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function read(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function toast(icon, title) { if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: icon === 'success' ? 2000 : 4500 }); }
  function post(d, retried) {
    var f = new FormData(); f.append('csrf_token', P.csrf); Object.keys(d).forEach(function (k) { f.append(k, d[k]); });
    return fetch(P.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': P.csrf } })
      .then(function (r) {
        return r.json().then(function (j) { j._status = r.status; return j; }).catch(function () {
          // not JSON: the security token changed (signed in again elsewhere) or the session ended
          if (r.status === 403 && !retried) return freshToken().then(function (ok) { return ok ? post(d, true) : signedOut(); });
          if (r.status === 403 || r.redirected || /login/.test(r.url)) return signedOut();
          return { ok: false, _status: r.status, error: 'Unexpected answer from the server (' + r.status + ') — your changes are kept on this device.' };
        });
      })
      .then(function (j) { if (j && j._status === 401) return signedOut(); if (j && j.ok) sessionOk(); return j; })
      .catch(function () { return { ok: false, _status: 0, error: 'No connection.' }; });
  }
  function freshToken() {
    return fetch(P.api + '?a=token', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok && j.csrf) { P.csrf = j.csrf; return true; } return false; }).catch(function () { return false; });
  }
  /** Session ended: say so once, keep everything on the device, retry by itself after signing in (any tab). */
  function signedOut() {
    if (!S.signedOut) {
      S.signedOut = true;
      var b = document.querySelector('[data-banners]');
      if (b) b.insertAdjacentHTML('afterbegin', signedOutHtml());
      clearInterval(S.reauth);
      S.reauth = setInterval(function () { freshToken().then(function (ok) { if (ok) { sessionOk(); if (S.dirty) saveNow(); } }); }, 8000);
    }
    return { ok: false, _status: 401, error: 'Session expired — sign in again. Your changes are kept on this device.' };
  }
  function signedOutHtml() { return '<div class="sr-note err" data-signed-out data-testid="sr-signed-out"><i class="fa-solid fa-right-to-bracket"></i> Your session has expired. Your changes are kept on this device. <a href="login.php" target="_blank" rel="noopener">Sign in again</a> (new tab) — saving continues by itself.</div>'; }
  function sessionOk() {
    if (!S.signedOut) return;
    S.signedOut = false; clearInterval(S.reauth);
    var n = document.querySelector('[data-signed-out]'); if (n) n.remove();
  }
  /** JSON with sorted keys and without page-only flags, to compare two copies of the data. */
  function canon(v) {
    if (Array.isArray(v)) return '[' + v.map(canon).join(',') + ']';
    if (v && typeof v === 'object') return '{' + Object.keys(v).filter(function (k) { return k !== '_tried'; }).sort().map(function (k) { return JSON.stringify(k) + ':' + canon(v[k]); }).join(',') + '}';
    return JSON.stringify(v == null ? '' : String(v));
  }
  /** A generated file; the page without sign-in names its report by the secret link (P.guest). */
  function fileUrl(id) { return P.api + '?a=file&id=' + encodeURIComponent(id) + (P.guest ? '&t=' + encodeURIComponent(P.id) : ''); }
  function uid() { return 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function num(v) { var t = String(v == null ? '' : v).replace(/,/g, '').trim(); return t !== '' && !isNaN(t) ? parseFloat(t) : null; }

  // ---------- network state ----------
  function online() { return navigator.onLine !== false; }
  function netState() {
    var el = $('[data-net]');
    el.className = 'sr-net ' + (online() ? 'on' : 'off');
    el.innerHTML = online() ? '<i class="fa-solid fa-wifi"></i> Online' : '<i class="fa-solid fa-plane"></i> Offline — saved on this device';
    $$('[data-online-only]').forEach(function (b) { b.disabled = !online() || !S.canEdit || b.hasAttribute('data-blocked'); });
    $$('[data-gate]').forEach(function (b) { b.disabled = !S.canEdit || b.hasAttribute('data-blocked'); }); // work offline too (PDF made on this device)
    $$('[data-off-note]').forEach(function (e) { e.hidden = online(); });
  }
  window.addEventListener('online', function () {
    netState();
    setTimeout(pendingImo, 2500); // an IMO typed while offline
    if (P.draft) { draftSync(); return; }
    // opened from the copy on this device: save what was typed, then the report as the server has it now
    if (S.offlineMode && S.data) { flush().then(function () { if (online() && !S.dirty) load(); }); return; }
    if (S.dirty) saveNow();
  });
  window.addEventListener('offline', netState);
  function saveState(text, cls) { var el = $('[data-save-state]'); el.className = 'sr-save ' + (cls || ''); el.textContent = text; }

  // ---------- load ----------
  function load() {
    netState();
    if (P.draft) { // started without internet: everything is on this device
      var d = read(DRAFT);
      if (!d || !d.res) { $('[data-form]').innerHTML = '<div class="sr-note err"><i class="fa-solid fa-plane"></i> This report is not on this device any more (it may have been sent already). <a href="survey_report_public.php">Start a report</a></div>'; return Promise.resolve(); }
      start(d.res, true);
      if (online()) draftSync();
      return Promise.resolve();
    }
    return fetch(P.api + '?a=get&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { $('[data-form]').innerHTML = '<div class="sr-note err">' + esc(res.error || 'Report not found.') + '</div>'; return; }
      store(CACHE, res);
      start(res, false);
    }).catch(function () {
      var res = read(CACHE);
      if (!res) { $('[data-form]').innerHTML = '<div class="sr-note err"><i class="fa-solid fa-plane"></i> You are offline and this report has not been opened on this device before. Open it once while online.</div>'; return; }
      start(res, true);
    });
  }
  function start(res, fromCache) {
    S.cfg = res.config; S.report = res.report; S.canEdit = res.can_edit; S.locked = !!res.locked; S.files = res.files || []; S.offlineMode = fromCache;
    S.version = res.report.version; S.data = normalise(res.report.data);
    var local = read(LS);
    if (local && local.dirty) {
      if (local.base === S.version || fromCache) { S.data = normalise(local.data); S.dirty = true; }
      else if (canon(normalise(JSON.parse(JSON.stringify(local.data)))) === canon(S.data)) store(LS, null); // already saved (e.g. on leaving the page)
      else return conflictLocal(local, res);
    }
    $('[data-title]').textContent = res.report.vessel;
    $('[data-subtitle]').textContent = (res.report.kind_label || 'Survey') + ' report';
    headChips();
    S.isAdmin = !!res.is_admin; S.tankLog = null;
    var have = steps().map(function (s) { return s.id; });
    var mapStep = function (x) { return /^g:/.test(x || '') ? 'tanks' : x === 'summary' ? 'calc' : x; };
    S.data.step = mapStep(S.data.step);
    var mine = mapStep(read(LS + '_step')); if (mine && have.indexOf(mine) >= 0) S.data.step = mine; // where this person was on this device
    if (have.indexOf(S.data.step) < 0) S.data.step = 'vessel';
    var seen = read(LS + '_seen') || []; if (seen.indexOf(S.data.step) < 0) { seen.push(S.data.step); store(LS + '_seen', seen); }
    if (VESSEL().some(function (k) { return k !== 'vessel_name' && val(k) !== ''; })) S.manual = true; // a draft with particulars: show them (a new report opens with the IMO search only)
    render();
    saveState(S.dirty ? (online() ? 'Saving…' : 'Changes on this device') : 'Saved', S.dirty ? 'dirty' : '');
    if (S.dirty && online() && !fromCache) saveNow();
    if (online() && !fromCache) { precache(); loadPrevious(); setTimeout(finalAfterOffline, 1500); setTimeout(pendingImo, 1200); }
  }
  /** Keep this page and everything it loaded (scripts incl. ExcelJS, styles, fonts, CDN files) for offline use. */
  function headChips() {
    var set = function (sel, v) { var e = $(sel); if (e) e.textContent = v; };
    set('[data-vchip]', S.report.vessel || '—'); set('[data-kchip]', S.report.kind_label || '—'); set('[data-kindcrumb]', S.report.kind_label || 'Survey');
    var d = val('completed_date') || val('started_date'), dt = d ? new Date(d + 'T00:00:00') : new Date();
    set('[data-dchip]', isNaN(dt) ? d : dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }));
  }
  function precache() {
    if (!('caches' in window)) return;
    var urls = [location.href];
    $$('script[src]').forEach(function (x) { urls.push(x.src); });
    urls.push(new URL(LOGO, location.href).href, new URL(BANNER, location.href).href); // the letterhead of the PDF made offline
    if (P.pdfjs) urls.push(P.pdfjs, P.pdfjsWorker); // the preview viewer (offline preview of the PDF made on this device)
    $$('link[rel=stylesheet][href]').forEach(function (x) { urls.push(x.href); });
    try { performance.getEntriesByType('resource').forEach(function (e) { if (/\.(woff2?|ttf|otf|png|jpe?g|svg|webp|css|js)(\?|$)/i.test(e.name)) urls.push(e.name); }); } catch (e) {}
    caches.open('ysms-sr-v1').then(function (c) {
      urls.filter(function (u, i) { return urls.indexOf(u) === i; }).forEach(function (u) {
        var same = new URL(u, location.href).origin === location.origin;
        c.match(u).then(function (hit) {
          if (hit && u !== location.href) return;
          fetch(u, same ? { credentials: 'same-origin' } : { mode: 'no-cors' }).then(function (r) { if (r.ok || r.type === 'opaque') c.put(u, r); }).catch(function () {});
        });
      });
    }).catch(function () {});
  }
  function conflictLocal(local, res) {
    Swal.fire({ icon: 'warning', title: 'Changes made on this device', html: 'This device has changes that were not saved yet, and the report was saved meanwhile by ' + esc(res.report.updated_by || 'someone') + ' (' + esc(String(res.report.updated_at).slice(0, 16)) + ').<br>Which one do you want to keep?',
      showDenyButton: true, confirmButtonText: 'Keep the saved report', denyButtonText: 'Use my device changes', allowOutsideClick: false })
      .then(function (r) {
        if (r.isDenied) { local.base = res.report.version; store(LS, local); } else store(LS, null);
        start(res, false);
      });
  }
  var ROW_KEYS = ['name', 'ref_height', 'sounding', 'volume', 'density', 'temp', 'mode', 'ullage', 'su', 'done', 'id', 'copy'];
  function normalise(d) {
    d = d || {}; d.fields = d.fields || {}; d.tanks = d.tanks || {}; d.extra = d.extra || {};
    d.grades = Array.isArray(d.grades) ? d.grades : [];
    d.step = d.step || 'vessel';
    S.cfg.grades.forEach(function (g) {
      d.tanks[g.key] = (d.tanks[g.key] || []).map(function (r) {
        var o = {}; ROW_KEYS.forEach(function (k) { o[k] = r[k] == null ? '' : String(r[k]); });
        o.mode = o.mode === 'ullage' ? 'ullage' : 'sounding'; if (!o.id) o.id = uid();
        o.su = o.mode === 'ullage' ? 'u' : o.su === 's' ? 's' : ''; // S / U chosen on the computer: printed next to the reading
        return o;
      });
    });
    return d;
  }


  // ---------- fields ----------
  var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
  var LABELS = { vessel_name: 'Vessel Name', flag: 'Flag', port_of_registry: 'Port of Registry', grt: 'GRT', nrt: 'NRT', owners: 'Owner Name', holds: 'Number of Holds',
    builder: 'Builder', keel_month: 'Keel Laid Month', keel_year: 'Keel Laid Year', operator: 'Manager / Operator Name', callsign: 'Callsign', loa: 'LOA (m)',
    breadth: 'Breadth Moulded (m)', depth: 'Depth Moulded (m)', summer_draft: 'Summer Draft (m)', summer_dwt: 'Summer Deadweight (MT)', 'class': 'Class', imo: 'IMO Number',
    fwe_date: 'FWE Date', fwe_time: 'FWE Time (LT)', started_date: 'Survey Started Date', started_time: 'Survey Started Time (LT)', completed_date: 'Survey Completed Date',
    completed_time: 'Survey Completed Time (LT)', surveyor_name: 'Surveyor Name', chief_engineer: 'Chief Engineer Name', master: 'Master Name', port_name: 'Port Name',
    place: 'Place of Survey', draft_fwd: 'Forward Draft (m)', draft_aft: 'Aft Draft (m)', list: 'List (degrees)', voyage_no: 'Voyage No.', charterers: 'Charterers Name', alongside: 'Alongside' };
  function FIELD(k) {
    var f = null;
    S.cfg.fields.forEach(function (x) { if (x.key === k) f = x; });
    if (!f) S.cfg.extra_fields.forEach(function (x) { if (x.key === k) f = { key: x.key, label: x.label, type: x.type, extra: true }; });
    return f;
  }
  function isExtra(k) { var f = FIELD(k); return !!(f && f.extra); }
  function val(k) { var v = isExtra(k) ? S.data.extra[k] : S.data.fields[k]; return v == null ? '' : String(v).trim(); }
  function setVal(k, v) { if (isExtra(k)) S.data.extra[k] = v; else S.data.fields[k] = v; }
  function VESSEL() { return S.cfg.vessel_keys; }
  function gradeInfo(g) { var o = null; S.cfg.grades.forEach(function (x) { if (x.key === g) o = x; }); return o; }
  function chosen() { return S.cfg.grades.map(function (g) { return g.key; }).filter(function (g) { return S.data.grades.indexOf(g) >= 0; }); }
  function label(k) {
    var m = /^brob_(fwe|started)_(\w+)$/.exec(k);
    if (m) return (m[1] === 'fwe' ? 'FWE ROB ' : 'Log Book ROB ') + gradeInfo(m[2]).short + ' (MT)';
    return LABELS[k] || (FIELD(k) || {}).label || k;
  }
  /** Quick type check as in the server (sfValidate); the server checks again. */
  function typeError(f, v) {
    if (v === '') return '';
    var n = v.replace(/,/g, '');
    if (f.type === 'imo') { var d = v.replace(/^IMO\s*/i, ''); if (!/^\d{7}$/.test(d)) return 'IMO number has 7 digits.'; var s = 0; for (var i = 0; i < 6; i++) s += +d[i] * (7 - i); return s % 10 === +d[6] ? '' : 'IMO check digit does not match — please re-check the number.'; }
    if (f.type === 'int') return /^\d+$/.test(n) ? '' : 'Whole number only.';
    if (/^num/.test(f.type)) { if (isNaN(n)) return 'Numbers only.'; var dec = +f.type.split(':')[1], p = n.split('.')[1]; return p && p.length > dec ? 'Use at most ' + dec + ' decimals.' : ''; }
    if (f.type === 'time') return /^([01]\d|2[0-3]):[0-5]\d$/.test(v) ? '' : 'Enter the time as HH:MM (24 h).';
    if (f.type === 'date') return /^\d{4}-\d{2}-\d{2}$/.test(v) ? '' : 'Enter a valid date.';
    return '';
  }

  // ---------- checks (same rules as srValidate on the server) ----------
  function tankErrors(r) {
    var e = {}, ull = r.mode === 'ullage';
    var need = [['name', 'Tank name', 'text'], ['ref_height', 'Reference height', 'height'], [ull ? 'ullage' : 'sounding', ull ? 'Ullage' : 'Sounding', ull ? 'num:3' : 'height'], ['volume', 'Volume', 'num:3'], ['density', 'Density @15°C', 'num:4'], ['temp', 'Temperature', 'num:1']];
    need.forEach(function (n) {
      var v = String(r[n[0]] || '').trim();
      if (v === '') { e[n[0]] = n[1] + ' is missing.'; return; }
      if (n[2] === 'height' || n[2] === 'text') return;
      var t = typeError({ type: n[2] }, v); if (t) { e[n[0]] = n[1] + ': ' + t; return; }
      var x = num(v);
      if (n[0] === 'density' && (x < 0.6 || x > 1.1)) e[n[0]] = 'Density @15°C is normally between 0.6000 and 1.1000.';
      if (n[0] === 'temp' && (x < -10 || x > 150)) e[n[0]] = 'Temperature looks wrong (−10 to 150 °C).';
      if (n[0] === 'volume' && x < 0) e[n[0]] = 'Volume must be 0 or more.';
    });
    if (ull && !e.ref_height && !e.ullage) {
      if (num(r.ref_height) === null) e.ref_height = 'Reference height must be a number to work out the sounding from the ullage.';
      else if (num(r.ullage) > num(r.ref_height)) e.ullage = 'Ullage is more than the reference height.';
    }
    return e;
  }
  function issues(stepId) {
    var out = [];
    var add = function (step, key, msg) { if (!stepId || stepId === step) out.push({ step: step, key: key, msg: msg }); };
    ['imo'].concat(VESSEL()).forEach(function (k) {
      var f = FIELD(k); if (!f) return;
      var v = val(k), t = typeError(f, v);
      if (v === '') add('vessel', 'f:' + k, label(k) + ' is missing.'); else if (t) add('vessel', 'f:' + k, label(k) + ': ' + t);
    });
    var g = chosen();
    if (!g.length) add('survey', 'grades', 'Choose the fuel grades on board.');
    var req = S.cfg.survey_required.slice();
    g.forEach(function (x) { req.push('brob_fwe_' + x, 'brob_started_' + x); });
    req.concat(['voyage_no', 'charterers', 'alongside', 'list']).forEach(function (k) {
      var f = FIELD(k); if (!f) return;
      var v = val(k), t = typeError(f, v);
      if (v === '' && req.indexOf(k) >= 0) add('survey', 'f:' + k, label(k) + ' is missing.'); else if (t) add('survey', 'f:' + k, label(k) + ': ' + t);
    });
    var s = val('started_date') + ' ' + val('started_time'), c = val('completed_date') + ' ' + val('completed_time');
    if (s.length === 16 && c.length === 16 && c < s) add('survey', 'f:completed_time', 'Survey Completed is before Survey Started.');
    g.forEach(function (x) {
      var rows = S.data.tanks[x], short = gradeInfo(x).short;
      if (!rows.some(function (r) { return !blankRow(r); })) { add('tanks', '', short + ': add at least one tank.'); return; }
      rows.forEach(function (r, i) {
        if (blankRow(r)) return;
        var e = tankErrors(r), tn = short + ' tank ' + (i + 1) + (r.name ? ' (' + r.name + ')' : '');
        Object.keys(e).forEach(function (col) { add('tanks', 't:' + x + ':' + i + ':' + col, tn + ': ' + e[col]); });
      });
    });
    return out;
  }

  // ---------- steps ----------
  function steps() {
    return [{ id: 'vessel', title: 'Vessel Details', short: 'Vessel Details', m: 'Vessel' }, { id: 'survey', title: 'Survey Details', short: 'Survey Details', m: 'Survey' },
      { id: 'tanks', title: 'Tank Details', short: 'Tank Details', m: 'Tank' }, { id: 'calc', title: 'Calculations', short: 'Calculations', m: 'Calc' },
      { id: 'docs', title: 'Documents', short: 'Documents', m: 'Docs' }, { id: 'generate', title: 'Preview & Generate', short: 'Preview & Generate', m: 'Generate' }];
  }
  function stepIndex(id) { var l = steps(); for (var i = 0; i < l.length; i++) if (l[i].id === id) return i; return 0; }
  function stepDone(id) {
    if (id === 'generate') return false;
    if (id === 'calc' || id === 'docs') return !issues().length;
    return !issues(id).length && (id !== 'survey' || chosen().length > 0);
  }
  /** A step can be opened from the step bar when every step before it is complete, or when it was opened before (to come back after a fix). */
  function reachable(i) {
    var l = steps(), seen = read(LS + '_seen') || [];
    if (l[i] && seen.indexOf(l[i].id) >= 0) return true;
    for (var j = 0; j < i; j++) if (!stepDone(l[j].id)) return false;
    return true;
  }
  function go(id) {
    var seen = read(LS + '_seen') || []; if (seen.indexOf(id) < 0) { seen.push(id); store(LS + '_seen', seen); }
    S.data.step = id; store(LS + '_step', id); S.sheet = null; render(); window.scrollTo(0, 0); // the step is remembered on this device; moving between steps saves nothing by itself (two people can look at the same report without clashing)
    if (id === 'calc' || id === 'generate') { verify(); }
    if (id === 'docs') S.tankLog = null;
  }

  // ---------- render ----------
  function inputHtml(k, opts) {
    opts = opts || {};
    var f = FIELD(k); if (!f) return '';
    var v = val(k), dis = S.canEdit && !opts.ro ? '' : ' disabled', inp;
    var attrs = ' data-f="' + k + '" data-testid="sr-f-' + k + '"' + dis + (S.ai[k] ? ' class="ai" title="Filled by Vessel lookup — please check"' : '');
    if (f.type === 'month') inp = '<select' + attrs + '><option value=""></option>' + MONTHS.map(function (m) { return '<option' + (m === v ? ' selected' : '') + '>' + m + '</option>'; }).join('') + '</select>';
    else if (k === 'survey_type') { // the certificate's wording follows it (includes/survey_certificates.php)
      var ch = (S.cfg.cert || {}).choices || {}, cur = certType();
      inp = '<select' + attrs + '>' + Object.keys(ch).map(function (c) { return '<option value="' + esc(c) + '"' + (c === cur ? ' selected' : '') + '>' + esc(ch[c]) + '</option>'; }).join('') + '</select>';
    }
    else {
      var t = f.type === 'date' ? 'date' : f.type === 'time' ? 'time' : 'text';
      var mode = /^(num|int|imo)/.test(f.type) ? ' inputmode="decimal"' : '';
      inp = '<input type="' + t + '"' + mode + attrs + ' value="' + esc(v) + '" maxlength="200" autocomplete="off"' + (t === 'text' ? ' placeholder=" "' : '') + '>'; // " ": the label sits in the empty box (phone)
    }
    return '<label class="sr-f' + (opts.wide ? ' wide' : '') + '" data-fw="' + k + '"><span>' + esc(opts.label || label(k)) + (opts.req ? ' <b class="sr-req">*</b>' : '') + '</span>' + inp + '<em data-err="' + k + '"></em></label>';
  }
  function render() {
    var note = '';
    if (!S.canEdit) note = S.locked ? '<div class="sr-note warn" data-testid="sr-locked"><i class="fa-solid fa-lock"></i> This report is locked by the Admin — read only. Ask the Admin for edit access.</div>' : '<div class="sr-note warn"><i class="fa-solid fa-lock"></i> View only.</div>';
    else if (S.offlineMode) note = '<div class="sr-note warn"><i class="fa-solid fa-plane"></i> Offline — saved on this device, sent when you are back online.</div>';
    $('[data-banners]').innerHTML = (S.signedOut ? signedOutHtml() : '') + note;
    S.wide = window.matchMedia('(min-width: 992px)').matches;
    if (S.wide) xlLoad();
    var list = steps(), i = stepIndex(S.data.step), st = list[i], body;
    if (st.id === 'vessel') body = vesselHtml();
    else if (st.id === 'survey') body = surveyHtml();
    else if (st.id === 'tanks') body = tanksHtml();
    else if (st.id === 'calc') body = calcHtml();
    else if (st.id === 'docs') body = docsHtml();
    else body = generateHtml();
    var crumb = $('[data-crumb]'); if (crumb) crumb.textContent = st.title;
    document.body.setAttribute('data-sr-step', st.id);
    var bar = '<ol class="sr-steps" data-testid="sr-steps">' + list.map(function (x, j) {
      var cls = j === i ? 'on' : stepDone(x.id) ? 'done' : '', can = reachable(j) || j < i;
      return '<li class="' + cls + '"><button type="button" data-goto="' + x.id + '"' + (can ? '' : ' disabled') + ' data-testid="sr-step-' + x.id + '" title="' + esc(x.title) + '">' +
        '<span class="n">' + (cls === 'done' ? '<i class="fa-solid fa-check"></i>' : j + 1) + '</span><span class="t">' + esc(x.short) + '</span><span class="tm">' + esc(x.m) + '</span></button></li>';
    }).join('') + '</ol>';
    var next = i < list.length - 1 ? list[i + 1] : null;
    var blocked = st.id === 'vessel' && issues('vessel').length > 0;
    var slot = $('[data-steps]'); if (slot) { slot.innerHTML = bar; bar = ''; }
    $('[data-form]').innerHTML =
      '<div class="sr-wiz" data-testid="sr-wizard">' + bar +
      '<div class="sr-wiz-top"><span data-testid="sr-step-no">Step ' + (i + 1) + ' of ' + list.length + '</span><span class="sr-muted">' + list.filter(function (x) { return stepDone(x.id); }).length + ' of ' + (list.length - 1) + ' steps complete</span></div>' +
      '<h2 class="sr-qt' + (st.id === 'tanks' ? ' sr-vh' : '') + '" data-testid="sr-step-title">' + esc(st.title) + '</h2>' +
      '<div class="sr-step" data-step="' + st.id + '">' + body + '</div>' +
      '<div class="sr-wiz-nav" data-testid="sr-nav"><button type="button" class="sr-btn" data-back' + (i ? '' : ' disabled') + ' data-testid="sr-back"><i class="fa-solid fa-chevron-left"></i> Previous</button>' +
      '<span class="sr-nav-r">' + (S.canEdit ? '<button type="button" class="sr-btn" data-save-now data-testid="sr-save-now"><i class="fa-solid fa-floppy-disk"></i> Save Draft</button>' : '') +
      (next ? '<button type="button" class="sr-btn pri" data-next data-testid="sr-next"' + (blocked ? ' disabled title="Complete the vessel particulars first"' : '') + '>Next: ' + esc(next.title) + ' <i class="fa-solid fa-arrow-right"></i></button>' : '') + '</span></div></div>' +
      (S.sheet ? sheetHtml() : '');
    showErrors(); netState(); pvHint();
  }
  // step 1
  function vesselHtml() {
    var show = S.manual || S.lookup;
    var miss = show ? issues('vessel').filter(function (x) { return x.key !== 'f:imo'; }) : [];
    return '<section class="sr-card sr-imo" data-testid="sr-vessel"><div class="sr-lookup">' + inputHtml('imo', { label: 'IMO Number', req: true }) +
      (S.canEdit ? '<button type="button" class="sr-btn pri" data-lookup data-online-only data-testid="sr-lookup"' + (S.looking ? ' disabled><i class="fa-solid fa-spinner fa-spin"></i> Looking up…' : '><i class="fa-solid fa-magnifying-glass"></i> Lookup') + '</button>' : '') + '</div>' +
      (S.canEdit && S.prev ? '<div class="sr-prev" data-testid="sr-prev"><button type="button" class="sr-btn alt-o" data-prev data-testid="sr-prev-fill"><i class="fa-solid fa-clock-rotate-left"></i> Fill from previous attendance</button>' +
        '<span class="sr-muted sr-small">This vessel was attended before — ' + esc(S.prev.label) + '. Fills the particulars, the grades and the tank names with heights.</span></div>' : '') +
      '<div data-lookup-info data-testid="sr-lookup-info">' + lookupInfoHtml() + '</div>' +
      (!show && S.canEdit ? '<button type="button" class="sr-link" data-manual data-testid="sr-manual">or enter the particulars by hand</button>' : '') + '</section>' +
      (show ? '<section class="sr-card"><h3>Vessel particulars</h3>' +
        (miss.length ? '<div class="sr-note err" data-testid="sr-vessel-missing"><i class="fa-solid fa-circle-exclamation"></i> ' + miss.length + ' particular' + (miss.length === 1 ? '' : 's') + ' still to enter: ' + esc(miss.map(function (x) { return label(x.key.slice(2)); }).join(', ')) + '. Type them in to continue.</div>' : '') +
        '<div class="sr-grid">' + VESSEL().map(function (k) { return inputHtml(k, { req: true }); }).join('') + '</div></section>' : '');
  }
  function lookupInfoHtml() {
    if (S.looking) return '<div class="sr-note sr-looking" data-testid="sr-looking"><i class="fa-solid fa-spinner fa-spin"></i> Finding the particulars of IMO ' + esc(S.autoImo || val('imo')) + '… <span class="sr-muted">You can carry on; they are filled in when found.</span></div>';
    if (S.lookupMsg) return '<div class="sr-note warn" data-testid="sr-lookup-msg"><i class="fa-solid fa-circle-info"></i> ' + esc(S.lookupMsg) + ' You can type the particulars in below.</div>';
    var L = S.lookup; if (!L) return '';
    var n = Object.keys(L.prev).length;
    var where = L.from === 'report' ? 'from this vessel\'s last survey report' + (L.at ? ' (' + esc(String(L.at).slice(0, 10)) + ')' : '')
      : L.from === 'saved' ? 'found earlier for this IMO' : 'found on the web';
    return '<div class="sr-note sr-ai-note"><b><i class="fa-solid fa-wand-magic-sparkles"></i> ' + n + ' field' + (n === 1 ? '' : 's') + ' filled for IMO ' + esc(L.imo) + '</b> — ' + where + '; please check the highlighted values against the ship\'s documents.' +
      (S.canEdit ? ' <button type="button" class="sr-btn sm" data-undo data-testid="sr-undo"><i class="fa-solid fa-rotate-left"></i> Undo</button>' : '') +
      (L.sources.length ? '<div class="sr-src">Sources: ' + L.sources.map(function (s) { return '<a href="' + esc(s.uri) + '" target="_blank" rel="noopener noreferrer">' + esc(s.title || s.uri) + '</a>'; }).join(' · ') + '</div>' : '') + '</div>';
  }
  // step 2
  function surveyHtml() {
    var g = chosen(), req = { req: true };
    var robs = function (kind) {
      if (!g.length) return '<p class="sr-muted sr-span">Choose the fuel grades above to enter the ROB.</p>';
      return g.map(function (x) { return inputHtml('brob_' + kind + '_' + x, req); }).join('');
    };
    var trim = num(val('draft_fwd')) !== null && num(val('draft_aft')) !== null ? (num(val('draft_aft')) - num(val('draft_fwd'))).toFixed(3) : '';
    var card = function (title, icon, inner, tid, keys) {
      var st = keys ? doneOf(keys) : '';
      return '<section class="sr-card sr-s2c" data-testid="sr-grp-' + tid + '"><h3><i class="fa-solid ' + icon + '"></i> ' + title + st + '</h3><div class="sr-grid">' + inner + '</div></section>'; };
    /** "2 / 4" or a green tick once every required box of the group is filled */
    function doneOf(keys) {
      var n = keys.filter(function (k) { return String(val(k) || '').trim() !== ''; }).length;
      return n === keys.length ? '<span class="sr-s2-ok" data-testid="sr-s2-done"><i class="fa-solid fa-circle-check"></i> Done</span>'
        : '<span class="sr-s2-n">' + n + ' / ' + keys.length + '</span>';
    }
    /** one event of the survey (FWE, start, completion): date + time with a "Now" button, then the ROB of each grade */
    var EV = [
      { k: 'fwe', title: 'Finish With Engine (FWE)', sub: 'As per Engine Logbook', icon: 'fa-gears', d: 'fwe_date', t: 'fwe_time', rob: 'fwe', robT: 'FWE ROB' },
      { k: 'started', title: 'Survey Start Time', sub: 'As per Engine Logbook', icon: 'fa-play', d: 'started_date', t: 'started_time', rob: 'started', robT: 'Log Book ROB' },
      { k: 'completed', title: 'Survey Completed Time', sub: '1-2 hours from start time', icon: 'fa-flag-checkered', d: 'completed_date', t: 'completed_time' }
    ];
    var timeline = '<section class="sr-card sr-tl" data-testid="sr-timeline"><h3><i class="fa-solid fa-timeline"></i> Survey timeline <small>Local time (LT)</small></h3><div class="sr-tl-row">' +
      EV.map(function (e) {
        return '<div class="sr-tl-i sr-tl-plain" data-testid="sr-grp-' + e.k + '"><div class="sr-tl-b">' +
          '<div class="sr-tl-h"><b><i class="fa-solid ' + e.icon + '"></i> ' + esc(e.title) + '</b><small data-testid="sr-tl-sub-' + e.k + '">' + esc(e.sub) + '</small></div>' +
          '<div class="sr-tl-g">' + inputHtml(e.d, { req: true, label: 'Date' }) + inputHtml(e.t, { req: true, label: 'Time (LT)' }) +
            (e.extra ? inputHtml(e.extra, req) : '') + '</div>' +
          (e.rob ? '<div class="sr-tl-rob"><span class="sr-tl-rl">' + esc(e.robT) + ' <small>MT</small></span>' +
            (g.length ? '<div class="sr-tl-g">' + g.map(function (x) {
              var gr = S.cfg.grades.filter(function (y) { return y.key === x; })[0];
              return inputHtml('brob_' + e.rob + '_' + x, { req: true, label: gr ? gr.short : x.toUpperCase() });
            }).join('') + '</div>' : '<p class="sr-muted">Choose the fuel grades above first.</p>') + '</div>' : '') +
          '</div></div>';
      }).join('') + '</div></section>';
    return '<section class="sr-card sr-s2g" data-testid="sr-grades" data-fw="grades"><h3><i class="fa-solid fa-gas-pump"></i> Fuel grades on board <b class="sr-req">*</b>' +
      '<small>' + (g.length ? g.length + ' selected' : 'tap the grades the vessel has') + '</small></h3>' +
      '<div class="sr-chips">' + S.cfg.grades.map(function (x) {
        var on = g.indexOf(x.key) >= 0;
        return '<label class="sr-chip' + (on ? ' on' : '') + '" data-testid="sr-grade-' + x.key + '"><input type="checkbox" data-grade="' + x.key + '"' + (on ? ' checked' : '') + (S.canEdit ? '' : ' disabled') + '><i class="fa-solid fa-check sr-chip-tick" aria-hidden="true"></i><b>' + esc(x.short) + '</b><small>' + esc(x.name) + '</small></label>';
      }).join('') + '</div><em data-err="grades"></em></section>' +
      timeline +
      (function () { // every other survey detail in one card, as the vessel particulars in step 1
        var keys = ['owners', 'charterers', 'port_name', 'place', 'draft_aft', 'draft_fwd', 'master', 'chief_engineer', 'surveyor_name'];
        return card('Survey particulars', 'fa-clipboard-list',
          (S.cfg.cert ? inputHtml('survey_type', { label: 'Survey Type' }) : '') +
          inputHtml('owners', { req: true, label: 'Owners' }) + inputHtml('charterers', { req: true, label: 'Charterers' }) +
          inputHtml('port_name', req) + inputHtml('place', { req: true, label: 'Place of Survey' }) + inputHtml('voyage_no', { label: 'Voyage No.' }) +
          inputHtml('draft_aft', { req: true, label: 'Draft Aft (m)' }) + inputHtml('draft_fwd', { req: true, label: 'Draft Fwd (m)' }) +
          '<label class="sr-f calc"><span>Trim (m) <small>Aft − Fwd, worked out</small></span><output data-testid="sr-trim">' + trim + '</output></label>' + inputHtml('list', { label: 'List (degrees)' }) +
          inputHtml('master', { req: true, label: 'Master (Capt.)' }) + inputHtml('chief_engineer', { req: true, label: 'Chief Engineer' }) + inputHtml('surveyor_name', { req: true, label: 'Surveyor Name' }), 'details', keys) +
          '<p class="sr-muted sr-small sr-s2-tip"><i class="fa-solid fa-circle-info"></i> Names print in capital letters on the report, however they are typed.</p>';
      })();
  }
  // tank steps
  /** Readings as the surveyors write them, however typed: sounding 0.000, density 0.0000, temperature 00.0. */
  var TFMT = { sounding: 3, density: 4, temp: 1 };
  function fmtT(col, v) {
    var n = num(v); if (n === null || !(col in TFMT)) return v == null ? '' : String(v);
    var t = n.toFixed(TFMT[col]);
    if (col === 'temp' && n >= 0 && n < 10) t = '0' + t;
    return t;
  }
  function fmtRow(r) { Object.keys(TFMT).forEach(function (c) { if (c !== 'sounding' || r.mode !== 'ullage') r[c] = fmtT(c, r[c]); }); }
  function sounding(r) {
    if (r.mode !== 'ullage') return String(r.sounding || '');
    var a = num(r.ref_height), b = num(r.ullage);
    return a === null || b === null ? '' : (Math.round((a - b) * 1000) / 1000).toFixed(3);
  }
  function calc(r) {
    var t = window.ReportCalc && S.cfg.calc ? ReportCalc.tank(r, S.cfg.calc) : {};
    var d = num(r.density);
    t.wcf = d === null || !S.cfg.calc ? null : ReportCalc.round(d - S.cfg.calc.wcf, 4);
    return t;
  }
  function gradeTotals(g) {
    var mt = 0, gsv = 0, ok = true;
    var rows = S.data.tanks[g].filter(function (r) { return !blankRow(r); });
    rows.forEach(function (r) { var t = calc(r); if (t.mt === null || t.mt === undefined) ok = false; else { mt += t.mt; gsv += t.gsv; } });
    return { mt: ReportCalc.round(mt, 3), gsv: ReportCalc.round(gsv, 3), ok: ok && rows.length > 0, n: rows.length };
  }
  // ---------- step 3: tank details — a grid on a computer, a list + bottom sheet on a phone ----------
  var OPT_COLS = [['ullage', 'Ullage'], ['volume', 'Volume'], ['vcf', 'V.C.F.'], ['wcf', 'W.C.F.'], ['gsv', 'G.S.V.']];
  function hiddenCols() { return read('ysms_sr_cols') || []; }
  function allTotals() {
    var n = 0, mt = 0, gsv = 0;
    chosen().forEach(function (g) { var t = gradeTotals(g); n += t.n; mt += t.mt || 0; gsv += t.gsv || 0; });
    return { n: n, mt: ReportCalc.round(mt, 3), gsv: ReportCalc.round(gsv, 3) };
  }
  function f3c(v) { return v === null || v === undefined || v === '' || isNaN(v) ? '' : (+v).toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 }); }
  function totalsBar() {
    var t = allTotals();
    return '<div class="sr-totbar" data-testid="sr-totbar">' + (cardMode() ? '<div><span>Total Tanks:</span> <b data-testid="sr-tot-tanks">' + t.n + '</b></div>' : '<div></div>') +
      '<div class="r mt"><span>Total Metric Tons:</span> <b data-testid="sr-tot-mt">' + f3c(t.mt) + ' MT</b></div><b hidden data-testid="sr-tot-gsv">' + f3c(t.gsv) + ' m³</b></div>';
  }
  function tankToolbar() {
    var prevTanks = S.canEdit && S.prev && Object.keys(S.prev.tanks || {}).length;
    return prevTanks ? '<div class="sr-tk-tools"><button type="button" class="sr-btn sm" data-prev-tanks data-testid="sr-copy-prev"><i class="fa-regular fa-copy"></i> Copy from Previous</button></div>' : '';
  }
  /** Page without sign-in: the usual tanks of each grade are already in the table, readings 0.000 (settling / service tanks
      GAUGE), density and temperature left to type. Added once per grade when its table is still empty; any can be renamed or deleted. */
  var G_GAUGE = { ref_height: 'GAUGE', sounding: 'GAUGE' };
  var DEFAULT_TANKS = {
    vlsfo: ['1P', '1S', '2P', '2S', '3P', '3S', ['SETT 1', G_GAUGE], ['SERV 1', G_GAUGE], ['SETT 2', G_GAUGE], ['SERV 2', G_GAUGE], 'OVERFLOW'],
    hsfo: ['TANK NO.1', 'TANK NO.2', 'TANK NO.3'],
    ulsfo: ['TANK NO.1', 'TANK NO.2', 'TANK NO.3'],
    lsmgo: ['MDO NO.1 P', 'MDO NO.1 S', 'MDO NO.2 P', 'MDO NO.2 S', ['SERV 1', G_GAUGE], ['SERV 2', G_GAUGE]]
  };
  function guestDefaults() {
    if (!(P.guest || P.std) || !S.canEdit) return;
    S.defDone = S.defDone || {};
    var added = false;
    chosen().forEach(function (g) {
      if (S.defDone[g] || !DEFAULT_TANKS[g]) return;
      S.defDone[g] = true;
      if (S.data.tanks[g].some(function (r) { return !blankRow(r); })) return;
      S.data.tanks[g] = DEFAULT_TANKS[g].map(function (t) {
        var r = emptyRow(), nm = typeof t === 'string' ? t : t[0], over = typeof t === 'string' ? {} : t[1];
        r.name = nm; r.ref_height = over.ref_height || '0.000'; r.sounding = over.sounding || '0.000'; r.volume = '0.000';
        return r;
      });
      if (S.spare) delete S.spare[g];
      added = true;
    });
    if (added) changed(true);
  }
  function tanksHtml() {
    guestDefaults();
    var g = chosen();
    if (!g.length) return '<div class="sr-note warn"><i class="fa-solid fa-gas-pump"></i> Choose the fuel grades on board in Survey Details first. <button type="button" class="sr-btn sm" data-goto="survey">Go to Survey Details</button></div>';
    if (xlOn()) return window.SRXL.html();
    return tankToolbar() + gridHtml() + totalsBar();
  }
  /** Desktop / laptop (≥992px): the Excel-style sheet (assets/js/survey-report-xl.js), loaded only on a computer screen
      and never on a phone, where the tank cards stay as they are. Off with ?classic=1 (or SR_PAGE.xl = false). */
  var XL_ON = !!P.xl && !/[?&]classic=1\b/.test(location.search);
  function xlOn() { return XL_ON && S.wide && !!window.SRXL; }
  function xlLoad() {
    if (!XL_ON || !S.wide || window.SRXL || S.xlLoading) return;
    S.xlLoading = true;
    var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = P.xl.css; document.head.appendChild(l);
    var sc = document.createElement('script'); sc.src = P.xl.js; sc.async = true;
    sc.onload = function () { if (window.SRXL && S.data && S.data.step === 'tanks' && S.wide) render(); };
    sc.onerror = function () { S.xlLoading = false; }; // stays on the plain grid
    document.head.appendChild(sc);
  }
  function gradeSelect(g, i) {
    return '<select data-tg="' + g + ':' + i + '" data-testid="sr-tg-' + g + '-' + i + '"' + (S.canEdit ? '' : ' disabled') + '>' + chosen().map(function (x) { return '<option value="' + x + '"' + (x === g ? ' selected' : '') + '>' + esc(gradeInfo(x).short) + '</option>'; }).join('') + '</select>';
  }
  function cellIn(g, i, col, r, mode) {
    var key = g + ':' + i;
    return '<label class="sr-f sr-cellf" data-tw="' + key + ':' + col + '"><input data-t="' + key + ':' + col + '" data-testid="sr-t-' + g + '-' + i + '-' + col + '" value="' + esc(shown(r, col)) + '"' +
      (mode ? ' inputmode="decimal"' : '') + (S.canEdit ? '' : ' disabled') + ' autocomplete="off"' + (col === 'name' ? ' placeholder="Tank name"' : '') + '><em data-terr="' + key + ':' + col + '"></em></label>';
  }
  /** A cell's value as shown: readings in the report's format (0.000 / 0.0000 / 00.0), the other of sounding / ullage worked out. */
  function shown(r, col) { return col === 'ullage' ? ullageOf(r) : col === 'sounding' ? fmtT('sounding', sounding(r)) : col in TFMT ? fmtT(col, r[col]) : r[col]; }
  /** Ullage shown next to the sounding: typed, or reference height − sounding. */
  function ullageOf(r) {
    if (r.mode === 'ullage') return String(r.ullage || '');
    var a = num(r.ref_height), b = num(r.sounding);
    return a === null || b === null ? '' : (Math.round((a - b) * 1000) / 1000).toFixed(3);
  }
  function rowState(r) { return r.done && !r._editing ? 'done' : r.copy ? 'copy' : 'open'; }
  /** Computer screen: one compact table per fuel grade with the vessel's tanks. Tank Name · Reference Height · Sounding /
      Ullage · Volume · Density @ 15°C · Temperature are typed into the cells; VCF · GSV · Metric Tons are worked out as you
      type (grey, not editable), the grade's total below. "Add Tank" gives one more row, any row can be deleted. Empty rows
      are never saved or checked. */
  var XCOLS = [['name', 'Tank Name', ''], ['ref_height', 'Reference Height', '(m)'], ['sounding', 'Sounding / Ullage', '(m)'], ['volume', 'Volume', '(m³)'], ['density', 'Density', '@ 15°C'], ['temp', 'Temperature', '(°C)']];
  /** Worked out by report-calc.js (the template's formulas), never typed: grey cells. */
  var XCALC = [['vcf', 'VCF', ''], ['gsv', 'GSV', '(m³)'], ['mt', 'Metric Tons', '(MT)']];
  /** Order of the grade tables on a computer. */
  var XORDER = ['vlsfo', 'lsmgo', 'ulsfo', 'hsfo', 'hsmgo'];
  /** Empty rows shown below the tanks of a grade: at first enough to reach the grade's row count. */
  function xSpare(g) {
    S.spare = S.spare || {};
    if (!(g in S.spare)) S.spare[g] = 0; // only the vessel's tanks; Add Tank gives one more row
    return S.canEdit ? S.spare[g] : 0;
  }
  function xCell(g, i, col, r) {
    var key = g + ':' + i, ul = col === 'sounding' && r && r.mode === 'ullage', so = !!(r && r.su === 's'), pre = '';
    if (col === 'sounding') {
      if (ul) col = 'ullage';
      pre = '<span class="sr-su" role="radiogroup" aria-label="Sounding or ullage">' +
        '<button type="button" tabindex="-1" role="radio" class="s' + (so ? ' on' : '') + '" aria-checked="' + so + '" data-su="' + key + ':s" title="Sounding" data-testid="sr-su-' + g + '-' + i + '-s"' + (S.canEdit ? '' : ' disabled') + '>S</button>' +
        '<button type="button" tabindex="-1" role="radio" class="u' + (ul ? ' on' : '') + '" aria-checked="' + ul + '" data-su="' + key + ':u" title="Ullage" data-testid="sr-su-' + g + '-' + i + '-u"' + (S.canEdit ? '' : ' disabled') + '>U</button></span>';
    }
    if (col === 'name' && r && S.canEdit) {
      var n = S.data.tanks[g].length;
      pre = '<span class="sr-grip" draggable="true" data-grip="' + key + '" title="Drag to move the tank (or Alt + ↑ / ↓)" data-testid="sr-grip-' + g + '-' + i + '"><i class="fa-solid fa-grip-vertical"></i></span>' +
        '<span class="sr-mv"><button type="button" tabindex="-1" data-mv="' + key + ':-1" title="Move up" aria-label="Move up" data-testid="sr-up-' + g + '-' + i + '"' + (i === 0 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-up"></i></button>' +
        '<button type="button" tabindex="-1" data-mv="' + key + ':1" title="Move down" aria-label="Move down" data-testid="sr-down-' + g + '-' + i + '"' + (i === n - 1 ? ' disabled' : '') + '><i class="fa-solid fa-chevron-down"></i></button></span>';
    }
    var v = r ? shown(r, col) : '', dec = col === 'volume' || col === 'density' || col === 'temp';
    var suOff = (col === 'sounding' || col === 'ullage') && num(v) === null; // S / U only next to a number (not GAUGE, not empty)
    return '<td class="xi x-' + (col === 'ullage' ? 'sounding' : col) + (pre ? ' has-pre' : '') + (suOff ? ' su-off' : '') + '">' + pre + '<label class="sr-f sr-cellf" data-tw="' + key + ':' + col + '"><input data-t="' + key + ':' + col + '" data-testid="sr-t-' + g + '-' + i + '-' + col + '" value="' + esc(v) + '"' +
      (dec ? ' inputmode="decimal"' : '') + (S.canEdit ? '' : ' disabled') + ' autocomplete="off" spellcheck="false"' + (/^GAUGE$/i.test(v) ? ' class="gauge"' : '') + '><em data-terr="' + key + ':' + col + '"></em></label></td>';
  }
  function rowHtml(g, r, i) {
    var key = g + ':' + i, t = r ? calc(r) : {}, real = !!r;
    return '<tr class="' + (real ? 'sr-row' : 'sr-spare') + '" data-tank="' + key + '" data-testid="sr-' + (real ? 'tank' : 'spare') + '-' + g + '-' + i + '">' +
      XCOLS.map(function (c) { return xCell(g, i, c[0], r); }).join('') +
      '<td class="xo x-vcf"><b data-rc="' + key + ':vcf" data-testid="sr-c-' + g + '-' + i + '-vcf">' + (t.vcf == null ? (real ? '—' : '') : t.vcf.toFixed(4)) + '</b></td>' +
      '<td class="xo x-gsv"><b data-rc="' + key + ':gsv" data-testid="sr-c-' + g + '-' + i + '-gsv">' + (t.gsv == null ? (real ? '—' : '') : f3c(t.gsv)) + '</b></td>' +
      '<td class="xo mt"><b data-rc="' + key + ':mt" data-testid="sr-c-' + g + '-' + i + '-mt">' + f3c(t.mt) + '</b></td>' +
      '<td class="xd">' + (S.canEdit ? '<button type="button" tabindex="-1" data-del="' + key + '"' + (real ? '' : ' data-spare') + ' title="Delete row" aria-label="Delete row" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-regular fa-trash-can"></i></button>' : '') + '</td></tr>';
  }
  /** Link page on a phone: each tank a card that opens and closes (readings in labelled boxes, MT in its header). */
  function cardMode() { return !!(P.guest || P.std) && !S.wide; }
  var TC_FIELDS = [['ref_height', 'Ref. Height (m)', 'fa-ruler', 0], ['sounding', 'Sounding (m)', 'fa-water', 0], ['volume', 'Volume (m³)', 'fa-cubes', 1],
    ['density', 'Density @15°C', 'fa-flask', 1], ['temp', 'Temperature (°C)', 'fa-temperature-half', 1]];
  function tcOpen(g, r, i) {
    S.tcOpen = S.tcOpen || {};
    if (r.id in S.tcOpen) return S.tcOpen[r.id];
    if (!(('_' + g) in S.tcOpen)) { // at first: the first tank still to complete is open
      var first = -1;
      S.data.tanks[g].some(function (x, k) { if (Object.keys(tankErrors(x)).length) { first = k; return true; } return false; });
      S.tcOpen['_' + g] = first;
    }
    return S.tcOpen['_' + g] === i;
  }
  function cardHtml(g, r, i) {
    var key = g + ':' + i, t = calc(r), open = tcOpen(g, r, i), short = gradeInfo(g).short, dis = S.canEdit ? '' : ' disabled';
    var box = function (f) {
      var v = shown(r, f[0]);
      return '<label class="sr-f sr-cellf sr-tc-f' + (f[0] === 'temp' ? ' w' : '') + '" data-tw="' + key + ':' + f[0] + '"><i class="fa-solid ' + f[2] + '"></i><span class="l">' + f[1] + '</span>' +
        '<input data-t="' + key + ':' + f[0] + '" data-testid="sr-t-' + g + '-' + i + '-' + f[0] + '" value="' + esc(v) + '"' + (f[3] ? ' inputmode="decimal"' : '') + dis + ' autocomplete="off" spellcheck="false" placeholder=" "' + (/^GAUGE$/i.test(v) ? ' class="gauge"' : '') + '><em data-terr="' + key + ':' + f[0] + '"></em></label>';
    };
    return '<div class="sr-tc sr-row' + (open ? ' open' : '') + '" data-tank="' + key + '" data-testid="sr-tank-' + g + '-' + i + '">' +
      '<div class="sr-tc-h" data-tc="' + key + '" role="button" aria-expanded="' + open + '" data-testid="sr-tc-toggle-' + g + '-' + i + '"><i class="fa-solid fa-chevron-right sr-tc-c"></i><i class="fa-solid fa-database sr-tc-i"></i>' +
        '<b class="sr-tc-n"><span data-tc-name="' + key + '">' + esc(r.name || 'New tank') + '</span> <small>(' + esc(short) + ')</small></b>' +
        '<span class="sr-tc-mt">MT: <b data-rc="' + key + ':mt">' + (t.mt == null ? '—' : f3c(t.mt)) + '</b></span></div>' +
      '<div class="sr-tc-b"><label class="sr-f sr-cellf sr-tc-f w" data-tw="' + key + ':name"><i class="fa-solid fa-tag"></i><span class="l">Tank name</span><input data-t="' + key + ':name" data-testid="sr-t-' + g + '-' + i + '-name" value="' + esc(r.name) + '"' + dis + ' autocomplete="off" placeholder=" "><em data-terr="' + key + ':name"></em></label>' +
        TC_FIELDS.map(box).join('') +
        '<span hidden data-rc="' + key + ':vcf"></span><span hidden data-rc="' + key + ':gsv"></span>' +
        (S.canEdit ? '<div class="sr-tc-a"><button type="button" class="sr-btn sm danger" data-del="' + key + '" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-regular fa-trash-can"></i> Delete tank</button></div>' : '') +
      '</div></div>';
  }
  function cardsHtml() {
    return chosen().map(function (g) {
      var gi = gradeInfo(g), rows = S.data.tanks[g], tot = gradeTotals(g);
      var shut = !!(S.tgShut && S.tgShut[g]);
      return '<section class="sr-tcg' + (shut ? ' shut' : '') + '" data-testid="sr-tanks-' + g + '"><div class="sr-tcg-h" data-tgfold="' + g + '" role="button" aria-expanded="' + !shut + '" data-testid="sr-tcg-toggle-' + g + '"><i class="fa-solid fa-chevron-down sr-tcg-c"></i><i class="fa-solid fa-gas-pump"></i><b>' + esc(gi.short) + ' Tanks</b>' +
        '<span>Total MT: <b data-gmt="' + g + '">' + f3c(tot.mt) + '</b></span></div><div class="sr-tcg-b">' +
        (rows.map(function (r, i) { return cardHtml(g, r, i); }).join('') || '<div class="sr-tc-none">No tanks yet.</div>') +
        (S.canEdit ? '<button type="button" class="sr-tc-add" data-add="' + g + '" data-testid="sr-add-' + g + '"><i class="fa-solid fa-plus"></i> Add ' + esc(gi.short) + ' tank</button>' : '') +
        '</div><b hidden data-testid="sr-total-' + g + '">' + f3(tot.mt) + '</b><b hidden data-testid="sr-total-gsv-' + g + '">' + f3(tot.gsv) + '</b></section>';
    }).join('');
  }
  function gridHtml() {
    if (cardMode()) return cardsHtml();
    var head = '<colgroup><col class="cw-name">' + XCOLS.slice(1).map(function () { return '<col>'; }).join('') + '<col class="cw-vcf"><col class="cw-gsv"><col class="cw-mt"><col class="cw-del"></colgroup>' +
      '<thead><tr>' + XCOLS.map(function (c) { return '<th class="x-' + c[0] + '">' + c[1] + ' <i class="req">*</i>' + (c[2] ? '<small>' + c[2] + '</small>' : '') + '</th>'; }).join('') +
      XCALC.map(function (c) { return '<th class="xo x-' + c[0] + '">' + c[1] + (c[2] ? '<small>' + c[2] + '</small>' : '') + '</th>'; }).join('') + '<th class="xd"></th></tr></thead>';
    var list = chosen().slice().sort(function (a, b) { return (XORDER.indexOf(a) + 1 || 99) - (XORDER.indexOf(b) + 1 || 99); });
    return list.map(function (g, gi_) {
      var gi = gradeInfo(g), rows = S.data.tanks[g], tot = gradeTotals(g), spare = xSpare(g), body = '';
      rows.forEach(function (r, i) { body += rowHtml(g, r, i); });
      for (var k = 0; k < spare; k++) body += rowHtml(g, null, rows.length + k);
      if (!rows.length && !spare) body = '<tr><td colspan="10" class="xe">No tanks yet — press Add Tank.</td></tr>';
      return '<section class="sr-xsec" data-testid="sr-tanks-' + g + '"><h4>' + esc(gi.short) + ' <small>' + esc(gi.name) + '</small></h4>' +
        '<div class="sr-xwrap"><table class="sr-xg" data-g="' + g + '" data-testid="' + (gi_ === 0 ? 'sr-tgrid' : 'sr-tgrid-' + g) + '">' + head + '<tbody>' + body + '</tbody></table></div>' +
        (S.canEdit ? '<div class="sr-xadd"><button type="button" data-more="' + g + '" data-testid="sr-add-' + g + '"><i class="fa-solid fa-plus"></i> Add Tank</button></div>' : '') +
        '<div class="sr-xtot"><span>TOTAL Metric Tons</span><b data-gmt="' + g + '">' + f3c(tot.mt) + '</b></div>' +
        '<b hidden data-testid="sr-total-' + g + '">' + f3(tot.mt) + '</b><b hidden data-testid="sr-total-gsv-' + g + '">' + f3(tot.gsv) + '</b></section>';
    }).join('');
  }
  function listHtml() {
    var q = (S.q || '').toUpperCase(), n = 0;
    return '<div class="sr-msearch"><i class="fa-solid fa-magnifying-glass"></i><input type="search" data-q value="' + esc(S.q || '') + '" placeholder="Search tank (e.g. NO 1 PORT)" data-testid="sr-q"></div>' +
      chosen().map(function (g) {
        var gi = gradeInfo(g), rows = S.data.tanks[g], tot = gradeTotals(g);
        return '<div class="sr-mgrp" data-testid="sr-tanks-' + g + '"><div class="sr-mgrp-h"><b>' + esc(gi.short) + '</b><span>' + rows.length + ' · <b data-testid="sr-total-' + g + '">' + f3(tot.mt) + '</b> MT</span><b hidden data-testid="sr-total-gsv-' + g + '">' + f3(tot.gsv) + '</b></div>' +
          rows.map(function (r, i) {
            n++;
            if (q && String(r.name).toUpperCase().indexOf(q) < 0) return '';
            var t = calc(r), st = rowState(r);
            return '<button type="button" class="sr-mrow ' + st + '" data-open="' + g + ':' + i + '" data-testid="sr-tank-' + g + '-' + i + '"><span class="no">' + n + '</span><span class="nm"><b>' + esc(r.name || 'New tank') + '</b><span class="bd"><i>' + esc(gi.short) + '</i>' +
              (st === 'done' ? '<i class="ok"><i class="fa-solid fa-check"></i> Saved</i>' : st === 'copy' ? '<i class="cp">Copy</i>' : '<i class="op">Not saved</i>') + '</span></span><span class="mt">' + (t.mt == null ? '—' : f3c(t.mt) + ' MT') + '</span><i class="fa-solid fa-chevron-right"></i></button>';
          }).join('') + '</div>';
      }).join('') +
      (S.canEdit ? '<button type="button" class="sr-madd" data-add="' + chosen()[0] + '" data-testid="sr-add-' + chosen()[0] + '"><i class="fa-solid fa-plus"></i> Add Tank</button>' : '');
  }
  function sheetHtml() {
    var p = S.sheet.split(':'), g = p[0], i = +p[1], r = S.data.tanks[g] && S.data.tanks[g][i];
    if (!r) { S.sheet = null; return ''; }
    var key = g + ':' + i, t = calc(r), dis = S.canEdit ? '' : ' disabled';
    var f = function (col, lab, mode) { return '<label class="sr-f" data-tw="' + key + ':' + col + '"><span>' + lab + '</span><input data-t="' + key + ':' + col + '" data-testid="sr-t-' + g + '-' + i + '-' + col + '" value="' + esc(shown(r, col)) + '"' + (mode ? ' inputmode="decimal"' : '') + dis + ' autocomplete="off"><em data-terr="' + key + ':' + col + '"></em></label>'; };
    return '<div class="sr-sheet-bg" data-sheet-close></div><div class="sr-sheet" role="dialog" data-tank="' + key + '" data-testid="sr-sheet"><div class="sr-sheet-grab"></div>' +
      '<div class="sr-sheet-h"><b>Edit Tank – ' + esc(r.name || 'New tank') + '</b><button type="button" class="sr-ic" data-sheet-close data-testid="sr-sheet-close" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>' +
      (r.copy ? '<div class="sr-copy-b" data-testid="sr-copy-badge-' + g + '-' + i + '"><i class="fa-regular fa-copy"></i> Copy — change the values, then Save Tank</div>' : '') +
      '<div class="sr-sheet-f">' + f('name', 'Tank Name *') + '<label class="sr-f"><span>Grade *</span>' + gradeSelect(g, i) + '</label>' +
      f('ref_height', 'Ref. Height (m)', true) + f('sounding', 'Sounding (m)', true) + f('ullage', 'Ullage (m)', true) + f('temp', 'Temperature (°C)', true) + f('volume', 'Volume (m³)', true) + f('density', 'Density (kg/l)', true) + '</div>' +
      '<div class="sr-res" data-res="' + key + '">' + resHtml(t) + '</div>' +
      (S.canEdit ? '<div class="sr-sheet-a"><button type="button" class="sr-btn danger" data-del="' + key + '" data-testid="sr-del-' + g + '-' + i + '"><i class="fa-solid fa-trash"></i> Delete</button>' +
        '<button type="button" class="sr-btn" data-copy="' + key + '" data-testid="sr-copy-' + g + '-' + i + '"><i class="fa-regular fa-copy"></i> Copy</button>' +
        '<button type="button" class="sr-btn pri" data-save-tank="' + key + '" data-testid="sr-save-tank-' + g + '-' + i + '"><i class="fa-solid fa-check"></i> Save Tank</button></div>' : '') + '</div>';
  }
  /** After a typed reading: the row's results, the other of sounding / ullage, the totals — without redrawing the inputs. */
  function updateRow(g, i) {
    var r = S.data.tanks[g][i], key = g + ':' + i, t = calc(r);
    var set = function (k, v) { var e = $('[data-rc="' + key + ':' + k + '"]'); if (e) e.textContent = v; };
    set('vcf', t.vcf == null ? '—' : t.vcf.toFixed(4)); set('gsv', t.gsv == null ? '—' : f3c(t.gsv)); set('mt', f3c(t.mt));
    var res = $('[data-res="' + key + '"]'); if (res) res.innerHTML = resHtml(t);
    var other = r.mode === 'ullage' ? ['sounding', sounding(r)] : ['ullage', ullageOf(r)];
    var oi = $('[data-t="' + key + ':' + other[0] + '"]'); if (oi && document.activeElement !== oi) oi.value = other[1];
    var tot = gradeTotals(g), a = $('[data-testid=sr-total-' + g + ']'), b = $('[data-testid=sr-total-gsv-' + g + ']');
    if (a) a.textContent = f3(tot.mt); if (b) b.textContent = f3(tot.gsv);
    ['[data-gmt="', '[data-gmt2="'].forEach(function (s0) { var e0 = $(s0 + g + '"]'); if (e0) e0.textContent = f3c(tot.mt); });
    ['[data-ggsv="', '[data-ggsv2="'].forEach(function (s0) { var e0 = $(s0 + g + '"]'); if (e0) e0.textContent = f3c(tot.gsv); });
    var all = allTotals(), x;
    if ((x = $('[data-testid=sr-tot-tanks]'))) x.textContent = all.n;
    if ((x = $('[data-testid=sr-tot-gsv]'))) x.textContent = f3c(all.gsv) + ' m³';
    if ((x = $('[data-testid=sr-tot-mt]'))) x.textContent = f3c(all.mt) + ' MT';
    if (window.SRXL && window.SRXL.updated) window.SRXL.updated(g, i, t);
  }
  function trackOf(r) { return [sounding(r), r.volume, r.density, r.temp].map(function (v) { var n = num(v); return n === null ? String(v || '') : String(n); }).join('|'); }
  /** Leaving a row (grid) or closing the sheet after typing: a complete tank is saved (its version is written). */
  function finishRow(g, i) {
    var r = S.data.tanks[g] && S.data.tanks[g][i]; if (!r || !r._editing) return;
    delete r._editing;
    if (blankRow(r)) { r.done = ''; return; }
    if (Object.keys(tankErrors(r)).length) { r._tried = true; showErrors(); markRow(g, i); return; }
    if (r.copy && r._src && trackOf(r) === r._src) { markRow(g, i); return; } // an unchanged copy waits for new values
    fmtRow(r); r.done = '1'; r.copy = ''; delete r._src; delete r._tried;
    changed(true); markRow(g, i);
  }
  function markRow(g, i) {
    var r = S.data.tanks[g][i], tr = $('.sr-row[data-tank="' + g + ':' + i + '"]');
    if (!tr) return;
    var html = tr.classList.contains('sr-tc') ? cardHtml(g, r, i) : tr.classList.contains('xl-row') && window.SRXL ? window.SRXL.row(g, r, i) : rowHtml(g, r, i), tmp = document.createElement(tr.tagName === 'TR' ? 'tbody' : 'div'); tmp.innerHTML = html;
    var nw = tmp.firstChild; tr.parentNode.replaceChild(nw, tr); showErrors();
  }
  function resHtml(t) {
    var c = function (l, v, cls) { return '<div class="' + (cls || '') + '"><span>' + l + '</span><b>' + (v === '' || v == null ? '—' : v) + '</b></div>'; };
    return c('V.C.F.', t.vcf == null ? '' : t.vcf.toFixed(4)) + c('W.C.F.', t.wcf == null ? '' : t.wcf.toFixed(4)) + c('G.S.V. m³ @15°C', f3(t.gsv)) + c('MT in Air', f3(t.mt), 'mt');
  }
  // summary
  function summaryRows() {
    return chosen().map(function (g) {
      var tot = gradeTotals(g), log = num(val('brob_started_' + g)), sv = S.server && S.server.grades && S.server.grades[g];
      var done = sv ? sv.completed : tot.ok ? tot.mt : null;
      return { g: g, short: gradeInfo(g).short, completed: done, logbook: log, diff: done !== null && log !== null ? ReportCalc.round(done - log, 3) : null };
    }).filter(function (x) { return S.data.tanks[x.g].length; });
  }
  function problemsHtml() {
    var all = issues(), byStep = {};
    if (!all.length) return '';
    all.forEach(function (x) { (byStep[x.step] = byStep[x.step] || []).push(x); });
    var names = {}; steps().forEach(function (s) { names[s.id] = s.title; }); names.grades = 'Survey Details';
    return '<section class="sr-card sr-probs" data-testid="sr-problems"><h3><i class="fa-solid fa-triangle-exclamation"></i> Before making the report, complete:</h3>' + Object.keys(byStep).map(function (s) {
      var target = names[s] ? s : 'survey';
      return '<div class="sr-prob" data-testid="sr-prob-' + s + '"><div><b>' + esc(names[s] || s) + '</b><ul>' + byStep[s].slice(0, 6).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') +
        (byStep[s].length > 6 ? '<li>… and ' + (byStep[s].length - 6) + ' more</li>' : '') + '</ul></div><button type="button" class="sr-btn sm" data-goto="' + target + '">Go to step</button></div>';
    }).join('') + '</section>';
  }
  /** Step 4: the ROB comparison (checked on the server) and the totals per grade. */
  function calcHtml() {
    var rows = summaryRows(), tot = 0, any = false;
    rows.forEach(function (r) { if (r.diff !== null) { tot += r.diff; any = true; } });
    tot = ReportCalc.round(tot, 3);
    var srv = S.verifying ? '<span class="sr-ver" data-testid="sr-verify"><i class="fa-solid fa-spinner fa-spin"></i> Checking on the server…</span>'
      : S.server ? '<span class="sr-ver ok" data-testid="sr-verify"><i class="fa-solid fa-shield-halved"></i> Calculated on the server from the template\'s formulas</span>' : '';
    var gt = chosen().map(function (g) { var t = gradeTotals(g); return '<tr><td><b>' + esc(gradeInfo(g).short) + '</b></td><td>' + S.data.tanks[g].length + '</td><td>' + f3c(t.gsv) + '</td><td><b>' + f3c(t.mt) + '</b></td></tr>'; }).join('');
    return '<section class="sr-card"><h3><i class="fa-solid fa-scale-balanced"></i> ROB comparison ' + srv +
      (rows.length ? '<button type="button" class="sr-share" data-share data-testid="sr-share"><i class="fa-brands fa-whatsapp"></i> Share</button>' : '') + '</h3>' +
      (rows.length ? '<div class="sr-cmp-wrap"><table class="sr-cmp" data-testid="sr-cmp"><thead><tr><th>Fuel Grade</th><th>Survey Completed</th><th>Log Book</th><th>Difference</th></tr></thead><tbody>' +
        rows.map(function (r) { return '<tr data-testid="sr-cmp-' + r.g + '"><td>' + esc(r.short) + '</td><td data-c="completed">' + f3(r.completed) + '</td><td data-c="logbook">' + f3(r.logbook) + '</td><td data-c="diff" class="' + (r.diff < 0 ? 'neg' : r.diff > 0 ? 'pos' : '') + '">' + sgn(r.diff) + '</td></tr>'; }).join('') +
        '</tbody></table></div><div class="sr-totdiff" data-testid="sr-total-diff"><span>TOTAL DIFFERENCE</span><b class="' + (tot < 0 ? 'neg' : tot > 0 ? 'pos' : '') + '">' + (any ? sgn(tot) + ' MT' : '—') + '</b></div>' +
        '<p class="sr-muted sr-small">Survey Completed = sum of MT in Air of the tanks of each grade. Log Book = ROB at survey start (engine-room log book). Difference = Survey Completed − Log Book.</p>'
        : '<p class="sr-muted">No fuel grade with tanks yet.</p>') + '</section>' +
      '<section class="sr-card"><h3><i class="fa-solid fa-calculator"></i> Totals per grade</h3><div class="sr-cmp-wrap"><table class="sr-cmp" data-testid="sr-gtotals"><thead><tr><th>Grade</th><th>Tanks</th><th>G.S.V. (m³ @15°C)</th><th>MT (Air)</th></tr></thead><tbody>' + gt + '</tbody></table></div></section>' +
      problemsHtml();
  }
  /** Step 5: the reports made (PDF), the Excel kept for the Word report generator, the Admin's tank history. */
  function docsHtml() {
    var pdfs = S.files.filter(function (f) { return f.type === 'pdf' || S.isAdmin; });
    return '<section class="sr-card"><h3><i class="fa-solid fa-folder-open"></i> Reports made</h3><div data-files data-testid="sr-files">' + (pdfs.length ? filesHtml(pdfs) : '<span class="sr-muted">No report made yet — make it in Preview &amp; Generate.</span>') + '</div></section>' +
      '<section class="sr-card"><h3><i class="fa-solid fa-file-word"></i> Word report</h3><p class="sr-small" data-testid="sr-excel-note"><i class="fa-solid fa-circle-info"></i> The Excel of each report made is kept in the system and is picked automatically in the Word report generator for this vessel.</p>' +
      (S.report.survey_id ? '<a class="sr-btn" href="report_generator.php?id=' + S.report.survey_id + '" data-testid="sr-word-link"><i class="fa-solid fa-file-word"></i> Open Word report generator</a>' : '') + '</section>' +
      tankLogHtml();
  }
  /** Step 6: preview and make the report. */
  function generateHtml() {
    var all = issues();
    return problemsHtml() +
      '<section class="sr-card"><h3><i class="fa-solid fa-file-export"></i> Preview &amp; Generate</h3><div class="sr-out" data-testid="sr-output">' +
      '<button type="button" class="sr-btn pri big" data-gen="pdf" data-gate' + (all.length ? ' data-blocked' : '') + ' data-testid="sr-download-pdf"><i class="fa-solid fa-file-pdf"></i> Download PDF Report</button>' +
      '<button type="button" class="sr-btn big" data-pv="load" data-testid="sr-preview-summary"><i class="fa-solid fa-eye"></i> Preview Report</button>' +
      '<button type="button" class="sr-btn big" data-print data-gate' + (all.length ? ' data-blocked' : '') + ' data-testid="sr-print"><i class="fa-solid fa-print"></i> Print</button></div>' +
      '<p class="sr-small sr-muted"><i class="fa-solid fa-circle-info"></i> The PDF is made in the YMR report format on the company letterhead' + (certType() !== 'none' && S.cfg.cert ? ': page 1 the <b data-testid="sr-cert-name">' + esc(S.cfg.cert.types[certType()].title) + '</b> (' + esc(S.cfg.cert.choices[certType()]) + '), then the tank report' : '') + '; its Excel is kept in the system for the Word report.</p>' +
      '<p class="sr-small sr-off-note" data-off-note data-testid="sr-off-note"' + (online() ? ' hidden' : '') + '><i class="fa-solid fa-plane"></i> Offline: the PDF is made on this device. When the connection is back the report is saved and the final PDF on the YMR letterhead is made automatically.</p>' +
      '<button type="button" class="sr-link" data-offline-pdf data-testid="sr-offline-pdf"' + (all.length ? ' hidden' : '') + '><i class="fa-solid fa-plane"></i> No internet? Download the PDF made on this device</button></section>';
  }
  /** Admin: the version history of the saved tanks (Sounding, Volume, Density, Temperature). */
  var HCOLS = [['sounding', 'Sounding'], ['volume', 'Volume'], ['density', 'Density'], ['temperature', 'Temp']];
  function tankLogHtml() {
    if (!S.isAdmin) return '';
    if (S.tankLog === null) { loadTankLog(); return '<section class="sr-card" data-testid="sr-tank-log"><h3><i class="fa-solid fa-clock-rotate-left"></i> Tank history</h3><span class="sr-muted">Loading…</span></section>'; }
    // only the tanks changed after their first save (a tank still at v1 is not listed), with every version
    var T = S.tankLog.filter(function (t) { return t.versions.length > 1; }), edits = 0;
    T.forEach(function (t) { edits += t.versions.length - 1; });
    return '<section class="sr-card" data-testid="sr-tank-log"><h3><i class="fa-solid fa-clock-rotate-left"></i> Tank history <small class="sr-muted">Admin only · ' + edits + ' change' + (edits === 1 ? '' : 's') + ' after the first save</small></h3>' +
      (T.length ? '<div class="sr-cmp-wrap"><table class="sr-t sr-log"><thead><tr><th>Tank</th><th>Version</th>' + HCOLS.map(function (c) { return '<th>' + c[1] + '</th>'; }).join('') + '<th>By</th><th>When</th></tr></thead><tbody>' + T.map(function (t) {
        return t.versions.slice().reverse().map(function (v, k) {
          return '<tr data-testid="sr-hist-' + esc(t.tank_id) + '-' + v.version + '">' + (k ? '' : '<td rowspan="' + t.versions.length + '"><b>' + esc(t.name) + '</b> <small class="sr-muted">' + esc(t.grade_label) + '</small>' + (t.in_report ? '' : ' <span class="sr-del">removed</span>') + '</td>') +
            '<td>v' + v.version + (v.version === t.current_version ? ' <span class="sr-cur">current</span>' : '') + '</td>' +
            HCOLS.map(function (c) { return '<td' + (v.changed.indexOf(c[0]) >= 0 ? ' class="chg"' : '') + '>' + esc(v.values[c[0]] == null ? '—' : v.values[c[0]]) + '</td>'; }).join('') +
            '<td>' + esc(v.by || '') + '</td><td>' + esc(String(v.at).slice(0, 16)) + '</td></tr>';
        }).join('');
      }).join('') + '</tbody></table></div>' : '<span class="sr-muted" data-testid="sr-hist-none">No tank has been changed after its first save.</span>') + '</section>';
  }
  function loadTankLog() {
    if (S.tankLogLoading) return; S.tankLogLoading = true;
    fetch(P.api + '?a=tank_history&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      S.tankLogLoading = false; S.tankLog = res.ok ? res.tanks : [];
      if (S.data.step === 'docs') render();
    }).catch(function () { S.tankLogLoading = false; });
  }
  function filesHtml(list) {
    list = list || S.files;
    if (!list.length) return '<span class="sr-muted">No files made yet.</span>';
    return '<table class="sr-t"><thead><tr><th>File</th><th>Made</th><th></th></tr></thead><tbody>' + list.map(function (f) {
      var url = fileUrl(f.id);
      return '<tr data-testid="sr-file-' + f.id + '"><td><i class="fa-solid ' + (f.type === 'pdf' ? 'fa-file-pdf" style="color:#dc2626' : 'fa-file-excel" style="color:#16a34a') + '"></i> ' + esc(f.file_name) + '</td>' +
        '<td>' + esc(String(f.created_at).slice(0, 16)) + '<br><small class="sr-muted">' + esc(f.created_by_name || '') + '</small></td>' +
        '<td style="white-space:nowrap"><a href="' + url + '">Download</a>' + (f.type === 'pdf' ? ' · <a href="' + url + '&inline=1" target="_blank" rel="noopener">View</a>' : '') + '</td></tr>';
    }).join('') + '</tbody></table>';
  }

  // ---------- error marks ----------
  function showErrors() {
    $$('.sr-f.bad').forEach(function (e) { e.classList.remove('bad'); });
    $$('[data-err],[data-terr]').forEach(function (e) { e.textContent = ''; });
    var id = S.data.step, list = issues(id), show = S.showErr[id] || id === 'vessel' && (S.lookup || S.lookupMsg || S.manual);
    if (id === 'vessel' && !S.showErr[id]) list = list.filter(function (x) { return x.key !== 'f:imo' || val('imo') !== ''; });
    Object.keys(S.errors || {}).forEach(function (k) { if (k.indexOf('f:') === 0) list.push({ key: k, msg: S.errors[k], server: true }); });
    list.forEach(function (x) {
      if (!show && !x.server && !/: /.test(x.msg)) return; // "missing" marks wait for Next (wrong values show at once)
      if (x.key.indexOf('f:') === 0 || x.key === 'grades') {
        var k = x.key === 'grades' ? 'grades' : x.key.slice(2), w = $('[data-fw="' + k + '"]');
        if (w && !w.classList.contains('bad')) { w.classList.add('bad'); var em = $('[data-err="' + k + '"]', w); if (em) em.textContent = x.msg.replace(label(k) + ': ', '').replace(label(k) + ' is missing.', 'Required.'); }
      } else if (x.key.indexOf('t:') === 0) {
        var p = x.key.split(':'), tk = p[1] + ':' + p[2] + ':' + p[3], tw = $('[data-tw="' + tk + '"]');
        var r = S.data.tanks[p[1]][+p[2]];
        if (tw && (S.showErr[id] || (r && r._tried))) { tw.classList.add('bad'); $('[data-terr="' + tk + '"]').textContent = x.msg.replace(/^.*?: /, ''); }
      }
    });
  }
  function block(id) { // Next / Save tank on an incomplete step: mark, scroll to the first, say what is missing
    S.showErr[id] = true; showErrors();
    var list = issues(id);
    var first = $('.sr-step .sr-f.bad, .sr-step [data-fw=grades].bad');
    if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); var i = $('input,select', first); if (i) setTimeout(function () { i.focus({ preventScroll: true }); }, 250); }
    Swal.fire({ icon: 'warning', title: 'Please complete this step', html: '<ul class="sr-swal-list">' + list.slice(0, 8).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') + (list.length > 8 ? '<li>… and ' + (list.length - 8) + ' more</li>' : '') + '</ul>', confirmButtonText: 'OK' });
  }

  // ---------- events ----------
  document.addEventListener('click', function (ev) {
    var t = ev.target, b;
    if ((b = t.closest('[data-goto]'))) { if (!b.disabled) go(b.getAttribute('data-goto')); return; }
    if (t.closest('[data-next]')) {
      var l = steps(), i = stepIndex(S.data.step), id = l[i].id;
      var marked = false;
      if (id === 'tanks') chosen().forEach(function (g) { S.data.tanks[g].forEach(function (r) { if (!r.done && !r.copy && !Object.keys(tankErrors(r)).length) { fmtRow(r); r.done = '1'; delete r._editing; marked = true; } }); }); // a copy waits for its own save
      if (marked) changed(true);
      if (issues(id).length || (id === 'survey' && !chosen().length)) { if (id === 'tanks') render(); block(id); return; }
      go(l[i + 1].id); return;
    }
    if (t.closest('[data-back]')) { go(steps()[Math.max(0, stepIndex(S.data.step) - 1)].id); return; }
    if (t.closest('[data-lookup]')) { lookup(); return; }
    var nowB = t.closest('[data-now]');
    if (nowB && S.canEdit) {
      var e = nowB.getAttribute('data-now'), d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
      setVal(e === 'fwe' ? 'fwe_date' : e + '_date', d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()));
      setVal(e === 'fwe' ? 'fwe_time' : e + '_time', z(d.getHours()) + ':' + z(d.getMinutes()));
      changed(); render(); return;
    }
    if (t.closest('[data-prev]')) { fillPrevious(); return; }
    if (t.closest('[data-share]')) { shareCard(); return; }
    if (t.closest('[data-undo]')) { undoLookup(); return; }
    if (t.closest('[data-manual]')) { S.manual = true; render(); var f = $('[data-f=vessel_name]'); if (f) f.focus(); return; }
    if ((b = t.closest('[data-more]'))) { moreRows(b.getAttribute('data-more')); return; }
    if ((b = t.closest('[data-tgfold]'))) { // a grade row on a phone: its tanks fold away (grade name left, Total MT right)
      var tg = b.getAttribute('data-tgfold'), tsec = b.closest('.sr-tcg'); S.tgShut = S.tgShut || {};
      S.tgShut[tg] = !tsec.classList.contains('shut'); tsec.classList.toggle('shut', S.tgShut[tg]); b.setAttribute('aria-expanded', S.tgShut[tg] ? 'false' : 'true'); return;
    }
    if ((b = t.closest('[data-tc]'))) {
      var card = b.closest('.sr-tc'), rr = S.data.tanks[b.getAttribute('data-tc').split(':')[0]][+b.getAttribute('data-tc').split(':')[1]];
      S.tcOpen = S.tcOpen || {}; var op = !card.classList.contains('open'); if (rr) S.tcOpen[rr.id] = op;
      card.classList.toggle('open', op); b.setAttribute('aria-expanded', op); return;
    }
    if ((b = t.closest('[data-gfold]'))) { if (!S.wide) { var gf = b.getAttribute('data-gfold'), sec = b.closest('.sr-gsec'); S.gOpen = S.gOpen || {}; S.gOpen[gf] = !sec.classList.contains('open'); sec.classList.toggle('open', S.gOpen[gf]); b.setAttribute('aria-expanded', S.gOpen[gf] ? 'true' : 'false'); } return; }
    if ((b = t.closest('[data-add]'))) { addTank(b.getAttribute('data-add')); return; }
    if ((b = t.closest('[data-open]'))) { openSheet(b.getAttribute('data-open')); return; }
    if (t.closest('[data-sheet-close]')) { closeSheet(); return; }
    if (t.closest('[data-import]')) { var fi = $('[data-import-file]'); if (fi) { fi.value = ''; fi.click(); } return; }
    if (t.closest('[data-prev-tanks]')) { fillPrevious(true); return; }
    if (t.closest('[data-cols]')) { S.colsOpen = !S.colsOpen; render(); return; }
    if (S.colsOpen && !t.closest('.sr-cols')) { S.colsOpen = false; var cm = $('[data-testid=sr-cols-menu]'); if (cm) cm.remove(); }
    if ((b = t.closest('[data-save-tank]'))) { saveTank(b.getAttribute('data-save-tank')); return; }
    if ((b = t.closest('[data-copy]'))) { copyTank(b.getAttribute('data-copy')); return; }
    if ((b = t.closest('[data-edit]'))) { var ek = b.getAttribute('data-edit'); var x = $('[data-t="' + ek + ':name"]'); if (x) { x.focus(); x.select(); } return; }
    if ((b = t.closest('[data-expand]'))) { var q = b.getAttribute('data-expand').split(':'), rr = S.data.tanks[q[0]][+q[1]]; S.expand[rr.id] = !S.expand[rr.id]; render(); return; }
    if ((b = t.closest('[data-su]'))) { setSU(b); return; }
    if ((b = t.closest('[data-mv]'))) { var mv = b.getAttribute('data-mv').split(':'); reorderTank(mv[0], +mv[1], +mv[1] + +mv[2], 'name'); return; }
    if ((b = t.closest('[data-del]'))) { delTank(b.getAttribute('data-del')); return; }
    if ((b = t.closest('[data-mode]'))) { var m = b.getAttribute('data-mode').split(':'), tr = S.data.tanks[m[0]][+m[1]]; tr.mode = m[2]; tr.sounding = m[2] === 'ullage' ? sounding(tr) : tr.sounding; changed(); render(); return; }
    if ((b = t.closest('[data-gen]'))) { if (!online()) { offlinePdf(); return; } generate(b.getAttribute('data-gen')); return; }
    if (t.closest('[data-offline-pdf]')) { offlinePdf(); return; }
    if ((b = t.closest('[data-print]'))) { if (b.hasAttribute('data-blocked')) { block('generate'); return; } printPdf(b); return; }
    if (t.closest('[data-save-now]')) { saveProgress(); return; }
    if ((b = t.closest('[data-pv]'))) { pvAction(b.getAttribute('data-pv')); return; }
  });
  document.addEventListener('change', function (ev) {
    var el = ev.target;
    if (el.matches('[data-grade]')) { toggleGrade(el.getAttribute('data-grade'), el.checked); return; }
    if (el.matches('[data-tg]')) { moveTank(el.getAttribute('data-tg'), el.value); return; }
    if (el.matches('[data-colt]')) { var hc = hiddenCols().filter(function (c) { return c !== el.getAttribute('data-colt'); }); if (!el.checked) hc.push(el.getAttribute('data-colt')); store('ysms_sr_cols', hc); render(); return; }
    if (el.matches('[data-import-file]')) { if (el.files && el.files[0]) importTanks(el.files[0]); return; }
    if (el.matches('select[data-f]')) { setVal(el.getAttribute('data-f'), el.value); changed(); refreshLight(); }
    if (el.matches('[data-t]')) { // leaving a reading: shown in the report's format
      var p = el.getAttribute('data-t').split(':'), r = S.data.tanks[p[0]] && S.data.tanks[p[0]][+p[1]];
      if (r && p[2] in TFMT) { var v = fmtT(p[2], el.value); if (v !== el.value) { el.value = v; r[p[2]] = v; changed(); } }
    }
  });
  document.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.matches('[data-f]')) {
      var k = el.getAttribute('data-f'); setVal(k, el.value);
      if (S.ai[k]) { delete S.ai[k]; el.classList.remove('ai'); el.title = ''; }
      delete S.errors['f:' + k];
      changed(); refreshLight();
      if (k === 'imo') autoLookup(el.value);
      return;
    }
    if (el.matches('[data-q]')) { S.q = el.value; render(); var qi = $('[data-q]'); if (qi) { qi.focus(); qi.setSelectionRange(qi.value.length, qi.value.length); } return; }
    if (el.matches('[data-t]')) {
      var p = el.getAttribute('data-t').split(':'), r = S.data.tanks[p[0]][+p[1]];
      if (!r) { r = spareToRow(el); p = el.getAttribute('data-t').split(':'); }
      if (r.done === '1') r.done = ''; // being edited: its version is written when it is saved again
      r._editing = true;
      if (p[2] === 'sounding') { r.mode = 'sounding'; r.sounding = el.value; r.ullage = ''; }
      else if (p[2] === 'ullage') { r.mode = 'ullage'; r.ullage = el.value; r.sounding = sounding(r); }
      else { r[p[2]] = el.value; if (p[2] === 'ref_height' && r.mode === 'ullage') r.sounding = sounding(r); }
      if (p[2] === 'ref_height' || p[2] === 'sounding') el.classList.toggle('gauge', /^GAUGE$/i.test(el.value.trim()));
      if (p[2] === 'sounding' || p[2] === 'ullage') { // S / U shown only while the reading is a number; GAUGE / empty: no S
        var nOk = num(el.value) !== null, std = el.closest('td'); if (std) std.classList.toggle('su-off', !nOk);
        if (!nOk && r.mode !== 'ullage' && r.su) { r.su = ''; var sb = std && $('[data-su$=":s"]', std); if (sb) { sb.classList.remove('on'); sb.setAttribute('aria-checked', 'false'); } }
      }
      if (p[2] === 'name') { var tn = $('[data-tc-name="' + p[0] + ':' + p[1] + '"]'); if (tn) tn.textContent = el.value || 'New tank'; }
      updateRow(p[0], +p[1]);
      changed(); showErrors();
    }
  });
  /** Typing in an empty row at the end of a grid: it becomes the next tank of the grade (its cells are renamed in place). */
  function spareToRow(el) {
    var tr = el.closest('tr'), tb = tr.parentNode, g = tb.closest('table').getAttribute('data-g');
    var row = emptyRow(); S.data.tanks[g].push(row);
    var i = S.data.tanks[g].length - 1;
    // this row takes the next number, the empty rows after the tanks follow it
    var re = function (t, n) {
      var old = t.getAttribute('data-tank'), key = g + ':' + n;
      t.setAttribute('data-tank', key);
      $$('[data-t],[data-tw],[data-terr],[data-rc]', t).forEach(function (e) { ['data-t', 'data-tw', 'data-terr', 'data-rc'].forEach(function (a) { var v = e.getAttribute(a); if (v && v.indexOf(old + ':') === 0) e.setAttribute(a, key + v.slice(old.length)); }); });
      $$('[data-t]', t).forEach(function (e) { e.setAttribute('data-testid', 'sr-t-' + g + '-' + n + '-' + e.getAttribute('data-t').split(':')[2]); });
    };
    re(tr, i); tr.className = 'sr-row'; tr.setAttribute('data-testid', 'sr-tank-' + g + '-' + i);
    var db = $('.xd button', tr); if (db) db.removeAttribute('data-spare');
    $$('[data-del]', tb).forEach(function (b) { var t = b.closest('tr'); b.setAttribute('data-del', t.getAttribute('data-tank')); b.setAttribute('data-testid', 'sr-del-' + t.getAttribute('data-tank').replace(':', '-')); });
    var n = i + 1; $$('tr.sr-spare', tb).forEach(function (t) { re(t, n); t.setAttribute('data-testid', 'sr-spare-' + g + '-' + n); n++; });
    $$('[data-del]', tb).forEach(function (b) { var t = b.closest('tr'); b.setAttribute('data-del', t.getAttribute('data-tank')); b.setAttribute('data-testid', 'sr-del-' + t.getAttribute('data-tank').replace(':', '-')); });
    S.spare[g] = Math.max(xSpare(g) - 1, 0); // the table keeps its number of rows, as in Excel
    // keep the tanks above the empty rows
    var firstSpare = $('tr.sr-spare', tb); if (firstSpare && firstSpare.compareDocumentPosition(tr) & Node.DOCUMENT_POSITION_FOLLOWING) { tb.insertBefore(tr, firstSpare); el.focus(); }
    return row;
  }
  /** S / U in the Sounding / Ullage cell: the reading typed is a sounding or an ullage (sounding = reference height − ullage).
      The choice prints next to the reading in the report ("0.012 S"); none chosen, nothing printed. A second click clears it. */
  function setSU(b) {
    var q = b.getAttribute('data-su').split(':'), g = q[0], i = +q[1], r = S.data.tanks[g][i];
    if (!r) { var inp = $('[data-t]', b.closest('tr')); if (!inp) return; r = spareToRow(inp); i = S.data.tanks[g].indexOf(r); }
    var su = r.su === q[2] ? '' : q[2], m = su === 'u' ? 'ullage' : 'sounding';
    if (r.su !== su) { r.su = su; if (r.done === '1') r.done = ''; r._editing = true; changed(); }
    if (r.mode !== m) {
      if (m === 'ullage') { r.ullage = ullageOf(r); r.mode = 'ullage'; r.sounding = sounding(r); }
      else { r.sounding = sounding(r); r.mode = 'sounding'; r.ullage = ''; }
      if (r.done === '1') r.done = '';
      r._editing = true; changed();
    }
    render(); var x = $('[data-t="' + g + ':' + i + ':' + m + '"]'); if (x) x.focus();
  }
  /** A tank to another place in its grade's list (the report keeps this order). */
  function reorderTank(g, from, to, col) {
    var a = S.data.tanks[g]; if (!a || !a[from] || to < 0 || to >= a.length || to === from) return false;
    var r = a.splice(from, 1)[0]; a.splice(to, 0, r); changed(true); render();
    var c = col === 'sounding' || col === 'ullage' ? (r.mode === 'ullage' ? 'ullage' : 'sounding') : col || 'name';
    var x = $('[data-t="' + g + ':' + to + ':' + c + '"]'); if (x) x.focus();
    return true;
  }
  function moreRows(g) {
    xSpare(g); S.spare[g]++; render();
    var all = $$('table[data-g="' + g + '"] tbody tr'), x = all.length && $('[data-t$=":name"]', all[all.length - 1]); if (x) { x.focus(); x.scrollIntoView({ block: 'center' }); }
  }
  /** Moving around the grid like Excel: Enter / ↓ next row, ↑ row above, ← → at the edge of the text the next cell. */
  function gridMove(el, dr, dc) {
    var td = el.closest('td'), tr = td.parentNode, tb = tr.parentNode, ci = $$('td.xi', tr).indexOf(td);
    var rows = $$('tr', tb).filter(function (t) { return $('td.xi', t); }), ri = rows.indexOf(tr), tr2 = rows[ri + dr];
    var cells = function (t) { return $$('td.xi input', t); };
    if (dc) { var c = cells(tr)[ci + dc]; if (c) { c.focus(); c.select(); return true; } return false; }
    if (!tr2) { // past the last row of this grade: the next grade's first row (up on the first row: the grade above's last row)
      var sib = dr < 0 ? 'previousElementSibling' : 'nextElementSibling';
      var sec = el.closest('.sr-xsec')[sib]; while (sec && !sec.classList.contains('sr-xsec')) sec = sec[sib];
      var rs = sec ? $$('tbody tr', sec).filter(function (t) { return $('td.xi', t); }) : [];
      tr2 = dr < 0 ? rs[rs.length - 1] : rs[0]; if (!tr2) return false;
    }
    var x = cells(tr2)[ci]; if (x) { x.focus(); x.select(); return true; }
    return false;
  }
  /** Rows copied from Excel (tab-separated): filled in from the cell in focus, down and to the right; extra tanks are added. */
  function gridPaste(el, text) {
    var lines = text.replace(/\r/g, '').split('\n'); while (lines.length && lines[lines.length - 1] === '') lines.pop();
    if (!lines.length || (lines.length === 1 && lines[0].indexOf('\t') < 0)) return false;
    var p = el.getAttribute('data-t').split(':'), g = p[0], i0 = +p[1], cols = XCOLS.map(function (c) { return c[0]; }), c0 = cols.indexOf(p[2] === 'ullage' ? 'sounding' : p[2]);
    if (c0 < 0) return false;
    var before = S.data.tanks[g].length;
    lines.forEach(function (ln, k) {
      var vals = ln.split('\t'), i = i0 + k;
      while (S.data.tanks[g].length <= i) S.data.tanks[g].push(emptyRow());
      var r = S.data.tanks[g][i];
      vals.forEach(function (v, j) {
        var col = cols[c0 + j]; if (!col) return;
        v = v.trim();
        if (col === 'sounding' && r.mode === 'ullage') { r.ullage = v; r.sounding = sounding(r); return; }
        if (col === 'sounding') { r.mode = 'sounding'; r.ullage = ''; }
        r[col] = v;
        if (col === 'ref_height' && r.mode === 'ullage') r.sounding = sounding(r);
      });
      r.done = ''; r._editing = false;
      if (!blankRow(r) && !Object.keys(tankErrors(r)).length) { fmtRow(r); r.done = '1'; }
    });
    xSpare(g); S.spare[g] = Math.max(S.spare[g] - (S.data.tanks[g].length - before), 0);
    changed(); render(); toast('success', lines.length + ' row' + (lines.length === 1 ? '' : 's') + ' pasted');
    return true;
  }
  // like an Excel cell: taking a cell (click, tap, Tab, arrows) selects its value, so typing replaces it; a double click edits inside it
  document.addEventListener('focusin', function (ev) { var el = ev.target; if (el.matches && el.matches('.sr-xg [data-t], .sr-tc [data-t]')) el.select(); });
  var drag = null;
  document.addEventListener('dragstart', function (ev) {
    var gp = ev.target.closest && ev.target.closest('[data-grip]'); if (!gp) return;
    var q = gp.getAttribute('data-grip').split(':'); drag = { g: q[0], i: +q[1] };
    ev.dataTransfer.effectAllowed = 'move'; try { ev.dataTransfer.setData('text/plain', ''); ev.dataTransfer.setDragImage(gp.closest('tr'), 12, 15); } catch (e) {}
    gp.closest('tr').classList.add('sr-dragging');
  });
  function dropRow(ev) {
    var tr = drag && ev.target.closest && ev.target.closest('tr.sr-row'); if (!tr) return null;
    var q = tr.getAttribute('data-tank').split(':'); if (q[0] !== drag.g) return null;
    var rc = tr.getBoundingClientRect(), after = ev.clientY > rc.top + rc.height / 2;
    return { tr: tr, i: +q[1], after: after };
  }
  document.addEventListener('dragover', function (ev) {
    var d = dropRow(ev); $$('.sr-drop-b,.sr-drop-a').forEach(function (e) { e.classList.remove('sr-drop-b', 'sr-drop-a'); });
    if (!d) return; ev.preventDefault(); ev.dataTransfer.dropEffect = 'move'; d.tr.classList.add(d.after ? 'sr-drop-a' : 'sr-drop-b');
  });
  document.addEventListener('drop', function (ev) {
    var d = dropRow(ev); if (!d) return; ev.preventDefault();
    var to = d.i + (d.after ? 1 : 0); if (to > drag.i) to--;
    var from = drag.i; drag = null; reorderTank(d.tr.getAttribute('data-tank').split(':')[0], from, to, 'name');
  });
  document.addEventListener('dragend', function () { drag = null; $$('.sr-dragging,.sr-drop-b,.sr-drop-a').forEach(function (e) { e.classList.remove('sr-dragging', 'sr-drop-b', 'sr-drop-a'); }); });
  document.addEventListener('focusout', function (ev) { var el = ev.target; if (el.matches && el.matches('.sr-xg [data-t]')) el.removeAttribute('data-xedit'); });
  document.addEventListener('dblclick', function (ev) { var el = ev.target; if (el.matches && el.matches('.sr-xg [data-t]')) el.setAttribute('data-xedit', '1'); });
  document.addEventListener('mousedown', function (ev) {
    var el = ev.target; if (!el.matches || !el.matches('.sr-xg [data-t]') || el.disabled || document.activeElement === el || ev.detail > 1) return;
    ev.preventDefault(); el.focus();
  });
  document.addEventListener('paste', function (ev) {
    var el = ev.target; if (!el.matches || !el.matches('.sr-xg [data-t]') || !S.canEdit) return;
    var text = (ev.clipboardData || window.clipboardData).getData('text');
    if (gridPaste(el, text)) ev.preventDefault();
  });
  // the grid: leaving a row saves a complete tank (its version), like "Save tank"
  document.addEventListener('focusout', function (ev) {
    var tr = ev.target.closest && ev.target.closest('.sr-row'); if (!tr) return;
    var key = tr.getAttribute('data-tank');
    setTimeout(function () {
      var a = document.activeElement, now = a && a.closest && a.closest('.sr-row');
      if (now && now.getAttribute('data-tank') === key) return;
      var p = key.split(':'); finishRow(p[0], +p[1]);
    }, 0);
  });
  var lastWide = window.matchMedia('(min-width: 992px)').matches; // the first resize across the breakpoint counts too
  window.addEventListener('resize', function () { var w = window.matchMedia('(min-width: 992px)').matches; if (S.data && lastWide !== null && w !== lastWide && S.data.step === 'tanks') render(); lastWide = w; });
  document.addEventListener('keydown', function (ev) { // Enter: lookup from the IMO box, otherwise the next box
    var gt = ev.target;
    if (gt.matches && gt.matches('.sr-xg [data-t]') && ev.altKey && (ev.key === 'ArrowUp' || ev.key === 'ArrowDown')) {
      ev.preventDefault(); var mq = gt.getAttribute('data-t').split(':'); reorderTank(mq[0], +mq[1], +mq[1] + (ev.key === 'ArrowUp' ? -1 : 1), mq[2]); return;
    }
    if (gt.matches && gt.matches('.sr-xg [data-t]') && !ev.altKey && !ev.ctrlKey && !ev.metaKey) {
      // like Excel: arrow keys go to the next cell; after a double click (or F2) left / right move inside the value until the cell is left
      var edit = gt.getAttribute('data-xedit') === '1';
      if (ev.key === 'F2') { ev.preventDefault(); gt.setAttribute('data-xedit', '1'); var n = gt.value.length; gt.setSelectionRange(n, n); return; }
      if (ev.key === 'Enter' || ev.key === 'ArrowDown') { ev.preventDefault(); gridMove(gt, ev.shiftKey && ev.key === 'Enter' ? -1 : 1, 0); return; }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); gridMove(gt, -1, 0); return; }
      if ((ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') && !edit && !ev.shiftKey) { ev.preventDefault(); gridMove(gt, 0, ev.key === 'ArrowLeft' ? -1 : 1); return; }
      return;
    }
    if (ev.key !== 'Enter' || !ev.target.matches('[data-f],[data-t]')) return;
    ev.preventDefault();
    if (ev.target.matches('[data-f=imo]') && $('[data-lookup]')) { lookup(); return; }
    var all = $$('.sr-step [data-f]:not([disabled]), .sr-step [data-t]:not([disabled])'), i = all.indexOf(ev.target);
    if (i < all.length - 1) all[i + 1].focus();
  });
  /** After a typed value: step marks, the Next button and the computed drafts, without redrawing the inputs. */
  function refreshLight() {
    var id = S.data.step;
    if (id === 'vessel') {
      var nx = $('[data-next]'); if (nx) nx.disabled = issues('vessel').length > 0;
      var miss = issues('vessel').filter(function (x) { return x.key !== 'f:imo'; }), box = $('[data-testid=sr-vessel-missing]');
      if (box && !miss.length) box.remove(); else if (box) box.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> ' + miss.length + ' particular' + (miss.length === 1 ? '' : 's') + ' still to enter: ' + esc(miss.map(function (x) { return label(x.key.slice(2)); }).join(', ')) + '. Type them in to continue.';
    }
    if (id === 'survey') {
      var a = num(val('draft_aft')), f = num(val('draft_fwd')), m = $('[data-testid=sr-mean]'), t = $('[data-testid=sr-trim]');
      if (m) m.textContent = a !== null && f !== null ? ((a + f) / 2).toFixed(3) : '';
      if (t) t.textContent = a !== null && f !== null ? (a - f).toFixed(3) : '';
    }
    showErrors();
    var l = steps(), cur = stepIndex(id);
    $$('.sr-steps li').forEach(function (li, j) {
      var s = l[j]; if (!s) return;
      var b = $('button', li); if (b) b.disabled = !(reachable(j) || j < cur);
      if (!li.classList.contains('on')) { li.classList.toggle('done', stepDone(s.id)); var n = $('.n', li); if (n) n.innerHTML = li.classList.contains('done') ? '<i class="fa-solid fa-check"></i>' : j + 1; }
    });
  }
  /** Earlier attendance of this vessel (a report on the website, or its uploaded Excel). */
  function loadPrevious() {
    if (!S.canEdit || !online()) return;
    fetch(P.api + '?a=previous&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      S.prev = res && res.ok ? res.previous : null;
      if (S.prev && (S.data.step === 'vessel' || S.data.step === 'tanks')) render();
    }).catch(function () {});
  }
  function fillPrevious(tanksOnly) {
    var P0 = S.prev; if (!P0) return;
    var gNames = (P0.grades || []).map(function (g) { var i = gradeInfo(g); return i ? i.short : g; });
    var tankCount = 0; Object.keys(P0.tanks || {}).forEach(function (g) { tankCount += P0.tanks[g].length; });
    var busy = Object.keys(P0.tanks || {}).filter(function (g) { return gradeInfo(g) && S.data.tanks[g] && S.data.tanks[g].length; });
    Swal.fire({ icon: 'question', title: 'Fill from previous attendance?', confirmButtonText: 'Fill', showCancelButton: true,
      html: '<div style="text-align:left;font-size:14px">From: <b>' + esc(P0.label) + '</b><ul style="margin:8px 0 0 18px;padding:0">' +
        (tanksOnly ? '' : '<li>Step 1: ' + Object.keys(P0.fields || {}).length + ' vessel particulars</li>' +
        '<li>Step 2: grades ' + esc(gNames.join(', ') || '—') + '</li>') +
        '<li>Step 3: ' + tankCount + ' tank names with reference heights (readings stay empty)</li></ul>' +
        (busy.length ? '<p class="sr-muted" style="margin-top:8px">Tanks already entered (' + esc(busy.map(function (g) { return gradeInfo(g).short; }).join(', ')) + ') are kept as they are.</p>' : '') + '</div>'
    }).then(function (x) {
      if (!x.isConfirmed) return;
      if (!tanksOnly) Object.keys(P0.fields || {}).forEach(function (k) { if (FIELD(k)) { setVal(k, P0.fields[k]); if (P0.source === 'excel') S.ai[k] = true; } });
      var gs = (P0.grades || []).filter(function (g) { return !!gradeInfo(g); });
      if (gs.length && !tanksOnly) S.data.grades = S.cfg.grades.map(function (g) { return g.key; }).filter(function (g) { return gs.indexOf(g) >= 0; });
      if (tanksOnly) gs.forEach(function (g) { if (S.data.grades.indexOf(g) < 0 && (P0.tanks[g] || []).length) S.data.grades.push(g); });
      Object.keys(P0.tanks || {}).forEach(function (g) {
        if (!gradeInfo(g) || (S.data.tanks[g] && S.data.tanks[g].length)) return;
        S.data.tanks[g] = P0.tanks[g].map(function (t) { var r = emptyRow(); r.name = t.name; r.ref_height = t.ref_height; return r; });
      });
      S.manual = true; S.errors = {};
      changed(); render(); toast('success', 'Filled from the previous attendance — please check');
    });
  }
  // ---------- ROB comparison as an image, shared on WhatsApp ----------
  function cardImage() {
    var rows = summaryRows(), tot = 0, any = false;
    rows.forEach(function (r) { if (r.diff !== null) { tot += r.diff; any = true; } });
    tot = ReportCalc.round(tot, 3);
    var W = 1080, pad = 64, rowH = 92, H = 520 + rows.length * rowH + 260;
    var c = document.createElement('canvas'); c.width = W; c.height = H;
    var x = c.getContext('2d'), F = function (w, s) { return w + ' ' + s + 'px Lexend, "Segoe UI", Roboto, Arial, sans-serif'; };
    var rr = function (X, Y, w, h, r) { x.beginPath(); x.moveTo(X + r, Y); x.arcTo(X + w, Y, X + w, Y + h, r); x.arcTo(X + w, Y + h, X, Y + h, r); x.arcTo(X, Y + h, X, Y, r); x.arcTo(X, Y, X + w, Y, r); x.closePath(); };
    x.fillStyle = '#F4F7FB'; x.fillRect(0, 0, W, H);
    var gr = x.createLinearGradient(0, 0, W, 300); gr.addColorStop(0, '#00A3DF'); gr.addColorStop(1, '#6C4CF1');
    x.fillStyle = gr; x.fillRect(0, 0, W, 300);
    x.fillStyle = 'rgba(255,255,255,.85)'; x.font = F(600, 30); x.fillText('YMR MARINE SOLUTIONS', pad, 78);
    x.fillStyle = '#fff'; x.font = F(800, 54);
    var vn = S.report.vessel || val('vessel_name'); while (x.measureText(vn).width > W - 2 * pad && vn.length > 4) vn = vn.slice(0, -2);
    x.fillText(vn, pad, 158);
    x.font = F(500, 30); x.fillStyle = 'rgba(255,255,255,.92)';
    var sub = [(S.report.kind_label || 'Survey') + ' · ROB comparison', val('place') || val('port_name'), val('completed_date')].filter(Boolean).join('  ·  ');
    x.fillText(sub, pad, 214);
    if (val('imo')) { x.font = F(500, 26); x.fillText('IMO ' + val('imo'), pad, 262); }
    // table card
    var top = 340, cw = W - 2 * pad;
    x.save(); x.shadowColor = 'rgba(15,23,42,.10)'; x.shadowBlur = 30; x.shadowOffsetY = 8; x.fillStyle = '#fff'; rr(pad, top, cw, 120 + rows.length * rowH, 28); x.fill(); x.restore();
    var cols = [pad + 36, pad + 330, pad + 610, pad + cw - 36];
    x.font = F(700, 24); x.fillStyle = '#64748B';
    x.textAlign = 'left'; x.fillText('FUEL GRADE', cols[0], top + 66);
    x.textAlign = 'right'; x.fillText('SURVEY (MT)', cols[1] + 150, top + 66); x.fillText('LOG BOOK (MT)', cols[2] + 150, top + 66); x.fillText('DIFFERENCE', cols[3], top + 66);
    rows.forEach(function (r, i) {
      var y = top + 120 + i * rowH;
      x.strokeStyle = '#E2E8F0'; x.lineWidth = 2; x.beginPath(); x.moveTo(pad + 24, y - 34); x.lineTo(pad + cw - 24, y - 34); x.stroke();
      x.textAlign = 'left'; x.fillStyle = '#0F172A'; x.font = F(800, 34); x.fillText(r.short, cols[0], y + 22);
      x.textAlign = 'right'; x.font = F(600, 32); x.fillStyle = '#0F172A';
      x.fillText(f3(r.completed) || '—', cols[1] + 150, y + 22); x.fillText(f3(r.logbook) || '—', cols[2] + 150, y + 22);
      x.fillStyle = r.diff < 0 ? '#DC2626' : r.diff > 0 ? '#16A34A' : '#334155'; x.font = F(800, 34); x.fillText(sgn(r.diff), cols[3], y + 22);
    });
    // total
    var ty = top + 150 + rows.length * rowH;
    x.fillStyle = '#0F2B5B'; rr(pad, ty, cw, 120, 28); x.fill();
    x.textAlign = 'left'; x.fillStyle = 'rgba(255,255,255,.8)'; x.font = F(700, 28); x.fillText('TOTAL DIFFERENCE', pad + 36, ty + 72);
    x.textAlign = 'right'; x.fillStyle = tot < 0 ? '#FCA5A5' : tot > 0 ? '#86EFAC' : '#fff'; x.font = F(800, 48); x.fillText(any ? sgn(tot) + ' MT' : '—', pad + cw - 36, ty + 78);
    x.textAlign = 'left'; x.fillStyle = '#64748B'; x.font = F(500, 24);
    x.fillText('Survey Completed − Log Book ROB at survey start' + (val('surveyor_name') ? '  ·  Surveyor: ' + val('surveyor_name') : ''), pad, H - 50);
    return new Promise(function (ok) { c.toBlob(function (b) { ok(b); }, 'image/png'); });
  }
  function shareCard() {
    var name = ((S.report.vessel || 'vessel') + ' ROB comparison').replace(/[^A-Za-z0-9 ._-]+/g, '').trim().replace(/\s+/g, '_') + '.png';
    var text = (S.report.vessel || '') + ' — ' + (S.report.kind_label || 'Survey') + ' ROB comparison';
    var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    ready.then(cardImage).then(function (blob) {
      S.shareBlob = blob;
      var file = new File([blob], name, { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) { // phone: the share sheet (WhatsApp)
        return navigator.share({ files: [file], text: text, title: text }).catch(function () {});
      }
      // computer: copy the picture (paste it in the chat) and download it, then open WhatsApp
      var copied = window.ClipboardItem && navigator.clipboard && navigator.clipboard.write ? navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () { return true; }, function () { return false; }) : Promise.resolve(false);
      return copied.then(function (ok) {
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; a.setAttribute('data-testid', 'sr-share-dl');
        document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
        Swal.fire({ icon: 'success', title: 'Image ready', html: (ok ? 'The picture is copied — paste it in the WhatsApp chat (Ctrl+V).' : 'The picture was downloaded — attach it in the WhatsApp chat.'),
          confirmButtonText: '<i class="fa-brands fa-whatsapp"></i> Open WhatsApp', showCancelButton: true, cancelButtonText: 'Close' })
          .then(function (r) { if (r.isConfirmed) window.open('https://web.whatsapp.com/send?text=' + encodeURIComponent(text), '_blank', 'noopener'); });
      });
    }).catch(function (e) { toast('error', 'Could not make the image: ' + (e && e.message || e)); });
  }
  function addTank(g) {
    var row = emptyRow(); S.data.tanks[g].push(row); changed();
    S.tcOpen = S.tcOpen || {}; S.tcOpen[row.id] = true;
    var key = g + ':' + (S.data.tanks[g].length - 1);
    S.gOpen = S.gOpen || {}; S.gOpen[g] = true;
    render(); var n = $('[data-t="' + key + ':name"]'); if (n) { n.focus(); n.scrollIntoView({ block: 'center' }); }
  }
  function openSheet(key) { S.sheet = key; render(); document.body.classList.add('sr-sheet-on'); var n = $('.sr-sheet [data-t$=":name"]'); if (n && !n.value) n.focus(); }
  function closeSheet() {
    if (S.sheet) { var p = S.sheet.split(':'); S.sheet = null; finishRow(p[0], +p[1]); }
    document.body.classList.remove('sr-sheet-on'); render();
  }
  function moveTank(key, to) {
    var p = key.split(':'), from = p[0], i = +p[1];
    if (from === to || !S.data.tanks[to]) return;
    var r = S.data.tanks[from].splice(i, 1)[0]; S.data.tanks[to].push(r);
    if (S.sheet === key) S.sheet = to + ':' + (S.data.tanks[to].length - 1);
    changed(); render(); toast('success', (r.name || 'Tank') + ' moved to ' + gradeInfo(to).short);
  }
  /** "Import from Excel": tank rows from a workbook (company format or a plain table), added to the grid. */
  function importTanks(file) {
    if (!online()) { Swal.fire({ icon: 'info', title: 'Import needs internet', text: 'Reading an Excel file is done by the server. Offline, copy the rows in Excel and paste them into the tank table — that works without internet.' }); return; }
    var f = new FormData(); f.append('csrf_token', P.csrf); f.append('a', 'import_tanks'); f.append('id', P.id); f.append('file', file);
    Swal.fire({ title: 'Reading the Excel…', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
    fetch(P.api, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-CSRF-Token': P.csrf } }).then(function (r) { return r.json(); }).then(function (res) {
      if (!res.ok) { Swal.fire({ icon: 'error', title: 'Not imported', text: res.error || 'Please try again.' }); return; }
      var rows = res.rows, have = 0; chosen().forEach(function (g) { have += S.data.tanks[g].length; });
      var byG = {}; rows.forEach(function (r) { byG[r.grade] = (byG[r.grade] || 0) + 1; });
      Swal.fire({ icon: 'question', title: 'Import ' + rows.length + ' tank' + (rows.length === 1 ? '' : 's') + '?', confirmButtonText: have ? 'Add to the list' : 'Import', showCancelButton: true, showDenyButton: !!have, denyButtonText: 'Replace the list',
        html: Object.keys(byG).map(function (g) { return esc((gradeInfo(g) || { short: g }).short) + ': ' + byG[g]; }).join(' · ') })
        .then(function (x) {
          if (!x.isConfirmed && !x.isDenied) return;
          if (x.isDenied) chosen().forEach(function (g) { S.data.tanks[g] = []; });
          rows.forEach(function (t) {
            if (!S.data.tanks[t.grade]) return;
            if (S.data.grades.indexOf(t.grade) < 0) S.data.grades.push(t.grade);
            var r = emptyRow(); ['name', 'ref_height', 'volume', 'density', 'temp'].forEach(function (k) { r[k] = t[k] || ''; });
            if (t.ullage && !t.sounding) { r.mode = 'ullage'; r.ullage = t.ullage; r.sounding = sounding(r); } else r.sounding = t.sounding || '';
            if (!Object.keys(tankErrors(r)).length) { fmtRow(r); r.done = '1'; }
            S.data.tanks[t.grade].push(r);
          });
          changed(); render(); toast('success', rows.length + ' tanks imported');
        });
    }).catch(function () { Swal.fire({ icon: 'error', title: 'Not imported', text: 'No connection.' }); });
  }
  /** A row with nothing typed (the empty rows at the end of a grid): ignored in checks and totals, never saved. */
  function blankRow(r) { return !['name', 'ref_height', 'sounding', 'ullage', 'volume', 'density', 'temp'].some(function (k) { return String(r[k] || '').trim() !== ''; }); }
  function emptyRow() { return { name: '', ref_height: '', sounding: '', volume: '', density: '', temp: '', mode: 'sounding', ullage: '', su: '', done: '', id: uid() }; }
  function saveTank(key) {
    var p = key.split(':'), r = S.data.tanks[p[0]][+p[1]], e = tankErrors(r);
    r._tried = true;
    if (Object.keys(e).length) { showErrors(); var first = $('[data-tank="' + key + '"] .sr-f.bad'); if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' }); toast('error', 'Complete the marked values of this tank.'); return; }
    fmtRow(r); r.done = '1'; r.copy = ''; delete S.open[r.id]; delete r._tried; delete r._editing; delete r._src;
    if (S.sheet === key) { S.sheet = null; document.body.classList.remove('sr-sheet-on'); }
    changed(); saveNow(); render();
    var card = $('[data-tank="' + key + '"]'); if (card) card.scrollIntoView({ block: 'nearest' });
  }
  /** A new tank right after this one with the same values, open for editing; its version 1 is made when it is saved. */
  function copyTank(key) {
    var p = key.split(':'), src = S.data.tanks[p[0]][+p[1]], row = {};
    ROW_KEYS.forEach(function (k) { row[k] = src[k] == null ? '' : String(src[k]); });
    row.id = uid(); row.done = ''; row.copy = '1'; row._src = trackOf(src);
    S.data.tanks[p[0]].splice(+p[1] + 1, 0, row);
    changed();
    var nk = p[0] + ':' + (+p[1] + 1);
    render();
    var n = $('[data-t="' + nk + ':name"]'); if (n) { n.scrollIntoView({ block: 'center' }); n.focus(); n.select(); }
    toast('success', 'Tank copied — change the values and press Save tank');
  }
  function delTank(key) {
    var p = key.split(':'), r = S.data.tanks[p[0]][+p[1]];
    if (!r || blankRow(r)) { // an empty row: gone at once
      if (r) S.data.tanks[p[0]].splice(+p[1], 1); else { xSpare(p[0]); S.spare[p[0]] = Math.max(S.spare[p[0]] - 1, 0); }
      changed(); render(); return;
    }
    var doit = function () { S.data.tanks[p[0]].splice(+p[1], 1); if (S.sheet) { S.sheet = null; document.body.classList.remove('sr-sheet-on'); } changed(); render(); };
    Swal.fire({ icon: 'question', title: 'Delete tank ' + (+p[1] + 1) + (r.name ? ' "' + r.name + '"' : '') + '?', text: 'Its readings are removed from the report.', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc2626' })
      .then(function (x) { if (x.isConfirmed) doit(); });
  }
  function toggleGrade(g, on) {
    var apply = function () {
      if (on && S.data.grades.indexOf(g) < 0) S.data.grades.push(g);
      if (!on) S.data.grades = S.data.grades.filter(function (x) { return x !== g; });
      changed(); render();
    };
    if (!on && S.data.tanks[g].length) {
      Swal.fire({ icon: 'question', title: 'Remove ' + gradeInfo(g).short + '?', text: 'Its ' + S.data.tanks[g].length + ' tank(s) are kept but left out of the report. Tick it again to bring them back.', showCancelButton: true, confirmButtonText: 'Remove' })
        .then(function (x) { if (x.isConfirmed) apply(); else render(); });
    } else apply();
  }
  // paste rows copied from Excel into a tank (name, ref. height, sounding, volume, density, temp)
  document.addEventListener('paste', function (ev) {
    var el = ev.target; if (ev.defaultPrevented || !el.matches || !el.matches('[data-t]')) return; // the grid's own paste ran already
    var txt = (ev.clipboardData || window.clipboardData).getData('text');
    if (txt.indexOf('\t') < 0 && txt.indexOf('\n') < 0) return;
    ev.preventDefault();
    var p = el.getAttribute('data-t').split(':'), g = p[0], r0 = +p[1], keys = ['name', 'ref_height', 'sounding', 'volume', 'density', 'temp'], c0 = Math.max(0, keys.indexOf(p[2]));
    var lines = txt.replace(/\r/g, '').split('\n').filter(function (l) { return l.trim() !== ''; });
    lines.forEach(function (line, li) {
      var r = r0 + li; if (r >= 60) return;
      while (S.data.tanks[g].length <= r) S.data.tanks[g].push(emptyRow());
      var row = S.data.tanks[g][r]; row.mode = 'sounding';
      line.split('\t').forEach(function (v, ci) { var k = keys[c0 + ci]; if (k) row[k] = v.trim(); });
      if (!Object.keys(tankErrors(row)).length) row.done = '1';
    });
    changed(); render();
    toast('success', lines.length + ' tank' + (lines.length === 1 ? '' : 's') + ' pasted');
  });

  // ---------- vessel lookup by IMO (AI + web search on the server) ----------
  // A whole, valid IMO starts the lookup by itself (no button needed); each IMO once.
  /** An IMO typed while offline: look it up now (on the report itself — a draft first becomes a report on the server). */
  function pendingImo() {
    var v = read(LS + '_imo');
    if (!v || P.draft || !online()) return;
    if (S.data && v === val('imo')) autoLookup(v); else store(LS + '_imo', null);
  }
  function autoLookup(v) {
    clearTimeout(S.autoT);
    v = String(v || '').replace(/\D/g, '');
    if (!S.canEdit || v.length !== 7 || S.autoImo === v || typeError(FIELD('imo'), v)) return;
    if (!online()) { store(LS + '_imo', v); return; } // looked up as soon as the connection is back
    store(LS + '_imo', null);
    S.autoT = setTimeout(function () { if (val('imo') === v && !S.looking) { S.autoImo = v; lookup(); } }, 400);
  }
  function lookup() {
    if (S.looking) return;
    var imo = val('imo');
    var e = typeError(FIELD('imo'), imo);
    if (imo === '' || e) { S.showErr.vessel = true; showErrors(); toast('error', e || 'Enter the 7-digit IMO number first.'); var i = $('[data-f=imo]'); if (i) i.focus(); return; }
    var btn = $('[data-lookup]'); if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Looking up…'; }
    S.lookupMsg = ''; S.looking = true; S.autoImo = imo;
    var info = $('[data-lookup-info]'); if (info) info.innerHTML = lookupInfoHtml();
    post({ a: 'lookup', id: P.id, imo: imo }).then(function (res) {
      S.looking = false; res = res || { ok: false };
      setTimeout(finalAfterOffline, 2500); // a PDF made offline: the final one now has the looked-up particulars
      S.manual = true;
      if (!res.ok) { S.lookupMsg = res.error || 'Nothing found for this IMO.'; S.lookup = null; render(); return; }
      var prev = {};
      Object.keys(res.fields).forEach(function (k) {
        if (VESSEL().indexOf(k) < 0 && k !== 'owners') return; // owners: found with the particulars, shown in step 2
        var old = val(k);
        if (old === res.fields[k]) return;
        prev[k] = old; setVal(k, res.fields[k]); S.ai[k] = true;
      });
      S.lookup = { imo: res.imo, prev: prev, sources: res.sources || [], from: res.from || 'web', at: res.at || '' };
      if (Object.keys(prev).length) changed();
      render();
    });
  }
  function undoLookup() {
    var L = S.lookup; if (!L) return;
    Object.keys(L.prev).forEach(function (k) { setVal(k, L.prev[k]); delete S.ai[k]; });
    S.lookup = null; changed(); render();
  }

  // ---------- saving ----------
  function changed(quiet) {
    if (!S.canEdit) return;
    S.dirty = true; S.server = null;
    store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() });
    if (!quiet) saveState(online() ? 'Saving…' : 'Changes on this device', 'dirty');
    clearTimeout(S.timer); S.timer = setTimeout(saveNow, quiet ? 300 : 1000);
  }
  function payload() {
    var d = Object.assign({}, S.data, { tanks: {} });
    Object.keys(S.data.tanks).forEach(function (g) { d.tanks[g] = S.data.tanks[g].filter(function (r) { return !blankRow(r); }); });
    return JSON.stringify(d, function (k, v) { return k === '_tried' ? undefined : v; });
  }
  function saveNow() {
    if (P.draft) { if (online()) return draftSync(); saveState('Changes on this device', 'dirty'); return Promise.resolve(false); }
    if (!S.dirty || S.saving || !S.canEdit) return Promise.resolve(true);
    if (!online()) { saveState('Changes on this device', 'dirty'); return Promise.resolve(false); }
    S.saving = true;
    var sent = payload();
    return post({ a: 'save', id: P.id, version: S.version, data: sent }).then(function (res) {
      S.saving = false;
      if (res.ok) {
        S.version = res.version; S.errors = res.errors || {};
        if (payload() === sent) { S.dirty = false; store(LS, null); saveState('Saved', ''); pvHint(); }
        else { store(LS, { data: S.data, base: S.version, dirty: true, at: Date.now() }); saveNow(); }
        var c = read(CACHE); if (c) { c.report.version = S.version; c.report.data = JSON.parse(sent); store(CACHE, c); }
        showErrors();
        return true;
      }
      if (res._status === 409 && res.server) {
        saveState('Save failed — changed elsewhere', 'err');
        Swal.fire({ icon: 'warning', title: 'Changed by someone else', html: esc(res.error) + '<br>Keep your changes (they replace the other version) or load the saved one?', showDenyButton: true,
          confirmButtonText: 'Keep my changes', denyButtonText: 'Load the saved report', allowOutsideClick: false }).then(function (r) {
          if (r.isConfirmed) { S.version = res.server.version; saveNow(); }
          else { S.version = res.server.version; S.data = normalise(res.server.data); S.dirty = false; store(LS, null); render(); saveState('Saved', ''); }
        });
        return false;
      }
      saveState(res._status === 0 ? 'Changes on this device' : 'Save failed', 'err');
      if (res.error) toast('error', res.error);
      return false;
    }).catch(function () { S.saving = false; saveState('Changes on this device', 'dirty'); return false; });
  }
  /**
   * Back online with a report started without internet: the report is made on the server (once —
   * its link is kept with the draft, so a second try only saves), everything typed is saved into it,
   * and the page moves to the report's own link. Nothing is lost if the connection drops halfway.
   */
  function draftSync() {
    if (!P.draft || S.drafting || !online() || !S.data) return Promise.resolve(false);
    S.drafting = true; saveState('Sending the report…', 'dirty');
    var d = read(DRAFT) || {}, token = d.token || '';
    return freshToken().then(function () {
      if (token) return { ok: true, token: token };
      return post({ a: 'new', kind: S.report.kind });
    }).then(function (j) {
      if (!j || !j.ok || !j.token) throw new Error((j && j.error) || 'The report could not be made on the server.');
      token = j.token; d.token = token; store(DRAFT, d);
      return fetch(P.api + '?a=get&id=' + encodeURIComponent(token), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (g) {
      if (!g || !g.ok) throw new Error((g && g.error) || 'The report could not be opened on the server.');
      return post({ a: 'save', id: token, version: g.report.version, data: payload() });
    }).then(function (res) {
      if (!res || !res.ok) throw new Error((res && res.error) || 'The report could not be saved.');
      var to = 'ysms_sr_' + token;
      store(to + '_step', S.data.step);
      var op = read(LS + '_offpdf'); if (op) store(to + '_offpdf', op); // a PDF made offline: the final one is made on the report page
      var im = read(LS + '_imo'); if (im) store(to + '_imo', im); // an IMO typed offline: looked up on the report page
      try { localStorage.setItem('ysms_srp_last', token); } catch (e) {}
      [DRAFT, LS, LS + '_step', LS + '_seen', LS + '_offpdf', LS + '_imo', CACHE].forEach(function (k) { store(k, null); });
      S.dirty = false; saveState('Saved', '');
      location.replace('survey_report_public.php?t=' + token);
      return true;
    }).catch(function (e) {
      S.drafting = false; saveState('Changes on this device', 'dirty');
      if (online()) toast('error', (e && e.message) || 'Not sent yet — kept on this device.');
      return false;
    });
  }
  function flush() { // wait for the save in flight and save what is left
    clearTimeout(S.timer);
    var wait = function () { return S.saving ? new Promise(function (r) { setTimeout(r, 150); }).then(wait) : Promise.resolve(); };
    return wait().then(function () { return S.dirty ? saveNow() : true; }).then(function () { return wait(); });
  }
  window.addEventListener('beforeunload', function () { if (S.dirty && online()) saveNow(); });
  /**
   * Live: what someone else saves shows up here within seconds (e.g. the Admin watching the
   * surveyor fill the tanks). Only while nothing is waiting to be saved on this device and no box
   * is being typed in; this person's own step stays where it is.
   */
  function livePoll() {
    if (document.hidden || !online() || S.dirty || S.saving || !S.data || S.signedOut || S.offlineMode) return;
    var a = document.activeElement;
    if (a && a.matches && a.matches('[data-f],[data-t]') && S.canEdit) return;
    fetch(P.api + '?a=version&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (v) {
      if (!v || !v.ok || v.version <= S.version) return null;
      return fetch(P.api + '?a=get&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (res) {
      if (!res || !res.ok || res.report.version <= S.version || S.dirty || S.saving) return;
      var step = S.data.step;
      S.version = res.report.version; S.data = normalise(res.report.data); S.data.step = step;
      S.files = res.files || S.files; S.server = null; S.tankLog = null;
      store(CACHE, res);
      render();
      if (S.data.step === 'calc' || S.data.step === 'generate') verify();
      headChips();
      var who = res.report.updated_by || 'someone';
      var el = $('[data-live]'); if (el) { el.textContent = 'Updated live — ' + who + ', ' + String(res.report.updated_at).slice(11, 16); el.hidden = false; }
      pvHint();
    }).catch(function () {});
  }
  setInterval(livePoll, 8000); // what is not saved yet stays on this device (localStorage)
  /** "Save Progress": save now (whatever the timer), say how it went. */
  function saveProgress() {
    if (!S.canEdit) return;
    if (!online()) { toast('info', 'Offline — kept on this device, saved when you are back online.'); return; }
    if (!S.dirty) { saveState('Saved', ''); toast('success', 'All changes are saved.'); return; }
    saveState('Saving…', 'dirty');
    flush().then(function () { if (!S.dirty) toast('success', 'Progress saved.'); });
  }

  // ---------- live preview (right panel; a phone opens it with the Preview button) ----------
  // The server makes the report from the SAVED values into a temporary PDF (ajax a=preview): nothing
  // is stored with the report, no file is listed, the vessel's status is not touched.
  var PV = { doc: null, page: 1, zoom: 1, seq: 0, timer: null, ver: null, lib: null };
  function pvOpen() { return document.body.classList.contains('sr-pv-open'); }
  function pvEl(s) { return document.querySelector('[data-pv-' + s + ']'); }
  function pvLib() {
    if (PV.lib) return PV.lib;
    PV.lib = new Promise(function (ok, no) {
      if (window.pdfjsLib) return ok(window.pdfjsLib);
      var sc = document.createElement('script'); sc.src = P.pdfjs; sc.onload = function () { window.pdfjsLib.GlobalWorkerOptions.workerSrc = P.pdfjsWorker; ok(window.pdfjsLib); };
      sc.onerror = function () { PV.lib = null; no(new Error('The preview viewer could not be loaded.')); }; document.head.appendChild(sc);
    });
    return PV.lib;
  }
  function pvStatus(text, cls) { var e = pvEl('status'); if (e) { e.textContent = text; e.className = 'sr-pv-st ' + (cls || ''); } }
  function preview(now) {
    if (!pvEl('canvas')) return;
    clearTimeout(PV.timer);
    PV.timer = setTimeout(pvLoad, now ? 0 : 900);
  }
  function pvLoad() {
    if (!online()) { pvDevice(); return; }
    if (S.dirty || S.saving) { PV.timer = setTimeout(pvLoad, 700); return; } // preview what is saved, never a half-saved state
    if (PV.ver === S.version && PV.doc) return;
    var seq = ++PV.seq, ver = S.version;
    pvStatus('Updating…', 'busy');
    Promise.all([pvLib(), fetch(P.api + '?a=preview&id=' + P.id + '&v=' + ver, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error(j.error || 'Preview not available (' + r.status + ').'); });
      return r.arrayBuffer();
    })]).then(function (x) {
      if (seq !== PV.seq) return; // a newer preview was asked for meanwhile
      return x[0].getDocument({ data: new Uint8Array(x[1]) }).promise.then(function (doc) {
        if (seq !== PV.seq) return;
        if (PV.doc) PV.doc.destroy();
        PV.doc = doc; PV.ver = ver; PV.page = Math.min(PV.page, doc.numPages) || 1;
        pvStatus('Preview of the saved report', 'ok');
        return pvDraw();
      });
    }).catch(function (e) { if (seq === PV.seq) pvStatus(e.message || 'Preview not available.', 'err'); });
  }
  /** Offline: preview the PDF made on this device (the server makes the final one when online). */
  function pvDevice() {
    var seq = ++PV.seq;
    pvStatus('Offline — making the preview on this device…', 'busy');
    Promise.all([pvLib(), devicePdf()]).then(function (x) {
      if (seq !== PV.seq) return;
      return x[0].getDocument({ data: new Uint8Array(x[1].doc.output('arraybuffer')) }).promise.then(function (doc) {
        if (seq !== PV.seq) return;
        if (PV.doc) PV.doc.destroy();
        PV.doc = doc; PV.ver = null; PV.page = Math.min(PV.page, doc.numPages) || 1;
        pvStatus('Offline preview — made on this device', 'warn');
        return pvDraw();
      });
    }).catch(function (e) {
      if (seq !== PV.seq) return;
      pvStatus(e && e.title === 'Please complete the report first' ? 'Complete the report to see the preview' : (e && (e.text || e.message)) || 'Preview not available offline', 'err');
    });
  }
  function pvDraw() {
    if (!PV.doc) return;
    var box = pvEl('canvas'), n = PV.doc.numPages;
    pvEl('page').textContent = PV.page + ' / ' + n;
    pvEl('zoomv').textContent = Math.round(PV.zoom * 100) + '%';
    return PV.doc.getPage(PV.page).then(function (pg) {
      var w = Math.max(240, box.clientWidth - 16), v1 = pg.getViewport({ scale: 1 }), scale = (w / v1.width) * PV.zoom, dpr = window.devicePixelRatio || 1;
      var vp = pg.getViewport({ scale: scale * dpr }), c = document.createElement('canvas');
      c.width = vp.width; c.height = vp.height; c.style.width = (vp.width / dpr) + 'px'; c.style.height = (vp.height / dpr) + 'px';
      c.setAttribute('data-testid', 'sr-pv-page');
      return pg.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise.then(function () { box.innerHTML = ''; box.appendChild(c); });
    });
  }
  function pvPanelShown() { var a = $('[data-testid=sr-preview]'); return !!a && a.offsetParent !== null && !pvOpen() || pvOpen(); }
  function pvTab(t) {
    PV.tab = t;
    $$('[data-pv-tab]').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-pv-tab') === t); });
    var c = pvEl('canvas'), x = $('[data-pv-xl]'), bar = $('[data-pv-bar]');
    if (c) c.hidden = t !== 'pdf'; if (x) x.hidden = t !== 'excel'; if (bar) bar.hidden = false;
    var nav = ['prev', 'next', 'in', 'out', 'fit']; $$('[data-pv-bar] .sr-pv-b, [data-pv-page]').forEach(function (e) { e.hidden = t !== 'pdf'; });
  }
  /** Report changed since the preview was made: say so (the preview is refreshed only when pressed). */
  function pvHint() {
    if (PV.tab && PV.ver != null && S.version !== PV.ver) pvStatus('The report changed — press ' + (PV.tab === 'excel' ? 'Excel View' : 'Report Preview') + ' to refresh', 'warn');
  }
  function excelView() {
    var x = $('[data-pv-xl]');
    if (!x) { // made only when asked for (an empty sandboxed frame is never on the page)
      x = document.createElement('iframe'); x.className = 'sr-pv-xl'; x.title = 'Excel view'; x.setAttribute('sandbox', 'allow-same-origin'); x.setAttribute('data-pv-xl', ''); x.setAttribute('data-testid', 'sr-pv-xl'); // no scripts run inside
      var cv = pvEl('canvas'); cv.parentNode.insertBefore(x, cv.nextSibling);
    }
    if (!online()) { pvStatus('Offline — the views are made by the server', 'warn'); return; }
    pvStatus('Updating…', 'busy');
    var go2 = function () {
      fetch(P.api + '?a=excel_view&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.ok) { pvStatus(res.error || 'Not available', 'err'); return; }
        x.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:8px;font-family:Arial,sans-serif;background:#fff}.xv-sheet{font:700 13px Arial;color:#0f2b5b;margin:14px 0 6px}table{border-collapse:collapse}</style></head><body>' + res.html + '</body></html>';
        PV.ver = res.version; pvStatus('Excel view of the saved report', 'ok');
      }).catch(function () { pvStatus('Not available', 'err'); });
    };
    if (S.dirty || S.saving) flush().then(go2); else go2();
  }
  /** Print: the report PDF (certificate + tank report) opened in a hidden frame and sent to the printer. */
  function printBlob(bl, done) {
    var u = URL.createObjectURL(bl), fr = document.createElement('iframe');
    fr.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0'; fr.setAttribute('data-testid', 'sr-print-frame'); fr.src = u;
    fr.onload = function () { setTimeout(function () { try { fr.contentWindow.focus(); fr.contentWindow.print(); } catch (e) { window.open(u, '_blank'); } done(); }, 300); };
    var old = $('[data-testid=sr-print-frame]'); if (old) old.parentNode.removeChild(old);
    document.body.appendChild(fr);
  }
  /** Print: the report PDF (certificate + tank report) opened in a hidden frame and sent to the printer — offline, the one made on this device. */
  function printPdf(btn) {
    btn.disabled = true;
    var done = function () { btn.disabled = false; };
    if (!online()) { devicePdf().then(function (x) { printBlob(x.doc.output('blob'), done); }, function (e) { done(); devicePdfFail(e); }); return; }
    (S.dirty || S.saving ? flush() : Promise.resolve()).then(function () {
      return fetch(P.api + '?a=preview&id=' + encodeURIComponent(P.id), { credentials: 'same-origin' });
    }).then(function (r) {
      if (!r.ok || !/pdf/.test(r.headers.get('content-type') || '')) throw new Error();
      return r.blob();
    }).then(function (bl) { printBlob(bl, done); })
      .catch(function () { done(); toast('error', 'The PDF could not be made for printing. Please try again.'); });
  }
  function pvAction(a) {
    if (a === 'load') { if (!pvPanelShown()) document.body.classList.add('sr-pv-open'); pvTab('pdf'); PV.ver = null; if (S.dirty || S.saving) flush(); preview(true); return; }
    if (a === 'excel') { if (!pvPanelShown()) document.body.classList.add('sr-pv-open'); pvTab('excel'); excelView(); return; }
    if (a === 'open') { document.body.classList.add('sr-pv-open'); if (!PV.tab) pvAction('load'); else if (PV.doc) pvDraw(); return; }
    if (a === 'close') { document.body.classList.remove('sr-pv-open'); return; }
    if (a === 'refresh') { PV.ver = null; preview(true); return; }
    if (!PV.doc) return;
    if (a === 'prev' && PV.page > 1) PV.page--;
    else if (a === 'next' && PV.page < PV.doc.numPages) PV.page++;
    else if (a === 'in') PV.zoom = Math.min(3, PV.zoom + 0.25);
    else if (a === 'out') PV.zoom = Math.max(0.5, PV.zoom - 0.25);
    else if (a === 'fit') PV.zoom = 1;
    else return;
    pvDraw();
  }
  window.addEventListener('resize', function () { clearTimeout(PV.rs); PV.rs = setTimeout(pvDraw, 200); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && pvOpen()) pvAction('close'); });

  // ---------- summary check + files ----------
  function verify() {
    if (!online() || !chosen().length) return;
    S.verifying = true; render();
    flush().then(function () {
      return fetch(P.api + '?a=verify&id=' + P.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }).then(function (res) {
      S.verifying = false;
      var here = S.data.step === 'calc' || S.data.step === 'generate';
      if (res && res.ok && res.results && here && !S.dirty) S.server = res.results;
      if (here) render();
    }).catch(function () { S.verifying = false; if (S.data.step === 'calc' || S.data.step === 'generate') render(); });
  }
  function generate(type) {
    var btns = $$('[data-gen]'); btns.forEach(function (b) { b.disabled = true; });
    var btn = $('[data-gen="' + type + '"]'), html = btn.innerHTML; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Making the report…';
    flush().then(function () {
      return post({ a: 'generate', id: P.id, pdf: type === 'pdf' ? 1 : 0 });
    }).then(function (res) {
      btn.innerHTML = html; btns.forEach(function (b) { b.disabled = false; });
      if (res.version) S.version = res.version;
      if (!res.ok) {
        if (res.issues && res.issues.length) Swal.fire({ icon: 'warning', title: 'Report not made', html: '<ul class="sr-swal-list">' + res.issues.slice(0, 8).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') + '</ul>' });
        else Swal.fire({ icon: 'error', title: 'Report not made', text: res.error || 'Please try again.' });
        return;
      }
      if (type === 'pdf' && res.pdf_error) { Swal.fire({ icon: 'warning', title: 'PDF not available', text: res.pdf_error + ' The Excel report was made; download it below.' }); }
      S.files = res.files; S.server = res.results; S.tankLog = null;
      var c = read(CACHE); if (c) { c.files = S.files; c.report.version = S.version; store(CACHE, c); }
      render();
      var id = res.new_files && res.new_files[type];
      if (id) {
        var a = document.createElement('a'); a.href = fileUrl(id); a.setAttribute('download', ''); a.setAttribute('data-testid', 'sr-dl-link');
        document.body.appendChild(a); a.click(); setTimeout(function () { a.remove(); }, 4000);
        toast('success', (type === 'pdf' ? 'PDF' : 'Excel') + ' report downloaded');
      }
      // the Admin also gets the Excel made with it (a user only the PDF)
      var xid = S.isAdmin && type === 'pdf' && res.new_files && res.new_files.xlsx;
      if (xid) setTimeout(function () {
        var x = document.createElement('a'); x.href = fileUrl(xid); x.setAttribute('download', ''); x.setAttribute('data-testid', 'sr-dl-xlsx');
        document.body.appendChild(x); x.click(); setTimeout(function () { x.remove(); }, 4000);
      }, 900);
    });
  }
  // ---------- the report made on this device (no internet) ----------
  // The final report is made by the server (includes/survey_report_pdf.php). Without internet the same
  // report is drawn here with jsPDF in the same YMR format: letterhead, vessel name + IMO, the header
  // lines with the BROB of each grade, drafts, one tank table per grade (readings with S / U), TOTAL
  // Metric Tons, the remark, the MT of each grade and the three signatures in one row — on one page.
  // When the connection is back the data is saved and the server's PDF is made by itself.
  var LOGO = 'assets/images/ymr-logo-horizontal.svg', BANNER = 'assets/images/survey-letterhead.png';
  // load them once as images while online, so the offline cache (survey_report_sw.js) has them later
  try { [LOGO, BANNER].forEach(function (u) { var im = new Image(); im.src = u; }); } catch (e) {}
  /** An image as PNG data (the vector logo drawn sharp on a canvas, w px wide), or null. */
  function imgPng(url, w) {
    return new Promise(function (ok) {
      var im = new Image();
      im.onload = function () {
        try {
          var cw = w || im.naturalWidth, ch = Math.round(cw * im.naturalHeight / im.naturalWidth), c = document.createElement('canvas'); c.width = cw; c.height = ch;
          var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, cw, ch); x.drawImage(im, 0, 0, cw, ch);
          ok({ data: c.toDataURL('image/png'), w: cw, h: ch });
        } catch (e) { ok(null); }
      };
      im.onerror = function () { ok(null); };
      im.src = url;
    });
  }
  function pNum(v, d) { var n = num(v); d = d == null ? 3 : d; return n === null ? '—' : n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); }
  function pRead(col, v) {
    v = String(v == null ? '' : v).trim(); if (v === '') return '—';
    var n = num(v); if (n === null) return v.toUpperCase();
    var d = { ref_height: 3, sounding: 3, volume: 3, density: 4, temp: 1 }[col]; return d == null ? v : pNum(n, d);
  }
  var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
  function pDate(v) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v || ''); if (!m) return v || '';
    var d = +m[3], s = d % 100 >= 11 && d % 100 <= 13 ? 'th' : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][d % 10];
    return d + s + ' ' + MONTHS[+m[2] - 1] + ' ' + m[1];
  }
  /** The reading of the Sounding column as printed: "0.012 S", "1.250 U" (the ullage typed), or the plain value. */
  function pSdg(r) {
    if (r.mode === 'ullage') return pRead('sounding', r.ullage) + ' U';
    var s = sounding(r); return pRead('sounding', s) + (r.su === 's' && num(s) !== null ? ' S' : '');
  }
  /** The YMR letterhead at the top of a page (the same on the certificate and the tank report) → the y below it. */
  function lhDraw(doc, logo, banner, k) {
    var W = 210, L = 10, CW = 190, PT = 0.3528, y = 7;
    if (logo) {
      var lgw = k >= 0.5 ? 182 : 160, lgh = lgw * logo.h / logo.w;
      doc.addImage(logo.data, 'PNG', (W - lgw) / 2, y, lgw, lgh); y += lgh + 4.6;
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8.4); doc.setTextColor(59, 95, 149);
      var lab = 'REGISTERED OFFICE ADDRESS:', all = lab + ' Runwal Elegante, A2605, Lokhandwala, Andheri West, Mumbai, Maharashtra- 400058', x0 = (W - doc.getTextWidth(all)) / 2;
      doc.text(all, x0, y); doc.setDrawColor(59, 95, 149); doc.setLineWidth(0.15); doc.line(x0, y + 0.6, x0 + doc.getTextWidth(lab), y + 0.6);
      y += 4.3; var aw = CW * 0.92, ax = (W - aw) / 2;
      doc.text('e-mail: ops@ymrmarine.com', ax, y); doc.text('Phone: +919820482713', W / 2, y, { align: 'center' }); doc.text('http://www.ymrmarine.com', ax + aw, y, { align: 'right' });
      y += 2.4;
    } else if (banner) {
      var bh = CW * banner.h / banner.w; if (bh > 40) bh = 40; doc.addImage(banner.data, 'PNG', (W - bh * banner.w / banner.h) / 2, y, bh * banner.w / banner.h, bh); y += bh + 1.5;
    } else {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(20); doc.setTextColor(0, 160, 226); doc.text('YMR MARINE SOLUTIONS', W / 2, y + 8, { align: 'center' }); y += 11;
    }
    doc.setDrawColor(61, 139, 214); doc.setLineWidth(0.9 * PT); doc.line(L, y, W - L, y); y += 2.1;
    return y;
  }
  /** Survey type of the report: as chosen in Survey Details, else the one its report type opens with. */
  function certType() {
    var C = S.cfg.cert; if (!C) return 'none';
    var t = val('survey_type'); if (t && C.choices[t]) return t;
    return C.by_kind[S.cfg.template.kind] || 'none';
  }
  /** The certificate as srCertModel() makes it on the server (includes/survey_certificates.php), from the same wording. */
  function certModel() {
    var C = S.cfg.cert, cfg = C && C.types[certType()]; if (!cfg) return null;
    var up = function (k) { return val(k).toUpperCase(); }, parts = [];
    if (val('flag')) parts.push('under ' + up('flag') + ' Flag');
    if (val('port_of_registry')) parts.push('Port of Registry ' + up('port_of_registry'));
    if (val('grt')) parts.push('GRT ' + val('grt'));
    if (val('nrt')) parts.push('NRT ' + val('nrt'));
    var rep = { '{vessel}': up('vessel_name'), '{particulars}': parts.length ? ', ' + parts.join(', ') : '', '{owners}': up('owners'), '{charterers}': up('charterers') };
    var fill = function (t) { return String(t || '').replace(/\{(vessel|particulars|owners|charterers)\}/g, function (m) { return rep[m]; }); };
    var box = function (fk) { var x = fk ? String((S.data.fields[fk] != null ? S.data.fields[fk] : S.data.extra[fk]) || '').trim() : ''; if (/^\d{4}-\d{2}-\d{2}$/.test(x)) x = pDate(x); return x || C.asper; };
    var grades = chosen().filter(function (g) { return S.data.tanks[g].some(function (r) { return !blankRow(r); }); });
    var asper = grades.map(function (g) { return [gradeInfo(g).name || gradeInfo(g).short, C.asper]; });
    return { title: cfg.title,
      boxes: [['Place of ' + cfg.event + ':', box(cfg.boxes.place)], ['Date of ' + cfg.event + ':', box(cfg.boxes.date)], ['Time of ' + cfg.event + ':', box(cfg.boxes.time)]],
      completed: [pDate(val('completed_date')), val('completed_time') ? val('completed_time') + '  Hrs LT' : ''],
      paras: [fill(cfg.certify), fill(cfg.survey)],
      qty: grades.map(function (g) { var t = gradeTotals(g).mt; return [gradeInfo(g).name || gradeInfo(g).short, t == null || isNaN(t) ? '—' : (+t).toFixed(3), 'M. Tons']; }),
      consumption: fill(cfg.consumption), onboard: fill(cfg.onboard), asper: asper, remark: fill(cfg.remark), without_prejudice: fill(cfg.without_prejudice),
      sign_left: cfg.sign_left.map(fill), sign_right: cfg.sign_right.map(fill), master: 'Capt. ' + up('master'), chief: 'Mr. ' + up('chief_engineer'),
      surveyor_title: cfg.surveyor_title, surveyor: 'Mr. ' + up('surveyor_name') };
  }
  /** The certificate page drawn with jsPDF (as srCertHtml() on the server). */
  function drawCert(doc, m, logo, banner) {
    var W = 210, L = 10, CW = 190, PT = 0.3528, FS = 9.4, LH = 1.34, y = lhDraw(doc, logo, banner, 1);
    var base = { font: 'helvetica', fontSize: FS, textColor: 0, lineColor: 0, lineWidth: 0, valign: 'middle', cellPadding: { top: 0.8, bottom: 0.8, left: 2.2, right: 2.2 } };
    doc.setTextColor(0); doc.setFont('helvetica', 'bold'); doc.setFontSize(13.5); y += 2.5 + 13.5 * PT * 0.85;
    doc.text(m.title, W / 2, y, { align: 'center' }); var tw = doc.getTextWidth(m.title); doc.setDrawColor(0); doc.setLineWidth(0.25); doc.line((W - tw) / 2, y + 0.9, (W + tw) / 2, y + 0.9); y += 5;
    doc.autoTable({ startY: y, margin: { left: L }, tableWidth: CW * 0.6, theme: 'plain', styles: Object.assign({}, base, { lineWidth: 0.7 * PT, halign: 'center' }), columnStyles: { 0: { cellWidth: CW * 0.6 * 0.45 } }, body: m.boxes });
    y = doc.lastAutoTable.finalY + 2.5;
    doc.autoTable({ startY: y, margin: { left: L }, tableWidth: CW, theme: 'plain', styles: Object.assign({}, base, { lineWidth: 0.9 * PT, fontStyle: 'bold', cellPadding: { top: 1.1, bottom: 1.1, left: 2.2, right: 2.2 } }),
      columnStyles: { 0: { cellWidth: CW * 0.36 }, 1: { cellWidth: CW * 0.32, halign: 'center' } }, body: [['Date / Time of Survey Completed:', m.completed[0], m.completed[1]]] });
    y = doc.lastAutoTable.finalY + 3.2;
    var para = function (t, style) {
      doc.setFont('helvetica', style || 'normal'); doc.setFontSize(FS); doc.setTextColor(style === 'italic' ? 31 : 0, style === 'italic' ? 41 : 0, style === 'italic' ? 55 : 0);
      var lines = doc.splitTextToSize(t, CW); y += FS * PT * 0.8;
      if (lines.length > 1) doc.text(t, L, y, { align: 'justify', maxWidth: CW, lineHeightFactor: LH }); else doc.text(t, L, y);
      y += (lines.length - 1) * FS * PT * LH + 2.6; doc.setTextColor(0);
    };
    var list = function (rows, bold) {
      doc.autoTable({ startY: y - 0.6, margin: { left: L + CW * 0.12 }, tableWidth: 'wrap', theme: 'plain', styles: Object.assign({}, base, { cellPadding: { top: 0.55, bottom: 0.55, left: 2.8, right: 2.8 } }), body: rows,
        columnStyles: { 0: { halign: 'right', minCellWidth: 52 }, 1: bold ? { halign: 'right', fontStyle: 'bold', minCellWidth: 24 } : {}, 2: { cellPadding: { left: 5, top: 0.55, bottom: 0.55 } } } });
      y = doc.lastAutoTable.finalY + 2.6;
    };
    m.paras.forEach(function (t) { para(t); });
    list(m.qty, true);
    para(m.consumption); list(m.asper);
    para(m.onboard); list(m.asper);
    // REMARK: bold label, the text after it
    doc.setFontSize(FS); doc.setFont('helvetica', 'normal'); var rl = doc.splitTextToSize('REMARK: ' + m.remark, CW); y += FS * PT * 0.8;
    doc.setFont('helvetica', 'bold'); doc.text('REMARK:', L, y); var bw = doc.getTextWidth('REMARK: ');
    doc.setFont('helvetica', 'normal'); doc.text(rl[0].slice(8), L + bw, y); rl.slice(1).forEach(function (t, i) { doc.text(t, L, y + (i + 1) * FS * PT * LH); });
    y += (rl.length - 1) * FS * PT * LH + 2.6;
    if (m.without_prejudice) para(m.without_prejudice, 'italic');
    // signatures: Owners' side (Master, Chief Engineer) left, the surveyor right
    y += 2;
    var colW = CW * 0.46, xs = [L, L + CW * 0.54], sy = [y, y];
    var head = function (c, lines) { doc.setFont('helvetica', 'normal'); doc.setFontSize(FS); lines.forEach(function (t, i) { doc.text(t, xs[c], sy[c] + FS * PT * (0.8 + i * 1.4)); }); sy[c] += FS * PT * (0.8 + (lines.length - 1) * 1.4) + 1; };
    var sig = function (c, title, names) {
      sy[c] += 40 * PT; doc.setDrawColor(0); doc.setLineWidth(0.8 * PT); doc.line(xs[c], sy[c], xs[c] + colW, sy[c]);
      doc.setFont('helvetica', 'bold'); doc.setFontSize(FS); [title].concat(names).forEach(function (t, i) { doc.text(t, xs[c], sy[c] + 1 + FS * PT * (0.85 + i * 1.3)); });
      sy[c] += 1 + FS * PT * (0.85 + names.length * 1.3) + 1;
    };
    head(0, m.sign_left); sig(0, 'The Master', [m.master]); sig(0, 'The Chief Engineer', [m.chief]);
    head(1, m.sign_right); sig(1, m.surveyor_title, ['For YMR MARINE SOLUTIONS LLP', m.surveyor]);
  }
  /** The report drawn at print scale k (1 = full size; text and spacing shrink, the letterhead keeps its size). */
  function ymrPdf(k, logo, banner, cm) {
    var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' }), W = 210, L = 10, CW = W - 20, PT = 0.3528, BOT = 286, pages = cm ? 2 : 1;
    if (cm) { drawCert(doc, cm, logo, banner); doc.addPage(); } // the certificate of the survey type, then the tank report
    var f = function (pt) { return pt * k; }, mm = function (pt) { return pt * k * PT; }, lw = Math.max(0.5 * k, 0.3) * PT;
    var up = function (key) { return val(key).toUpperCase(); }, y = 7;
    doc.setLineHeightFactor(1.15);
    y = lhDraw(doc, logo, banner, k);
    // vessel name and IMO
    var vessel = (val('vessel_name') || S.report.vessel || '').toUpperCase() || 'BUNKER SURVEY REPORT';
    doc.setFont('helvetica', 'bold'); doc.setFontSize(f(18.5)); doc.setTextColor(11, 60, 122); y += mm(18.5) * 0.85;
    doc.text(vessel, W / 2, y, { align: 'center' });
    if (val('imo')) {
      y += mm(3) + mm(9.5);
      doc.setFontSize(f(9.5)); doc.setFont('helvetica', 'normal'); var a = 'IMO No. ', aW = doc.getTextWidth(a); doc.setFont('helvetica', 'bold'); var bW = doc.getTextWidth(val('imo')), sx = (W - aW - bW) / 2;
      doc.setFont('helvetica', 'normal'); doc.setTextColor(0); doc.text(a, sx, y); doc.setFont('helvetica', 'bold'); doc.setTextColor(30, 58, 138); doc.text(val('imo'), sx + aW, y);
    }
    y += mm(9) + mm(3);
    var grades = chosen().filter(function (g) { return S.data.tanks[g].some(function (r) { return !blankRow(r); }); }), nb = grades.length;
    var base = { font: 'helvetica', fontSize: f(8.2), textColor: 0, lineColor: 0, lineWidth: 0, cellPadding: { top: mm(1.6), bottom: mm(1.6), left: mm(3), right: mm(3) }, valign: 'middle', overflow: 'linebreak' };
    var C = function (content, o) { o = o || {}; return { content: content == null ? '' : String(content), colSpan: o.span || 1, styles: { lineWidth: o.b ? lw : 0, halign: o.al || 'left', fontStyle: o.bold ? 'bold' : 'normal' } }; };
    var cols = function (ws) { var t = ws.reduce(function (s, x) { return s + x; }, 0), o = {}; ws.forEach(function (x, i) { o[i] = { cellWidth: CW * x / t }; }); return o; };
    // header lines: the BROB of each grade beside FWE and Survey Started, the grade names right above them
    var blank = function () { var o = [C('')]; grades.forEach(function () { o.push(C('')); }); o.push(C('')); return nb ? o : []; };
    var brob = function (p) { var o = [C('BROB:', { al: 'right' })]; grades.forEach(function (g) { var v = val('brob_' + p + '_' + g); o.push(C(v === '' ? '' : pNum(v), { b: 1 })); }); o.push(C('M.Tons')); return nb ? o : []; };
    var heads = function () { var o = [C('')]; grades.forEach(function (g) { o.push(C(gradeInfo(g).short, { b: 1 })); }); o.push(C('')); return nb ? o : []; };
    var line = function (label, v, time, right, bold) {
      var o = [C(label, { b: 1, bold: bold })];
      if (time === '') o.push(C(v, { b: 1, span: 2, bold: bold })); else o.push(C(v, { b: 1, bold: bold }), C(time, { b: 1, al: 'center', bold: bold }));
      o.push(C(time !== '' ? 'Hrs LT' : '', { bold: bold })); return o.concat(right);
    };
    var hw = [22, 22, 8, 7].concat(nb ? [6] : []).concat(grades.map(function () { return 10; })).concat(nb ? [8] : []);
    doc.autoTable({ startY: y, margin: { left: L, right: L }, tableWidth: CW, theme: 'plain', styles: base, columnStyles: cols(hw), body: [
      line('Owners', up('owners'), '', blank()), line('Charterers', up('charterers'), '', heads()),
      line('FWE', pDate(val('fwe_date')), val('fwe_time'), brob('fwe')), line('Survey Started', pDate(val('started_date')), val('started_time'), brob('started')),
      line('Survey Completed', pDate(val('completed_date')), val('completed_time'), blank(), true), line('Place of Survey', val('place'), '', blank()), line('Voyage No.', val('voyage_no'), '', blank())] });
    y = doc.lastAutoTable.finalY + mm(6);
    // drafts
    var fa = num(val('draft_aft')), ff = num(val('draft_fwd')), li = num(val('list')), d2 = function (n) { return n === null ? '' : n.toFixed(2); };
    doc.autoTable({ startY: y, margin: { left: L, right: L }, tableWidth: CW, theme: 'plain', styles: base, columnStyles: cols([13, 14, 10, 26, 13, 14, 10]), body: [
      [C('Draft Aft', { b: 1 }), C(d2(fa), { b: 1 }), C('Meters', { b: 1 }), C(''), C('Trim:', { b: 1 }), C(fa !== null && ff !== null ? (Math.round((fa - ff) * 1000) / 1000).toFixed(2) : '', { b: 1 }), C('Meters', { b: 1 })],
      [C('Draft Fwd', { b: 1 }), C(d2(ff), { b: 1 }), C('Meters', { b: 1 }), C(''), C('List', { b: 1 }), C(d2(li), { b: 1 }), C('Degrees', { b: 1 })]] });
    y = doc.lastAutoTable.finalY + mm(10);
    // one table per grade: thin grey lines between the tanks, only TOTAL Metric Tons
    var tw = [15].concat([1, 2, 3, 4, 5, 6, 7, 8].map(function () { return 85 / 8; }));
    grades.forEach(function (g, gi) {
      var rows = S.data.tanks[g].filter(function (r) { return !blankRow(r); }), anyU = rows.some(function (r) { return r.mode === 'ullage'; });
      if (gi) y += mm(8);
      doc.setFont('helvetica', 'bold'); doc.setFontSize(f(11)); doc.setTextColor(0); y += mm(11) * 0.8; doc.text(gradeInfo(g).name || gradeInfo(g).short, L, y); y += mm(3);
      var body = rows.map(function (r) {
        var t = calc(r);
        return [String(r.name || '').toUpperCase(), pRead('ref_height', r.ref_height), pSdg(r), pRead('volume', r.volume), pRead('density', r.density), pRead('temp', r.temp), pNum(t.vcf, 4), pNum(t.gsv), pNum(t.mt)];
      });
      var last = body.length - 1;
      doc.autoTable({ startY: y, margin: { left: L, right: L }, tableWidth: CW, theme: 'plain', columnStyles: cols(tw),
        styles: Object.assign({}, base, { fontSize: f(7.8), halign: 'center', cellPadding: { top: mm(1.5), bottom: mm(1.5), left: mm(2), right: mm(2) } }),
        headStyles: { fontStyle: 'normal', fontSize: f(7.6), lineWidth: lw, cellPadding: mm(2) },
        head: [['Tank\nName:', 'Ref. Height\n(m)', (anyU ? 'Sdg. / Ullage' : "Sdg. Corr'd") + '\n(m)', 'Volume\nCubic Metres', 'Density\n@15°C', 'Temp\nDeg(C)', 'V.C.F.\nT(54B)', 'G.S.V\nm³ @ 15°C', 'Metric\nTons']],
        body: body,
        didParseCell: function (d) { if (d.section === 'body' && d.column.index === 0) d.cell.styles.halign = 'left'; },
        didDrawCell: function (d) {
          if (d.section !== 'body') return;
          var c = d.cell; doc.setDrawColor(0); doc.setLineWidth(lw);
          doc.line(c.x, c.y, c.x, c.y + c.height); doc.line(c.x + c.width, c.y, c.x + c.width, c.y + c.height);
          if (d.row.index === last) { doc.line(c.x, c.y + c.height, c.x + c.width, c.y + c.height); return; }
          doc.setDrawColor(180, 191, 204); doc.setLineWidth(Math.max(0.3 * k, 0.3) * PT); doc.line(c.x, c.y + c.height, c.x + c.width, c.y + c.height);
        } });
      doc.autoTable({ startY: doc.lastAutoTable.finalY, margin: { left: L, right: L }, tableWidth: CW, theme: 'plain', columnStyles: cols([58, 28, 14]),
        styles: Object.assign({}, base, { fontSize: f(7.8), cellPadding: { top: mm(1.5), bottom: mm(1.5), left: mm(3), right: mm(3) } }),
        body: [[C(''), C('TOTAL Metric Tons', { b: 1, bold: 1, al: 'right' }), { content: pNum(gradeTotals(g).mt), styles: { lineWidth: lw, halign: 'right', fontStyle: 'bold', fontSize: f(8.6) } }]] });
      y = doc.lastAutoTable.finalY;
    });
    // remark, MT of each grade, the signatures in one row with room to sign and stamp
    y += mm(8) + mm(6);
    var remark = val('remarks') || 'All bunker tanks were carefully sounded together with Ch. Engineer/his representative and the quantities, as calculated from the vessels calibration tables, were found and agreed to be at the time of survey :';
    doc.setFont('helvetica', 'normal'); doc.setFontSize(f(8.2)); doc.setTextColor(0);
    var rl = doc.splitTextToSize(remark, CW); y += mm(8.2) * 0.8; doc.text(rl, L, y, { lineHeightFactor: 1.35 }); y += (rl.length - 1) * mm(8.2) * 1.35 + mm(10);
    if (nb) {
      doc.autoTable({ startY: y, margin: { left: L, right: L }, tableWidth: CW * 0.46, theme: 'plain', styles: base, columnStyles: { 0: { cellWidth: CW * 0.46 * 0.45 }, 1: { cellWidth: CW * 0.46 * 0.35 }, 2: { cellWidth: CW * 0.46 * 0.20 } },
        body: grades.map(function (g) { return [C(gradeInfo(g).short, { b: 1, al: 'center' }), C(pNum(gradeTotals(g).mt), { b: 1, al: 'center', bold: 1 }), C('MT', { b: 1, al: 'center' })]; }) });
      y = doc.lastAutoTable.finalY + mm(6);
    }
    var sigH = mm(10) + mm(62) + mm(8) * 1.35 * 3.2;
    if (y + sigH > BOT) { doc.addPage(); y = 12; }
    y += mm(10) + mm(62);
    var colW = CW * 0.3, gap = (CW - 3 * colW) / 2, sig = function (i, lines) {
      var x = L + i * (colW + gap); doc.setDrawColor(0); doc.setLineWidth(0.7 * k * PT); doc.line(x, y, x + colW, y);
      doc.setFontSize(f(8)); lines.forEach(function (t, j) { doc.setFont('helvetica', j ? 'normal' : 'bold'); doc.text(t, x, y + mm(2) + mm(8) * (0.85 + j * 1.35)); });
    };
    sig(0, ['The Master', 'CAPT. ' + up('master')]); sig(1, ['The Chief Engineer', 'MR. ' + up('chief_engineer')]); sig(2, ['Independent Marine Surveyor', 'MR. ' + up('surveyor_name'), 'For YMR MARINE SOLUTIONS LLP']);
    y += mm(2) + mm(8) * 1.35 * 3;
    // footer on every page
    var now = new Date(), p2 = function (n) { return (n < 10 ? '0' : '') + n; };
    var stamp = p2(now.getDate()) + ' ' + ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][now.getMonth()] + ' ' + now.getFullYear() + ' ' + p2(now.getHours()) + ':' + p2(now.getMinutes());
    for (var pg = 1; pg <= doc.getNumberOfPages(); pg++) {
      doc.setPage(pg); doc.setDrawColor(203, 213, 225); doc.setLineWidth(0.18); doc.line(L, 289, W - L, 289);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(100, 116, 139);
      doc.text(vessel + ' · Generated by YMR Marine YSMS on ' + stamp + ' (offline)', L, 292);
      if (doc.getNumberOfPages() > pages) doc.text('Page ' + pg + ' of ' + doc.getNumberOfPages(), W - L, 292, { align: 'right' });
    }
    return { doc: doc, fits: doc.getNumberOfPages() === pages && y <= BOT };
  }
  /** The report PDF made on this device (no server): resolves { doc, name }, or rejects with what to tell the user. */
  function devicePdf() {
    if (!window.jspdf || !window.jspdf.jsPDF) return Promise.reject({ title: 'Not available', text: 'The PDF tools are not on this device yet — open the report once while online.' });
    var all = issues();
    if (all.length) return Promise.reject({ icon: 'warning', title: 'Please complete the report first', html: '<ul class="sr-swal-list">' + all.slice(0, 8).map(function (x) { return '<li>' + esc(x.msg) + '</li>'; }).join('') + '</ul>' });
    return Promise.all([imgPng(LOGO, 2800), imgPng(BANNER)]).then(function (im) {
      var out = null;
      var cm = certModel();
      [1, 0.9, 0.8, 0.72, 0.65, 0.58, 0.52, 0.46, 0.41, 0.36, 0.32].some(function (k) { out = ymrPdf(k, im[0], im[1], cm); return out.fits; }); // the certificate, then the whole tank report on one page
      var vessel = val('vessel_name') || S.report.vessel || 'SURVEY';
      return { doc: out.doc, name: (vessel + ' - ' + (S.report.kind_label || 'SURVEY') + ' REPORT').toUpperCase().replace(/[^A-Z0-9 .\-]+/g, '').trim() + '.pdf' };
    });
  }
  function devicePdfFail(e) { Swal.fire({ icon: (e && e.icon) || 'error', title: (e && e.title) || 'PDF not made', text: e && e.text, html: e && e.html }); }
  function offlinePdf() {
    devicePdf().then(function (x) {
      x.doc.save(x.name);
      store(LS + '_offpdf', { at: Date.now(), version: S.version }); // make the server's report as soon as the connection is back
      toast('success', 'PDF saved on this device');
    }, devicePdfFail);
  }
  /** Back online after a draft PDF was made offline: save, then make the final PDF (and the Excel kept in the system). */
  function finalAfterOffline() {
    var f = read(LS + '_offpdf'); if (!f || !online() || !S.canEdit || S.finalBusy) return;
    if (read(LS + '_imo')) return; // an IMO typed offline is looked up first; the final PDF follows that
    S.finalBusy = true;
    flush().then(function () {
      if (S.dirty || issues().length) { S.finalBusy = false; return; }
      return post({ a: 'generate', id: P.id, pdf: 1 }).then(function (res) {
        S.finalBusy = false;
        if (!res || !res.ok) return;
        store(LS + '_offpdf', null);
        S.files = res.files || S.files; if (res.version) S.version = res.version;
        var id = res.new_files && res.new_files.pdf;
        Swal.fire({ icon: 'success', title: 'Synced — final report ready', text: 'Your offline changes are saved and the final PDF was made on the YMR letterhead.',
          confirmButtonText: '<i class="fa-solid fa-file-pdf"></i> Download final PDF', showCancelButton: !!id, cancelButtonText: 'Later', showConfirmButton: !!id })
          .then(function (r) { if (r.isConfirmed && id) { var a = document.createElement('a'); a.href = fileUrl(id); a.setAttribute('download', ''); document.body.appendChild(a); a.click(); setTimeout(function () { a.remove(); }, 4000); } });
      });
    }).catch(function () { S.finalBusy = false; });
  }
  window.addEventListener('online', function () { setTimeout(finalAfterOffline, 1500); });

  // the desktop sheet (survey-report-xl.js) works on the same data with the same functions
  window.SR_INT = { S: S, P: P, $: $, $$: $$, esc: esc, f3: f3, f3c: f3c, num: num, val: val, calc: calc, shown: shown, chosen: chosen, gradeInfo: gradeInfo,
    gradeTotals: gradeTotals, allTotals: allTotals, xSpare: xSpare, blankRow: blankRow, emptyRow: emptyRow, tankErrors: tankErrors, fmtRow: fmtRow,
    render: render, changed: changed, saveNow: saveNow, gridPaste: gridPaste, delTank: delTank, copyTank: copyTank, moreRows: moreRows,
    summaryRows: summaryRows, calcHtml: calcHtml, label: label, toast: toast, online: online };

  // ---------- offline support ----------
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('survey_report_sw.js', { scope: './survey_report' }).catch(function () {});
  load();
})();
