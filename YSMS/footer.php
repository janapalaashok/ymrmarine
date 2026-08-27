</div> <!-- .mobile-container క్లోజింగ్ ట్యాగ్ -->

<!-- Global Center FAB Action Drawer Overlay -->
<div class="fab-overlay" id="fabActionOverlay">
    <div class="fab-popup-sheet" id="fabPopupSheet">
        <h5 class="fw-bold mb-3 text-dark" style="font-size: 16px;">Quick Actions</h5>
        
        <!-- 1. Assign Vessel -->
        <a href="assign_vessel.php" class="fab-option-item" data-testid="fab-assign-vessel-link">
            <i class="fa-solid fa-ship text-primary" style="width: 24px; text-align: center;"></i> 
            <span>Assign Vessel</span>
        </a>
        
        <!-- 2. Add Surveyor -->
        <a href="add_surveyor.php" class="fab-option-item" data-testid="fab-add-surveyor-link">
            <i class="fa-solid fa-user-gear text-warning" style="width: 24px; text-align: center;"></i> 
            <span>Add Surveyor</span>
        </a>
        
        <!-- 3. Add Client -->
        <a href="add_client.php" class="fab-option-item" data-testid="fab-add-client-link">
            <i class="fa-solid fa-user-plus text-success" style="width: 24px; text-align: center;"></i> 
            <span>Add Client</span>
        </a>

        <!-- 4. Admin Controls (Admin మాత్రమే ఈ FAB మెనూను చూస్తారు కాబట్టి ఇక్కడ role చెక్ అవసరం లేదు) -->
        <a href="admin_controls.php" class="fab-option-item" data-testid="fab-admin-controls-link">
            <i class="fa-solid fa-user-shield text-primary" style="width: 24px; text-align: center;"></i> 
            <span>Admin Controls</span>
        </a>
        
        <button class="btn btn-light w-100 mt-3 rounded-3 fw-bold text-danger" id="closeFabBtn" style="font-size: 14px; padding: 12px;" data-testid="fab-close-button">Close</button>
    </div>
</div>

<!-- Scripts Loader -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>

<script>
(function() {
    var btn = document.getElementById('notifBellBtn');
    var panel = document.getElementById('notifPanel');
    var body = document.getElementById('notifPanelBody');
    var badge = document.getElementById('notifBadge');
    var markAll = document.getElementById('notifMarkAll');
    if (!btn || !panel) return;

    var apiBase = 'ajax/notifications.php';
    // If page is in a subfolder, still point to root ajax
    try {
        var path = window.location.pathname || '';
        if (path.indexOf('/ajax/') !== -1) apiBase = 'notifications.php';
    } catch (e) {}

    function setBadge(n) {
        n = parseInt(n, 10) || 0;
        if (!badge) return;
        if (n > 0) {
            badge.textContent = n > 99 ? '99+' : String(n);
            badge.classList.add('show');
        } else {
            badge.textContent = '';
            badge.classList.remove('show');
        }
    }

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function(c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
        });
    }

    function renderItems(items) {
        if (!body) return;
        if (!items || !items.length) {
            body.innerHTML = '<div class="notif-empty"><i class="fa-regular fa-bell-slash d-block mb-2 fs-4"></i>No notifications yet</div>';
            return;
        }
        var html = '';
        items.forEach(function(it) {
            var cls = 'notif-item' + (it.is_read ? '' : ' unread');
            var href = it.link ? esc(it.link) : '#';
            html += '<a class="' + cls + '" href="' + href + '" data-id="' + it.id + '">' +
                '<div class="notif-icon type-' + esc(it.type || 'info') + '"><i class="fa-solid ' + esc(it.icon || 'fa-bell') + '"></i></div>' +
                '<div class="notif-content">' +
                    '<div class="notif-title">' + esc(it.title) + '</div>' +
                    (it.message ? '<div class="notif-msg">' + esc(it.message) + '</div>' : '') +
                    '<div class="notif-time">' + esc(it.time_ago) + '</div>' +
                '</div>' +
                '<span class="notif-dot"></span>' +
            '</a>';
        });
        body.innerHTML = html;
        body.querySelectorAll('.notif-item').forEach(function(el) {
            el.addEventListener('click', function(e) {
                var id = el.getAttribute('data-id');
                if (id) {
                    fetch(apiBase, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'action=read&id=' + encodeURIComponent(id)
                    }).then(function(r){ return r.json(); }).then(function(d){
                        if (d && d.ok) setBadge(d.count);
                    }).catch(function(){});
                }
                if (!el.getAttribute('href') || el.getAttribute('href') === '#') {
                    e.preventDefault();
                }
            });
        });
    }

    function loadList() {
        if (body) body.innerHTML = '<div class="notif-loading">Loading…</div>';
        fetch(apiBase + '?action=list', { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok) {
                    if (body) body.innerHTML = '<div class="notif-empty">Could not load</div>';
                    return;
                }
                setBadge(d.count);
                renderItems(d.items || []);
            })
            .catch(function(){
                if (body) body.innerHTML = '<div class="notif-empty">Network error</div>';
            });
    }

    function refreshCount() {
        fetch(apiBase + '?action=count', { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){ if (d && d.ok) setBadge(d.count); })
            .catch(function(){});
    }

    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var open = panel.classList.toggle('open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) loadList();
    });

    document.addEventListener('click', function(e) {
        if (!panel.classList.contains('open')) return;
        if (e.target.closest('.notif-bell-wrap')) return;
        panel.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    });

    if (markAll) {
        markAll.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fetch(apiBase, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=read_all'
            }).then(function(r){ return r.json(); }).then(function(d){
                setBadge(0);
                loadList();
            }).catch(function(){});
        });
    }

    // Poll unread count every 45s
    setInterval(refreshCount, 45000);
})();
</script>

</body>
</html>