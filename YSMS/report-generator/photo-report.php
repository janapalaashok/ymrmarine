<?php
require_once "../config/auth.php";
require_once "../config/database.php";
?>

<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Photo Report Generator — Marine Survey</title>

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<!-- SortableJS -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<!-- docx.js -->
<script src="https://unpkg.com/docx@7.8.2/build/index.js"></script>
<!-- FileSaver.js -->
<script src="https://cdn.jsdelivr.net/npm/file-saver@2.0.5/dist/FileSaver.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
  --bg-primary: #0a0f1e;
  --bg-secondary: #0d1526;
  --bg-card: #111927;
  --bg-card-hover: #162038;
  --bg-input: #0d1a2d;
  --border: #1e3250;
  --border-accent: #1e4a7a;
  --accent: #00A3DF;
  --accent-dim: #0093C8;
  --accent-glow: rgba(0,163,223,0.15);
  --gold: #f59e0b;
  --gold-dim: rgba(245,158,11,0.15);
  --text-primary: #e2eaf4;
  --text-secondary: #7ea5c7;
  --text-muted: #4a7098;
  --danger: #ef4444;
  --success: #22c55e;
  --warning: #f59e0b;
  --section-mandatory: rgba(14,165,233,0.08);
  --section-hold: rgba(16,185,129,0.05);
}
[data-theme="light"] {
  --bg-primary: #f0f4f8;
  --bg-secondary: #e8edf5;
  --bg-card: #ffffff;
  --bg-card-hover: #f5f8fc;
  --bg-input: #f8fafc;
  --border: #d1dce8;
  --border-accent: #93c5fd;
  --accent: #00A3DF;
  --accent-dim: #0093C8;
  --accent-glow: rgba(0,163,223,0.1);
  --gold: #d97706;
  --gold-dim: rgba(217,119,6,0.1);
  --text-primary: #1e3a5f;
  --text-secondary: #3b6494;
  --text-muted: #7ea5c7;
  --danger: #dc2626;
  --success: #22c55e;
  --warning: #d97706;
  --section-mandatory: rgba(2,132,199,0.06);
  --section-hold: rgba(5,150,105,0.04);
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  background: var(--bg-primary);
  color: var(--text-primary);
  min-height: 100vh;
  font-size: 14px;
  transition: background 0.3s, color 0.3s;
}

/* ── Navbar ── */
.topbar {
  background: var(--bg-secondary);
  border-bottom: 1px solid var(--border);
  padding: 0 1.5rem;
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 1000;
}
.topbar-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 700;
  font-size: 15px;
  color: var(--accent);
  letter-spacing: 0.3px;
}
.topbar-brand .icon-ship {
  width: 32px;
  height: 32px;
  background: var(--accent-glow);
  border: 1px solid var(--border-accent);
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
}
.topbar-brand span.sub { color: var(--text-secondary); font-weight: 400; font-size: 12px; margin-top: 2px; display: block; }
.topbar-right { display: flex; align-items: center; gap: 10px; }

.btn-theme {
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  border-radius: 8px;
  padding: 6px 10px;
  cursor: pointer;
  font-size: 14px;
  transition: all 0.2s;
}
.btn-theme:hover { border-color: var(--accent); color: var(--accent); }

.total-counter {
  background: var(--accent-glow);
  border: 1px solid var(--border-accent);
  color: var(--accent);
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

/* ── Layout ── */
.main-layout {
  display: grid;
  grid-template-columns: 340px 1fr;
  min-height: calc(100vh - 56px);
}

/* ── Sidebar ── */
.sidebar {
  background: var(--bg-secondary);
  border-right: 1px solid var(--border);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
  position: sticky;
  top: 56px;
  height: calc(100vh - 56px);
  overflow-y: auto;
}
.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
.sidebar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

.panel-title {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--text-muted);
  padding-bottom: 8px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 4px;
}

.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--text-secondary);
  letter-spacing: 0.3px;
}
.form-label .req { color: var(--danger); margin-left: 2px; }
.form-control {
  background: var(--bg-input);
  border: 1px solid var(--border);
  color: var(--text-primary);
  border-radius: 8px;
  padding: 8px 12px;
  font-size: 13px;
  transition: border-color 0.2s, box-shadow 0.2s;
  width: 100%;
}
.form-control:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-glow);
  background: var(--bg-input);
  color: var(--text-primary);
}
.form-control::placeholder { color: var(--text-muted); }

/* Progress ring */
.progress-wrap {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.progress-bar-custom {
  height: 4px;
  background: var(--border);
  border-radius: 4px;
  overflow: hidden;
}
.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, var(--accent), var(--gold));
  border-radius: 4px;
  transition: width 0.4s ease;
  width: 0%;
}
.progress-label { font-size: 11px; color: var(--text-muted); display: flex; justify-content: space-between; }

/* Validation checklist */
.checklist { display: flex; flex-direction: column; gap: 5px; }
.check-item {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 11px;
  color: var(--text-muted);
  transition: color 0.2s;
}
.check-item.done { color: var(--success); }
.check-item .ci { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 8px; transition: all 0.2s; flex-shrink: 0; }
.check-item.done .ci { background: var(--success); border-color: var(--success); color: white; }

/* Generate button */
.btn-generate {
  background: linear-gradient(135deg, var(--accent), #0369a1);
  border: none;
  color: white;
  border-radius: 10px;
  padding: 12px;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  letter-spacing: 0.3px;
}
.btn-generate:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 20px rgba(14,165,233,0.35); }
.btn-generate:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
.btn-generate.loading { animation: pulse 1.2s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.6} }

.btn-reset {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--text-secondary);
  border-radius: 10px;
  padding: 9px;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
}
.btn-reset:hover { border-color: var(--danger); color: var(--danger); }

/* ── Main Content ── */
.content-area {
  padding: 1.5rem;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

/* Section Cards */
.section-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
  transition: border-color 0.2s;
}
.section-card.mandatory { background: var(--section-mandatory); border-color: var(--border-accent); }
.section-card.hold { background: var(--section-hold); }
.section-card.has-images { border-color: var(--accent-dim); }

.section-header {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  user-select: none;
  transition: background 0.2s;
}
.section-header:hover { background: var(--bg-card-hover); }

.section-title-row { display: flex; align-items: center; gap: 10px; }
.section-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.icon-mandatory { background: rgba(14,165,233,0.15); color: var(--accent); }
.icon-hold { background: rgba(16,185,129,0.12); color: var(--success); }
.icon-other { background: rgba(245,158,11,0.12); color: var(--gold); }

.section-name { font-weight: 700; font-size: 13px; }
.section-badge-wrap { display: flex; align-items: center; gap: 8px; }
.mandatory-badge {
  background: var(--accent-glow);
  border: 1px solid var(--border-accent);
  color: var(--accent);
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.8px;
  padding: 2px 7px;
  border-radius: 20px;
  text-transform: uppercase;
}
.img-count-badge {
  background: var(--bg-input);
  border: 1px solid var(--border);
  color: var(--text-secondary);
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 20px;
  min-width: 28px;
  text-align: center;
}
.img-count-badge.has-imgs { background: var(--accent-glow); border-color: var(--border-accent); color: var(--accent); }

.chevron { color: var(--text-muted); transition: transform 0.25s; font-size: 12px; }
.collapsed .chevron { transform: rotate(-90deg); }

/* Section body */
.section-body { padding: 14px 16px; display: none; }
.section-body.open { display: block; }

/* Drop zone */
.drop-zone {
  border: 2px dashed var(--border);
  border-radius: 10px;
  padding: 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s;
  margin-bottom: 14px;
  position: relative;
}
.drop-zone.dragover { border-color: var(--accent); background: var(--accent-glow); }
.drop-zone input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.drop-icon { font-size: 24px; color: var(--text-muted); margin-bottom: 6px; }
.drop-text { color: var(--text-muted); font-size: 12px; }
.drop-text strong { color: var(--accent); }
.btn-upload {
  background: var(--accent);
  color: white;
  border: none;
  border-radius: 8px;
  padding: 7px 16px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  margin-top: 8px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all 0.2s;
}
.btn-upload:hover { background: var(--accent-dim); }

/* Thumbnail grid */
.thumb-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
  min-height: 0;
}

.thumb-item {
  background: var(--bg-input);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  cursor: grab;
  transition: transform 0.15s, box-shadow 0.15s, border-color 0.2s;
  position: relative;
}
.thumb-item:hover { border-color: var(--accent-dim); box-shadow: 0 4px 16px rgba(0,0,0,0.3); }
.thumb-item.sortable-ghost { opacity: 0.35; }
.thumb-item.sortable-chosen { cursor: grabbing; }

.thumb-img-wrap {
  position: relative;
  width: 100%;
  padding-top: 60%;
  background: var(--bg-primary);
  overflow: hidden;
}
.thumb-img-wrap img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.thumb-sno {
  position: absolute;
  top: 6px;
  left: 6px;
  background: rgba(0,0,0,0.7);
  color: white;
  font-size: 10px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 10px;
}
.thumb-del {
  position: absolute;
  top: 6px;
  right: 6px;
  background: rgba(239,68,68,0.9);
  color: white;
  border: none;
  border-radius: 6px;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 12px;
  opacity: 0;
  transition: opacity 0.2s;
}
.thumb-item:hover .thumb-del { opacity: 1; }

.thumb-desc {
  padding: 8px 10px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

/* Custom select */
.desc-select {
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-primary);
  border-radius: 6px;
  padding: 5px 8px;
  font-size: 11px;
  width: 100%;
  cursor: pointer;
}
.desc-select:focus { outline: none; border-color: var(--accent); }

.desc-input {
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-primary);
  border-radius: 6px;
  padding: 5px 8px;
  font-size: 11px;
  width: 100%;
  resize: none;
}
.desc-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent-glow); }

/* Drag handle */
.drag-handle {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 20px;
  color: var(--text-muted);
  font-size: 12px;
  cursor: grab;
}

/* Toast */
.toast-container {
  position: fixed;
  bottom: 24px;
  right: 24px;
  z-index: 9999;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.toast-msg {
  background: var(--bg-card);
  border: 1px solid var(--border);
  color: var(--text-primary);
  border-radius: 10px;
  padding: 12px 16px;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.4);
  animation: slideIn 0.3s ease;
  max-width: 320px;
}
.toast-msg.success { border-color: var(--success); }
.toast-msg.error { border-color: var(--danger); }
.toast-msg.warning { border-color: var(--warning); }
@keyframes slideIn { from { transform: translateX(40px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

/* Loading overlay */
.loading-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(10,15,30,0.88);
  z-index: 9998;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 16px;
}
.loading-overlay.show { display: flex; }
.loading-spinner {
  width: 52px;
  height: 52px;
  border: 3px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loading-text { color: var(--text-secondary); font-size: 14px; }

/* Mobile */
@media (max-width: 768px) {
  .main-layout { grid-template-columns: 1fr; }
  .sidebar { position: static; height: auto; }
  .thumb-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
}

/* Scrollbar */
.content-area::-webkit-scrollbar { width: 5px; }
.content-area::-webkit-scrollbar-track { background: transparent; }
.content-area::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }

/* Section nav dots */
.section-nav { display: flex; flex-wrap: wrap; gap: 5px; }
.nav-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--border);
  border: none;
  cursor: pointer;
  transition: all 0.2s;
}
.nav-dot.has { background: var(--accent); }
.nav-dot.mandatory-dot { border: 1.5px solid var(--accent-dim); }

/* Auto-save badge */
.autosave-badge {
  font-size: 10px;
  color: var(--success);
  display: flex;
  align-items: center;
  gap: 4px;
  opacity: 0;
  transition: opacity 0.4s;
}
.autosave-badge.show { opacity: 1; }

</style>
</head>
<body>
<!-- Navbar -->
<header class="topbar">
  <a href="index.php"><div class="topbar-brand">
<button class="btn-theme" id="themeBtn2" title="Toggle Theme2"><i class="bi bi-house-fill"></i></button>        <div>
      GO BACK TO REPORT DASHBOARD
          </div>
  </div></a>
  <div class="topbar-right">
    <div class="total-counter" id="totalCounter">0 / 500 Photos</div>
    <div class="autosave-badge" id="autosaveBadge"><i class="bi bi-check-circle-fill"></i> Auto-saved</div>
    <button class="btn-theme" id="themeBtn" title="Toggle Theme"><i class="bi bi-moon-fill"></i></button>
    
  </div>
</header>

<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="loading-spinner"></div>
  <div class="loading-text" id="loadingText">Generating Report…</div>
</div>

<!-- Toast Container -->
<div class="toast-container" id="toastContainer"></div>

<!-- Main Layout -->
<div class="main-layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div>
      <div class="panel-title">Report Information</div>
      <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px;">
        <div class="form-group">
          <label class="form-label">Report Name <span class="req">*</span></label>
          <input class="form-control" id="reportName" placeholder="e.g. Cargo Hold Survey Report" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Vessel Name <span class="req">*</span></label>
          <input class="form-control" id="vesselName" placeholder="e.g. MV Pacific Star" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Place of Survey <span class="req">*</span></label>
          <input class="form-control" id="placeOfSurvey" placeholder="e.g. Port of Singapore" autocomplete="off">
        </div>
        <div class="form-group">
          <label class="form-label">Survey Date <span class="req">*</span></label>
          <input class="form-control" id="surveyDate" type="date">
        </div>
        <div class="form-group">
          <label class="form-label">Surveyor Name <span style="color:var(--text-muted);font-weight:400">(Optional)</span></label>
          <input class="form-control" id="surveyorName" placeholder="e.g. Capt. A. Kumar" autocomplete="off">
        </div>
      </div>
    </div>

    <div>
      <div class="panel-title">Progress</div>
      <div class="progress-wrap">
        <div class="progress-bar-custom"><div class="progress-fill" id="progressFill"></div></div>
        <div class="progress-label"><span id="progressLabel">0 photos uploaded</span><span id="progressPct">0%</span></div>
      </div>
      <div style="height:10px"></div>
      <div class="checklist" id="checklist">
        <div class="check-item" id="chk-reportName"><div class="ci"><i class="bi bi-check"></i></div>Report Name</div>
        <div class="check-item" id="chk-vesselName"><div class="ci"><i class="bi bi-check"></i></div>Vessel Name</div>
        <div class="check-item" id="chk-placeOfSurvey"><div class="ci"><i class="bi bi-check"></i></div>Place of Survey</div>
        <div class="check-item" id="chk-surveyDate"><div class="ci"><i class="bi bi-check"></i></div>Survey Date</div>
        <div class="check-item" id="chk-shipSide"><div class="ci"><i class="bi bi-check"></i></div>Ship Side Photos ≥ 1</div>
        <div class="check-item" id="chk-deckSide"><div class="ci"><i class="bi bi-check"></i></div>Deck Side Photos ≥ 1</div>
      </div>
    </div>

    <div>
      <div class="panel-title">Section Overview</div>
      <div class="section-nav" id="sectionNav"></div>
    </div>

    <div style="margin-top:auto;display:flex;flex-direction:column;gap:8px;">
      <button class="btn-generate" id="generateBtn" disabled>
        <i class="bi bi-file-earmark-word-fill"></i> Generate PHOTO_REPORT.docx
      </button>
      <button class="btn-reset" id="resetBtn">
        <i class="bi bi-trash3"></i> Clear All
      </button>
    </div>
  </aside>

  <!-- Content -->
  <main class="content-area" id="contentArea"></main>
</div>

<script>
// ─── CONFIG ──────────────────────────────────────────────────────────────────
const MAX_PHOTOS = 500;
const SECTIONS = [
  { id: 'shipSide',  label: 'SHIP SIDE PHOTOS',  icon: '🚢', type: 'mandatory', descKey: 'shipSide' },
  { id: 'hold1',    label: 'HOLD NO.1 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold1'    },
  { id: 'hold2',    label: 'HOLD NO.2 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold2'    },
  { id: 'hold3',    label: 'HOLD NO.3 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold3'    },
  { id: 'hold4',    label: 'HOLD NO.4 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold4'    },
  { id: 'hold5',    label: 'HOLD NO.5 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold5'    },
  { id: 'hold6',    label: 'HOLD NO.6 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold6'    },
  { id: 'hold7',    label: 'HOLD NO.7 PHOTOS',   icon: '🔲', type: 'hold',      descKey: 'hold7'    },
  { id: 'deckSide', label: 'DECK SIDE PHOTOS',   icon: '🏗️', type: 'mandatory', descKey: 'deckSide' },
  { id: 'other',    label: 'OTHER PHOTOS',        icon: '📷', type: 'other',     descKey: 'other'    }
];

const DESCRIPTIONS = {
  shipSide: ['Viewing at the M/V “VESSEL NAME” Forward section while she lay afloat and moored port/Starboard side alongside at Berth No., Port Name, Country name.','Port Side View','Starboard Side View','Bow View','Stern View'],
  hold1:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
  hold2:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
  hold3:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
  hold4:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
  hold5:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
  hold6:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
  hold7:    ['Forward hatch coamings.','Aft hatch coamings.','Portside hatch coamings','starboard side hatch coamings','Forward Bulkhead.','Aft Bulkhead.','Portside Bulkhead','starboard side Bulkhead','starboard side lower hopper','starboard side upper hopper','port side lower hopper','port side upper hopper','starboard side lower hopper','starboard side upper hopper','Vertical ladder','Australian ladder',],
 
  deckSide: ['DECK SIDE : Main Deck View','DECK SIDE : Hatch Cover View','DECK SIDE : Ventilator Area','DECK SIDE : Crane Area','DECK SIDE : Manifold Area'],
  other:    ['OTHER : Survey Observation','OTHER : Equipment Inspection','OTHER : Additional Observation']
};

// ─── STATE ───────────────────────────────────────────────────────────────────
const state = {};
SECTIONS.forEach(s => state[s.id] = []);

// ─── INIT ─────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  buildSections();
  buildSectionNav();
  
  attachInfoListeners();
  attachTheme();
  document.getElementById('generateBtn').addEventListener('click', generateReport);
  document.getElementById('resetBtn').addEventListener('click', resetAll);
});

// ─── BUILD UI ─────────────────────────────────────────────────────────────────
function buildSections() {
  const area = document.getElementById('contentArea');
  SECTIONS.forEach(sec => {
    const card = document.createElement('div');
    card.className = `section-card ${sec.type === 'mandatory' ? 'mandatory' : sec.type === 'hold' ? 'hold' : ''}`;
    card.id = `card-${sec.id}`;

    const iconClass = sec.type === 'mandatory' ? 'icon-mandatory' : sec.type === 'hold' ? 'icon-hold' : 'icon-other';
    const mandBadge = sec.type === 'mandatory' ? '<span class="mandatory-badge">Required</span>' : '';

    card.innerHTML = `
      <div class="section-header" onclick="toggleSection('${sec.id}')">
        <div class="section-title-row">
          <div class="section-icon ${iconClass}">${sec.icon}</div>
          <div>
            <div class="section-name">${sec.label}</div>
          </div>
        </div>
        <div class="section-badge-wrap">
          ${mandBadge}
          <span class="img-count-badge" id="badge-${sec.id}">0</span>
          <i class="bi bi-chevron-down chevron" id="chev-${sec.id}"></i>
        </div>
      </div>
      <div class="section-body" id="body-${sec.id}">
        <div class="drop-zone" id="dz-${sec.id}">
          <input type="file" accept=".jpg,.jpeg,.png" multiple id="file-${sec.id}" onchange="handleFiles('${sec.id}', this.files)">
          <div class="drop-icon"><i class="bi bi-cloud-upload"></i></div>
          <div class="drop-text">Drag & drop photos here or</div>
          <button class="btn-upload" onclick="document.getElementById('file-${sec.id}').click();event.stopPropagation()">
            <i class="bi bi-plus-lg"></i> Add Photos
          </button>
          <div class="drop-text" style="margin-top:6px;font-size:10px">JPG, JPEG, PNG — Up to 500 total</div>
        </div>
        <div class="thumb-grid" id="grid-${sec.id}"></div>
      </div>
    `;
    area.appendChild(card);

    // Drag-and-drop on drop zone
    const dz = card.querySelector(`#dz-${sec.id}`);
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
    dz.addEventListener('drop', e => {
      e.preventDefault();
      dz.classList.remove('dragover');
      handleFiles(sec.id, e.dataTransfer.files);
    });

    // SortableJS
    Sortable.create(card.querySelector(`#grid-${sec.id}`), {
      animation: 150,
      handle: '.drag-handle',
      ghostClass: 'sortable-ghost',
      chosenClass: 'sortable-chosen',
      onEnd: () => syncStateFromDOM(sec.id)
    });

    // Expand mandatory sections by default
    if (sec.type === 'mandatory') toggleSection(sec.id);
  });
}

function buildSectionNav() {
  const nav = document.getElementById('sectionNav');
  SECTIONS.forEach(sec => {
    const dot = document.createElement('button');
    dot.className = `nav-dot ${sec.type === 'mandatory' ? 'mandatory-dot' : ''}`;
    dot.id = `dot-${sec.id}`;
    dot.title = sec.label;
    dot.onclick = () => {
      const card = document.getElementById(`card-${sec.id}`);
      card.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const body = document.getElementById(`body-${sec.id}`);
      if (!body.classList.contains('open')) toggleSection(sec.id);
    };
    nav.appendChild(dot);
  });
}

// ─── TOGGLE SECTION ───────────────────────────────────────────────────────────
function toggleSection(id) {
  const body = document.getElementById(`body-${id}`);
  const header = body.previousElementSibling;
  const chev = document.getElementById(`chev-${id}`);
  const isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  header.classList.toggle('collapsed', isOpen);
}

// ─── HANDLE FILES ─────────────────────────────────────────────────────────────
function handleFiles(sectionId, files) {
  const totalNow = getTotalCount();
  const arr = Array.from(files).filter(f => /\.(jpg|jpeg|png)$/i.test(f.name));
  const canAdd = MAX_PHOTOS - totalNow;
  if (canAdd <= 0) { showToast('Maximum 500 photos reached!', 'error'); return; }
  const toAdd = arr.slice(0, canAdd);
  if (arr.length > canAdd) showToast(`Only ${canAdd} more photos allowed. ${arr.length - canAdd} skipped.`, 'warning');

  toAdd.forEach(file => {
    const id = 'img_' + Date.now() + '_' + Math.random().toString(36).slice(2);
    const reader = new FileReader();
    reader.onload = e => {
      const item = {
  id,
  dataUrl: e.target.result,
  name: file.name,
  desc: ''
};
      state[sectionId].push(item);
addThumb(sectionId, item);
updateCounters();
updateValidation();
       
    };
    reader.readAsDataURL(file);
  });

  // Reset file input
  const fi = document.getElementById(`file-${sectionId}`);
  if (fi) fi.value = '';
}

// ─── ADD THUMBNAIL ─────────────────────────────────────────────────────────────
function addThumb(sectionId, item) {
  const grid = document.getElementById(`grid-${sectionId}`);
  const descs = DESCRIPTIONS[SECTIONS.find(s=>s.id===sectionId).descKey];

  const el = document.createElement('div');
  el.className = 'thumb-item';
  el.dataset.id = item.id;

const options =
`<option value="" ${!item.desc ? 'selected' : ''} disabled>
-- Select Description --
</option>` +
descs.map(d =>
`<option value="${d}" ${d===item.desc?'selected':''}>${d}</option>`
).join('');

  el.innerHTML = `
    <div class="thumb-img-wrap">
      <img src="${item.dataUrl}" loading="lazy" alt="${item.name}">
      <span class="thumb-sno">—</span>
      <button class="thumb-del" onclick="deleteImage('${sectionId}','${item.id}')"><i class="bi bi-x"></i></button>
    </div>
    <div class="thumb-desc">
      <select class="desc-select" onchange="onDescSelect('${sectionId}','${item.id}',this)">
        ${options}
        <option value="__custom__">✏️ Custom…</option>
      </select>
      <textarea class="desc-input" rows="2" placeholder="Description…" onchange="onDescInput('${sectionId}','${item.id}',this)">${item.desc}</textarea>
    </div>
    <div class="drag-handle">⠿ drag to reorder</div>
  `;
  grid.appendChild(el);
  updateSerialNumbers(sectionId);
}

function onDescSelect(sectionId, imgId, sel) {
  if (sel.value === '__custom__') {
    const ta = sel.nextElementSibling;
    ta.value = '';
    ta.focus();
    updateItemDesc(sectionId, imgId, '');
  } else {
    sel.nextElementSibling.value = sel.value;
    updateItemDesc(sectionId, imgId, sel.value);
  }
   
}

function onDescInput(sectionId, imgId, ta) {
  updateItemDesc(sectionId, imgId, ta.value);
   
}

function updateItemDesc(sectionId, imgId, desc) {
  const item = state[sectionId].find(i => i.id === imgId);

  if (item) {
    item.desc = desc;
  }

  updateValidation();
}

// ─── DELETE ───────────────────────────────────────────────────────────────────
function deleteImage(sectionId, imgId) {
  state[sectionId] = state[sectionId].filter(i => i.id !== imgId);
  const el = document.querySelector(`[data-id="${imgId}"]`);
  if (el) { el.style.opacity='0'; el.style.transform='scale(0.8)'; el.style.transition='all 0.2s'; setTimeout(()=>el.remove(),200); }
  updateCounters();
  updateSerialNumbers(sectionId);
   
}

// ─── SYNC STATE FROM DOM (after sort) ────────────────────────────────────────
function syncStateFromDOM(sectionId) {
  const grid = document.getElementById(`grid-${sectionId}`);
  const newOrder = [];
  grid.querySelectorAll('.thumb-item').forEach(el => {
    const found = state[sectionId].find(i => i.id === el.dataset.id);
    if (found) newOrder.push(found);
  });
  state[sectionId] = newOrder;
  updateSerialNumbers(sectionId);
   
}

// ─── SERIAL NUMBERS ───────────────────────────────────────────────────────────
function updateSerialNumbers(sectionId) {
  const grid = document.getElementById(`grid-${sectionId}`);
  grid.querySelectorAll('.thumb-sno').forEach((el, i) => { el.textContent = `#${i+1}`; });
}

// ─── COUNTERS ─────────────────────────────────────────────────────────────────
function getTotalCount() {
  return SECTIONS.reduce((a, s) => a + state[s.id].length, 0);
}

function updateCounters() {
  const total = getTotalCount();
  document.getElementById('totalCounter').textContent = `${total} / 500 Photos`;
  document.getElementById('progressLabel').textContent = `${total} photo${total!==1?'s':''} uploaded`;
  document.getElementById('progressPct').textContent = Math.round(total/MAX_PHOTOS*100) + '%';
  document.getElementById('progressFill').style.width = Math.min(total/MAX_PHOTOS*100, 100) + '%';

  SECTIONS.forEach(sec => {
    const cnt = state[sec.id].length;
    const badge = document.getElementById(`badge-${sec.id}`);
    badge.textContent = cnt;
    badge.className = `img-count-badge ${cnt > 0 ? 'has-imgs' : ''}`;
    const card = document.getElementById(`card-${sec.id}`);
    card.classList.toggle('has-images', cnt > 0);
    const dot = document.getElementById(`dot-${sec.id}`);
    if (dot) dot.classList.toggle('has', cnt > 0);
  });

  updateValidation();
}

let allDescriptionsValid = true;

SECTIONS.forEach(sec => {
  state[sec.id].forEach(img => {
    if (!img.desc || img.desc.trim() === '') {
      allDescriptionsValid = false;
    }
  });
});
// ─── VALIDATION ───────────────────────────────────────────────────────────────
function updateValidation() {
let allDescriptionsValid = true;

SECTIONS.forEach(sec => {
  state[sec.id].forEach(img => {
    if (!img.desc || img.desc.trim() === '') {
      allDescriptionsValid = false;
    }
  });
});

  const vals = {
    reportName: document.getElementById('reportName').value.trim(),
    vesselName: document.getElementById('vesselName').value.trim(),
    placeOfSurvey: document.getElementById('placeOfSurvey').value.trim(),
    surveyDate: document.getElementById('surveyDate').value.trim(),
    shipSide: state.shipSide.length > 0,
    deckSide: state.deckSide.length > 0
  };
  const keys = Object.keys(vals);
  let done = 0;
  keys.forEach(k => {
    const ok = k === 'shipSide' || k === 'deckSide' ? vals[k] : vals[k].length > 0;
    const el = document.getElementById(`chk-${k}`);
    if (el) { el.classList.toggle('done', ok); }
    if (ok) done++;
  });
const allOk =
  keys.every(k =>
    k === 'shipSide' || k === 'deckSide'
      ? vals[k]
      : vals[k].length > 0
  ) &&
  getTotalCount() > 0 &&
  allDescriptionsValid;
  document.getElementById('generateBtn').disabled = false;
}

// ─── INFO LISTENERS ───────────────────────────────────────────────────────────
function attachInfoListeners() {
  ['reportName','vesselName','placeOfSurvey','surveyDate','surveyorName'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => {
      updateValidation();
       
    });
  });
  updateValidation();
}

// ─── THEME ────────────────────────────────────────────────────────────────────
function attachTheme() {
  const btn = document.getElementById('themeBtn');
  const saved = localStorage.getItem('prg_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', saved);
  btn.innerHTML = saved === 'dark' ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-fill"></i>';
  btn.addEventListener('click', () => {
    const cur = document.documentElement.getAttribute('data-theme');
    const next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    btn.innerHTML = next === 'dark' ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-fill"></i>';
    localStorage.setItem('prg_theme', next);
  });
}

// ─── LOCAL STORAGE ────────────────────────────────────────────────────────────


// ─── RESET ────────────────────────────────────────────────────────────────────
function resetAll() {
  if (!confirm('Clear all photos and report information?')) return;
  SECTIONS.forEach(s => {
    state[s.id] = [];
    document.getElementById(`grid-${s.id}`).innerHTML = '';
  });
  ['reportName','vesselName','placeOfSurvey','surveyDate','surveyorName'].forEach(id => {
    document.getElementById(id).value = '';
  });
  
  updateCounters();
  showToast('All data cleared.', 'warning');
}

// ─── TOAST ────────────────────────────────────────────────────────────────────
function showToast(msg, type='success') {
  const tc = document.getElementById('toastContainer');
  const icon = type === 'success' ? '✅' : type === 'error' ? '❌' : '⚠️';
  const t = document.createElement('div');
  t.className = `toast-msg ${type}`;
  t.innerHTML = `<span>${icon}</span><span>${msg}</span>`;
  tc.appendChild(t);
  setTimeout(() => { t.style.opacity='0'; t.style.transform='translateX(30px)'; t.style.transition='all 0.3s'; setTimeout(()=>t.remove(),300); }, 3500);
}

// ─── GENERATE REPORT ──────────────────────────────────────────────────────────
async function generateReport() {

const missingFields = [];

if (!document.getElementById('reportName').value.trim()) {
  missingFields.push('Report Name');
}

if (!document.getElementById('vesselName').value.trim()) {
  missingFields.push('Vessel Name');
}

if (!document.getElementById('placeOfSurvey').value.trim()) {
  missingFields.push('Place of Survey');
}

if (!document.getElementById('surveyDate').value.trim()) {
  missingFields.push('Survey Date');
}

if (state.shipSide.length === 0) {
  missingFields.push('Minimum 1 Ship Side Photo');
}

if (state.deckSide.length === 0) {
  missingFields.push('Minimum 1 Deck Side Photo');
}

if (missingFields.length > 0) {

    Swal.fire({
        icon: 'warning',
        title: 'Report Cannot Be Generated',
        html: `
            <div style="text-align:left">
                <p>Please complete the following mandatory fields:</p>

                <ul style="
                    text-align:left;
                    margin-top:10px;
                    line-height:1.8;
                ">
                    ${missingFields.map(
                        field => `<li>❌ ${field}</li>`
                    ).join('')}
                </ul>
            </div>
        `,
        confirmButtonText: 'OK',
        confirmButtonColor: '#0ea5e9',
        background: '#111927',
        color: '#ffffff',
        width: '550px'
    });

    return;
}
let missingDescriptions = [];

SECTIONS.forEach(sec => {
  state[sec.id].forEach((img, index) => {
    if (!img.desc || img.desc.trim() === '') {
      missingDescriptions.push(
        `${sec.label} - Photo ${index + 1}`
      );
    }
  });
});

if (missingDescriptions.length > 0) {
  showToast(
    'Please enter/select description for all photos.',
    'error'
  );

  Swal.fire({
    icon: 'error',
    title: 'Photo Descriptions Missing',
    html: `
        <div style="text-align:left">
            <p>Please enter description for:</p>

            <ul style="
                text-align:left;
                line-height:1.8;
                margin-top:10px;
            ">
                ${missingDescriptions.map(
                    item => `<li>📷 ${item}</li>`
                ).join('')}
            </ul>
        </div>
    `,
    confirmButtonText: 'OK',
    confirmButtonColor: '#ef4444',
    background: '#111927',
    color: '#ffffff',
    width: '600px'
});

  return;
}
  const btn = document.getElementById('generateBtn');
  const overlay = document.getElementById('loadingOverlay');
  const loadText = document.getElementById('loadingText');

  btn.disabled = true;
  btn.classList.add('loading');
  overlay.classList.add('show');

  try {
    loadText.textContent = 'Collecting photos…';
    await sleep(50);

    const info = {
      reportName: document.getElementById('reportName').value.trim(),
      vesselName: document.getElementById('vesselName').value.trim(),
      placeOfSurvey: document.getElementById('placeOfSurvey').value.trim(),
      surveyDate: document.getElementById('surveyDate').value,
      surveyorName: document.getElementById('surveyorName').value.trim()
    };

    // Collect photos in section order, skip empty
    const allPhotos = [];
    for (const sec of SECTIONS) {
      const imgs = state[sec.id];
      if (imgs.length === 0) continue;
      allPhotos.push({ sectionLabel: sec.label, photos: imgs });
    }

    loadText.textContent = 'Building DOCX document…';
    await sleep(50);

    const doc = await buildDocx(info, allPhotos);
    loadText.textContent = 'Saving file…';
    await sleep(50);

    const lib = window.docx || (typeof docx !== 'undefined' ? docx : null);
    const blob = await lib.Packer.toBlob(doc);
    const vesselName = info.vesselName
  .replace(/[\\/:*?"<>|]/g, '') // invalid filename characters remove
  .trim();

saveAs(blob, `${vesselName} PHOTO REPORT.docx`);

    overlay.classList.remove('show');
    btn.disabled = false;
    btn.classList.remove('loading');
    Swal.fire({
    icon: 'success',
    title: 'Report Generated Successfully',
    text: 'Photo Report downloaded successfully.',
    confirmButtonText: 'OK',
    confirmButtonColor: '#10b981',
    background: '#111927',
    color: '#ffffff'
});
  } catch (err) {
    overlay.classList.remove('show');
    btn.disabled = false;
    btn.classList.remove('loading');
    console.error(err);
    showToast('Error generating report: ' + err.message, 'error');
  }
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ─── BUILD DOCX ───────────────────────────────────────────────────────────────
async function buildDocx(info, sections) {
  // docx.js exposes itself on window — resolve safely regardless of bundle format
  const lib = window.docx || window.DocxJS || (typeof docx !== 'undefined' ? docx : null);
  if (!lib) throw new Error('docx library not loaded. Please check your internet connection and reload.');
  const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    ImageRun, AlignmentType, PageBreak, BorderStyle, WidthType, ShadingType,
    VerticalAlign, HeadingLevel } = lib;

  // A4 in DXA. Margins 0.5in = 720 DXA. Content width = 11906 - 720*2 = 10466
  const PAGE_W = 11906, PAGE_H = 16838;
  const MARGIN = 720;
  const CONTENT_W = PAGE_W - MARGIN * 2; // 10466 DXA

  // Image dimensions: 50/50 split, image left, description right
  const IMAGE_COL_W = Math.floor(CONTENT_W * 0.75);
const DESC_COL_W = CONTENT_W - IMAGE_COL_W;

  const children = [];

  // ── COVER PAGE ──────────────────────────────────────────────────────────────
  const makePara = (text, opts={}) => new Paragraph({
    alignment: opts.align || AlignmentType.CENTER,
    spacing: { before: opts.spaceBefore || 0, after: opts.spaceAfter || 120 },
    children: [new TextRun({
      text,
      size: opts.size || 24,
      bold: opts.bold || false,
      color: opts.color || '1E3A5F',
      font: 'Calibri'
    })]
  });

  // Title
  children.push(new Paragraph({ spacing: { before: 1440, after: 480 }, alignment: AlignmentType.CENTER, children: [new TextRun({ text: 'PHOTO REPORT', size: 52, bold: true, color: '0A3D6B', font: 'Calibri' })] }));

  // Divider
  children.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { before: 0, after: 480 },
    border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: '0EA5E9', space: 1 } },
    children: [new TextRun({ text: '', size: 4 })]
  }));

  const infoRows = [
    ['Report Name', info.reportName],
    ['Vessel Name', info.vesselName],
    ['Place of Survey', info.placeOfSurvey],
    ['Survey Date', formatDate(info.surveyDate)],
  ];
  if (info.surveyorName) infoRows.push(['Surveyor Name', info.surveyorName]);

  const border0 = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };
  const noBorders = { top: border0, bottom: border0, left: border0, right: border0 };

  const infoTable = new Table({
    width: { size: 7000, type: WidthType.DXA },
    columnWidths: [3000, 4000],
    rows: infoRows.map(([label, value]) =>
      new TableRow({
        children: [
          new TableCell({
            borders: noBorders,
            width: { size: 3000, type: WidthType.DXA },
            margins: { top: 80, bottom: 80, left: 120, right: 120 },
            children: [new Paragraph({ alignment: AlignmentType.RIGHT, children: [new TextRun({ text: label, size: 26, bold: true, color: '0A3D6B', font: 'Calibri' })] })]
          }),
          new TableCell({
            borders: noBorders,
            width: { size: 4000, type: WidthType.DXA },
            margins: { top: 80, bottom: 80, left: 200, right: 120 },
            children: [new Paragraph({ alignment: AlignmentType.LEFT, children: [new TextRun({ text: ': ' + value, size: 26, bold: false, color: '1E3A5F', font: 'Calibri' })] })]
          })
        ]
      })
    ),
    alignment: AlignmentType.CENTER
  });

  children.push(infoTable);

  // Divider after info
  children.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: { before: 480, after: 0 },
    border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: '0EA5E9', space: 1 } },
    children: [new TextRun({ text: '', size: 4 })]
  }));

  // Total photos summary
  const totalPhotos = sections.reduce((a,s)=>a+s.photos.length, 0);
  children.push(new Paragraph({
    spacing: { before: 480, after: 240 },
    alignment: AlignmentType.CENTER,
    children: [new TextRun({ text: `Total Photos: ${totalPhotos}`, size: 22, color: '4A7098', font: 'Calibri' })]
  }));

  // Page break after cover
  children.push(new Paragraph({ children: [new PageBreak()] }));

  // ── PHOTO PAGES ─────────────────────────────────────────────────────────────
  let serialNo = 1;
  let photosOnCurrentPage = 0;

  for (const section of sections) {
    // Section header
    children.push(new Paragraph({
      spacing: { before: 0, after: 240 },
      alignment: AlignmentType.LEFT,
      children: [new TextRun({ text: section.sectionLabel, size: 28, bold: true, color: '0A3D6B', font: 'Calibri' })]
    }));
    children.push(new Paragraph({
      spacing: { before: 0, after: 360 },
      border: { bottom: { style: BorderStyle.SINGLE, size: 4, color: '0EA5E9', space: 1 } },
      children: [new TextRun({ text: '', size: 4 })]
    }));
    photosOnCurrentPage = 0;

    for (const photo of section.photos) {
      // Load image
      const imgData = await urlToArrayBuffer(photo.dataUrl);
      // Detect type: png or jpeg/jpg
      const imgType = photo.dataUrl.startsWith('data:image/png') ? 'png' : 'jpeg';
      // Pixel sizes for ImageRun (at 96dpi): COL_W DXA → inches → px
      // COL_W DXA / 1440 = inches; * 96 = px
      const IMG_PX_W = Math.round((IMAGE_COL_W - 150) / 1440 * 96);
const IMG_PX_H = Math.round(IMG_PX_W * 0.75);

      // S.No row
      children.push(new Paragraph({
        spacing: { before: 0, after: 120 },
        children: [new TextRun({ text: `S.No : ${serialNo}`, size: 22, bold: true, color: '0369A1', font: 'Calibri' })]
      }));

      // Photo row: image | description
      const outerBorder = { style: BorderStyle.SINGLE, size: 4, color: 'CBD5E1' };
      const cellBorders = { top: outerBorder, bottom: outerBorder, left: outerBorder, right: outerBorder };

      const photoRow = new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: [IMAGE_COL_W, DESC_COL_W],
        rows: [
          new TableRow({
            children: [
              // Image cell
              new TableCell({
                borders: cellBorders,
                width: { size: IMAGE_COL_W, type: WidthType.DXA },
                margins: { top: 120, bottom: 120, left: 120, right: 120 },
                verticalAlign: VerticalAlign.CENTER,
                children: [
                  new Paragraph({
                    alignment: AlignmentType.CENTER,
                    spacing: { before: 0, after: 0 },
                    children: [
                      new ImageRun({
                        data: imgData,
                        transformation: { width: IMG_PX_W, height: IMG_PX_H },
                        type: imgType
                      })
                    ]
                  })
                ]
              }),
              // Description cell
              new TableCell({
                borders: cellBorders,
                width: { size: DESC_COL_W, type: WidthType.DXA },
                margins: { top: 160, bottom: 160, left: 200, right: 200 },
                verticalAlign: VerticalAlign.CENTER,
                shading: { fill: 'F0F7FF', type: ShadingType.CLEAR },
                children: [
                  new Paragraph({
                    spacing: { before: 0, after: 120 },
                    children: [new TextRun({ text:
section.sectionLabel.includes('Hold No.')? `${section.sectionLabel.replace(' PHOTOS','')}: View of ${photo.desc || ''}`
 : `View of ${photo.desc || ''}`, size: 24, color: '1E3A5F', font: 'Calibri', bold: false })]
                  })
                ]
              })
            ]
          })
        ]
      });

      children.push(photoRow);
      children.push(new Paragraph({ spacing: { before: 0, after: 200 }, children: [new TextRun('')] }));

      serialNo++;
      photosOnCurrentPage++;

      // Page break every 2 photos
      if (photosOnCurrentPage % 2 === 0) {
        children.push(new Paragraph({ children: [new PageBreak()] }));
        photosOnCurrentPage = 0;
      }
    }

    // After each section, if not at start of page, break
    if (photosOnCurrentPage !== 0 && section !== sections[sections.length - 1]) {
      children.push(new Paragraph({ children: [new PageBreak()] }));
      photosOnCurrentPage = 0;
    }
  }

  // Build document
  const document2 = new Document({
    sections: [{
      properties: {
        page: {
          size: { width: PAGE_W, height: PAGE_H },
          margin: { top: MARGIN, right: MARGIN, bottom: MARGIN, left: MARGIN }
        }
      },
      children
    }]
  });

  return document2;
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────
function urlToArrayBuffer(dataUrl) {
  return new Promise((resolve) => {
    const base64 = dataUrl.split(',')[1];
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    resolve(bytes.buffer);
  });
}

function formatDate(dateStr) {
  if (!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'long', year: 'numeric' });
}
</script>
<?php include "../includes/footer.php"; ?>
</body>
</html>
