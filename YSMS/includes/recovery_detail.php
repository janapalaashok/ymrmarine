<!-- 🌟 Recovery Details Modal: Total Recovery / This Month Recovery కార్డ్ క్లిక్ చేస్తే వచ్చే వెసెల్-వైజ్ రికవరీ లిస్ట్ -->
<div class="modal fade" id="recoveryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
            <div class="modal-header">
                <h5 class="modal-title" id="recoveryModalTitle">Recovery Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size: 13px;">
                <div id="recoveryModalLoading" class="text-center text-muted py-4">Loading...</div>
                <div class="table-responsive" style="display:none;" id="recoveryModalTableWrap">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Vessel Name</th>
                                <th>Survey Type</th>
                                <th class="text-end">VLSFO (MT)</th>
                                <th class="text-end">LSMGO (MT)</th>
                                <th class="text-end">Total (MT)</th>
                            </tr>
                        </thead>
                        <tbody id="recoveryModalBody"></tbody>
                    </table>
                </div>
                <div id="recoveryModalEmpty" class="text-center text-muted py-4" style="display:none;">No recovery records found.</div>
            </div>
            <div class="modal-footer">
                <a href="#" id="recoveryModalExportBtn" class="btn btn-success btn-sm" download>
                    <i class="fa-solid fa-file-excel me-1"></i> Export
                </a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function openRecoveryModal(period) {
    const modalEl = document.getElementById('recoveryModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const title = document.getElementById('recoveryModalTitle');
    const loading = document.getElementById('recoveryModalLoading');
    const tableWrap = document.getElementById('recoveryModalTableWrap');
    const tbody = document.getElementById('recoveryModalBody');
    const empty = document.getElementById('recoveryModalEmpty');
    const exportBtn = document.getElementById('recoveryModalExportBtn');

    title.textContent = period === 'month' ? 'This Month Recovery — Vessel Details' : 'Total Recovery — Vessel Details';
    exportBtn.href = 'export_recovery.php?period=' + encodeURIComponent(period);

    loading.style.display = 'block';
    tableWrap.style.display = 'none';
    empty.style.display = 'none';
    tbody.innerHTML = '';

    modal.show();

    fetch('ajax/recovery_details.php?period=' + encodeURIComponent(period))
        .then(res => res.json())
        .then(data => {
            loading.style.display = 'none';
            if (!data.success || !data.rows || data.rows.length === 0) {
                empty.style.display = 'block';
                return;
            }
            data.rows.forEach(row => {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + escapeHtml(row.vessel_name) + '</td>' +
                    '<td>' + escapeHtml(row.survey_type) + '</td>' +
                    '<td class="text-end">' + escapeHtml(row.vlsfo_recovery) + '</td>' +
                    '<td class="text-end">' + escapeHtml(row.lsmgo_recovery) + '</td>' +
                    '<td class="text-end fw-bold">' + escapeHtml(row.total_recovery) + '</td>';
                tbody.appendChild(tr);
            });
            tableWrap.style.display = 'block';
        })
        .catch(() => {
            loading.style.display = 'none';
            empty.textContent = 'Something went wrong while loading recovery details.';
            empty.style.display = 'block';
        });
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
</script>

