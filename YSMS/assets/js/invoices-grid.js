/* Invoices (invoices.php) — the Invoice Airtable grid for the Invoices module: summary cards,
   quick filters (All pending / CP Prices / Cost / Other Reason / Completed / Deleted), Hide fields,
   delete a wrong entry while it is still pending (no invoice made yet) and restore it,
   Filter, Sort, Export, search, resize / move columns (saved per user), paging, row panel.
   Data and actions: ajax/invoices.php. Styles: assets/css/invoice-airtable.css. */
(function () {
  'use strict';
  var C = window.INV_CONFIG || {};
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var CLS = { 'Pending for CP Prices': 'cp', 'Pending for Cost': 'cost', 'Pending for Other Reason': 'other', 'Completed': 'done' };

  // ---------- columns (invoice number is the primary field: always first, always shown) ----------
  var COLS = {
    invoice_no:       { label: 'Invoice Number', icon: 'fa-hashtag', w: 180, sort: 'invoice_no', pk: true },
    status:           { label: 'Status', icon: 'fa-circle-half-stroke', w: 200, sort: 'status' },
    actions:          { label: 'Actions', icon: 'fa-bolt', w: 230 },
    vessel:           { label: 'Vessel', icon: 'fa-ship', w: 170, sort: 'vessel' },
    client:           { label: 'Client Name', icon: 'fa-building', w: 180, sort: 'client' },
    type:             { label: 'Survey Type', icon: 'fa-tags', w: 140, sort: 'type' },
    port:             { label: 'Port Name', icon: 'fa-location-dot', w: 150, sort: 'port' },
    survey_completed: { label: 'Survey Completed Date', icon: 'fa-regular fa-calendar-check', w: 160, sort: 'survey_completed' },
    report_no:        { label: 'Report No', icon: 'fa-file-lines', w: 130, sort: 'report_no' },
    document:         { label: 'Invoice Document', icon: 'fa-paperclip', w: 190 },
    invoice_date:     { label: 'Invoice Date', icon: 'fa-regular fa-calendar', w: 112, sort: 'invoice_date' },
    amount:           { label: 'Invoice Amount', icon: 'fa-dollar-sign', w: 130, sort: 'amount', num: true }
  };
  var DEFAULT_ORDER = ['status', 'actions', 'vessel', 'client', 'type', 'port', 'survey_completed', 'report_no', 'document', 'invoice_date', 'amount'];
  var SORT_LABEL = { invoice_no: 'Invoice Number', vessel: 'Vessel', client: 'Client Name', type: 'Survey Type', port: 'Port Name', survey_completed: 'Survey Completed Date', status: 'Status', report_no: 'Report No', invoice_date: 'Invoice Date', amount: 'Invoice Amount' };

  var P = C.prefs || {}, I = C.init || {};
  var order = (P.order || []).filter(function (k) { return COLS[k] && !COLS[k].pk; });
  DEFAULT_ORDER.forEach(function (k) { if (order.indexOf(k) < 0) order.push(k); });
  var S = {
    view: ['completed', 'deleted'].indexOf(I.view) >= 0 ? I.view : 'pending', q: I.q || '', f: {},
    sort: Array.isArray(P.sort) ? P.sort.filter(function (s) { return SORT_LABEL[s.f]; }) : [],
    page: 1, per: [25, 50, 100, 200].indexOf(+P.per) >= 0 ? +P.per : 50,
    order: order, hidden: (P.hidden || []).filter(function (k) { return COLS[k] && !COLS[k].pk; }), widths: P.widths || {},
    rows: [], total: 0, pages: 1, counts: null, sel: {}, openId: null, seq: 0
  };
  ['status', 'type', 'month', 'client', 'port', 'vessel', 'scd_from', 'scd_to'].forEach(function (k) { if (I[k]) S.f[k] = String(I[k]); });

  // ---------- helpers ----------
  var money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function usd(v) { return v === null || v === undefined || v === '' ? '' : money.format(+v); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  var MON = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  function fdate(d) { if (!d) return ''; var p = String(d).slice(0, 10).split('-'); return p.length === 3 ? p[2] + ' ' + MON[+p[1] - 1] + ' ' + p[0] : esc(d); }
  function muted(t) { return '<span class="muted">' + (t || '—') + '</span>'; }
  function toast(icon, title) { if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2600, timerProgressBar: true }); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, me = this; clearTimeout(t); t = setTimeout(function () { fn.apply(me, a); }, ms); }; }
  function post(data) {
    data.append('csrf_token', C.csrf);
    return fetch(C.api, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ').' }; }); });
  }
  function fd(obj) { var f = new FormData(); Object.keys(obj).forEach(function (k) { f.append(k, obj[k]); }); return f; }
  function visible() { return ['invoice_no'].concat(S.order.filter(function (k) { return S.hidden.indexOf(k) < 0; })); }
  function width(k) { return Math.max(60, +(S.widths[k] || COLS[k].w)); }
  function quickKey() { return S.view === 'completed' ? 'Completed' : S.view === 'deleted' ? '__deleted' : (S.f.status || ''); }
  function fileUrl(d, rec) { return C.api + '?a=file&id=' + d.id + (rec ? '&which=recovery' : ''); }
  function rowById(id) { for (var i = 0; i < S.rows.length; i++) if (S.rows[i].id === id) return S.rows[i]; return null; }

  var savePrefs = debounce(function () {
    post(fd({ a: 'prefs', prefs: JSON.stringify({ order: S.order, hidden: S.hidden, widths: S.widths, sort: S.sort, per: S.per }) }));
  }, 600);

  function params(withView) {
    var u = new URLSearchParams();
    if (withView !== false && S.view !== 'pending') u.set('view', S.view);
    if (S.q) u.set('q', S.q);
    Object.keys(S.f).forEach(function (k) { if (S.f[k] !== '' && S.f[k] != null && !(k === 'status' && S.view !== 'pending')) u.set(k, S.f[k]); });
    return u;
  }
  function syncUrl() { try { var q = params().toString(); history.replaceState(null, '', 'invoices.php' + (q ? '?' + q : '')); } catch (e) {} }

  // ---------- data ----------
  var grid = $('[data-grid]'), tbody = $('[data-body]');
  function load() {
    var my = ++S.seq, u = params();
    u.set('a', 'list'); u.set('page', S.page); u.set('per', S.per);
    if (S.sort.length) u.set('sort', JSON.stringify(S.sort));
    if (!S.rows.length) skeleton(); else tbody.style.opacity = '.55';
    $('.at-sum').classList.add('loading');
    syncUrl();
    return fetch(C.api + '?' + u.toString(), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      if (my !== S.seq) return;
      tbody.style.opacity = '';
      $('.at-sum').classList.remove('loading');
      if (!res.ok) { toast('error', res.error || 'Could not load invoices.'); return; }
      S.rows = res.rows; S.total = res.total; S.pages = res.pages; S.page = res.page; S.counts = res.counts; S.pending = res.pending; S.deleted = res.deleted || 0;
      render(); counters(); toolbarState();
      if (S.openId) { var r = rowById(S.openId); if (r) renderPanel(r); else closePanel(); }
    }).catch(function () { if (my === S.seq) { tbody.style.opacity = ''; toast('error', 'Network error — could not load invoices.'); } });
  }
  function skeleton() {
    var cols = visible(), h = '';
    for (var i = 0; i < 8; i++) h += '<tr class="at-skel"><td class="c-no"><span></span></td>' + cols.map(function () { return '<td><span style="width:' + (40 + (i * 13) % 50) + '%"></span></td>'; }).join('') + '</tr>';
    renderHead(); tbody.innerHTML = h;
  }

  // ---------- counters (summary cards + quick chips) ----------
  function counters() {
    var c = S.counts || {}, set = function (k, v) { $$('[data-count="' + k + '"]').forEach(function (el) { el.textContent = v; }); };
    var all = 0; Object.keys(c).forEach(function (k) { all += c[k]; });
    set('all', all.toLocaleString('en-US'));
    set('pending', (S.pending || 0).toLocaleString('en-US'));
    set('deleted', (S.deleted || 0).toLocaleString('en-US'));
    Object.keys(c).forEach(function (k) { set(k, (c[k] || 0).toLocaleString('en-US')); });
    $('[data-view-label]').textContent = S.view === 'completed' ? 'Completed invoices' : S.view === 'deleted' ? 'Deleted entries' : (S.f.status ? S.f.status : 'Pending invoices');
  }

  // ---------- grid ----------
  function renderHead() {
    var cols = visible();
    $('[data-cols]').innerHTML = '<col style="width:56px">' + cols.map(function (k) { return '<col data-col="' + k + '" style="width:' + width(k) + 'px">'; }).join('');
    var allSel = S.rows.length && S.rows.every(function (r) { return S.sel[r.id]; });
    $('[data-head]').innerHTML = '<tr><th class="c-no"><input type="checkbox" data-selall aria-label="Select all on this page" data-testid="inv-selall"' + (allSel ? ' checked' : '') + '></th>' + cols.map(function (k) {
      var c = COLS[k], si = -1;
      S.sort.forEach(function (s, i) { if (s.f === c.sort) si = i; });
      var ind = si >= 0 ? '<span class="sorti">' + (S.sort.length > 1 ? (si + 1) : '') + '<i class="fa-solid fa-arrow-' + (S.sort[si].d === 'desc' ? 'down' : 'up') + '"></i></span>' : '';
      var ic = c.icon.indexOf(' ') > 0 ? c.icon : 'fa-solid ' + c.icon;
      return '<th data-k="' + k + '" class="' + (c.pk ? 'c-pk ' : '') + (c.sort ? 'sortable' : '') + (c.num ? ' num-r' : '') + '"' + (c.pk ? '' : ' draggable="true"') + ' data-testid="inv-th-' + k + '" title="' + esc(c.label) + (c.sort ? ' — click to sort, Shift+click to add' : '') + '">' +
        '<div class="th"><i class="ic ' + ic + '"></i><span class="lbl">' + esc(c.label) + '</span>' + ind + '</div><span class="rz" data-rz="' + k + '"></span></th>';
    }).join('') + '</tr>';
  }
  function statusCell(d) {
    if (d.deleted) return '<span class="at-pill deleted" data-testid="inv-status-' + d.id + '">Deleted</span>';
    if (S.view === 'completed') return '<span class="at-pill done" data-testid="inv-status-' + d.id + '">Completed</span>';
    return '<select class="at-stsel ' + CLS[d.status] + '" aria-label="Invoice status" data-stsel data-testid="inv-status-' + d.id + '">' +
      C.statuses.map(function (s) { return '<option' + (s === d.status ? ' selected' : '') + '>' + esc(s) + '</option>'; }).join('') + '</select>' +
      '<button type="button" class="at-upd" data-act="update" hidden data-testid="inv-update-' + d.id + '"><i class="fa-solid fa-check"></i> Update</button>';
  }
  function docCell(d) {
    var h = '';
    if (d.has_file) h += '<a href="' + fileUrl(d) + '&mode=view" target="_blank" rel="noopener" title="Open ' + esc(d.file_name) + '" data-testid="inv-view-' + d.id + '"><i class="fa-solid fa-file-pdf"></i> PDF</a>' +
      '<a class="dl" href="' + fileUrl(d) + '&mode=download" title="Download invoice" aria-label="Download invoice" data-testid="inv-download-' + d.id + '"><i class="fa-solid fa-download"></i></a>';
    if (d.has_rec) h += '<a class="rec" href="' + fileUrl(d, 1) + '&mode=view" target="_blank" rel="noopener" title="Open recovery invoice" data-testid="inv-rec-view-' + d.id + '"><i class="fa-solid fa-droplet"></i> Recovery</a>' +
      '<a class="dl" href="' + fileUrl(d, 1) + '&mode=download" title="Download recovery invoice" aria-label="Download recovery invoice" data-testid="inv-rec-download-' + d.id + '"><i class="fa-solid fa-download"></i></a>';
    return h ? '<span class="at-doc">' + h + '</span>' : muted('No file');
  }
  function actionsCell(d) {
    var a = '';
    if (d.deleted) {
      return '<span class="at-act inv-actions"><button type="button" class="rst" data-act="restore" data-testid="inv-restore-' + d.id + '"><i class="fa-solid fa-rotate-left"></i> Restore</button></span>';
    }
    if (S.view === 'completed') {
      a += '<a class="gen" href="invoice_pdf.php?id=' + d.id + '" data-testid="inv-modify-' + d.id + '"><i class="fa-solid fa-file-invoice-dollar"></i> Modify</a>' +
        '<button type="button" class="edt" data-act="editinv" title="Edit all invoice details" data-testid="inv-editinv-' + d.id + '"><i class="fa-solid fa-pen-to-square"></i> Edit</button>' +
        '<button type="button" class="del" data-act="delete" data-testid="inv-delete-' + d.id + '"><i class="fa-solid fa-trash"></i> Delete</button>';
    } else {
      a += '<a class="gen" href="invoice_pdf.php?id=' + d.id + (d.recovery_pending ? '&type=recovery' : '') + '" data-testid="inv-generate-' + d.id + '"><i class="fa-solid fa-file-invoice-dollar"></i> ' + (d.recovery_pending ? 'Recovery' : 'Generate') + '</a>';
      a += '<button type="button" class="edt" data-act="editinv" title="Edit all invoice details" data-testid="inv-editinv-' + d.id + '"><i class="fa-solid fa-pen-to-square"></i> Edit</button>';
      a += d.has_file ? '<button type="button" data-act="edit" title="Replace the PDF or change its number, date, amount" data-testid="inv-edit-' + d.id + '"><i class="fa-solid fa-file-pen"></i> PDF</button>' +
        '<button type="button" class="del" data-act="delete" data-testid="inv-delete-' + d.id + '"><i class="fa-solid fa-trash"></i> Delete</button>'
        : '<button type="button" data-act="upload" data-testid="inv-upload-' + d.id + '"><i class="fa-solid fa-upload"></i> Upload</button>';
      if (d.can_remove) a += '<button type="button" class="del" data-act="remove" title="Delete this wrong entry (can be restored from Deleted)" data-testid="inv-remove-' + d.id + '"><i class="fa-solid fa-trash-can"></i> Delete entry</button>';
    }
    return '<span class="at-act inv-actions">' + a + '</span>';
  }
  function cell(k, d) {
    switch (k) {
      case 'invoice_no': return (d.invoice_no ? '<span class="inv-no" data-testid="inv-no-' + d.id + '">' + esc(d.invoice_no) + '</span>' : muted('Not generated')) +
        (d.has_rec || d.has_rec_entry ? '<span class="at-kind" title="Recovery invoice ' + esc(d.rec_no || '') + '">+ Recovery</span>' : '') +
        (d.recovery_pending ? '<span class="at-pend" data-testid="inv-rec-pending-' + d.id + '">Recovery pending</span>' : '');
      case 'status': return statusCell(d);
      case 'actions': return actionsCell(d);
      case 'vessel': return (d.vessel ? esc(d.vessel) : muted()) + (d.manual ? '<span class="at-tag man" title="Added with Add Invoice">Manual</span>' : '') + (d.survey_deleted ? ' <span style="color:#DC2626;font-size:11px">· survey deleted</span>' : '');
      case 'client': return d.client ? esc(d.client) : muted();
      case 'type': return d.type ? '<span class="at-tag">' + esc(d.type) + '</span>' : muted();
      case 'port': return d.port ? esc(d.port) : muted();
      case 'survey_completed': return d.survey_completed_ymd ? fdate(d.survey_completed_ymd) : muted();
      case 'report_no': return d.report_no ? esc(d.report_no) : muted();
      case 'document': return docCell(d);
      case 'invoice_date': return d.invoice_date ? fdate(d.invoice_date) : muted();
      case 'amount': return d.amount === null || d.amount === undefined ? muted() : usd(d.amount);
    }
    return '';
  }
  function render() {
    renderHead();
    var cols = visible(), off = (S.page - 1) * S.per;
    tbody.innerHTML = S.rows.map(function (d, i) {
      return '<tr data-id="' + d.id + '" class="' + (S.sel[d.id] ? 'sel' : '') + (S.openId === d.id ? ' open' : '') + '" data-testid="inv-row-' + d.id + '">' +
        '<td class="c-no"><span class="num">' + (off + i + 1) + '</span><input type="checkbox" data-sel aria-label="Select row"' + (S.sel[d.id] ? ' checked' : '') + '></td>' +
        cols.map(function (k) {
          var c = COLS[k], tip = ['vessel', 'client', 'port', 'type'].indexOf(k) >= 0 && d[k] ? ' title="' + esc(d[k]) + '"' : '';
          return '<td class="' + (c.pk ? 'c-pk ' : '') + (c.num ? 'num-r' : '') + '" data-c="' + k + '"' + tip + '>' + cell(k, d) + '</td>';
        }).join('') + '</tr>';
    }).join('');
    $('[data-empty]').hidden = S.rows.length > 0;
    $('[data-empty-text]').textContent = S.view === 'completed' ? 'No completed invoices match.' : S.view === 'deleted' ? 'No deleted entries.' : 'No pending invoices match.';
    $('[data-foot]').innerHTML = S.rows.length ? '<tr><td class="c-no"></td>' + cols.map(function (k) {
      return '<td class="' + (COLS[k].pk ? 'c-pk' : '') + '">' + (k === 'invoice_no' ? '<b>' + S.total.toLocaleString('en-US') + '</b> record' + (S.total === 1 ? '' : 's') : '') + '</td>';
    }).join('') + '</tr>' : '';
    var from = S.total ? off + 1 : 0, to = off + S.rows.length, filtered = params(false).toString() !== '';
    $('[data-count-text]').innerHTML = 'Showing <b>' + from + '–' + to + '</b> of <b>' + S.total.toLocaleString('en-US') + '</b> ' + (S.view === 'deleted' ? 'deleted entr' + (S.total === 1 ? 'y' : 'ies') : (S.view === 'completed' ? 'completed' : 'pending') + ' invoice' + (S.total === 1 ? '' : 's')) + (filtered ? ' (filtered)' : '') +
      (S.sort.length ? ' · sorted by ' + S.sort.map(function (s) { return SORT_LABEL[s.f] + (s.d === 'desc' ? ' ↓' : ' ↑'); }).join(', ') : (S.view === 'completed' ? ' · in invoice number order' : ' · newest Survey Completed Date first'));
    $('[data-pginfo]').textContent = S.page + ' / ' + S.pages;
    $('[data-pg="-1"]').disabled = S.page <= 1; $('[data-pg="1"]').disabled = S.page >= S.pages;
    $('[data-per]').value = String(S.per);
    selInfo();
  }
  function markOpen() { $$('tr[data-id]', tbody).forEach(function (tr) { tr.classList.toggle('open', +tr.getAttribute('data-id') === S.openId); }); }
  function selInfo() {
    var n = Object.keys(S.sel).length, el = $('[data-sel-info]');
    el.hidden = !n; el.innerHTML = n + ' selected <button type="button" data-selclear>clear</button>';
    $('[data-sel-count]').textContent = '(' + n + ')';
  }

  // ---------- toolbar state ----------
  function activeFilters() { return Object.keys(S.f).filter(function (k) { return S.f[k] !== '' && S.f[k] != null && !(k === 'status'); }); }
  function toolbarState() {
    var nf = activeFilters().length + (S.f.status && S.view === 'pending' ? 1 : 0);
    $('[data-n="filter"]').textContent = nf ? nf : '';
    $('[data-n="sort"]').textContent = S.sort.length ? S.sort.length : '';
    $('[data-n="fields"]').textContent = S.hidden.length ? S.hidden.length + ' hidden' : '';
    $('[data-pop="filter"]').classList.toggle('active-f', nf > 0);
    $('[data-pop="sort"]').classList.toggle('active-s', S.sort.length > 0);
    $('[data-pop="fields"]').classList.toggle('active-h', S.hidden.length > 0);
    $('.at-bar [data-act="clear"]').hidden = !(activeFilters().length || S.q || (S.f.status && S.view === 'pending'));
    var qk = quickKey();
    $$('[data-quick]').forEach(function (b) { b.classList.toggle('on', qk === b.getAttribute('data-quick')); });
    $$('[data-only-pending]').forEach(function (el) { el.hidden = S.view !== 'pending'; });
    $$('[data-f]').forEach(function (el) { var k = el.getAttribute('data-f'); if (document.activeElement !== el) el.value = S.f[k] || ''; el.classList.toggle('on', !!S.f[k]); });
    var si = $('[data-testid="inv-search"]'); if (document.activeElement !== si) si.value = S.q;
  }
  var reload = function () { S.page = 1; load(); };
  var reloadSoon = debounce(reload, 300);

  // ---------- popovers ----------
  var openPop = null;
  function closePop() { if (openPop) { $('[data-popbox="' + openPop + '"]').hidden = true; $('[data-pop="' + openPop + '"]').classList.remove('on'); openPop = null; } }
  $$('[data-pop]').forEach(function (b) {
    b.addEventListener('click', function (ev) {
      ev.stopPropagation();
      var k = b.getAttribute('data-pop'), was = openPop === k;
      closePop(); if (was) return;
      if (k === 'fields') renderFields();
      if (k === 'sort') renderSorts();
      var box = $('[data-popbox="' + k + '"]'), shell = $('.at-shell');
      box.hidden = false; b.classList.add('on'); openPop = k;
      if (window.innerWidth >= 992) box.style.left = Math.min(b.offsetLeft, shell.clientWidth - box.offsetWidth - 8) + 'px'; else box.style.left = '';
    });
  });
  document.addEventListener('click', function (ev) {
    if (openPop && document.contains(ev.target) && !ev.target.closest('.at-pop') && !ev.target.closest('[data-pop]')) closePop();
  });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { if (openPop) closePop(); else if (S.openId) closePanel(); } });

  // fields
  function renderFields() {
    $('[data-fields]').innerHTML = '<div class="at-field fixed"><i class="grip fa-solid fa-lock"></i>' + esc(COLS.invoice_no.label) + ' <small class="muted">primary</small></div>' +
      S.order.map(function (k) {
        var on = S.hidden.indexOf(k) < 0;
        return '<div class="at-field" draggable="true" data-fk="' + k + '" data-testid="inv-field-' + k + '"><i class="grip fa-solid fa-grip-vertical"></i>' + esc(COLS[k].label) +
          '<button type="button" class="tg' + (on ? ' on' : '') + '" data-tg="' + k + '" aria-label="Show ' + esc(COLS[k].label) + '" data-testid="inv-tg-' + k + '"></button></div>';
      }).join('');
  }
  $('[data-popbox="fields"]').addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-tg]'), all = ev.target.closest('[data-fields-all]');
    if (t) { var k = t.getAttribute('data-tg'), i = S.hidden.indexOf(k); if (i >= 0) S.hidden.splice(i, 1); else S.hidden.push(k); }
    else if (all) S.hidden = all.getAttribute('data-fields-all') === '1' ? [] : S.order.slice();
    else return;
    renderFields(); render(); toolbarState(); savePrefs();
  });
  var dragF = null;
  $('[data-fields]').addEventListener('dragstart', function (ev) { var f = ev.target.closest('[data-fk]'); if (f) { dragF = f.getAttribute('data-fk'); ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', dragF); } });
  $('[data-fields]').addEventListener('dragover', function (ev) { var f = ev.target.closest('[data-fk]'); if (f && dragF) { ev.preventDefault(); $$('.at-field').forEach(function (x) { x.classList.toggle('drag-over', x === f); }); } });
  $('[data-fields]').addEventListener('drop', function (ev) { var f = ev.target.closest('[data-fk]'); if (f && dragF) { ev.preventDefault(); moveCol(dragF, f.getAttribute('data-fk'), false); dragF = null; renderFields(); } });
  $('[data-fields]').addEventListener('dragend', function () { dragF = null; $$('.at-field').forEach(function (x) { x.classList.remove('drag-over'); }); });
  function moveCol(k, target, after) {
    if (k === target) return;
    S.order.splice(S.order.indexOf(k), 1);
    var i = S.order.indexOf(target);
    S.order.splice(after ? i + 1 : i, 0, k);
    render(); savePrefs();
  }

  // filters + quick filters + search
  $$('[data-f]').forEach(function (el) {
    var ev = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'input';
    el.addEventListener(ev, function () { S.f[el.getAttribute('data-f')] = el.value.trim(); toolbarState(); reloadSoon(); });
  });
  function setQuick(k) {
    if (k === 'Completed') { S.view = 'completed'; S.f.status = ''; }
    else if (k === '__deleted') { S.view = 'deleted'; S.f.status = ''; }
    else { S.view = 'pending'; S.f.status = k; }
    S.sel = {}; toolbarState(); reload();
  }
  $$('[data-quick]').forEach(function (b) {
    b.addEventListener('click', function () { setQuick(b.getAttribute('data-quick')); });
    b.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); setQuick(b.getAttribute('data-quick')); } });
  });
  $$('[data-act="clear"]').forEach(function (b) { b.addEventListener('click', function () { var st = S.view === 'pending' ? '' : S.f.status; S.f = {}; if (st) S.f.status = st; S.q = ''; toolbarState(); reload(); }); });
  $('[data-testid="inv-search"]').addEventListener('input', debounce(function (ev) { S.q = ev.target.value.trim(); toolbarState(); reload(); }, 300));

  // sort
  function renderSorts() {
    var opts = function (cur) { return Object.keys(SORT_LABEL).map(function (f) { return '<option value="' + f + '"' + (f === cur ? ' selected' : '') + '>' + SORT_LABEL[f] + '</option>'; }).join(''); };
    $('[data-sorts]').innerHTML = S.sort.length ? S.sort.map(function (s, i) {
      return '<div class="at-sortrow" data-si="' + i + '"><select data-sf aria-label="Sort field">' + opts(s.f) + '</select><select data-sd aria-label="Direction"><option value="asc"' + (s.d !== 'desc' ? ' selected' : '') + '>Ascending</option><option value="desc"' + (s.d === 'desc' ? ' selected' : '') + '>Descending</option></select>' +
        '<button type="button" data-sx aria-label="Remove sort"><i class="fa-solid fa-xmark"></i></button></div>';
    }).join('') : '<div class="muted" style="font-size:12px;margin-bottom:6px">Default order — ' + (S.view === 'completed' ? 'invoice number.' : 'newest Survey Completed Date first.') + '</div>';
    $('[data-sort-add]').hidden = S.sort.length >= 3;
  }
  function sortChanged() { renderSorts(); toolbarState(); savePrefs(); reload(); }
  $('[data-popbox="sort"]').addEventListener('change', function (ev) {
    var row = ev.target.closest('[data-si]'); if (!row) return;
    var i = +row.getAttribute('data-si');
    if (ev.target.matches('[data-sf]')) S.sort[i].f = ev.target.value;
    if (ev.target.matches('[data-sd]')) S.sort[i].d = ev.target.value;
    sortChanged();
  });
  $('[data-popbox="sort"]').addEventListener('click', function (ev) {
    var x = ev.target.closest('[data-sx]');
    if (x) { S.sort.splice(+x.closest('[data-si]').getAttribute('data-si'), 1); sortChanged(); }
    if (ev.target.closest('[data-sort-add]')) {
      var used = S.sort.map(function (s) { return s.f; }), f = Object.keys(SORT_LABEL).filter(function (k) { return used.indexOf(k) < 0; })[0];
      if (f) { S.sort.push({ f: f, d: 'asc' }); sortChanged(); }
    }
  });

  // header: sort, resize, drag-reorder, select all
  var thead = $('[data-head]'), resizing = null, dragK = null;
  thead.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-rz]') || resizing) return;
    if (ev.target.matches('[data-selall]')) {
      S.rows.forEach(function (r) { if (ev.target.checked) S.sel[r.id] = 1; else delete S.sel[r.id]; });
      render(); return;
    }
    var th = ev.target.closest('th[data-k]'); if (!th) return;
    var f = COLS[th.getAttribute('data-k')].sort; if (!f) return;
    var i = -1; S.sort.forEach(function (s, j) { if (s.f === f) i = j; });
    if (ev.shiftKey) {
      if (i < 0) { if (S.sort.length < 3) S.sort.push({ f: f, d: 'asc' }); }
      else if (S.sort[i].d === 'asc') S.sort[i].d = 'desc'; else S.sort.splice(i, 1);
    } else if (i === 0 && S.sort.length === 1) {
      if (S.sort[0].d === 'asc') S.sort[0].d = 'desc'; else S.sort = [];
    } else S.sort = [{ f: f, d: 'asc' }];
    toolbarState(); savePrefs(); reload();
  });
  thead.addEventListener('mousedown', function (ev) {
    var h = ev.target.closest('[data-rz]'); if (!h) return;
    ev.preventDefault(); ev.stopPropagation();
    var k = h.getAttribute('data-rz'), col = $('col[data-col="' + k + '"]');
    resizing = { k: k, x: ev.clientX, w: width(k), col: col, h: h };
    h.classList.add('on'); document.body.style.cursor = 'col-resize';
  });
  document.addEventListener('mousemove', function (ev) {
    if (!resizing) return;
    var w = Math.max(60, Math.min(600, resizing.w + ev.clientX - resizing.x));
    resizing.col.style.width = w + 'px'; S.widths[resizing.k] = w;
  });
  document.addEventListener('mouseup', function () {
    if (!resizing) return;
    resizing.h.classList.remove('on'); document.body.style.cursor = '';
    setTimeout(function () { resizing = null; }, 0); savePrefs();
  });
  thead.addEventListener('dragstart', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || resizing) { ev.preventDefault(); return; }
    dragK = th.getAttribute('data-k'); th.classList.add('dragging');
    ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', dragK);
  });
  thead.addEventListener('dragover', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || !dragK) return;
    ev.preventDefault();
    var r = th.getBoundingClientRect(), after = ev.clientX > r.left + r.width / 2;
    $$('th', thead).forEach(function (x) { x.classList.remove('drop-l', 'drop-r'); });
    th.classList.add(after ? 'drop-r' : 'drop-l');
  });
  thead.addEventListener('drop', function (ev) {
    var th = ev.target.closest('th[draggable]'); if (!th || !dragK) return;
    ev.preventDefault();
    var r = th.getBoundingClientRect();
    moveCol(dragK, th.getAttribute('data-k'), ev.clientX > r.left + r.width / 2); dragK = null;
  });
  thead.addEventListener('dragend', function () { dragK = null; $$('th', thead).forEach(function (x) { x.classList.remove('drop-l', 'drop-r', 'dragging'); }); });

  // rows: select, open panel; status / actions are handled below (shared with the panel)
  tbody.addEventListener('click', function (ev) {
    var tr = ev.target.closest('tr[data-id]'); if (!tr) return;
    var id = +tr.getAttribute('data-id');
    if (ev.target.matches('[data-sel]')) { if (ev.target.checked) S.sel[id] = 1; else delete S.sel[id]; tr.classList.toggle('sel', ev.target.checked); selInfo(); renderHead(); return; }
    if (ev.target.closest('a, button, select, td.c-no')) return;
    openPanel(id);
  });
  $('.at-quick').addEventListener('click', function (ev) { if (ev.target.closest('[data-selclear]')) { S.sel = {}; render(); } });

  // pager
  $$('[data-pg]').forEach(function (b) { b.addEventListener('click', function () { S.page = Math.min(S.pages, Math.max(1, S.page + +b.getAttribute('data-pg'))); load(); grid.scrollTop = 0; }); });
  $('[data-per]').addEventListener('change', function (ev) { S.per = +ev.target.value; savePrefs(); reload(); });

  // export
  $('[data-popbox="export"]').addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-export]'); if (!b) return;
    var scope = b.getAttribute('data-export'), ids = Object.keys(S.sel), u;
    if (scope === 'selected' && !ids.length) { toast('info', 'Tick the invoices to export first.'); return; }
    if (scope === 'filtered') u = params();
    else { u = new URLSearchParams(); u.set('view', 'all'); if (scope === 'selected') u.set('ids', ids.join(',')); }
    if (S.sort.length) u.set('sort', JSON.stringify(S.sort));
    closePop();
    window.location.href = C.export + '?' + u.toString();
  });

  // ---------- status (grid + panel) ----------
  document.addEventListener('change', function (ev) {
    var sel = ev.target.closest('[data-stsel]'); if (!sel) return;
    var id = +(sel.closest('[data-id]') || sel.closest('[data-pid]')).getAttribute(sel.closest('[data-id]') ? 'data-id' : 'data-pid');
    var d = rowById(id); if (!d) return;
    var changed = sel.value !== d.status;
    sel.className = 'at-stsel ' + CLS[sel.value] + (changed ? ' changed' : '');
    var up = sel.parentNode.querySelector('[data-act="update"]'); if (up) up.hidden = !changed;
  });
  function holderId(el) { var h = el.closest('[data-id]') || el.closest('[data-pid]'); return h ? +(h.getAttribute('data-id') || h.getAttribute('data-pid')) : 0; }
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-act]'); if (!b || b.closest('.at-bar') || b.closest('.at-pop')) return;
    var id = holderId(b), d = rowById(id), act = b.getAttribute('data-act'); if (!d) return;
    if (act === 'update') {
      var sel = b.parentNode.querySelector('[data-stsel]'), want = sel.value;
      if (b.disabled || want === d.status) return;
      b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving';
      post(fd({ a: 'status', id: d.id, status: want })).then(function (res) {
        if (!res.ok) throw new Error(res.error || 'Could not update the status.');
        toast('success', res.row.status === 'Completed' ? 'Marked Completed — moved to Completed invoices' : 'Status updated: ' + res.row.status);
        load();
      }).catch(function (e) {
        sel.value = d.status; sel.className = 'at-stsel ' + CLS[d.status]; b.hidden = true; b.disabled = false; b.innerHTML = '<i class="fa-solid fa-check"></i> Update';
        Swal.fire({ icon: 'error', title: 'Status not saved', text: e.message || 'Please try again.' });
      });
    } else if (act === 'editinv') {
      if (window.InvoiceForm) window.InvoiceForm.open(d);
    } else if (act === 'upload' || act === 'edit') {
      pdfForm(d, act === 'upload' ? 'Upload Invoice' : 'Edit Invoice').then(function (r) {
        if (r.isConfirmed && r.value && r.value.ok) { toast('success', (act === 'upload' ? 'Invoice uploaded' : (r.value.replaced ? 'Invoice replaced' : 'Invoice details saved')) + (r.value.rec ? ' (with recovery invoice)' : '')); load(); }
      });
    } else if (act === 'remove') {
      Swal.fire({ icon: 'warning', title: 'Delete this entry?', width: 480,
        html: '<b>' + esc(d.vessel || 'This vessel') + '</b>' + (d.report_no ? ' · ' + esc(d.report_no) : '') + (d.invoice_no ? ' · <b>' + esc(d.invoice_no) + '</b>' : '') + '<br>It is removed from the Invoices list.' + (d.invoice_no ? ' Its invoice number stays with it and comes back if you restore it.' : '') +
          '<br><small>Survey records are not touched. You can bring it back from <b>Deleted</b> → Restore.</small>' +
          '<input id="invDelWhy" class="swal2-input" maxlength="255" placeholder="Reason (optional), e.g. wrong entry / duplicate" data-testid="inv-remove-reason" style="font-size:14px">',
        showCancelButton: true, confirmButtonText: 'Delete entry', confirmButtonColor: '#DC2626', showLoaderOnConfirm: true,
        preConfirm: function () { return post(fd({ a: 'remove', id: d.id, reason: document.getElementById('invDelWhy').value })).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Could not delete.'); return false; } return res; }); },
        allowOutsideClick: function () { return !Swal.isLoading(); }
      }).then(function (r) { if (r.isConfirmed && r.value && r.value.ok) { if (S.openId === d.id) closePanel(); toast('success', 'Entry deleted — see Deleted to restore it'); load(); } });
    } else if (act === 'restore') {
      post(fd({ a: 'restore', id: d.id })).then(function (res) {
        if (!res.ok) { Swal.fire({ icon: 'error', title: 'Not restored', text: res.error || 'Please try again.' }); return; }
        if (S.openId === d.id) closePanel();
        toast('success', 'Entry restored to pending invoices'); load();
      });
    } else if (act === 'delete') {
      var html = S.view === 'completed'
        ? 'The invoice PDF' + (d.has_rec ? 's (vessel + recovery invoice)' : '') + ' and saved details will be removed and the vessel goes back to <b>pending invoices</b> to be made again (same invoice number).<br><small>Survey records are not touched.</small>'
        : 'The uploaded PDF <b>' + esc(d.file_name) + '</b> and its invoice details will be removed.<br><small>The vessel stays in Invoices with its status and invoice number; survey records are not touched.</small>';
      Swal.fire({ icon: 'warning', title: 'Delete this invoice?', html: html, showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#DC2626', showLoaderOnConfirm: true,
        preConfirm: function () { return post(fd({ a: 'delete', id: d.id })).then(function (res) { if (!res.ok) { Swal.showValidationMessage(res.error || 'Could not delete.'); return false; } return res; }); },
        allowOutsideClick: function () { return !Swal.isLoading(); }
      }).then(function (r) { if (r.isConfirmed && r.value && r.value.ok) { toast('success', r.value.moved ? 'Invoice deleted — back in pending invoices' : 'Invoice deleted'); load(); } });
    }
  });
  function pdfForm(d, title) {
    // The vessel invoice PDF and details, and optionally its recovery invoice (number = invoice
    // number + B unless typed differently, date, amount, PDF) — saved in that order.
    var hadRec = !!(d.has_rec || d.has_rec_entry), recAuto = !d.rec_no_custom, vesselDone = false;
    var g = function (id) { return document.getElementById(id); };
    var usual = function () { var v = g('invNo').value.replace(/\s+/g, '').toUpperCase(); return v ? v + 'B' : ''; };
    function recSync() {
      g('invRecBox').hidden = !g('invRecOn').checked;
      if (recAuto) g('invRecNo').value = usual();
      g('invRecReset').hidden = recAuto;
      g('invRecHint').textContent = recAuto ? 'The invoice number + B — type here to use a different number.' : 'Typed number — it must not be used by another invoice.';
    }
    return Swal.fire({
      title: title, width: 560, showCancelButton: true, confirmButtonText: d.has_file ? 'Save' : 'Upload', confirmButtonColor: '#6C4CF1', showLoaderOnConfirm: true,
      html: '<form class="inv-form" onsubmit="return false">' +
        '<label>Invoice PDF' + (d.has_file ? ' (choose a new file to replace ' + esc(d.file_name) + ')' : '') + '</label><input type="file" id="invFile" accept="application/pdf,.pdf" data-testid="inv-file-input"><small>PDF only, up to ' + C.maxMb + ' MB.</small>' +
        '<div class="two"><div><label>Invoice No.</label><input id="invNo" value="' + esc(d.invoice_no) + '" data-testid="inv-no-input"></div><div><label>Invoice Date</label><input type="date" id="invDate" value="' + esc(d.invoice_date) + '"></div></div>' +
        '<label>Amount (USD)</label><input id="invAmt" inputmode="decimal" value="' + esc(d.invoice_amount) + '" data-testid="inv-amount-input">' +
        '<label>Notes</label><textarea id="invNotes" rows="2">' + esc(d.invoice_notes) + '</textarea>' +
        '<div class="inv-rec"><label class="chk"><input type="checkbox" id="invRecOn" data-testid="inv-rec-on"' + (hadRec ? ' checked disabled' : '') + '> Recovery invoice too <span>(optional)</span></label>' +
        (hadRec ? '<small>To remove the recovery invoice use Edit Invoice.</small>' : '') +
        '<div id="invRecBox" hidden>' +
          '<label>Recovery Invoice No. <button type="button" class="lnk" id="invRecReset" data-testid="inv-rec-reset" hidden>Use invoice number + B</button></label><input id="invRecNo" maxlength="64" value="' + esc(d.rec_no || '') + '" data-testid="inv-rec-no"><small id="invRecHint"></small>' +
          '<div class="two"><div><label>Recovery Date</label><input type="date" id="invRecDate" value="' + esc(d.rec_date || d.invoice_date || '') + '" data-testid="inv-rec-date"></div>' +
          '<div><label>Recovery Amount (USD)</label><input id="invRecAmt" inputmode="decimal" value="' + esc(d.rec_amount || '') + '" data-testid="inv-rec-amount"></div></div>' +
          '<label>Recovery invoice PDF' + (d.has_rec ? ' (choose a new file to replace ' + esc(d.rec_file_name || 'recovery-invoice.pdf') + ')' : ' (optional)') + '</label><input type="file" id="invRecFile" accept="application/pdf,.pdf" data-testid="inv-rec-file">' +
        '</div></div></form>',
      didOpen: function () {
        g('invRecOn').addEventListener('change', function () {
          if (g('invRecOn').checked && !g('invRecDate').value) g('invRecDate').value = g('invDate').value || new Date().toISOString().slice(0, 10);
          recSync();
        });
        g('invNo').addEventListener('input', function () { if (recAuto) recSync(); });
        g('invRecNo').addEventListener('input', function () {
          recAuto = g('invRecNo').value.replace(/\s+/g, '').toUpperCase() === usual() && usual() !== '';
          g('invRecReset').hidden = recAuto;
          g('invRecHint').textContent = recAuto ? 'The invoice number + B.' : 'Typed number — it must not be used by another invoice.';
        });
        g('invRecReset').addEventListener('click', function () { recAuto = true; recSync(); });
        recSync();
      },
      preConfirm: function () {
        var f = g('invFile').files[0], rec = g('invRecOn').checked, rf = rec ? g('invRecFile').files[0] : null;
        if (!d.has_file && !f && !vesselDone && !rf) { Swal.showValidationMessage('Choose the invoice PDF' + (rec ? ' (or the recovery invoice PDF)' : '') + '.'); return false; }
        var bad = function (x) { return x && !/\.pdf$/i.test(x.name) ? 'Only PDF files can be uploaded.' : x && x.size > C.maxMb * 1048576 ? 'The PDF must be smaller than ' + C.maxMb + ' MB.' : ''; };
        if (bad(f) || bad(rf)) { Swal.showValidationMessage(bad(f) || bad(rf)); return false; }
        if (rec) {
          var rn = g('invRecNo').value.trim(), ra = g('invRecAmt').value.replace(/[,\s$]/g, '');
          if (!rn) { Swal.showValidationMessage('Enter the recovery invoice number (or the invoice number first).'); return false; }
          if (rn.replace(/\s+/g, '').toUpperCase() === g('invNo').value.replace(/\s+/g, '').toUpperCase()) { Swal.showValidationMessage('The recovery invoice number must be different from the invoice number.'); return false; }
          if (!g('invRecDate').value) { Swal.showValidationMessage('Choose the recovery invoice date.'); return false; }
          if (ra !== '' && (isNaN(+ra) || +ra < 0)) { Swal.showValidationMessage('Recovery amount must be a number of 0 or more.'); return false; }
        }
        var vessel = vesselDone ? Promise.resolve({ ok: true }) : (function () {
          var data = fd({ a: f ? 'upload' : 'meta', id: d.id, invoice_no: g('invNo').value, invoice_date: g('invDate').value,
            invoice_amount: g('invAmt').value, invoice_notes: g('invNotes').value });
          if (f) data.append('invoice_pdf', f);
          return post(data);
        })();
        return vessel.then(function (res) {
          if (!res.ok) { Swal.showValidationMessage(res.error || 'Could not save.'); return false; }
          vesselDone = true; // a retry (recovery error) does not upload the invoice PDF again
          if (!rec) return res;
          var data = fd({ a: 'recovery', id: d.id, rec_on: '1', rec_invoice_no: recAuto ? '' : g('invRecNo').value.trim(),
            rec_invoice_date: g('invRecDate').value, rec_invoice_amount: g('invRecAmt').value.replace(/[,\s$]/g, '') });
          if (rf) data.append('rec_pdf', rf);
          return post(data).then(function (r2) {
            if (!r2.ok) { Swal.showValidationMessage('Invoice saved, but the recovery invoice was not: ' + (r2.error || 'please try again.')); load(); return false; }
            r2.replaced = res.replaced || r2.replaced; r2.rec = true;
            return r2;
          });
        }).catch(function () { Swal.showValidationMessage('Network error — please try again.'); return false; });
      },
      allowOutsideClick: function () { return !Swal.isLoading(); }
    });
  }

  // ---------- record panel ----------
  var panel = $('[data-panel]'), body = $('[data-p-body]'), scrim = $('[data-scrim]');
  function openPanel(id) {
    var d = rowById(id); if (!d) return;
    S.openId = id; markOpen();
    panel.classList.add('open'); panel.setAttribute('aria-hidden', 'false'); scrim.hidden = false;
    renderPanel(d);
  }
  function closePanel() { S.openId = null; panel.classList.remove('open'); panel.setAttribute('aria-hidden', 'true'); scrim.hidden = true; markOpen(); }
  scrim.addEventListener('click', closePanel);
  $('[data-close]').addEventListener('click', closePanel);
  function renderPanel(d) {
    $('[data-p-kind]').textContent = d.vessel || 'Invoice';
    $('[data-p-title]').textContent = d.invoice_no || 'Invoice not generated yet';
    $('[data-p-status]').innerHTML = '<span class="at-pill ' + CLS[d.status] + '">' + esc(d.status) + '</span>';
    var kv = function (k, v) { return '<div>' + k + '</div><div>' + v + '</div>'; };
    var h = '<div data-pid="' + d.id + '">';
    if (d.deleted) {
      $('[data-p-status]').innerHTML = '<span class="at-pill deleted">Deleted</span>';
      h += '<div class="at-del-note" data-testid="inv-p-deleted"><i class="fa-solid fa-trash-can"></i> Deleted ' + esc(d.deleted_at) + (d.deleted_by ? ' by ' + esc(d.deleted_by) : '') + (d.delete_reason ? '<br>Reason: ' + esc(d.delete_reason) : '') + '</div>';
    }
    if (d.recovery_pending) h += '<div class="at-cancel-note" style="color:#B45309;background:#FFFBEB;border-color:#FDE68A"><i class="fa-solid fa-droplet"></i> Recovery invoice pending — the invoice is completed once it is downloaded.</div>';
    h += '<div class="at-sec"><div class="at-sec-h">Vessel &amp; survey</div><div class="at-kv">' +
      kv('Vessel', d.vessel ? '<b>' + esc(d.vessel) + '</b>' : muted()) + kv('Client', d.client ? esc(d.client) : muted()) + kv('Survey Type', d.type ? '<span class="at-tag">' + esc(d.type) + '</span>' : muted()) +
      kv('Port', d.port ? esc(d.port) : muted()) + kv('Report No', d.report_no ? esc(d.report_no) : muted()) + kv('Survey Completed', d.survey_completed_ymd ? fdate(d.survey_completed_ymd) : muted()) + '</div></div>';
    h += '<div class="at-sec"><div class="at-sec-h">Invoice</div><div class="at-kv">' +
      kv('Invoice Number', d.invoice_no ? '<b>' + esc(d.invoice_no) + '</b>' + (d.has_rec || d.has_rec_entry ? ' <span class="at-kind">+ Recovery ' + esc(d.rec_no || '') + '</span>' : '') : muted('Assigned on the first download')) +
      (d.has_rec_entry ? kv('Recovery Invoice', '<b>' + esc(d.rec_no || '—') + '</b>' + (d.rec_date ? ' · ' + fdate(d.rec_date) : '') + (d.rec_amount !== '' && d.rec_amount != null ? ' · ' + usd(+d.rec_amount) : '')) : '') +
      kv('Invoice Date', d.invoice_date ? fdate(d.invoice_date) : muted()) + kv('Invoice Amount', d.amount === null || d.amount === undefined ? muted() : usd(d.amount)) +
      kv('Notes', d.invoice_notes ? esc(d.invoice_notes) : muted()) +
      kv('Status', S.view === 'completed' ? '<span class="at-pill done">Completed</span>' : statusCell(d).replace('data-testid="inv-status-' + d.id + '"', 'data-testid="inv-p-status"').replace('data-testid="inv-update-' + d.id + '"', 'data-testid="inv-p-update"')) +
      kv('Documents', docCell(d).replace(/data-testid="inv-/g, 'data-testid="inv-p-')) + '</div>';
    h += '<div class="at-p-actions at-act">';
    if (d.deleted) {
      h += '<button type="button" class="at-btn" data-act="restore" data-testid="inv-p-restore"><i class="fa-solid fa-rotate-left"></i> Restore entry</button></div></div>';
      body.innerHTML = h + '</div>';
      return;
    }
    if (S.view === 'completed') {
      h += '<a class="at-btn pri" href="invoice_pdf.php?id=' + d.id + '" data-testid="inv-p-modify"><i class="fa-solid fa-pen-to-square"></i> Modify</a>';
    } else {
      h += '<a class="at-btn pri" href="invoice_pdf.php?id=' + d.id + (d.recovery_pending ? '&type=recovery' : '') + '" data-testid="inv-p-generate"><i class="fa-solid fa-file-invoice-dollar"></i> ' + (d.recovery_pending ? 'Recovery Invoice' : 'Generate Invoice') + '</a>';
      h += '<button type="button" class="at-btn" data-act="' + (d.has_file ? 'edit' : 'upload') + '" data-testid="inv-p-upload"><i class="fa-solid ' + (d.has_file ? 'fa-pen' : 'fa-upload') + '"></i> ' + (d.has_file ? 'Edit / Replace PDF' : 'Upload Invoice') + '</button>';
    }
    h += '<button type="button" class="at-btn" data-act="editinv" data-testid="inv-p-editinv"><i class="fa-solid fa-pen-to-square"></i> Edit Invoice</button>';
    if (d.has_file || S.view === 'completed') h += '<button type="button" class="at-btn danger" data-act="delete" data-testid="inv-p-delete"><i class="fa-solid fa-trash"></i> Delete</button>';
    if (d.can_remove) h += '<button type="button" class="at-btn danger" data-act="remove" data-testid="inv-p-remove"><i class="fa-solid fa-trash-can"></i> Delete entry</button>';
    h += '</div></div>';
    h += '<div class="at-sec"><div class="at-sec-h">Payments</div><a class="at-btn" href="invoices_airtable.php" data-testid="inv-p-airtable"><i class="fa-solid fa-table-cells"></i> Track payments in Invoice Airtable</a></div>';
    body.innerHTML = h + '</div>';
  }

  // Add Invoice / Edit (assets/js/invoice-form.js): show the saved entry in the list straight away
  window.INV_GRID = {
    saved: function (row, added) {
      var done = row.status === 'Completed';
      if (S.view === 'deleted' || (done && S.view !== 'completed') || (!done && S.view === 'completed') || (S.f.status && S.f.status !== row.status)) {
        S.view = done ? 'completed' : 'pending'; S.f.status = '';
      }
      if (added) { S.q = ''; S.page = 1; }
      toolbarState();
      var show = function () {
        var tr = tbody.querySelector('tr[data-id="' + row.id + '"]');
        if (!tr && !S.q && (row.invoice_no || row.vessel)) { S.q = row.invoice_no || row.vessel; toolbarState(); return load().then(show); } // on another page: search for it
        if (tr) { tr.scrollIntoView({ block: 'nearest' }); tr.classList.add('flash'); setTimeout(function () { tr.classList.remove('flash'); }, 1800); }
      };
      load().then(show);
    }
  };

  // go
  toolbarState();
  load();
})();
