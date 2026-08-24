<?php
require_once 'config/config.php';
checkAuth();
if (($_SESSION['role'] ?? '') !== 'Admin') {
    header('Location: index.php');
    exit;
}

// Module registry - add a new module here to have it automatically show up
// in Admin Controls with full Add / Edit / Delete / Search support.
$modules = require __DIR__ . '/config/admin_modules.php';
$module_keys = array_keys($modules);
$default_module = $module_keys[0] ?? '';

// 🌟 Assigned Vessels & Pending Reports — ఇవి surveys టేబుల్ మీద ఆధారపడ్డ ప్రత్యేక
// (వర్చువల్) ట్యాబ్‌లు. ఇతర మాడ్యూళ్ల మాదిరి సాధారణ Add/Edit ఫారమ్ కాకుండా, ఇవి
// ఇప్పటికే ఉన్న vessel_detail.php ఎడిట్ పేజీకి లింక్ చేసి, ఇక్కడ కేవలం లిస్ట్ + Delete
// ఇస్తాయి (ajax/admin_surveys.php ద్వారా).
$survey_modules = [
    'assigned_vessels' => ['label' => 'Assigned Vessels', 'icon' => 'fa-ship'],
    'pending_reports'  => ['label' => 'Pending Reports',  'icon' => 'fa-file-invoice'],
];

include 'includes/header.php';
?>
<style>
    .admin-tabs-bar {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding: 14px 16px 4px;
        -webkit-overflow-scrolling: touch;
    }
    .admin-tab-btn {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 9px 14px;
        border-radius: 999px;
        border: 1px solid var(--border-color);
        background: #fff;
        color: var(--text-muted);
        font-size: 12.5px;
        font-weight: 650;
        white-space: nowrap;
    }
    .admin-tab-btn.active {
        background: var(--accent-purple);
        border-color: var(--accent-purple);
        color: #fff;
    }
    .admin-module-panel { display: none; padding: 14px 16px 20px; }
    .admin-module-panel.active { display: block; }
    .admin-search-row { display: flex; gap: 10px; margin-bottom: 14px; }
    .admin-search-row input {
        flex: 1;
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 13px;
        background: #f8fafc;
        outline: none;
    }
    .admin-search-row input:focus { border-color: var(--accent-purple); background: #fff; }
    .admin-add-btn {
        flex: 0 0 auto;
        border: none;
        background: var(--accent-purple);
        color: #fff;
        border-radius: 10px;
        padding: 0 16px;
        font-size: 13px;
        font-weight: 650;
        white-space: nowrap;
    }
    .admin-item-card {
        background: #fff;
        border: 1px solid var(--border-color);
        border-radius: 14px;
        padding: 13px 14px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .admin-item-main { min-width: 0; }
    .admin-item-title { font-size: 13.5px; font-weight: 650; color: var(--text-dark); margin: 0; word-break: break-word; }
    .admin-item-sub { font-size: 11.5px; color: var(--text-muted); margin: 2px 0 0; word-break: break-word; }
    .admin-item-actions { flex: 0 0 auto; display: flex; gap: 6px; }
    .admin-item-actions button {
        width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-color);
        background: #f8fafc; color: var(--text-muted); font-size: 13px;
    }
    .admin-item-actions button.edit-btn:hover { color: var(--accent-purple); border-color: var(--accent-purple); }
    .admin-item-actions button.delete-btn:hover { color: #dc2626; border-color: #dc2626; }
    .admin-item-actions button.cancel-btn { color: #b45309; }
    .admin-item-actions button.cancel-btn:hover { color: #92400e; border-color: #f59e0b; }
    .admin-empty-state { text-align: center; padding: 30px 10px; color: var(--text-muted); font-size: 12.5px; }
    .admin-loading { text-align: center; padding: 20px; color: var(--text-muted); font-size: 12.5px; }
    .admin-field-group { margin-bottom: 14px; }
    .admin-field-group label { display: block; font-size: 12px; font-weight: 650; color: var(--text-muted); margin-bottom: 5px; }
    .admin-field-group input, .admin-field-group select {
        width: 100%; border: 1px solid var(--border-color); border-radius: 10px;
        padding: 10px 12px; font-size: 13.5px; background: #f8fafc; outline: none; color: var(--text-dark);
    }
    .admin-field-group input:focus, .admin-field-group select:focus { border-color: var(--accent-purple); background: #fff; }
    .admin-field-hint { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
    @media (max-width: 480px) {
        .admin-item-title { font-size: 13px; }
    }
</style>

<div class="scroll-content">
    <?php $page_title = 'Admin Controls'; $back_url = 'index.php'; $page_testid = 'admin-controls'; include 'includes/top_app_bar.php'; ?>

    <div class="admin-tabs-bar" data-testid="admin-controls-tabs">
        <?php foreach ($modules as $key => $mod): ?>
            <button type="button" class="admin-tab-btn <?= $key === $default_module ? 'active' : '' ?>" data-module="<?= sanitize($key) ?>" data-testid="admin-tab-<?= sanitize($key) ?>">
                <i class="fa-solid <?= sanitize($mod['icon']) ?>"></i> <?= sanitize($mod['label']) ?>
            </button>
        <?php endforeach; ?>
        <?php foreach ($survey_modules as $key => $mod): ?>
            <button type="button" class="admin-tab-btn" data-module="<?= sanitize($key) ?>" data-survey-tab="1" data-testid="admin-tab-<?= sanitize($key) ?>">
                <i class="fa-solid <?= sanitize($mod['icon']) ?>"></i> <?= sanitize($mod['label']) ?>
            </button>
        <?php endforeach; ?>
        <!-- 🌟 1.2: Business Card / ID Card ఫైళ్లను Admin ఇక్కడ నుండి surveyor కి అప్‌లోడ్ చేయగలరు -->
        <a href="admin_surveyor_cards.php" class="admin-tab-btn text-decoration-none" data-testid="admin-tab-surveyor-cards">
            <i class="fa-solid fa-id-card"></i> ID / Business Cards
        </a>
        <a href="admin_invoice_templates.php" class="admin-tab-btn text-decoration-none" data-testid="admin-tab-invoice-templates">
            <i class="fa-solid fa-file-invoice-dollar"></i> Invoice Templates
        </a>
        <a href="manage_templates.php" class="admin-tab-btn text-decoration-none" data-testid="admin-tab-word-templates">
            <i class="fa-solid fa-file-word"></i> Word / Photo Templates
        </a>
    </div>

    <?php foreach ($modules as $key => $mod): ?>
        <div class="admin-module-panel <?= $key === $default_module ? 'active' : '' ?>" data-module-panel="<?= sanitize($key) ?>" data-testid="admin-panel-<?= sanitize($key) ?>">
            <div class="admin-search-row">
                <input type="text" class="admin-search-input" placeholder="Search <?= sanitize(strtolower($mod['label'])) ?>..." data-testid="admin-search-<?= sanitize($key) ?>">
                <button type="button" class="admin-add-btn" data-testid="admin-add-<?= sanitize($key) ?>"><i class="fa-solid fa-plus"></i> Add</button>
            </div>
            <div class="admin-list-container" data-testid="admin-list-<?= sanitize($key) ?>">
                <div class="admin-loading">Loading...</div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($survey_modules as $key => $mod): ?>
        <div class="admin-module-panel" data-module-panel="<?= sanitize($key) ?>" data-survey-panel="1" data-testid="admin-panel-<?= sanitize($key) ?>">
            <div class="admin-search-row">
                <input type="text" class="admin-search-input" placeholder="Search <?= sanitize(strtolower($mod['label'])) ?>..." data-testid="admin-search-<?= sanitize($key) ?>">
                <?php if ($key === 'assigned_vessels'): ?>
                    <a href="assign_vessel.php" class="admin-add-btn text-decoration-none d-inline-flex align-items-center justify-content-center" data-testid="admin-add-<?= sanitize($key) ?>"><i class="fa-solid fa-plus"></i>&nbsp; Assign</a>
                <?php endif; ?>
            </div>
            <div class="admin-list-container" data-testid="admin-list-<?= sanitize($key) ?>">
                <div class="admin-loading">Loading...</div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Add / Edit Modal (shared, rebuilt per module) -->
<div class="modal fade" id="adminFormModal" tabindex="-1" data-testid="admin-form-modal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: none;">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                <h5 class="modal-title fw-bold" id="adminFormModalTitle" style="font-size: 15px;">Add</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" data-testid="admin-form-close"></button>
            </div>
            <form id="adminForm" data-testid="admin-form">
                <div class="modal-body" id="adminFormFields"></div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" style="background: var(--accent-purple); color:#fff; font-weight:650;" id="adminFormSubmitBtn" data-testid="admin-form-submit">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const ADMIN_MODULES = <?= json_encode($modules, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const SURVEY_MODULES = <?= json_encode($survey_modules, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
</script>

<?php include 'includes/nav.php'; ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    let adminFormModal = new bootstrap.Modal(document.getElementById('adminFormModal'));
    let currentModule = '<?= sanitize($default_module) ?>';
    let currentEditId = null;
    let loadedModules = {};

    function escapeHtml(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    function loadModule(moduleKey, searchTerm) {
        const $container = $('.admin-list-container').filter(function() {
            return $(this).closest('[data-module-panel]').data('module-panel') === moduleKey;
        });
        $container.html('<div class="admin-loading">Loading...</div>');

        const isSurveyModule = !!SURVEY_MODULES[moduleKey];

        $.ajax({
            url: isSurveyModule ? 'ajax/admin_surveys.php' : 'ajax/admin_master.php',
            method: 'GET',
            data: isSurveyModule
                ? { type: moduleKey, action: 'list', q: searchTerm || '' }
                : { module: moduleKey, action: 'list', q: searchTerm || '' },
            dataType: 'json'
        }).done(function(res) {
            if (!res || !res.success) {
                $container.html('<div class="admin-empty-state">Unable to load data.</div>');
                return;
            }
            if (isSurveyModule) {
                renderSurveyRows(moduleKey, $container, res.rows || []);
            } else {
                renderRows(moduleKey, $container, res.rows || []);
            }
        }).fail(function() {
            $container.html('<div class="admin-empty-state">Network error while loading data.</div>');
        });
    }

    // 🌟 "Assigned Vessels" / "Pending Reports" ట్యాబ్‌ల కోసం రో రెండరింగ్ — Edit ఇప్పటికే
    // ఉన్న vessel_detail.php ఎడిట్ పేజీకి లింక్ చేస్తుంది, Delete ఇక్కడే AJAX ద్వారా జరుగుతుంది.
    function renderSurveyRows(moduleKey, $container, rows) {
        const mod = SURVEY_MODULES[moduleKey];
        if (!rows.length) {
            $container.html('<div class="admin-empty-state">No ' + escapeHtml(mod.label.toLowerCase()) + ' found.</div>');
            return;
        }
        let html = '';
        rows.forEach(function(row) {
            const title = escapeHtml(row.vessel_name || '');
            const subParts = [];
            if (row.company_name) subParts.push('Client: ' + escapeHtml(row.company_name));
            if (row.port_name) subParts.push('Port: ' + escapeHtml(row.port_name));
            if (row.surveyor_name) subParts.push('Surveyor: ' + escapeHtml(row.surveyor_name));
            if (row.survey_type_display) subParts.push('Type: ' + escapeHtml(row.survey_type_display));
            const sub = subParts.join(' &middot; ');
            html += '<div class="admin-item-card" data-testid="admin-item-' + moduleKey + '-' + row.id + '">' +
                '<div class="admin-item-main">' +
                    '<p class="admin-item-title">' + title + '</p>' +
                    (sub ? '<p class="admin-item-sub">' + sub + '</p>' : '') +
                '</div>' +
                '<div class="admin-item-actions">' +
                    '<a href="vessel_detail.php?id=' + row.id + '&edit=1" class="edit-btn" style="display:inline-flex;align-items:center;justify-content:center;text-decoration:none;" data-testid="admin-edit-' + moduleKey + '-' + row.id + '" title="Edit"><i class="fa-solid fa-pen"></i></a>' +
                    (moduleKey === 'assigned_vessels'
                        ? '<button type="button" class="cancel-btn" data-id="' + row.id + '" data-testid="admin-cancel-' + moduleKey + '-' + row.id + '" title="Cancel vessel"><i class="fa-solid fa-ban"></i></button>'
                        : '') +
                    '<button type="button" class="delete-btn" data-id="' + row.id + '" data-testid="admin-delete-' + moduleKey + '-' + row.id + '" title="Delete"><i class="fa-solid fa-trash"></i></button>' +
                '</div>' +
            '</div>';
        });
        $container.html(html);

        $container.find('.delete-btn').on('click', function() {
            const id = $(this).data('id');
            confirmDeleteSurvey(moduleKey, id);
        });
        $container.find('.cancel-btn').on('click', function() {
            const id = $(this).data('id');
            confirmCancelSurvey(moduleKey, id);
        });
    }

    function confirmCancelSurvey(moduleKey, id) {
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this vessel?',
            text: 'It will be moved to the Cancelled Vessels list and removed from Pending Vessels.',
            showCancelButton: true,
            confirmButtonText: 'Yes, Cancel',
            confirmButtonColor: '#b45309',
            cancelButtonColor: '#64748b'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: 'ajax/admin_surveys.php',
                method: 'POST',
                data: { type: moduleKey, action: 'cancel', id: id },
                dataType: 'json'
            }).done(function(res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Cancelled', text: res.message, confirmButtonColor: '#3b32b3', timer: 1400, showConfirmButton: false });
                    loadModule(moduleKey, $('[data-module-panel="' + moduleKey + '"] .admin-search-input').val());
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Cancel', text: (res && res.message) ? res.message : 'Please try again.', confirmButtonColor: '#3b32b3' });
                }
            }).fail(function() {
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
            });
        });
    }

    function confirmDeleteSurvey(moduleKey, id) {
        const mod = SURVEY_MODULES[moduleKey];
        Swal.fire({
            icon: 'warning',
            title: 'Delete this record?',
            text: 'This will permanently remove this ' + mod.label.toLowerCase().replace(/s$/, '') + ' and any uploaded reports linked to it. This action cannot be undone.',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: 'ajax/admin_surveys.php',
                method: 'POST',
                data: { type: moduleKey, action: 'delete', id: id },
                dataType: 'json'
            }).done(function(res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, confirmButtonColor: '#3b32b3', timer: 1400, showConfirmButton: false });
                    loadModule(moduleKey, $('[data-module-panel="' + moduleKey + '"] .admin-search-input').val());
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Delete', text: (res && res.message) ? res.message : 'Please try again.', confirmButtonColor: '#3b32b3' });
                }
            }).fail(function() {
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
            });
        });
    }

    function renderRows(moduleKey, $container, rows) {
        const mod = ADMIN_MODULES[moduleKey];
        if (!rows.length) {
            $container.html('<div class="admin-empty-state">No ' + escapeHtml(mod.label.toLowerCase()) + ' found.</div>');
            return;
        }
        let html = '';
        rows.forEach(function(row) {
            const title = escapeHtml(row[mod.name_field] || '');
            let subParts = [];
            mod.fields.forEach(function(f) {
                if (f.name === mod.name_field || f.type === 'password') return;
                if (row[f.name]) subParts.push(f.label + ': ' + escapeHtml(row[f.name]));
            });
            const sub = subParts.join(' &middot; ');
            html += '<div class="admin-item-card" data-testid="admin-item-' + moduleKey + '-' + row[mod.id_field] + '">' +
                '<div class="admin-item-main">' +
                    '<p class="admin-item-title">' + title + '</p>' +
                    (sub ? '<p class="admin-item-sub">' + sub + '</p>' : '') +
                '</div>' +
                '<div class="admin-item-actions">' +
                    '<button type="button" class="edit-btn" data-id="' + row[mod.id_field] + '" data-testid="admin-edit-' + moduleKey + '-' + row[mod.id_field] + '"><i class="fa-solid fa-pen"></i></button>' +
                    '<button type="button" class="delete-btn" data-id="' + row[mod.id_field] + '" data-testid="admin-delete-' + moduleKey + '-' + row[mod.id_field] + '"><i class="fa-solid fa-trash"></i></button>' +
                '</div>' +
            '</div>';
        });
        $container.html(html);

        $container.find('.edit-btn').on('click', function() {
            const id = $(this).data('id');
            const row = rows.find(r => String(r[mod.id_field]) === String(id));
            openForm(moduleKey, row);
        });
        $container.find('.delete-btn').on('click', function() {
            const id = $(this).data('id');
            confirmDelete(moduleKey, id);
        });
    }

    function openForm(moduleKey, row) {
        const mod = ADMIN_MODULES[moduleKey];
        currentModule = moduleKey;
        currentEditId = row ? row[mod.id_field] : null;

        $('#adminFormModalTitle').text((row ? 'Edit ' : 'Add ') + mod.singular);
        let fieldsHtml = '';
        mod.fields.forEach(function(f) {
            const val = row && row[f.name] != null ? row[f.name] : '';
            fieldsHtml += '<div class="admin-field-group">';
            fieldsHtml += '<label>' + escapeHtml(f.label) + (f.required ? ' *' : '') + '</label>';
            if (f.type === 'select') {
                fieldsHtml += '<select name="' + f.name + '" ' + (f.required ? 'required' : '') + ' data-testid="admin-field-' + f.name + '">';
                (f.options || []).forEach(function(opt) {
                    fieldsHtml += '<option value="' + escapeHtml(opt) + '" ' + (val === opt ? 'selected' : '') + '>' + escapeHtml(opt) + '</option>';
                });
                fieldsHtml += '</select>';
            } else if (f.type === 'password') {
                fieldsHtml += '<input type="password" name="' + f.name + '" autocomplete="new-password" ' + (f.required && !row ? 'required' : '') + ' data-testid="admin-field-' + f.name + '">';
                if (f.hint) fieldsHtml += '<div class="admin-field-hint">' + escapeHtml(f.hint) + '</div>';
            } else {
                fieldsHtml += '<input type="text" name="' + f.name + '" value="' + escapeHtml(val) + '" ' + (f.required ? 'required' : '') + ' data-testid="admin-field-' + f.name + '">';
            }
            fieldsHtml += '</div>';
        });
        $('#adminFormFields').html(fieldsHtml);
        adminFormModal.show();
    }

    function confirmDelete(moduleKey, id) {
        const mod = ADMIN_MODULES[moduleKey];
        Swal.fire({
            icon: 'warning',
            title: 'Delete ' + mod.singular + '?',
            text: 'This action cannot be undone.',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#64748b'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            $.ajax({
                url: 'ajax/admin_master.php',
                method: 'POST',
                data: { module: moduleKey, action: 'delete', id: id },
                dataType: 'json'
            }).done(function(res) {
                if (res && res.success) {
                    Swal.fire({ icon: 'success', title: 'Deleted', text: res.message, confirmButtonColor: '#3b32b3', timer: 1400, showConfirmButton: false });
                    loadModule(moduleKey, $('[data-module-panel="' + moduleKey + '"] .admin-search-input').val());
                } else {
                    Swal.fire({ icon: 'error', title: 'Cannot Delete', text: (res && res.message) ? res.message : 'Please try again.', confirmButtonColor: '#3b32b3' });
                }
            }).fail(function() {
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
            });
        });
    }

    // Tab switching
    $('.admin-tab-btn').on('click', function() {
        const key = $(this).data('module');
        $('.admin-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.admin-module-panel').removeClass('active');
        $('[data-module-panel="' + key + '"]').addClass('active');
        currentModule = key;
        if (!loadedModules[key]) {
            loadedModules[key] = true;
            loadModule(key, '');
        }
    });

    // Search (debounced)
    let searchTimer = null;
    $('.admin-search-input').on('input', function() {
        const $panel = $(this).closest('[data-module-panel]');
        const key = $panel.data('module-panel');
        const term = $(this).val();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() { loadModule(key, term); }, 300);
    });

    // Add button (survey tabs don't use this modal — the Assign link on the
    // Assigned Vessels tab navigates straight to assign_vessel.php instead)
    $('.admin-add-btn').on('click', function() {
        const key = $(this).closest('[data-module-panel]').data('module-panel');
        if (SURVEY_MODULES[key]) return;
        openForm(key, null);
    });

    // Submit add/edit form
    $('#adminForm').on('submit', function(e) {
        e.preventDefault();
        const mod = ADMIN_MODULES[currentModule];
        const action = currentEditId ? 'edit' : 'add';
        const formData = $(this).serializeArray();
        const postData = { module: currentModule, action: action };
        if (currentEditId) postData.id = currentEditId;
        formData.forEach(function(f) { postData[f.name] = f.value; });

        const $btn = $('#adminFormSubmitBtn');
        $btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: 'ajax/admin_master.php',
            method: 'POST',
            data: postData,
            dataType: 'json'
        }).done(function(res) {
            if (res && res.success) {
                adminFormModal.hide();
                Swal.fire({ icon: 'success', title: (action === 'add' ? mod.singular + ' Added' : mod.singular + ' Updated'), text: res.message, confirmButtonColor: '#3b32b3', timer: 1400, showConfirmButton: false });
                loadModule(currentModule, $('[data-module-panel="' + currentModule + '"] .admin-search-input').val());
            } else {
                Swal.fire({ icon: 'error', title: 'Could Not Save', text: (res && res.message) ? res.message : 'Please check the form and try again.', confirmButtonColor: '#3b32b3' });
            }
        }).fail(function() {
            Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please try again.', confirmButtonColor: '#3b32b3' });
        }).always(function() {
            $btn.prop('disabled', false).text('Save');
        });
    });

    // 🌟 FIX: URL లో ?tab=clients వంటి పారామీటర్ ఉంటే ఆ మాడ్యూల్ ట్యాబ్‌ను నేరుగా తెరవడం
    //    (Admin FAB మెనూలో "Add Client" లింక్ ఇప్పుడు ఇక్కడికి వస్తుంది).
    //    &add=1 కూడా ఉంటే ఆ మాడ్యూల్ యొక్క Add ఫారమ్‌ను కూడా వెంటనే తెరుస్తుంది.
    const urlParams = new URLSearchParams(window.location.search);
    const requestedTab = urlParams.get('tab');
    if (requestedTab && (ADMIN_MODULES[requestedTab] || SURVEY_MODULES[requestedTab])) {
        $('.admin-tab-btn[data-module="' + requestedTab + '"]').trigger('click');
        if (urlParams.get('add') === '1' && ADMIN_MODULES[requestedTab]) {
            openForm(requestedTab, null);
        }
    }

    // Initial load for the default (first) module
    loadedModules[currentModule] = true;
    loadModule(currentModule, '');
});
</script>

<?php include 'includes/footer.php'; ?>
