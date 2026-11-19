/* Report Generator — "IMO Lookup" (next to Fill XXX): the vessel particulars of an IMO number,
 * found the same way as on the Survey Report page (ajax/report_imo_lookup.php: saved lookup →
 * the latest survey report of this IMO → AI + web search), go into the section's fields.
 * Empty fields are filled; fields that already hold another value are replaced only when the
 * user says so. The Excel / PDF sources are not touched. */
(function () {
    var css = '.rg-imo{display:flex;align-items:center;gap:6px;margin-left:auto;flex-wrap:nowrap}'
        + '.rg-imo input{width:120px;min-height:42px;padding:6px 10px;border-radius:10px;border:1.5px solid #cbd5e1;font-size:14px;font-weight:700;letter-spacing:.04em;background:#fff;color:#0f172a}'
        + '.rg-imo input:focus{outline:none;border-color:#7c3aed}'
        + '.rg-imo-btn{min-height:42px;padding:6px 14px;border-radius:10px;border:1.5px solid #7c3aed;background:#f5f3ff;color:#5b21b6;font-weight:800;white-space:nowrap}'
        + '.rg-imo-btn:disabled{opacity:.6}'
        + '.rg-imo-note{flex:1 1 100%;font-size:12px;color:#475569;margin:0}.rg-imo-note:empty{display:none}.rg-imo-note.err{color:#b91c1c}'
        + '@media (max-width:575.98px){.rg-imo{margin-left:0;width:100%}.rg-imo input{flex:1;width:auto;min-width:0}}';
    var s = document.createElement('style'); s.textContent = css; document.head.appendChild(s);

    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    // lookup key → Report Generator field
    var MAP = {
        vessel_name: 'SHIP_NAME', port_of_registry: 'PORT_OF_REGISTRY', holds: 'HOLDS',
        keel_month: 'KEEL_LAID_MONTH', keel_year: 'KEEL_LAID_YEAR', 'class': 'CLASS', callsign: 'CALL_SIGN',
        loa: 'LENGTH_OVERALL', breadth: 'BREDTH_MOULDED', depth: 'DEPTH_MOULDED', summer_draft: 'SUMMER_DRAFT',
        summer_dwt: 'SUMMER_DEADWEIGHT', owners: 'OWNER_NAME', builder: 'BUILDER_NAME', operator: 'OPERATOR_NAME'
    };
    var busy = {}, lastImo = {};

    window.rgImoHtml = function (sid, imo) {
        return '<span class="rg-imo"><input type="text" inputmode="numeric" maxlength="7" placeholder="IMO No." value="' + esc(imo || '') + '" data-rg-imo="' + sid + '" aria-label="IMO number" data-testid="imo-lookup-input-' + sid + '">'
            + '<button type="button" class="rg-imo-btn" data-rg-imo-go="' + sid + '" data-testid="imo-lookup-' + sid + '"><i class="fa-solid fa-wand-magic-sparkles"></i> IMO Lookup</button></span>'
            + '<p class="rg-imo-note" data-rg-imo-note="' + sid + '" data-testid="imo-lookup-note-' + sid + '"></p>';
    };

    function note(sid, html, err) {
        var n = document.querySelector('[data-rg-imo-note="' + sid + '"]');
        if (n) { n.innerHTML = html; n.classList.toggle('err', !!err); }
    }
    function precision(id) { var f = REPORT_FIELDS.filter(function (x) { return x.id === id; })[0]; return f && f.precision; }
    function toForm(fields) {
        var out = {};
        Object.keys(MAP).forEach(function (k) {
            if (fields[k] == null || String(fields[k]).trim() === '') return;
            var id = MAP[k], v = String(fields[k]).trim(), p = precision(id);
            if (p && /^-?\d+(\.\d+)?$/.test(v)) v = Number(v).toFixed(p);
            if (id === 'HOLDS' && !NUMS_TO_WORDS[v]) return; // the No. of Holds list is 1–10
            out[id] = v;
        });
        if (fields.grt) out.GRT = String(fields.grt); // GRT only (no NRT)
        return out;
    }
    function lookup(sid) {
        if (busy[sid]) return;
        var input = document.querySelector('[data-rg-imo="' + sid + '"]');
        var imo = String(input ? input.value : '').replace(/\D/g, '');
        if (input) input.value = imo;
        if (!/^\d{7}$/.test(imo)) { note(sid, 'Enter the 7-digit IMO number.', true); if (input) input.focus(); return; }
        var btn = document.querySelector('[data-rg-imo-go="' + sid + '"]');
        busy[sid] = true; lastImo[sid] = imo;
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Looking up…'; }
        note(sid, 'Finding the particulars of IMO ' + esc(imo) + '… this can take up to a minute.');
        var fd = new FormData(); fd.append('imo', imo);
        fetch('ajax/report_imo_lookup.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': window.RG_CSRF || '' } })
            .then(function (r) { return r.json(); }).catch(function () { return { ok: false, error: 'No connection. Please try again.' }; })
            .then(function (res) {
                busy[sid] = false;
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> IMO Lookup'; }
                if (!res || !res.ok) { note(sid, esc((res && res.error) || 'Nothing found for this IMO.'), true); return; }
                apply(sid, res);
            });
    }
    function apply(sid, res) {
        var fv = sectionState[sid].formValues, found = toForm(res.fields || {});
        found.IMO = res.imo;
        var ids = Object.keys(found), clash = ids.filter(function (id) { var c = String(fv[id] || '').trim(); return c !== '' && c !== found[id]; });
        var write = function (replace) {
            var n = 0;
            ids.forEach(function (id) {
                var c = String(fv[id] || '').trim();
                if (c === found[id] || (c !== '' && !replace)) return;
                fv[id] = found[id]; n++;
                if (typeof window.rgPdfManual === 'function') window.rgPdfManual(sid, id); // a later PDF read asks before replacing it
            });
            if (fv.HOLDS) fv.HOLDS_ALPHA = NUMS_TO_WORDS[fv.HOLDS] || '';
            calculateConsumption(sid);
            renderGroupedGrids(sid);
            var filled = document.getElementById('filledSection_' + sid), arrow = document.getElementById('arrow2_' + sid);
            if (filled && filled.classList.contains('collapsed')) { filled.classList.remove('collapsed'); if (arrow) arrow.innerText = '▲'; }
            var from = res.from === 'saved' ? 'saved lookup' : res.from === 'report' ? 'latest survey report of this IMO' : 'web search';
            var src = (res.sources || []).slice(0, 3).map(function (x) { return x && x.uri ? '<a href="' + esc(x.uri) + '" target="_blank" rel="noopener">' + esc(x.title || x.uri) + '</a>' : ''; }).filter(Boolean).join(', ');
            note(sid, '<b>' + n + ' field' + (n === 1 ? '' : 's') + ' filled for IMO ' + esc(res.imo) + '</b> (' + from + ')' + (clash.length && !replace ? ', ' + clash.length + ' kept as you had them' : '')
                + '. Please check them against the ship\'s documents.' + (src ? ' Sources: ' + src : ''));
        };
        if (!clash.length) return write(false);
        if (typeof Swal === 'undefined') { // SweetAlert did not load: a plain confirm box
            return write(window.confirm(clash.length + ' field(s) already have a different value. Replace them with the lookup values?'));
        }
        var list = clash.map(function (id) { var f = REPORT_FIELDS.filter(function (x) { return x.id === id; })[0]; return '<li><b>' + esc(f ? f.label : id) + '</b>: yours "' + esc(fv[id]) + '" — lookup "' + esc(found[id]) + '"</li>'; }).join('');
        Swal.fire({ icon: 'question', title: 'Replace your values?', html: 'These fields already have a different value:<ul style="text-align:left;font-size:13px">' + list + '</ul>',
            showDenyButton: true, confirmButtonText: 'Use lookup values', denyButtonText: 'Keep mine', allowOutsideClick: false })
            .then(function (r) { write(r.isConfirmed); });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-rg-imo-go]'); if (b) lookup(b.getAttribute('data-rg-imo-go'));
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.matches('[data-rg-imo]')) { e.preventDefault(); lookup(e.target.getAttribute('data-rg-imo')); }
    });
    // a whole 7-digit IMO starts the lookup by itself (each IMO once), as on the Survey Report page
    document.addEventListener('input', function (e) {
        if (!e.target.matches('[data-rg-imo]')) return;
        var sid = e.target.getAttribute('data-rg-imo'), v = e.target.value.replace(/\D/g, '');
        if (v.length === 7 && lastImo[sid] !== v) lookup(sid);
    });
})();
