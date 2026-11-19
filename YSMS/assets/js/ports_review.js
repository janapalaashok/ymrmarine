/* Review Ports (ports_review.php): capitals, countries for "Not specified" ports, AI suggestions, save. */
(function () {
    var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var caps = function (v) { return String(v || '').replace(/\s+/g, ' ').trim().toUpperCase(); };
    var note = function (html, cls) { var n = document.querySelector('[data-prv-note]'); n.innerHTML = html; n.className = 'prv-note' + (cls ? ' ' + cls : ''); };
    var rows = function () { return $$('tr[data-id]'); };
    var nameIn = function (tr) { return tr.querySelector('[data-prv-name]'); };
    var ctryIn = function (tr) { return tr.querySelector('[data-prv-country]'); };
    function changed(tr) { return caps(nameIn(tr).value) !== nameIn(tr).dataset.old || caps(ctryIn(tr).value) !== ctryIn(tr).dataset.old; }
    function mark(tr) {
        [nameIn(tr), ctryIn(tr)].forEach(function (i) { i.classList.toggle('chg', caps(i.value) !== i.dataset.old); });
        ctryIn(tr).classList.toggle('need', caps(ctryIn(tr).value) === '');
    }
    function count() {
        var n = rows().filter(function (tr) { return tr.querySelector('[data-prv-on]').checked && changed(tr); }).length;
        var b = document.querySelector('[data-prv-save]');
        b.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save changes (' + n + ')';
        b.disabled = n === 0;
    }
    function filter() {
        var q = caps(document.querySelector('[data-prv-q]').value), show = document.querySelector('[data-prv-show]').value;
        rows().forEach(function (tr) {
            var txt = caps(nameIn(tr).value + ' ' + ctryIn(tr).value + ' ' + nameIn(tr).dataset.old + ' ' + ctryIn(tr).dataset.old);
            var ok = (!q || txt.indexOf(q) >= 0) && (show === 'all' || (show === 'change' && changed(tr)) || (show === 'missing' && (tr.classList.contains('miss') || caps(ctryIn(tr).value) === '')));
            tr.style.display = ok ? '' : 'none';
        });
    }
    rows().forEach(mark); count();

    document.addEventListener('input', function (e) {
        var tr = e.target.closest('tr[data-id]');
        if (tr) { mark(tr); count(); }
        if (e.target.matches('[data-prv-q]')) filter();
    });
    document.addEventListener('change', function (e) {
        if (e.target.matches('[data-prv-show]')) filter();
        if (e.target.matches('[data-prv-all]')) { rows().forEach(function (tr) { if (tr.style.display !== 'none') tr.querySelector('[data-prv-on]').checked = e.target.checked; }); count(); }
        if (e.target.matches('[data-prv-on]')) count();
        if (e.target.matches('[data-prv-name],[data-prv-country]')) { e.target.value = caps(e.target.value); var tr = e.target.closest('tr'); mark(tr); count(); }
    });

    // ---------- AI check (suggestions only; the Admin chooses "Use")
    var ai = {};
    function showAi(tr) {
        var s = ai[tr.dataset.id]; if (!s) return;
        var n = tr.querySelector('[data-prv-ai-name]'), c = tr.querySelector('[data-prv-ai-country]');
        n.innerHTML = s.name && s.name !== caps(nameIn(tr).value) ? 'AI: ' + esc(s.name) + (s.note ? ' <span style="color:#64748b">(' + esc(s.note) + ')</span>' : '') + '<button type="button" data-prv-use="name">Use</button>' : '';
        c.innerHTML = s.country && s.country !== caps(ctryIn(tr).value) ? 'AI: ' + esc(s.country) + '<button type="button" data-prv-use="country">Use</button>'
            : (!s.country && caps(ctryIn(tr).value) === '' ? '<span style="color:#b91c1c">AI is not sure — enter the country</span>' : '');
    }
    function use(tr, what) {
        var s = ai[tr.dataset.id]; if (!s) return;
        if (what === 'name' && s.name) nameIn(tr).value = s.name;
        if (what === 'country' && s.country) ctryIn(tr).value = s.country;
        mark(tr); showAi(tr); count();
    }
    document.addEventListener('click', function (e) {
        var u = e.target.closest('[data-prv-use]');
        if (u) { use(u.closest('tr'), u.getAttribute('data-prv-use')); return; }
        if (e.target.closest('[data-prv-use-all]')) {
            var what = e.target.closest('[data-prv-use-all]').getAttribute('data-prv-use-all');
            rows().forEach(function (tr) { if (what === 'country') { if (caps(ctryIn(tr).value) === '') use(tr, 'country'); } else { use(tr, 'name'); use(tr, 'country'); } });
            return;
        }
        if (e.target.closest('[data-prv-ai]')) runAi();
        if (e.target.closest('[data-prv-save]')) save();
    });
    function post(data) {
        var fd = new FormData(); Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch('ajax/ports_review.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.PRV_CSRF || '' } })
            .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'No connection. Please try again.' }; });
    }
    function runAi() {
        var btn = document.querySelector('[data-prv-ai]'), list = rows(), batches = [];
        for (var i = 0; i < list.length; i += 60) batches.push(list.slice(i, i + 60));
        btn.disabled = true;
        var done = 0, errors = 0;
        batches.reduce(function (p, b) {
            return p.then(function () {
                note('<i class="fa-solid fa-spinner fa-spin"></i> AI is checking the ports… ' + done + ' of ' + list.length);
                return post({ action: 'suggest', ports: JSON.stringify(b.map(function (tr) { return { id: +tr.dataset.id }; })) }).then(function (res) {
                    done += b.length;
                    if (!res.ok) { errors++; return; }
                    res.items.forEach(function (it) { ai[it.id] = it; });
                    b.forEach(showAi);
                });
            });
        }, Promise.resolve()).then(function () {
            btn.disabled = false;
            var names = 0, ctry = 0, unsure = 0;
            rows().forEach(function (tr) {
                var s = ai[tr.dataset.id]; if (!s) return;
                if (s.name && s.name !== caps(nameIn(tr).value)) names++;
                if (s.country && s.country !== caps(ctryIn(tr).value)) ctry++;
                if (!s.country && caps(ctryIn(tr).value) === '') unsure++;
            });
            note('AI check done: <b>' + names + '</b> name suggestion' + (names === 1 ? '' : 's') + ', <b>' + ctry + '</b> country suggestion' + (ctry === 1 ? '' : 's') + (unsure ? ', <b>' + unsure + '</b> country not known' : '') + (errors ? ' (' + errors + ' part(s) failed — press AI check again)' : '') + '. '
                + 'Check them in the table (AI: … Use), or <button type="button" class="prv-btn ghost" style="padding:4px 10px" data-prv-use-all="country">Use AI countries for "Not specified"</button> '
                + '<button type="button" class="prv-btn ghost" style="padding:4px 10px" data-prv-use-all="all">Use all AI suggestions</button>', errors ? 'err' : '');
        });
    }

    // ---------- save
    function save() {
        var pick = rows().filter(function (tr) { return tr.querySelector('[data-prv-on]').checked && changed(tr); });
        var empty = pick.filter(function (tr) { return caps(ctryIn(tr).value) === '' || /^NOT\s*SPECIFIED$/.test(caps(ctryIn(tr).value)); });
        if (empty.length) {
            note(empty.length + ' ticked port(s) have no country. Enter the country, or untick them.', 'err');
            document.querySelector('[data-prv-show]').value = 'missing'; filter();
            ctryIn(empty[0]).focus(); return;
        }
        if (!pick.length) return;
        var go = function () {
            var btn = document.querySelector('[data-prv-save]'); btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
            post({ action: 'apply', rows: JSON.stringify(pick.map(function (tr) { return { id: +tr.dataset.id, name: caps(nameIn(tr).value), country: caps(ctryIn(tr).value) }; })) }).then(function (res) {
                if (!res.ok) { note(esc(res.error || 'Could not save.'), 'err'); count(); return; }
                (res.failed || []).forEach(function (f) { var tr = document.querySelector('tr[data-id="' + f.id + '"]'); if (tr) tr.querySelector('[data-prv-ai-name]').innerHTML = '<span style="color:#b91c1c">Not saved: ' + esc(f.error) + '</span>'; });
                pick.forEach(function (tr) {
                    if ((res.failed || []).some(function (f) { return +f.id === +tr.dataset.id; })) return;
                    nameIn(tr).dataset.old = caps(nameIn(tr).value); ctryIn(tr).dataset.old = caps(ctryIn(tr).value);
                    tr.querySelectorAll('.prv-old')[0].textContent = 'Now: ' + nameIn(tr).dataset.old;
                    tr.querySelectorAll('.prv-old')[1].textContent = 'Now: ' + ctryIn(tr).dataset.old;
                    tr.classList.remove('miss'); mark(tr);
                });
                note('<b>' + res.updated + '</b> port(s) saved.' + (res.failed && res.failed.length ? ' <b>' + res.failed.length + '</b> not saved — see the red notes in the table.' : ''), res.failed && res.failed.length ? 'err' : 'ok');
                count();
            });
        };
        var msg = 'Save ' + pick.length + ' port(s)? The new names and countries are used everywhere in YSMS (vessels, reports, exports).';
        if (typeof Swal !== 'undefined') Swal.fire({ icon: 'question', title: 'Save changes?', text: msg, showCancelButton: true, confirmButtonText: 'Save' }).then(function (r) { if (r.isConfirmed) go(); });
        else if (window.confirm(msg)) go();
    }
})();
