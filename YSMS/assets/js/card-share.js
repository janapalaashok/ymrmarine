/*
 * Dashboard: share a card as a picture.
 * Every element with data-share="Title" has a small share button ([data-cs]) inside it. A tap makes a
 * PNG of that element (html2canvas, loaded on first use from assets/vendor) with the YMR Marine logo,
 * the title and the date above it, and shows it in a sheet: Share (the phone's / browser's share
 * sheet), Copy (desktop), Save picture, Close. The picture is made in the browser only; nothing is sent
 * to the server. Scrolling parts (wide tables, long lists) are shown in full in the picture.
 */
(function () {
  'use strict';
  var H2C = 'assets/vendor/html2canvas/html2canvas.min.js?v=1.4.1';
  var LOGO = 'assets/images/ymr-logo-horizontal.svg';
  var FONT = "Lexend, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
  var loading = null;

  function loadH2c() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (loading) return loading;
    loading = new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = H2C; s.async = true;
      s.onload = function () { window.html2canvas ? res(window.html2canvas) : rej(new Error('html2canvas')); };
      s.onerror = function () { loading = null; rej(new Error('html2canvas')); };
      document.head.appendChild(s);
    });
    return loading;
  }
  function loadImg(src) {
    return new Promise(function (res) { var im = new Image(); im.onload = function () { res(im); }; im.onerror = function () { res(null); }; im.src = src; });
  }
  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function stamp() {
    var d = new Date(), M = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
    var h = d.getHours(), ap = h < 12 ? 'AM' : 'PM'; h = h % 12 || 12;
    return ('0' + d.getDate()).slice(-2) + '-' + M[d.getMonth()] + '-' + d.getFullYear() + ', ' + h + ':' + ('0' + d.getMinutes()).slice(-2) + ' ' + ap;
  }
  function titleOf(card) {
    var t = card.getAttribute('data-share') || 'Dashboard';
    var y = document.querySelector('[data-mc-year]');  // month charts: the year shown
    return t.replace('{year}', y ? y.value : String(new Date().getFullYear()));
  }

  // Extra width the element needs so its scrolling parts show in full.
  function fullWidth(card) {
    var extra = 0;
    card.querySelectorAll('*').forEach(function (n) {
      var ox = getComputedStyle(n).overflowX;
      if ((ox === 'auto' || ox === 'scroll') && n.scrollWidth > n.clientWidth) extra = Math.max(extra, n.scrollWidth - n.clientWidth);
    });
    return Math.ceil(card.getBoundingClientRect().width + extra);
  }

  function snap(card) {
    var width = Math.max(fullWidth(card), card.matches('.dash-hl > a') ? 300 : 0);
    var scale = Math.min(3, Math.max(2, window.devicePixelRatio || 1));
    card.setAttribute('data-cs-snap', '1');
    return loadH2c().then(function (h2c) {
      return h2c(card, {
        scale: scale, backgroundColor: null, useCORS: true, logging: false,
        ignoreElements: function (n) { return n.hasAttribute && (n.hasAttribute('data-cs') || n.classList.contains('mc-tip')); },
        onclone: function (doc) {
          var c = doc.querySelector('[data-cs-snap]');
          if (!c) return;
          c.classList.add('cs-snap');
          c.style.width = width + 'px'; c.style.maxWidth = 'none'; c.style.transform = 'none';
          c.querySelectorAll('*').forEach(function (n) {   // scrolling parts in full
            var cs = doc.defaultView.getComputedStyle(n);
            if (/(auto|scroll)/.test(cs.overflowX + cs.overflowY)) { n.style.overflow = 'visible'; n.style.maxHeight = 'none'; }
          });
          // Inline SVG is drawn as a separate image: give its parts their page styles (fill, font).
          var orig = card.querySelectorAll('svg'), copy = c.querySelectorAll('svg');
          for (var i = 0; i < orig.length && i < copy.length; i++) {
            var o = [orig[i]].concat([].slice.call(orig[i].querySelectorAll('*'))), k = [copy[i]].concat([].slice.call(copy[i].querySelectorAll('*')));
            for (var j = 0; j < o.length && j < k.length; j++) {
              if (k[j].tagName.toLowerCase() === 'title') continue;
              var s = getComputedStyle(o[j]);
              k[j].setAttribute('style', 'fill:' + s.fill + ';stroke:' + s.stroke + ';opacity:' + s.opacity +
                ';font-family:' + s.fontFamily.replace(/"/g, "'") + ';font-weight:' + s.fontWeight + ';font-size:' + s.fontSize);
            }
          }
        }
      });
    }).then(function (cv) { card.removeAttribute('data-cs-snap'); cv.csScale = scale; return cv; },
            function (err) { card.removeAttribute('data-cs-snap'); throw err; });
  }

  // The card picture on a white sheet with the logo, the title and the date.
  function compose(shot, title) {
    return loadImg(LOGO).then(function (logo) {
      var k = shot.csScale || 1; // html2canvas scale
      var P = 20, LH = 26, cw = shot.width / k, W = Math.max(cw + P * 2, 440);
      var showTitle = title !== '';
      var c = document.createElement('canvas').getContext('2d');
      c.font = '800 17px ' + FONT;
      var head = P + LH + (showTitle ? 14 + 22 + 12 : 16), Ht = head + shot.height / k + P + 18;
      var cv = document.createElement('canvas');
      cv.width = Math.round(W * k); cv.height = Math.round(Ht * k);
      c = cv.getContext('2d'); c.scale(k, k);
      c.fillStyle = '#ffffff'; c.fillRect(0, 0, W, Ht);
      c.fillStyle = '#1E1B6B'; c.fillRect(0, 0, W, 4);
      if (logo) c.drawImage(logo, P, P, LH * (logo.naturalWidth / logo.naturalHeight || 7.5), LH);
      else { c.fillStyle = '#0b1324'; c.font = '800 18px ' + FONT; c.fillText('YMR Marine', P, P + 19); }
      c.textAlign = 'right'; c.fillStyle = '#64748b'; c.font = '600 12px ' + FONT;
      c.fillText(stamp(), W - P, P + 17);
      c.textAlign = 'left'; c.fillStyle = '#0f172a'; c.font = '800 17px ' + FONT;
      if (showTitle) {
        var t = title; while (t.length > 3 && c.measureText(t).width > W - P * 2) t = t.slice(0, -2);
        c.fillText(t === title ? t : t + '…', P, P + LH + 14 + 16);
      }
      c.drawImage(shot, (W - cw) / 2, head, cw, shot.height / k);
      c.fillStyle = '#94a3b8'; c.font = '600 10.5px ' + FONT; c.textAlign = 'center';
      c.fillText('YSMS · Dashboard', W / 2, Ht - P / 2 - 2);
      return cv;
    });
  }

  function toFile(cv, title) {
    return new Promise(function (res, rej) {
      cv.toBlob(function (b) {
        if (!b) return rej(new Error('png'));
        var name = ('YMR ' + title + ' ' + stamp().split(',')[0]).replace(/['’]/g, '').replace(/[^A-Za-z0-9 _-]+/g, ' ').replace(/\s+/g, ' ').trim() + '.png';
        res(new File([b], name, { type: 'image/png' }));
      }, 'image/png');
    });
  }
  function canShareFile(f) { try { return !!(navigator.share && navigator.canShare && navigator.canShare({ files: [f] })); } catch (e) { return false; } }
  function save(f) {
    var u = URL.createObjectURL(f), a = document.createElement('a');
    a.href = u; a.download = f.name; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
  }

  var cssDone = false;
  function css() {
    if (cssDone) return; cssDone = true;
    var st = document.createElement('style');
    st.textContent =
      '.cs-sheet{position:fixed;inset:0;z-index:2147483000;background:rgba(11,19,36,.6);display:flex;align-items:center;justify-content:center;padding:16px}' +
      '.cs-box{background:#fff;width:100%;max-width:640px;max-height:calc(100vh - 32px);display:flex;flex-direction:column;border-radius:20px;padding:16px;box-sizing:border-box;font-family:' + FONT + ';box-shadow:0 20px 60px rgba(0,0,0,.3)}' +
      '.cs-box h3{margin:0 0 10px;font-size:16px;font-weight:700;color:#0b1324;display:flex;align-items:center;gap:8px}' +
      '.cs-box h3 span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.cs-x{border:0;background:#f1f5f9;width:32px;height:32px;border-radius:50%;font-size:18px;line-height:1;color:#334155;cursor:pointer;flex:none}' +
      '.cs-img{flex:1 1 auto;min-height:0;overflow:auto;border-radius:12px;background:#f1f5f9;border:1px solid #e2e8f0;text-align:center}' +
      '.cs-img img{display:block;max-width:100%;height:auto;margin:0 auto}' +
      '.cs-acts{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}' +
      '.cs-acts button{flex:1 1 0;min-width:110px;height:44px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0b1324;font:inherit;font-size:14.5px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:7px}' +
      '.cs-acts button.pri{background:#1E1B6B;border-color:#1E1B6B;color:#fff}' +
      '.cs-msg{font-size:13px;color:#64748b;margin-top:8px;min-height:18px;text-align:center}' +
      '.cs-wait{padding:40px 10px;text-align:center;color:#475569;font-size:14px;font-weight:600}' +
      '@media (max-width:575.98px){.cs-sheet{align-items:flex-end;padding:0}.cs-box{max-width:none;border-radius:20px 20px 0 0;max-height:92vh;padding:14px 14px calc(14px + env(safe-area-inset-bottom))}.cs-acts button{min-width:0}}';
    document.head.appendChild(st);
  }

  var sheet = null;
  function closeSheet() {
    if (!sheet) return;
    if (sheet.url) URL.revokeObjectURL(sheet.url);
    sheet.el.remove(); document.removeEventListener('keydown', onKey); sheet = null;
  }
  function onKey(e) { if (e.key === 'Escape') closeSheet(); }
  function openSheet(title) {
    css(); closeSheet();
    var el = document.createElement('div');
    el.className = 'cs-sheet'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('data-testid', 'card-share-sheet');
    el.innerHTML = '<div class="cs-box"><h3><span>' + esc(title) + '</span><button type="button" class="cs-x" data-cs-close aria-label="Close">&times;</button></h3>' +
      '<div class="cs-img"><div class="cs-wait"><i class="fa-solid fa-spinner fa-spin"></i> Making the picture…</div></div>' +
      '<div class="cs-acts"></div><div class="cs-msg" aria-live="polite"></div></div>';
    el.addEventListener('click', function (e) { if (e.target === el || e.target.closest('[data-cs-close]')) closeSheet(); });
    document.body.appendChild(el); document.addEventListener('keydown', onKey);
    sheet = { el: el, url: '' };
    el.querySelector('.cs-x').focus();
    return sheet;
  }
  function fillSheet(sh, file) {
    if (sheet !== sh) return;
    sh.url = URL.createObjectURL(file);
    sh.el.querySelector('.cs-img').innerHTML = '<img alt="" data-testid="card-share-img" src="' + sh.url + '">';
    var acts = sh.el.querySelector('.cs-acts'), msg = sh.el.querySelector('.cs-msg');
    var copyOk = !!(navigator.clipboard && navigator.clipboard.write && window.ClipboardItem);
    acts.innerHTML = (canShareFile(file) ? '<button type="button" class="pri" data-act="share" data-testid="card-share-go"><i class="fa-solid fa-share-nodes"></i> Share</button>' : '') +
      (copyOk ? '<button type="button" data-act="copy" data-testid="card-share-copy"><i class="fa-regular fa-copy"></i> Copy</button>' : '') +
      '<button type="button" class="' + (canShareFile(file) ? '' : 'pri') + '" data-act="save" data-testid="card-share-save"><i class="fa-solid fa-download"></i> Save picture</button>';
    acts.addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return;
      var a = b.getAttribute('data-act');
      if (a === 'share') navigator.share({ files: [file], title: file.name.replace(/\.png$/, '') }).catch(function (err) { if (err && err.name !== 'AbortError') save(file); });
      else if (a === 'save') { save(file); msg.textContent = 'Picture saved.'; }
      else if (a === 'copy') navigator.clipboard.write([new ClipboardItem({ 'image/png': file })])
        .then(function () { msg.textContent = 'Picture copied — paste it in WhatsApp, email or chat.'; }, function () { msg.textContent = 'Could not copy. Use Save picture.'; });
    });
    var first = acts.querySelector('button'); if (first) first.focus();
  }
  function failSheet(sh) {
    if (sheet !== sh) return;
    sh.el.querySelector('.cs-img').innerHTML = '<div class="cs-wait" style="color:#b91c1c">The picture could not be made. Please try again.</div>';
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-cs]');
    if (!btn) return;
    e.preventDefault(); e.stopPropagation();
    var card = btn.closest('[data-share]');
    if (!card || btn.classList.contains('busy')) return;
    var title = titleOf(card), sh = openSheet(title);
    btn.classList.add('busy');
    // the card shows its own name already (tiles, tables with their heading): no second title above it
    var norm = function (v) { return String(v).replace(/[\u2013\u2014]/g, '-').replace(/\s+/g, ' ').toLowerCase(); };
    var own = norm(card.textContent || '').indexOf(norm(title)) >= 0;
    snap(card).then(function (shot) { return compose(shot, own ? '' : title); })
      .then(function (cv) { return toFile(cv, title); })
      .then(function (f) { fillSheet(sh, f); }, function (err) { if (window.console) console.error('card share', err); failSheet(sh); })
      .then(function () { btn.classList.remove('busy'); });
  }, true);
  // load the library as soon as a finger / pointer is on a share button
  document.addEventListener('pointerdown', function (e) { if (e.target.closest && e.target.closest('[data-cs]')) loadH2c().catch(function () {}); }, true);
})();
