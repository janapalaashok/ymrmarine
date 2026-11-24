/*
 * App-wide "something is happening" feedback.
 *  - Any POST form: the pressed button shows a spinner + "Please wait…" and
 *    further submits are blocked; forms with files get a full-screen
 *    "Uploading…" overlay; download forms reset themselves after a few seconds.
 *  - Any POST made with fetch / XMLHttpRequest (jQuery included): a thin
 *    progress bar across the top of the screen while it runs.
 *  - Every file upload shows a progress bar with the % sent:
 *    · POST forms with files go up with XMLHttpRequest and then land on the page
 *      a normal submit would have shown (config/config.php answers these
 *      requests with {"redirect": …}); downloads are saved as files;
 *    · AJAX uploads (XMLHttpRequest / jQuery / fetch with a FormData holding
 *      files) get the same progress overlay while they run. Pages with their own
 *      upload progress (team chat, document replace) are left alone.
 * Opt out per form with data-no-loading.
 */
(function () {
    'use strict';
    if (window.__ysmsSubmitFeedback) return;
    window.__ysmsSubmitFeedback = true;

    var css = '' +
        '#ysmsBusyBar{position:fixed;top:0;left:0;height:3px;width:0;z-index:100000;background:linear-gradient(90deg,#2563eb,#5b2dee);box-shadow:0 0 8px rgba(37,99,235,.6);opacity:0;transition:width .3s ease,opacity .25s ease;pointer-events:none}' +
        'html.ysms-busy #ysmsBusyBar{opacity:1;width:85%;transition:width 8s cubic-bezier(.1,.6,.3,1),opacity .2s}' +
        'html.ysms-busy-done #ysmsBusyBar{opacity:0;width:100%;transition:width .2s ease,opacity .4s ease .2s}' +
        '.ysms-btn-loading{cursor:progress!important;opacity:.85;pointer-events:none}' +
        '.ysms-btn-loading .ysms-spin{display:inline-block;width:1em;height:1em;margin-right:.45em;vertical-align:-.15em;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:ysmsSpin .7s linear infinite}' +
        '@keyframes ysmsSpin{to{transform:rotate(360deg)}}' +
        '.ysms-submit-overlay{position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:20px}' +
        '.ysms-submit-overlay-box{background:#fff;border-radius:16px;padding:26px 24px;max-width:320px;text-align:center;font-size:14px;font-weight:600;color:#0b1e46;box-shadow:0 20px 50px rgba(0,0,0,.25);font-family:inherit}' +
        '.ysms-submit-overlay-box .ysms-spin{display:block;width:34px;height:34px;margin:0 auto 14px;border:3px solid #1e3a8a;border-right-color:transparent;border-radius:50%;animation:ysmsSpin .7s linear infinite}' +
        '.ysms-submit-overlay-box small{display:block;font-weight:500;color:#64748b;margin-top:6px}' +
        '.ysms-up-pct{font-size:30px;font-weight:800;color:#1e3a8a;line-height:1;margin:2px 0 10px;font-variant-numeric:tabular-nums}' +
        '.ysms-up-track{height:10px;border-radius:999px;background:#e2e8f0;overflow:hidden;margin:12px 0 4px}' +
        '.ysms-up-fill{height:100%;width:0;border-radius:999px;background:linear-gradient(90deg,#2563eb,#5b2dee);transition:width .2s ease}' +
        '.ysms-up-err{color:#b91c1c}' +
        '.ysms-up-close{margin-top:14px;border:0;border-radius:10px;padding:9px 18px;background:#1e3a8a;color:#fff;font-weight:700;font-size:13px}';
    var style = document.createElement('style');
    style.textContent = css;
    (document.head || document.documentElement).appendChild(style);

    // ---------------------------------------------------------- top bar
    var active = 0, doneTimer = null;
    function ensureBar() {
        if (!document.getElementById('ysmsBusyBar') && document.body) {
            var b = document.createElement('div');
            b.id = 'ysmsBusyBar';
            b.setAttribute('aria-hidden', 'true');
            document.body.appendChild(b);
        }
    }
    function busy(delta) {
        ensureBar();
        active = Math.max(0, active + delta);
        var root = document.documentElement;
        clearTimeout(doneTimer);
        if (active > 0) {
            root.classList.remove('ysms-busy-done');
            root.classList.add('ysms-busy');
        } else {
            root.classList.remove('ysms-busy');
            root.classList.add('ysms-busy-done');
            doneTimer = setTimeout(function () { root.classList.remove('ysms-busy-done'); }, 700);
        }
    }
    // Background traffic that should never flash the bar.
    var QUIET = /floating_notes\.php|team_chat\.php|notifications\.php|global_search|search_handler|preview_report_number|check_pending_vessel_duplicate/;
    function tracked(method, url) {
        return String(method || 'GET').toUpperCase() !== 'GET' && !QUIET.test(String(url || ''));
    }

    // AJAX uploads: a FormData body that holds a file gets the upload progress overlay
    var OWN_PROGRESS = /team_chat\.php|document_replace\.php/;
    function formDataHasFile(body) {
        if (!window.FormData || !(body instanceof FormData) || !body.entries) return false;
        var it = body.entries(), e;
        while (!(e = it.next()).done) {
            var v = e.value[1];
            if (window.Blob && v instanceof Blob && v.size > 0) return true;
        }
        return false;
    }

    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function (input, init) {
            var method = (init && init.method) || (input && input.method) || 'GET';
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            if (String(method).toUpperCase() === 'POST' && init && formDataHasFile(init.body) && !OWN_PROGRESS.test(url) && window.XMLHttpRequest) {
                return fetchWithProgress(url, init);
            }
            if (!tracked(method, url)) return origFetch.apply(this, arguments);
            busy(1);
            return origFetch.apply(this, arguments).then(
                function (r) { busy(-1); return r; },
                function (e) { busy(-1); throw e; }
            );
        };
    }
    var XHR = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
    if (XHR) {
        var origOpen = XHR.open, origSend = XHR.send;
        XHR.open = function (method, url) {
            this.__ysmsTrack = tracked(method, url);
            this.__ysmsUrl = String(url || '');
            return origOpen.apply(this, arguments);
        };
        XHR.send = function (body) {
            if (this.__ysmsTrack && !this.__ysmsCounted) {
                this.__ysmsCounted = true;
                busy(1);
                var self = this;
                this.addEventListener('loadend', function () { busy(-1); self.__ysmsCounted = false; });
            }
            if (!this.__ysmsOwnOverlay && !OWN_PROGRESS.test(this.__ysmsUrl) && formDataHasFile(body)) {
                var ov = progressOverlay();
                this.upload.addEventListener('progress', function (e) { if (e.lengthComputable) ov.progress(e.loaded, e.total); });
                this.upload.addEventListener('load', ov.sent);
                this.addEventListener('loadend', ov.close);
            }
            return origSend.apply(this, arguments);
        };
    }
    // fetch() can't report upload progress: send that one request with XMLHttpRequest instead.
    function fetchWithProgress(url, init) {
        return new Promise(function (resolve, reject) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', url);
            xhr.responseType = 'blob';
            var h = init.headers;
            if (h) {
                if (window.Headers && h instanceof Headers) h.forEach(function (v, k) { xhr.setRequestHeader(k, v); });
                else if (Array.isArray(h)) h.forEach(function (p) { xhr.setRequestHeader(p[0], p[1]); });
                else Object.keys(h).forEach(function (k) { xhr.setRequestHeader(k, h[k]); });
            }
            if (init.credentials === 'include') xhr.withCredentials = true;
            xhr.onload = function () {
                var headers = new Headers();
                xhr.getAllResponseHeaders().trim().split(/[\r\n]+/).forEach(function (line) {
                    var i = line.indexOf(':');
                    if (i > 0) { try { headers.append(line.slice(0, i).trim(), line.slice(i + 1).trim()); } catch (e) {} }
                });
                var nullBody = [101, 204, 205, 304].indexOf(xhr.status) !== -1;
                resolve(new Response(nullBody ? null : xhr.response, { status: xhr.status, statusText: xhr.statusText, headers: headers }));
            };
            xhr.onerror = function () { reject(new TypeError('Failed to fetch')); };
            xhr.onabort = function () { reject(new DOMException('The upload was aborted.', 'AbortError')); };
            if (init.signal) init.signal.addEventListener('abort', function () { xhr.abort(); });
            xhr.send(init.body);
        });
    }

    // ---------------------------------------------------------- forms
    var DOWNLOAD_FORM = /generate_invoice\.php|generate_expense\.php|export_[a-z_]+\.php/i;

    function isDownloadForm(form) {
        return DOWNLOAD_FORM.test(form.getAttribute('action') || '') || form.id === 'permissionCopyForm';
    }
    function hasFiles(form) {
        var inputs = form.querySelectorAll('input[type=file]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].files && inputs[i].files.length) return true;
        }
        return false;
    }
    function submitButtons(form, submitter) {
        var list = [];
        if (submitter && submitter.form === form) list.push(submitter);
        var all = form.querySelectorAll('button[type=submit], button:not([type]), input[type=submit]');
        for (var i = 0; i < all.length; i++) if (list.indexOf(all[i]) === -1) list.push(all[i]);
        if (form.id) {
            var outside = document.querySelectorAll('[form="' + form.id + '"][type=submit]');
            for (var j = 0; j < outside.length; j++) if (list.indexOf(outside[j]) === -1) list.push(outside[j]);
        }
        return list;
    }
    // Buttons are never set `disabled` while a submit is in flight: a disabled
    // submitter is left out of the posted data, and several pages check the
    // pressed button's name on the server. They are locked visually instead,
    // and repeat submits are blocked by the form's data-ysms-submitting flag.
    function setLoading(btn, label) {
        if (btn.dataset.ysmsLoading === '1') return;
        btn.dataset.ysmsLoading = '1';
        btn.setAttribute('aria-busy', 'true');
        btn.setAttribute('aria-disabled', 'true');
        btn.classList.add('ysms-btn-loading');
        if (btn.tagName !== 'INPUT') {
            btn.dataset.ysmsOrig = btn.innerHTML;
            btn.innerHTML = '<span class="ysms-spin" aria-hidden="true"></span>' + label;
        }
    }
    function clearLoading(btn) {
        if (btn.dataset.ysmsLoading !== '1') return;
        if (btn.tagName !== 'INPUT') btn.innerHTML = btn.dataset.ysmsOrig;
        btn.classList.remove('ysms-btn-loading');
        btn.removeAttribute('aria-busy');
        btn.removeAttribute('aria-disabled');
        delete btn.dataset.ysmsLoading;
    }
    function showOverlay(text, sub) {
        hideOverlay();
        var o = document.createElement('div');
        o.className = 'ysms-submit-overlay';
        o.id = 'ysmsSubmitOverlay';
        o.setAttribute('role', 'alert');
        o.setAttribute('aria-live', 'assertive');
        var box = document.createElement('div');
        box.className = 'ysms-submit-overlay-box';
        box.innerHTML = '<span class="ysms-spin" aria-hidden="true"></span>';
        box.appendChild(document.createTextNode(text));
        var s = document.createElement('small');
        s.textContent = sub;
        box.appendChild(s);
        o.appendChild(box);
        document.body.appendChild(o);
    }
    function hideOverlay() {
        var o = document.getElementById('ysmsSubmitOverlay');
        if (o) o.remove();
    }
    // ---------------------------------------------------------- upload progress
    function mbText(b) { return (b / 1048576).toFixed(b < 10485760 ? 1 : 0) + ' MB'; }
    // Full-screen "Uploading files… 45%" box; one at a time.
    function progressOverlay() {
        hideOverlay();
        var o = document.createElement('div');
        o.className = 'ysms-submit-overlay';
        o.id = 'ysmsSubmitOverlay';
        o.setAttribute('role', 'alert');
        o.setAttribute('data-testid', 'upload-progress');
        o.innerHTML = '<div class="ysms-submit-overlay-box" style="min-width:260px">' +
            '<div data-up-title>Uploading files…</div>' +
            '<div class="ysms-up-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="ysms-up-fill"></div></div>' +
            '<div class="ysms-up-pct" data-up-pct>0%</div>' +
            '<small data-up-sub>Please don\'t close this page.</small></div>';
        document.body.appendChild(o);
        var title = o.querySelector('[data-up-title]'), pct = o.querySelector('[data-up-pct]'), sub = o.querySelector('[data-up-sub]');
        var track = o.querySelector('.ysms-up-track'), fill = o.querySelector('.ysms-up-fill');
        function leaveGuard(e) { e.preventDefault(); e.returnValue = ''; }
        window.addEventListener('beforeunload', leaveGuard);
        function setPct(p) {
            p = Math.max(0, Math.min(100, Math.floor(p)));
            fill.style.width = p + '%';
            pct.textContent = p + '%';
            track.setAttribute('aria-valuenow', String(p));
        }
        return {
            progress: function (loaded, total) {
                setPct(loaded / total * 100);
                sub.textContent = mbText(loaded) + ' of ' + mbText(total) + ' — please don\'t close this page.';
            },
            sent: function () {
                setPct(100);
                title.textContent = 'Upload complete';
                sub.textContent = 'Saving files…';
            },
            release: function () { window.removeEventListener('beforeunload', leaveGuard); },
            close: function () { window.removeEventListener('beforeunload', leaveGuard); if (o.parentNode) o.remove(); },
            fail: function (msg) {
                window.removeEventListener('beforeunload', leaveGuard);
                title.textContent = 'Upload failed';
                title.className = 'ysms-up-err';
                pct.style.display = 'none';
                track.style.display = 'none';
                sub.textContent = msg;
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ysms-up-close';
                btn.textContent = 'Close';
                btn.addEventListener('click', function () { if (o.parentNode) o.remove(); });
                o.firstChild.appendChild(btn);
            }
        };
    }

    function isProgressUpload(form) {
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return false;
        if (form.hasAttribute('data-no-loading') || isDownloadForm(form)) return false;
        var target = form.getAttribute('target');
        if (target && target !== '_self') return false;
        return hasFiles(form) && !!window.FormData && !!window.XMLHttpRequest && !!window.Blob;
    }
    function saveBlob(blob, disposition) {
        var m = /filename\*=UTF-8''([^;]+)|filename="?([^";]+)"?/i.exec(disposition || '');
        var name = m ? decodeURIComponent(m[1] || m[2]) : 'download';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    }
    function uploadWithProgress(form, submitter) {
        if (form.dataset.ysmsSubmitting === '1') return;
        form.dataset.ysmsSubmitting = '1';
        submitButtons(form, submitter).forEach(function (b) { setLoading(b, 'Uploading…'); });
        var ov = progressOverlay();
        function unlock() {
            delete form.dataset.ysmsSubmitting;
            submitButtons(form, null).forEach(clearLoading);
        }
        function fail(msg) { unlock(); ov.fail(msg); }

        var fd;
        try { fd = new FormData(form, submitter || undefined); } catch (err) { fd = new FormData(form); }
        if (submitter && submitter.name && !fd.has(submitter.name)) fd.append(submitter.name, submitter.value || '');
        // the action attribute itself: form.action is an <input name="action"> on forms that have one
        var attr = form.getAttribute('action');
        var url = attr ? new URL(attr, window.location.href).href : window.location.href;
        var xhr = new XMLHttpRequest();
        xhr.__ysmsOwnOverlay = true;
        xhr.open('POST', url);
        xhr.responseType = 'blob';
        xhr.setRequestHeader('X-YSMS-Upload', '1');
        xhr.upload.addEventListener('progress', function (e) { if (e.lengthComputable) ov.progress(e.loaded, e.total); });
        xhr.upload.addEventListener('load', ov.sent);
        xhr.addEventListener('load', function () {
            var disposition = xhr.getResponseHeader('Content-Disposition') || '';
            if (xhr.status >= 200 && xhr.status < 300 && /attachment/i.test(disposition)) {
                saveBlob(xhr.response, disposition);
                unlock();
                ov.close();
                return;
            }
            xhr.response.text().then(function (text) {
                var data = null;
                try { data = JSON.parse(text); } catch (err) {}
                if (xhr.status === 200 && data && data.redirect) {
                    ov.release();
                    var next = new URL(data.redirect, url);
                    var here = new URL(window.location.href);
                    window.location.href = next.href;
                    // Same page with only a #section: assigning href just scrolls — reload to show the result
                    if (next.hash && next.origin + next.pathname + next.search === here.origin + here.pathname + here.search) window.location.reload();
                    return;
                }
                if (xhr.status >= 200 && xhr.status < 300) {
                    // A page without the server-side support: the upload went through — open the page it ended on
                    ov.release();
                    window.location.href = xhr.responseURL || url;
                    return;
                }
                text = (text || '').trim();
                fail(text && text.length < 300 && text.charAt(0) !== '<' ? text : 'The server could not save the files (error ' + xhr.status + '). Please try again.');
            });
        });
        xhr.addEventListener('error', function () { fail('No connection to the server. Check your internet and try again.'); });
        xhr.addEventListener('abort', function () { fail('The upload was interrupted. Please try again.'); });
        xhr.send(fd);
    }

    function reset(form) {
        delete form.dataset.ysmsSubmitting;
        submitButtons(form, null).forEach(clearLoading);
        hideOverlay();
        busy(-1);
    }

    function start(form, submitter) {
        if (!form || form.dataset.ysmsSubmitting === '1') return;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.hasAttribute('data-no-loading')) return;
        var target = form.getAttribute('target');
        if (target && target !== '_self') return;

        form.dataset.ysmsSubmitting = '1';
        var download = isDownloadForm(form);
        var uploading = !download && hasFiles(form);
        var label = download ? 'Preparing file…' : (uploading ? 'Uploading…' : 'Please wait…');
        submitButtons(form, submitter).forEach(function (b) { setLoading(b, label); });
        busy(1);
        if (uploading) {
            showOverlay('Uploading files…', "This can take a few seconds — please don't close this page.");
        }
        if (download) {
            setTimeout(function () { reset(form); }, 5000);
        }
    }

    // Registered on window (bubble phase) so it runs after the page's own
    // submit handlers: anything they cancel (validation, confirm dialogs,
    // AJAX forms) is left alone. Done synchronously — once the browser starts
    // a form navigation, timers queued here may never run.
    window.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.dataset) return;
        if (form.dataset.ysmsSubmitting === '1') {
            e.preventDefault();
            return;
        }
        if (e.defaultPrevented) return;
        if (isProgressUpload(form)) {
            e.preventDefault();
            uploadWithProgress(form, e.submitter || null);
            return;
        }
        start(form, e.submitter || null);
    });

    // form.submit() (used after confirm dialogs) never fires a submit event.
    var origSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (isProgressUpload(this)) return uploadWithProgress(this, null);
        start(this, null);
        return origSubmit.apply(this, arguments);
    };

    // Coming back with the browser's Back button can restore the page from
    // cache with the spinner still showing — reset everything then.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        var forms = document.querySelectorAll('form[data-ysms-submitting]');
        for (var i = 0; i < forms.length; i++) reset(forms[i]);
        active = 0;
        document.documentElement.classList.remove('ysms-busy', 'ysms-busy-done');
        hideOverlay();
    });
})();
