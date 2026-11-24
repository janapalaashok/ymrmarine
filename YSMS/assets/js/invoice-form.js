/* Add Invoice / Edit Invoice (invoices.php). One form for both:
     InvoiceForm.open()      → Add: manual invoice, next number in the series filled in
     InvoiceForm.open(row)   → Edit: the row's details filled in; saves to the same entry
   Server: ajax/invoices.php (a=lists, next, checkno, surveys, create, update, upload).
   The grid (assets/js/invoices-grid.js) exposes window.INV_GRID.reload(). */
(function () {
  'use strict';
  var C = window.INV_CONFIG || {};
  var box = document.querySelector('[data-ivf]'); if (!box) return;
  var form = box.querySelector('[data-ivf-form]');
  var $ = function (s) { return box.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(box.querySelectorAll(s)); };
  var el = {
    title: $('[data-ivf-title]'), sub: $('[data-ivf-sub]'), no: $('#ivfNo'), noBox: $('[data-ivf-nobox]'), badge: $('[data-ivf-nobadge]'), hint: $('[data-ivf-nohint]'),
    noAuto: $('[data-ivf-noauto]'), date: $('#ivfDate'), status: $('#ivfStatus'), amt: $('#ivfAmt'), notes: $('#ivfNotes'),
    vessel: $('#ivfVessel'), client: $('#ivfClient'), report: $('#ivfReport'), port: $('#ivfPort'), ports: $('#ivfPorts'), scd: $('#ivfScd'),
    types: $('[data-ivf-types]'), linkRow: $('[data-ivf-linkrow]'), linkQ: $('#ivfLinkQ'), res: $('[data-ivf-res]'), linked: $('[data-ivf-linked]'),
    linkedTxt: $('[data-ivf-linkedtxt]'), file: $('#ivfFile'), fileCur: $('[data-ivf-filecur]'), save: $('[data-ivf-save]'), reqDate: $('[data-req-date]'),
    recOn: $('#ivfRecOn'), rec: $('[data-ivf-rec]'), recNo: $('#ivfRecNo'), recHint: $('[data-ivf-rechint]'), recReset: $('[data-ivf-recreset]'),
    recDate: $('#ivfRecDate'), recAmt: $('#ivfRecAmt'), recFile: $('#ivfRecFile'), recFileCur: $('[data-ivf-recfilecur]')
  };
  var lists = null, listsP = null;
  var st = { mode: 'add', row: null, auto: true, next: null, survey: null, saving: false, rid: '', lastFocus: null, recAuto: true };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(icon, title) { if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 3200, timerProgressBar: true }); }
  function today() { var d = new Date(); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
  function rid() { var a = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function getJson(q) { return fetch(C.api + '?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function post(data) {
    data.append('csrf_token', C.csrf);
    return fetch(C.api, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-CSRF-Token': C.csrf } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ').' }; }); });
  }
  function debounce(fn, ms) { var t; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }

  // ---------- lists (clients, ports, survey types) ----------
  function loadLists() {
    if (lists) return Promise.resolve(lists);
    if (!listsP) listsP = getJson('a=lists').then(function (r) {
      if (!r.ok) throw new Error(r.error || 'Could not load the form.');
      lists = r;
      el.client.innerHTML = '<option value="">Choose client…</option>' + r.clients.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
      lists.portByLabel = {}; lists.portLabel = {};
      el.ports.innerHTML = r.ports.map(function (p) {
        var lab = p.name + (p.country ? ', ' + p.country : '');
        if (lists.portByLabel[lab.toLowerCase()]) lab += ' (#' + p.id + ')';
        lists.portByLabel[lab.toLowerCase()] = p.id; lists.portLabel[p.id] = lab;
        return '<option value="' + esc(lab) + '"></option>';
      }).join('');
      el.types.innerHTML = r.types.map(function (t) {
        return '<label data-testid="ivf-type-' + t.id + '"><input type="checkbox" value="' + t.id + '"> ' + esc(t.name) + '</label>';
      }).join('') || '<span class="ivf-small">No survey types.</span>';
      return lists;
    }).catch(function (e) { listsP = null; throw e; });
    return listsP;
  }
  el.types.addEventListener('change', function (ev) { var l = ev.target.closest('label'); if (l) l.classList.toggle('on', ev.target.checked); });
  function setTypes(ids) {
    ids = (ids || []).map(Number);
    $$('[data-ivf-types] input').forEach(function (i) { i.checked = ids.indexOf(+i.value) >= 0; i.closest('label').classList.toggle('on', i.checked); });
  }
  function typeIds() { return $$('[data-ivf-types] input:checked').map(function (i) { return i.value; }); }
  function portId() {
    var v = el.port.value.trim(); if (!v) return '';
    var id = lists && lists.portByLabel[v.toLowerCase()];
    if (id) return id;
    // typed only the port name: accept it when exactly one port has that name
    var hit = (lists ? lists.ports : []).filter(function (p) { return p.name.toLowerCase() === v.toLowerCase(); });
    return hit.length === 1 ? hit[0].id : null;
  }
  function setPort(id) { el.port.value = id && lists && lists.portLabel[id] ? lists.portLabel[id] : ''; }

  // ---------- errors ----------
  function clearErrors() { $$('.ivf-fl.err, .ivf-no.err').forEach(function (x) { x.classList.remove('err'); }); $$('[data-e]').forEach(function (x) { x.textContent = ''; }); }
  function fieldError(k, msg) {
    var e = $('[data-e="' + k + '"]'); if (!e) return false;
    e.textContent = msg; (e.closest('.ivf-fl') || e.closest('.ivf-no')).classList.add('err');
    return true;
  }
  function showErrors(errs, fallback) {
    var first = null;
    Object.keys(errs || {}).forEach(function (k) { var m = typeof errs[k] === 'string' ? errs[k] : fallback; if (fieldError(k, m) && !first) first = k; });
    if (!first && fallback) Swal.fire({ icon: 'error', title: 'Not saved', text: fallback });
    var f = first && $('[data-e="' + first + '"]');
    if (f) { var w = f.closest('.ivf-fl') || f.closest('.ivf-no'); var inp = w.querySelector('input, select, textarea'); w.scrollIntoView({ block: 'center', behavior: 'smooth' }); if (inp) setTimeout(function () { inp.focus({ preventScroll: true }); }, 250); }
  }

  // ---------- invoice number ----------
  function noState() {
    // the invoice number is typed by hand (no automatic series)
    el.badge.textContent = el.no.value.trim() ? 'Manual' : 'Not assigned';
    el.badge.className = 'ivf-badge' + (el.no.value.trim() ? ' man' : ' none');
    el.noAuto.hidden = true;
  }
  function fillAuto() {
    if (st.next === null) return;
    var code = st.next.code, cid = el.client.value;
    el.no.value = cid ? 'YMR-' + code + '-' + st.next.n : String(st.next.n);
    el.hint.className = 'hint';
    el.hint.textContent = cid ? 'Next number in the series. Saving takes the next free number at that moment.' : 'Next number: ' + st.next.n + ' — the client code is added when you choose the client.';
    noState();
  }
  function fetchNext() {
    var cid = el.client.value || 0;
    return getJson('a=next&client=' + encodeURIComponent(cid)).then(function (r) {
      if (!r.ok) throw new Error(r.error || 'Could not get the next invoice number.');
      st.next = r;
      if (st.mode === 'add' && st.auto) fillAuto();
      return r;
    });
  }
  el.no.addEventListener('input', function () {
    if (st.mode === 'add') st.auto = false;
    el.noBox.classList.remove('err'); el.hint.className = 'hint'; el.hint.textContent = 'Typed number — it is checked against every existing invoice.';
    noState(); checkNoSoon();
  });
  el.noAuto.addEventListener('click', function () {
    if (st.mode === 'add') { st.auto = true; el.noBox.classList.remove('err'); fetchNext(); }
    else fetchNext().then(function (r) { el.no.value = (el.client.value ? 'YMR-' + r.code + '-' : '') + r.n; el.hint.className = 'hint'; el.hint.textContent = 'Next number in the series.'; noState(); checkNo(); });
  });
  function checkNo() {
    var v = el.no.value.trim();
    if (!v || (st.mode === 'add' && st.auto) || (st.row && v.toUpperCase() === String(st.row.invoice_no || '').toUpperCase())) return;
    getJson('a=checkno&no=' + encodeURIComponent(v) + '&client=' + encodeURIComponent(el.client.value || 0) + '&id=' + (st.row ? st.row.id : 0)).then(function (r) {
      if (v !== el.no.value.trim()) return;
      if (!r.ok) { el.hint.className = 'hint bad'; el.hint.textContent = r.error || 'Not a valid invoice number.'; return; }
      if (r.taken) { el.hint.className = 'hint bad'; el.hint.textContent = '✕ ' + r.message; }
      else { el.hint.className = 'hint ok'; el.hint.textContent = '✓ ' + r.number + ' is free.' + (r.n && st.next && r.n > st.next.n ? ' Automatic numbering will continue from ' + (r.n + 1) + '.' : ''); }
    }).catch(function () {});
  }
  var checkNoSoon = debounce(checkNo, 450);
  el.client.addEventListener('change', function () {
    if (st.mode === 'add' && st.auto) fetchNext(); else checkNoSoon();
  });

  // ---------- recovery invoice (optional) ----------
  // Its number is the invoice number + "B" until it is typed differently (then it stays as typed).
  function recUsual() { var v = el.no.value.replace(/\s+/g, '').toUpperCase(); return v ? v + 'B' : ''; }
  function recSync() {
    el.rec.hidden = !el.recOn.checked;
    if (st.recAuto) el.recNo.value = recUsual();
    el.recReset.hidden = st.recAuto;
    el.recHint.textContent = st.recAuto
      ? (el.recNo.value ? 'The invoice number + B. ' + (/^\d+B$/.test(el.recNo.value) ? 'The client code is added when saving. ' : '') + 'Type here to use a different number.' : 'Fills in as the invoice number + B — or type the number.')
      : 'Typed number — it must not be used by another invoice.';
  }
  el.recOn.addEventListener('change', function () {
    if (el.recOn.checked && !el.recDate.value) el.recDate.value = el.date.value || today();
    recSync();
    if (el.recOn.checked) setTimeout(function () { el.recNo.focus(); }, 30);
  });
  el.recNo.addEventListener('input', function () {
    st.recAuto = el.recNo.value.replace(/\s+/g, '').toUpperCase() === recUsual() && recUsual() !== '';
    el.recReset.hidden = st.recAuto;
    el.recHint.textContent = st.recAuto ? 'The invoice number + B.' : 'Typed number — it must not be used by another invoice.';
  });
  el.recReset.addEventListener('click', function () { st.recAuto = true; recSync(); });
  el.no.addEventListener('input', function () { if (st.recAuto) recSync(); });

  // ---------- link to a survey (Add only) ----------
  var searchSeq = 0;
  var search = debounce(function () {
    var q = el.linkQ.value.trim(), my = ++searchSeq;
    if (q.length < 2) { el.res.hidden = true; return; }
    getJson('a=surveys&q=' + encodeURIComponent(q)).then(function (r) {
      if (my !== searchSeq) return;
      st.found = r.rows || [];
      el.res.innerHTML = st.found.length ? st.found.map(function (s, i) {
        return '<button type="button" data-pick="' + i + '" data-testid="ivf-pick-' + s.id + '"><b>' + esc(s.vessel || '—') + '</b>' + (s.report_no ? ' · ' + esc(s.report_no) : '') +
          '<small>' + esc([s.client, s.port, s.type, s.status].filter(Boolean).join(' · ')) + '</small></button>';
      }).join('') : '<div class="none">No survey without an Invoices entry matches.</div>';
      el.res.hidden = false;
    }).catch(function () {});
  }, 300);
  el.linkQ.addEventListener('input', search);
  el.res.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-pick]'); if (!b) return;
    var s = st.found[+b.getAttribute('data-pick')]; if (!s) return;
    st.survey = s;
    el.vessel.value = s.vessel; el.report.value = s.report_no; el.scd.value = s.survey_completed_ymd || '';
    if (s.client_id) el.client.value = String(s.client_id);
    setPort(s.port_id); setTypes(s.type_ids);
    el.linkedTxt.textContent = 'Linked to ' + s.vessel + (s.report_no ? ' · ' + s.report_no : '') + ' (' + s.status + ')';
    el.linked.hidden = false; el.linkQ.parentNode.hidden = true; el.res.hidden = true; el.linkQ.value = '';
    if (st.auto) fetchNext(); else checkNoSoon();
  });
  $('[data-ivf-unlink]').addEventListener('click', function () { st.survey = null; el.linked.hidden = true; el.linkQ.parentNode.hidden = false; el.linkQ.focus(); });
  document.addEventListener('click', function (ev) { if (!el.res.hidden && !ev.target.closest('.ivf-link')) el.res.hidden = true; });

  // ---------- open / close ----------
  function open(row) {
    if (st.saving) return;
    st.lastFocus = document.activeElement;
    st.mode = row ? 'edit' : 'add'; st.row = row || null; st.auto = false; st.survey = null; st.next = null; st.rid = rid();
    form.reset(); clearErrors(); setTypes([]);
    el.title.textContent = row ? 'Edit Invoice' : 'Add Invoice';
    el.sub.textContent = row ? (row.manual ? 'Manual entry' : 'From Pending Reports') + (row.invoice_no ? ' · ' + row.invoice_no : '') : 'Manual entry — not linked to Pending Reports';
    el.save.querySelector('span').textContent = row ? 'Update Invoice' : 'Save Invoice';
    el.linkRow.hidden = !!row; el.linked.hidden = true; el.linkQ.parentNode.hidden = false; el.res.hidden = true;
    el.reqDate.hidden = !!row;
    el.fileCur.hidden = !(row && row.has_file);
    st.recAuto = !(row && row.rec_no_custom);
    el.recOn.checked = !!(row && (row.has_rec_entry || row.has_rec));
    el.recOn.disabled = !!(row && row.has_rec); // a stored recovery PDF: the recovery invoice stays
    el.recDate.value = row && row.rec_date ? row.rec_date : ''; el.recAmt.value = row && row.rec_amount ? row.rec_amount : '';
    el.recFileCur.hidden = !(row && row.has_rec);
    if (row && row.has_rec) el.recFileCur.innerHTML = '<i class="fa-solid fa-file-pdf" style="color:#dc2626"></i> ' + esc(row.rec_file_name || 'recovery-invoice.pdf') + ' — choose a file only to replace it.';
    if (row && row.has_file) el.fileCur.innerHTML = '<i class="fa-solid fa-file-pdf" style="color:#dc2626"></i> ' + esc(row.file_name || 'invoice.pdf') + ' — choose a file only to replace it.';
    el.no.value = ''; el.hint.className = 'hint'; el.hint.textContent = row ? '' : 'Getting the next number…';
    noState();
    box.hidden = false; document.body.style.overflow = 'hidden'; document.body.classList.add('ivf-open');
    el.save.disabled = true;
    loadLists().then(function () {
      if (row) {
        el.no.value = row.invoice_no || '';
        el.hint.textContent = row.invoice_no ? 'You can change the number; it must not be used by another invoice.' + (row.has_file ? ' A stored PDF keeps the number it was made with until you generate or upload it again.' : '') : 'No number yet — it is given on the first Generate Invoice download, or type one.';
        el.date.value = row.invoice_date || ''; el.status.value = row.status; el.amt.value = row.invoice_amount || ''; el.notes.value = row.invoice_notes || '';
        el.vessel.value = row.vessel || ''; el.report.value = row.report_no || ''; el.scd.value = row.survey_completed_ymd || '';
        el.client.value = row.client_id ? String(row.client_id) : ''; setPort(row.port_id); setTypes(row.type_ids);
        var ms = row.manual_service || {}, keys = { service_desc: 'desc', service_rate: 'rate', service_qty: 'qty', travel_cost: 'travel', launch_cost: 'launch' };
        svc().forEach(function (x) { var v = ms[keys[x[0]]]; if (v !== undefined && v !== '') x[1].value = v; });
        if (row.client_id && !el.client.value) fieldError('client_id', 'The client of this entry no longer exists — choose one.');
        noState();
        if (!st.recAuto) el.recNo.value = row.rec_no || '';
        recSync();
      } else {
        el.date.value = today(); el.status.value = (C.statuses || [])[0] || '';
        el.no.value = ''; el.hint.className = 'hint'; el.hint.textContent = 'Type the invoice number (e.g. YMR-BB-962, or only 962 — the client code is added).'; noState();
        recSync();
      }
      el.save.disabled = false;
      setTimeout(function () { if (!box.hidden && !form.contains(document.activeElement)) (row ? el.amt : el.vessel).focus(); }, 60); // never steal focus from a field already in use
    }).catch(function (e) { close(); Swal.fire({ icon: 'error', title: 'Could not open the form', text: e.message || 'Please try again.' }); });
  }
  function close() {
    if (st.saving) return;
    box.hidden = true; document.body.style.overflow = ''; document.body.classList.remove('ivf-open');
    if (st.lastFocus && st.lastFocus.focus) try { st.lastFocus.focus(); } catch (e) {}
  }
  $$('[data-ivf-close]').forEach(function (b) { b.addEventListener('click', close); });
  box.addEventListener('mousedown', function (ev) { if (ev.target === box) close(); });
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !box.hidden && !(window.Swal && Swal.isVisible())) { ev.stopPropagation(); close(); } }, true);
  Array.prototype.forEach.call(document.querySelectorAll('[data-ivf-add]'), function (b) { b.addEventListener('click', function () { open(null); }); });
  form.addEventListener('input', function (ev) { var w = ev.target.closest('.ivf-fl'); if (w) w.classList.remove('err'); });
  form.addEventListener('change', function (ev) { var w = ev.target.closest('.ivf-fl'); if (w) w.classList.remove('err'); });

  // ---------- save ----------
  function validate() {
    clearErrors();
    var e = {};
    if (!el.no.value.trim() && st.mode === 'add') e.invoice_no = 'Enter the invoice number.';
    if (st.mode === 'edit' && st.row && st.row.invoice_no && !el.no.value.trim()) e.invoice_no = 'The invoice number cannot be removed.';
    if (!el.date.value && st.mode === 'add') e.invoice_date = 'Choose the invoice date.';
    if (!el.status.value) e.invoice_status = 'Choose the invoice status.';
    var a = el.amt.value.replace(/[,\s$]/g, '');
    if (a !== '' && (isNaN(+a) || +a < 0)) e.invoice_amount = 'Amount must be a number of 0 or more.';
    if (!el.vessel.value.trim()) e.vessel_name = 'Enter the vessel name.';
    if (!el.client.value) e.client_id = 'Choose the client.';
    if (portId() === null) e.port_id = 'Choose a port from the list (or leave it empty).';
    var f = el.file.files[0];
    if (f && !/\.pdf$/i.test(f.name)) e.file = 'Only PDF files can be attached.';
    else if (f && f.size > (C.maxMb || 20) * 1048576) e.file = 'The PDF must be smaller than ' + C.maxMb + ' MB.';
    if (el.recOn.checked) {
      if (!el.recNo.value.trim()) e.rec_invoice_no = 'Enter the recovery invoice number (or the invoice number first).';
      else if (!/^[A-Za-z0-9][A-Za-z0-9\-\/\s]*[A-Za-z0-9]$/.test(el.recNo.value.trim())) e.rec_invoice_no = 'Letters, digits and - only (e.g. YMR-BB-968B).';
      else if (el.no.value.trim() && el.recNo.value.replace(/\s+/g, '').toUpperCase() === el.no.value.replace(/\s+/g, '').toUpperCase()) e.rec_invoice_no = 'The recovery invoice number must be different from the invoice number.';
      if (!el.recDate.value) e.rec_invoice_date = 'Choose the recovery invoice date.';
      var ra = el.recAmt.value.replace(/[,\s$]/g, '');
      if (ra !== '' && (isNaN(+ra) || +ra < 0)) e.rec_invoice_amount = 'Amount must be a number of 0 or more.';
      var rf = el.recFile.files[0];
      if (rf && !/\.pdf$/i.test(rf.name)) e.rec_file = 'Only PDF files can be attached.';
      else if (rf && rf.size > (C.maxMb || 20) * 1048576) e.rec_file = 'The PDF must be smaller than ' + C.maxMb + ' MB.';
    }
    return e;
  }
  function payload(extra) {
    var d = new FormData();
    var v = {
      a: st.mode === 'add' ? 'create' : 'update', invoice_no: el.no.value.trim(), invoice_date: el.date.value, invoice_status: el.status.value,
      invoice_amount: el.amt.value.replace(/[,\s$]/g, ''), invoice_notes: el.notes.value, vessel_name: el.vessel.value, report_number: el.report.value,
      client_id: el.client.value, port_id: portId() || '', survey_type_ids: typeIds().join(','), survey_completed_date: el.scd.value,
      rec_on: el.recOn.checked ? '1' : '0'
    };
    svc().forEach(function (x) { v[x[0]] = x[1] ? x[1].value : ''; }); // Invoice Services (kept with the invoice for the PDF)
    if (el.recOn.checked) {
      v.rec_invoice_no = st.recAuto ? '' : el.recNo.value.trim(); // empty = the invoice number + B
      v.rec_invoice_date = el.recDate.value; v.rec_invoice_amount = el.recAmt.value.replace(/[,\s$]/g, '');
    }
    if (st.mode === 'add') { v.no_auto = st.auto ? '1' : '0'; v.shown_no = el.no.value.trim(); v.request_id = st.rid; if (st.survey) v.survey_id = st.survey.id; }
    else v.id = st.row.id;
    Object.keys(extra || {}).forEach(function (k) { v[k] = extra[k]; });
    Object.keys(v).forEach(function (k) { d.append(k, v[k]); });
    return d;
  }
  function svc() {
    return [['service_desc', $('[data-ivf-service-desc]')], ['service_rate', $('[data-ivf-service-rate]')], ['service_qty', $('[data-ivf-service-qty]')],
      ['travel_cost', $('[data-ivf-travel]')], ['launch_cost', $('[data-ivf-launch]')]].filter(function (x) { return !!x[1]; });
  }
  function busy(on) {
    st.saving = on; el.save.disabled = on;
    el.save.innerHTML = on ? '<i class="fa-solid fa-spinner fa-spin"></i> <span>Saving…</span>' : '<i class="fa-solid fa-floppy-disk"></i> <span>' + (st.mode === 'add' ? 'Save Invoice' : 'Update Invoice') + '</span>';
  }
  function confirmHigh() {
    // a typed number far above the series moves the series on — make sure it is meant
    if ((st.mode === 'add' && st.auto) || !st.next) return Promise.resolve(true);
    var m = /-(\d{1,9})$/.exec(el.no.value.trim()) || /^(\d{1,9})$/.exec(el.no.value.trim());
    var n = m ? +m[1] : 0;
    if (st.row && el.no.value.trim().toUpperCase() === String(st.row.invoice_no || '').toUpperCase()) return Promise.resolve(true);
    if (!n || n <= st.next.n + 20) return Promise.resolve(true);
    return Swal.fire({ icon: 'question', title: 'Use number ' + n + '?', html: 'The next number in the series is <b>' + st.next.n + '</b>. After saving <b>' + n + '</b>, automatic numbering continues from <b>' + (n + 1) + '</b>.',
      showCancelButton: true, confirmButtonText: 'Yes, use ' + n, cancelButtonText: 'Change it', confirmButtonColor: '#00A3DF' }).then(function (r) { return r.isConfirmed; });
  }
  function send(extra) {
    return post(payload(extra)).then(function (res) {
      if (res.ok) return res;
      if (res.confirm === 'vessel') {
        busy(false);
        var list = (res.matches || []).map(function (m) { return '<li>' + esc(m.invoice_no || 'no number yet') + (m.report_no ? ' · ' + esc(m.report_no) : '') + '</li>'; }).join('');
        return Swal.fire({ icon: 'warning', title: 'This vessel is already in Invoices', html: '<b>' + esc(el.vessel.value) + '</b> already has ' + ((res.matches || []).length > 1 ? 'these entries' : 'this entry') + ':<ul style="text-align:left;margin:8px 0 0 18px">' + list + '</ul><small>Save anyway only if this is a separate invoice.</small>',
          showCancelButton: true, confirmButtonText: 'Save anyway', cancelButtonText: 'Go back', confirmButtonColor: '#00A3DF' }).then(function (r) {
          if (!r.isConfirmed) return null;
          busy(true);
          return send({ confirm_dup: '1' });
        });
      }
      var e = res.errors || {};
      if (e.invoice_no === 1) e.invoice_no = res.error;
      showErrors(Object.keys(e).length ? e : null, res.error || 'Could not save. Please try again.');
      return null;
    });
  }
  function upload(row) {
    var f = el.file.files[0], rf = el.recOn.checked ? el.recFile.files[0] : null;
    var one = function (a, field, file, what) {
      if (!file) return Promise.resolve(null);
      var d = new FormData(); d.append('a', a); d.append('id', row.id); d.append(field, file);
      return post(d).then(function (r) { if (!r.ok) throw new Error(what + ': ' + (r.error || 'not stored.')); return r.row; });
    };
    return one('upload', 'invoice_pdf', f, 'Invoice PDF').then(function (r1) {
      return one('upload_rec', 'rec_pdf', rf, 'Recovery invoice PDF').then(function (r2) { if (r2 || r1) Object.assign(row, r2 || r1); return true; });
    });
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (st.saving) return;
    var e = validate();
    if (Object.keys(e).length) { showErrors(e); return; }
    confirmHigh().then(function (go) {
      if (!go) { el.no.focus(); return; }
      busy(true);
      return send().then(function (res) {
        if (!res) { busy(false); return; }
        var row = res.row, wasAdd = st.mode === 'add';
        return upload(row).then(function () { return ''; }, function (err) { return err.message; }).then(function (pdfErr) {
          busy(false); close();
          if (window.INV_GRID) window.INV_GRID.saved(row, wasAdd);
          var where = row.status === 'Completed' ? ' (in Completed invoices)' : '';
          if (pdfErr) Swal.fire({ icon: 'warning', title: 'Invoice ' + (wasAdd ? 'saved' : 'updated') + ', PDF not stored', text: pdfErr + ' Use Upload on the entry to try again.' });
          else if (res.number_changed && wasAdd) Swal.fire({ icon: 'info', title: 'Saved as ' + row.invoice_no, text: 'The number shown was taken by another invoice just before saving, so the next free number was used.' });
          else toast('success', (wasAdd ? 'Invoice ' + row.invoice_no + ' added' : 'Invoice ' + (row.invoice_no || '') + ' updated') + where);
        });
      });
    }).catch(function () { busy(false); Swal.fire({ icon: 'error', title: 'Not saved', text: 'Network error — please try again.' }); });
  });

  window.InvoiceForm = { open: open };
})();
