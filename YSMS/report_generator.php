<?php
require_once 'config/config.php';
checkAuth();

// మన గ్లోబల్ కలర్‌ఫుల్ హెడర్ (టాప్ ప్రొఫైల్ ఐకాన్ తో సహా లోడ్ అవుతుంది)
include 'includes/header.php';
?>

<script src="https://cdn.jsdelivr.net/npm/pizzip@3.1.4/dist/pizzip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docxtemplater@3.44.0/build/docxtemplater.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        --accent-glow: rgba(37,99,235,0.06);
        --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
        --error-color: #dc2626;
        --success-color: #16a34a;
        --offhire-color: #dc2626;
        --onhire-color: #16a34a;
    }
    .report-banner {
        background: var(--primary-gradient); color: white; padding: 16px 15px; text-align: center;
    }
    .upload-box-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 15px; }
    @media(max-width: 768px) { .upload-box-grid { grid-template-columns: 1fr; } }

    .upload-zone-pro {
        border: 2px dashed #cbd5e1; background: #ffffff; border-radius: 16px;
        padding: 20px 15px; text-align: center; cursor: pointer; transition: all 0.2s; position: relative;
    }
    .upload-zone-pro.uploaded { border-color: #22c55e; background: rgba(34,197,94,0.05); }
    .upload-zone-pro.required-err { border-color: var(--error-color) !important; background: rgba(220, 38, 38, 0.05) !important; }
    .upload-zone-pro input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }

    /* కొలాప్సిబుల్ అకార్డియన్ స్టైల్స్ */
    .accordion-group-card { background: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; margin: 15px; box-shadow: var(--card-shadow); overflow: hidden; }
    .accordion-header-bar { background: #f8fafc; padding: 15px 20px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; user-select: none; border-bottom: 1px solid #e2e8f0; }
    .accordion-header-title { font-size: 14px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
    .accordion-body-content { padding: 20px; display: block; transition: all 0.3s ease; }
    .accordion-body-content.collapsed { display: none; }
    .toggle-arrow-icon { font-size: 14px; transition: transform 0.2s; color: #64748b; }

    .form-grid-layout { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; }
    @media(max-width: 600px) { .form-grid-layout { grid-template-columns: 1fr; } }

    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .control-label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 4px; display: block; }

    /* ఇనిషియల్ రెడ్ అండ్ గ్రీన్ బోర్డర్స్ క్లాసెస్ */
    .generator-input { width: 100%; padding: 10px 12px; border: 1.5px solid var(--error-color); border-radius: 8px; font-size: 13.5px; background: #fff5f5; outline: none; transition: all 0.2s; color: #0f172a; }
    .generator-input:focus { border-color: #2563eb !important; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); background: #fff !important; }

    .generator-input.fc-invalid { border-color: var(--error-color) !important; box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15) !important; background: #fff5f5 !important; }
    .generator-input.fc-valid { border-color: var(--success-color) !important; background: #f0fdf4 !important; box-shadow: none !important; }
    .generator-input.auto-calc { background: rgba(37,99,235,0.06)!important; color: #2563eb!important; font-weight: 700; border-style: dashed!important; box-shadow: none !important; border-color: #cbd5e1 !important; }

    .btn-submit-pro {
        background: #cbd5e1; color: #64748b; border: none; width: calc(100% - 30px); margin: 0 15px 30px 15px;
        padding: 14px; border-radius: 10px; font-size: 15px; font-weight: 700; transition: all 0.2s; cursor: pointer;
    }
    .btn-submit-pro.ready { background: linear-gradient(135deg,#1d4ed8,#0ea5e9); color: white; box-shadow: 0 4px 20px rgba(37,99,235,0.3); }
    .btn-submit-pro.ready:hover { transform: translateY(-2px); }

    /* 🌟 గుడ్‌లుకింగ్ డ్రాప్‌డౌన్ (లోపల సెర్చ్ బాక్స్‌తో) — ఎక్సెల్ / వర్డ్ టెంప్లేట్ సెలెక్షన్ కోసం */
    .pro-dropdown { position: relative; text-align: left; }
    .pro-dropdown-trigger {
        width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 8px;
        border: 1.5px solid #e2e8f0; background: #f8fafc; border-radius: 10px;
        padding: 9px 12px; font-size: 12.5px; font-weight: 600; color: #94a3b8;
        cursor: pointer; transition: all 0.15s;
    }
    .pro-dropdown-trigger:hover { border-color: #cbd5e1; background: #f1f5f9; }
    .pro-dropdown.open .pro-dropdown-trigger { border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,0.12); }
    .pro-dropdown.has-value .pro-dropdown-trigger-text { color: #0f172a; font-weight: 700; }
    .pro-dropdown-trigger-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .pro-dropdown-caret { font-size: 11px; color: #94a3b8; transition: transform 0.15s; flex-shrink: 0; }
    .pro-dropdown.open .pro-dropdown-caret { transform: rotate(180deg); color: #2563eb; }

    .pro-dropdown-panel {
        position: absolute; top: calc(100% + 8px); left: 0; right: 0; z-index: 50;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
        box-shadow: 0 16px 32px -8px rgba(15,23,42,0.18), 0 4px 10px -4px rgba(15,23,42,0.08);
        overflow: hidden; opacity: 0; visibility: hidden; transform: translateY(-6px) scale(0.98);
        transition: all 0.15s ease;
    }
    .pro-dropdown.open .pro-dropdown-panel { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }

    .pro-dropdown-search {
        display: flex; align-items: center; gap: 8px; padding: 10px 12px;
        border-bottom: 1px solid #f1f5f9; background: #f8fafc;
    }
    .pro-dropdown-search i { color: #94a3b8; font-size: 13px; flex-shrink: 0; }
    .pro-dropdown-search input {
        flex: 1; border: none; background: transparent; outline: none;
        font-size: 12.5px; font-weight: 600; color: #0f172a;
    }
    .pro-dropdown-search input::placeholder { color: #94a3b8; font-weight: 500; }
    .pro-dropdown-search .pro-dropdown-clear {
        display: none; border: none; background: #e2e8f0; color: #64748b; width: 18px; height: 18px;
        border-radius: 50%; font-size: 11px; line-height: 1; cursor: pointer; align-items: center; justify-content: center;
    }
    .pro-dropdown-search.has-text .pro-dropdown-clear { display: flex; }

    .pro-dropdown-options { max-height: 230px; overflow-y: auto; padding: 6px; }
    .pro-dropdown-option {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        padding: 9px 10px; border-radius: 8px; cursor: pointer; transition: background 0.1s;
    }
    .pro-dropdown-option:hover, .pro-dropdown-option.active { background: #eff6ff; }
    .pro-dropdown-option.selected { background: rgba(37,99,235,0.08); }
    .pro-dropdown-option .opt-title { font-weight: 700; color: #0f172a; font-size: 12.5px; }
    .pro-dropdown-option .opt-sub { font-size: 10.5px; color: #64748b; margin-top: 2px; }
    .pro-dropdown-option .opt-check { color: #2563eb; font-size: 13px; flex-shrink: 0; display: none; }
    .pro-dropdown-option.selected .opt-check { display: block; }
    .pro-dropdown-empty { padding: 18px 12px; font-size: 12px; color: #94a3b8; text-align: center; }

    .zone-load-btn {
        margin-top: 8px; width: 100%; border: none; border-radius: 8px; padding: 6px 8px;
        font-size: 11px; font-weight: 700; background: #e2e8f0; color: #475569; cursor: pointer;
    }
    .zone-load-btn.ready { background: linear-gradient(135deg,#1d4ed8,#0ea5e9); color: #fff; }
    .manage-templates-link { display: block; text-align: center; font-size: 11px; color: #2563eb; text-decoration: none; margin: 10px 15px; font-weight: 600; }

    /* 🌟 టాబ్ బార్ — Offhire / Onhire / B+C / 3 Party రిపోర్ట్ టైప్‌ల ఎంపిక కోసం */
    .report-tabs-wrap { margin: 15px; }
    .report-tabs { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 4px; -webkit-overflow-scrolling: touch; }
    .report-tabs::-webkit-scrollbar { height: 4px; }
    .report-tab-btn {
        flex: 0 0 auto; display: flex; align-items: center; gap: 6px; border: 1.5px solid #e2e8f0;
        background: #fff; color: #64748b; padding: 9px 14px; border-radius: 999px; font-size: 12px;
        font-weight: 700; cursor: pointer; white-space: nowrap; transition: all 0.15s;
    }
    .report-tab-btn .tab-dot { width: 7px; height: 7px; border-radius: 50%; background: #cbd5e1; flex-shrink: 0; }
    .report-tab-btn.offhire-tab .tab-dot { background: var(--offhire-color); }
    .report-tab-btn.onhire-tab .tab-dot { background: var(--onhire-color); }
    .report-tab-btn.active.offhire-tab { border-color: var(--offhire-color); background: rgba(220,38,38,0.08); color: var(--offhire-color); }
    .report-tab-btn.active.onhire-tab { border-color: var(--onhire-color); background: rgba(22,163,74,0.08); color: var(--onhire-color); }

    .type-badge-pro {
        display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 999px;
        font-size: 10px; font-weight: 800; letter-spacing: 0.4px; text-transform: uppercase; margin-left: 8px;
    }
    .type-badge-pro.offhire { background: rgba(220,38,38,0.1); color: var(--offhire-color); border: 1px solid rgba(220,38,38,0.3); }
    .type-badge-pro.onhire { background: rgba(22,163,74,0.1); color: var(--onhire-color); border: 1px solid rgba(22,163,74,0.3); }

    .calc-info-pro { margin: 0 15px 12px; padding: 10px 14px; border-radius: 10px; font-size: 11.5px; font-weight: 600; }
    .calc-info-pro.offhire-calc { background: rgba(220,38,38,0.06); border: 1px solid rgba(220,38,38,0.2); color: #b91c1c; }
    .calc-info-pro.onhire-calc { background: rgba(22,163,74,0.06); border: 1px solid rgba(22,163,74,0.2); color: #15803d; }

    .report-section-block { display: none; }
    .report-section-block.active { display: block; animation: fadeInSec 0.25s ease; }
    @keyframes fadeInSec { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }

    /* 🌟 డ్యామేజ్ విడ్జెట్ (B+C టాబ్స్ లో మాత్రమే) */
    .damage-widget-card { margin: 15px; }
    .damage-trigger-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .damage-trigger-label { font-size: 12px; font-weight: 700; color: #334155; margin-right: 4px; }
    .damage-yn-btn { border: 1.5px solid #e2e8f0; background: #f8fafc; color: #64748b; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.15s; }
    .damage-yn-btn.active-yes { border-color: var(--offhire-color); background: rgba(220,38,38,0.08); color: var(--offhire-color); }
    .damage-yn-btn.active-no { border-color: #94a3b8; background: #f1f5f9; color: #475569; }
    .damage-entry-panel { display: none; }
    .damage-entry-panel.visible { display: block; }
    .damage-entry-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
    .damage-hold-select { min-width: 140px; padding: 9px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: 12.5px; }
    .damage-textarea { flex: 1; min-width: 200px; min-height: 42px; padding: 9px 10px; border-radius: 8px; border: 1.5px solid #e2e8f0; font-size: 12.5px; font-family: inherit; resize: vertical; }
    .damage-save-btn { border: none; background: linear-gradient(135deg,#1d4ed8,#0ea5e9); color: #fff; padding: 9px 16px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; }
    .damage-list { display: flex; flex-direction: column; gap: 6px; }
    .damage-entry-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px; font-size: 12px; color: #334155; }
    .damage-entry-hold { font-weight: 800; color: var(--offhire-color); margin-right: 4px; }
    .damage-remove-btn { border: none; background: #fee2e2; color: #dc2626; width: 20px; height: 20px; border-radius: 50%; font-size: 11px; cursor: pointer; flex-shrink: 0; }

    /* 🌟 ఎక్స్ట్రా ఫీల్డ్ కార్డ్ (3 పార్టీ టాబ్స్ లో మాత్రమే) */
    .extra-field-card { margin: 15px; }

    /* నావ్‌బార్‌ను ఫిక్స్ చేయడానికి — గమనిక: nav.php లోని .bottom-nav-bar కి
       ఇప్పటికే స్వంతంగా position:fixed ఉంది. ఈ wrapper కి కూడా position:fixed +
       z-index పెడితే అది ఒక కొత్త CSS స్టాకింగ్ కాంటెక్స్ట్ ని క్రియేట్ చేసేస్తుంది —
       దాంతో హ్యామ్‌బర్గర్ మెనూ తెరిచినప్పుడు వచ్చే మొబైల్ సైడ్‌బార్ (z-index:1100)
       దాని లోపలే బంధీ అయిపోయి, పైన ఉండాల్సిన dark overlay (z-index:1050) కిందపడిపోయి
       కనిపించకుండా పోయేది. కాబట్టి ఈ wrapper ని పొజిషన్ లేని ప్లెయిన్ డివ్ గా మార్చాం —
       లోపలి .bottom-nav-bar తన own ఫిక్స్‌డ్ పొజిషనింగ్ ని అలాగే వాడుకుంటుంది. */
    .fixed-bottom-nav {
        width: 100%;
    }

    /* కంటెంట్ నావ్‌బార్ కింద కనిపించకుండా ఉండటానికి */
    .scroll-content {
        padding-bottom: 90px !important;
    }
</style>

<div class="scroll-content" style="flex: 1; overflow-y: auto; padding-bottom: 80px;">

    <?php $page_title = 'Final Report Generator'; $back_url = isset($_GET['id']) ? 'report_detail.php?id=' . (int)$_GET['id'] : 'reports.php'; $page_testid = 'report-generator'; include 'includes/top_app_bar.php'; ?>

    <div class="report-banner">
        <p class="text-white-50 small m-0"><i class="fa-solid fa-file-word text-info me-2"></i>Generate automated .docx formal survey files seamlessly</p>
    </div>

    <?php if (($_SESSION['role'] ?? '') === 'Admin'): ?>
        <a href="manage_templates.php" class="manage-templates-link" data-testid="manage-templates-link"><i class="fa-solid fa-gear me-1"></i>Manage Word Templates</a>
    <?php endif; ?>

    <div class="report-tabs-wrap">
        <div class="report-tabs" id="reportTabsBar" data-testid="report-tabs-bar"></div>
    </div>

    <div id="sectionsRoot"></div>
</div>

<script>
/* ============================================================
   🌟 6 రిపోర్ట్ టైప్‌ల టాబ్ కాన్ఫిగ్ — ప్రతి టాబ్ లాజిక్ index (1).html లో ఇచ్చిన
   అదే లాజిక్ ను అనుసరిస్తుంది (Offhire/Onhire consumption formula,
   Redelivery→Delivery label ఓవర్రైడ్‌లు, B+C టాబ్స్ లో Damage widget,
   3 Party టాబ్స్ లో Sub/Next Charterer ఎక్స్ట్రా ఫీల్డ్).
   ============================================================ */
const SECTIONS = [
    { id:'offhire',           title:'Offhire Report',            tabLabel:'Offhire',            offhire:true  },
    { id:'onhire',             title:'Onhire Report',             tabLabel:'Onhire',              offhire:false },
    { id:'offhire-condition', title:'Offhire B+C Report',        tabLabel:'Offhire B+C',        offhire:true  },
    { id:'onhire-condition',  title:'Onhire B+C Report',          tabLabel:'Onhire B+C',          offhire:false },
    { id:'3party-rrr',        title:'3 Party — RRR Offhire',     tabLabel:'3 Party RRR Offhire', offhire:true  },
    { id:'3party-rrd',        title:'3 Party — RRD Offhire',     tabLabel:'3 Party RRD Offhire', offhire:true  }
];
const CONDITION_SECTIONS = ['offhire-condition','onhire-condition'];
const SECTION_EXTRA_FIELDS = {
    '3party-rrr': [{ id:'NEXT_CHARTERER_NAME', label:'Sub Charterer' }],
    '3party-rrd': [{ id:'NEXT_CHARTERER_NAME', label:'Next Charterer' }]
};

const ONHIRE_LABEL_OVERRIDES = {
    'REDELIVERY_DATE_LT':  'Delivery Date LT',
    'REDELIVERY_TIME_LT':  'Delivery Time LT',
    'REDELIVERY_DATE_UTC': 'Delivery Date UTC',
    'REDELIVERY_TIME_UTC': 'Delivery Time UTC',
    'REDELIVERY_PLACE':    'Delivery Place',
    'REDELIVERY_VLSFO':    'Delivery VLSFO (MT)',
    'REDELIVERY_LSMGO':    'Delivery LSMGO (MT)',
    'REDELIVERY_HSFO':     'Delivery HSFO (MT)'
};

const REPORT_FIELDS = [
    {id:'REPORT_DATE',          label:'Report Date',         type:'date'},
    {id:'REPORT_PLACE',         label:'Report Place',        type:'text'},
    {id:'SHIP_NAME',            label:'Ship / Vessel Name',   type:'text'},
    {id:'VOY_NO',               label:'Voyage No.',          type:'text'},
    {id:'IMO',                  label:'IMO Number',          type:'text'},
    {id:'GRT',                  label:'GRT / NRT',           type:'text'},
    {id:'PORT_OF_REGISTRY',     label:'Port of Registry',    type:'text'},
    {id:'ALONGSIDE',            label:'Alongside Position',  type:'select',  options:['allfast port side alongside to','allfast starboard side alongside to','Anchored at']},
    {id:'HOLDS',                label:'No. of Holds',        type:'select',  options:['1','2','3','4','5','6','7','8','9','10']},
    {id:'HOLDS_ALPHA',          label:'Holds (In Words)',    type:'text',    readonly:true},
    {id:'KEEL_LAID_MONTH',      label:'Keel Laid Month',     type:'text'},
    {id:'KEEL_LAID_YEAR',       label:'Keel Laid Year',      type:'text'},
    {id:'CLASS',                label:'Classification Society',type:'text'},
    {id:'CALL_SIGN',            label:'Call Sign',           type:'text'},
    {id:'LENGTH_OVERALL',       label:'Length Overall (LOA)',type:'text',    precision:2},
    {id:'BREDTH_MOULDED',       label:'Breadth Moulded (m)', type:'text',    precision:2},
    {id:'DEPTH_MOULDED',        label:'Depth Moulded (m)',   type:'text',    precision:2},
    {id:'SUMMER_DRAFT',         label:'Summer Draft (m)',    type:'text',    precision:2},
    {id:'SUMMER_DEADWEIGHT',    label:'Summer Deadweight',   type:'text'},
    {id:'CHARTERER_NAME',       label:'Charterer Name',      type:'text'},
    {id:'OWNER_NAME',           label:'Owner Name',          type:'text'},
    {id:'BUILDER_NAME',         label:'Builder Name',        type:'text'},
    {id:'CAPT_NAME',            label:'Master / Captain Name',type:'text'},
    {id:'CHIEF_ENGINEER_NAME',  label:'Chief Engineer Name', type:'text'},
    {id:'OPERATOR_NAME',        label:'Operator Name',       type:'text'},
    {id:'SURVEYOR_NAME',        label:'Surveyor Name',       type:'text'},
    {id:'SURVEYOR_SIGN',        label:'Surveyor Sign',       type:'text'},
    {id:'SURVEY_DATE',          label:'Survey Date',         type:'text'},
    {id:'SURVEY_TIME',          label:'Survey Local Time',   type:'time'},
    {id:'SURVEY_PLACE',         label:'Survey Place',        type:'text'},
    {id:'SURVEY_VLSFO',         label:'Survey VLSFO (MT)',   type:'number',   precision:3},
    {id:'SURVEY_LSMGO',         label:'Survey LSMGO (MT)',   type:'number',   precision:3},
    {id:'SURVEY_HSFO',          label:'Survey HSFO (MT)',    type:'number',   precision:3},
    {id:'SURVEY_TIME_FROM',     label:'Survey Time From',    type:'time'},
    {id:'REDELIVERY_DATE_LT',   label:'Redelivery Date LT',  type:'date'},
    {id:'REDELIVERY_TIME_LT',   label:'Redelivery Time LT',  type:'time'},
    {id:'REDELIVERY_DATE_UTC',  label:'Redelivery Date UTC', type:'date'},
    {id:'REDELIVERY_TIME_UTC',  label:'Redelivery Time UTC', type:'time'},
    {id:'REDELIVERY_PLACE',     label:'Redelivery Place',    type:'text'},
    {id:'REDELIVERY_VLSFO',     label:'Redelivery VLSFO (MT)',type:'number',  precision:3},
    {id:'REDELIVERY_LSMGO',     label:'Redelivery LSMGO (MT)',type:'number',  precision:3},
    {id:'REDELIVERY_HSFO',      label:'Redelivery HSFO (MT)',type:'number',  precision:3},
    {id:'CONS_VLSFO',           label:'Calculated VLSFO Cons',type:'calc',   precision:3},
    {id:'CONS_LSMGO',           label:'Calculated LSMGO Cons',type:'calc',   precision:3},
    {id:'CONS_HSFO',            label:'Calculated HSFO Cons', type:'calc',   precision:3}
];

const NUMS_TO_WORDS = {'1':'ONE','2':'TWO','3':'THREE','4':'FOUR','5':'FIVE','6':'SIX','7':'SEVEN','8':'EIGHT','9':'NINE','10':'TEN'};
const MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const PRESELECT_SURVEY_ID = <?= isset($_GET['id']) ? (int)$_GET['id'] : 'null' ?>;

function sectionDef(sid) { return SECTIONS.find(s => s.id === sid); }
function isOffhireSection(sid) { let s = sectionDef(sid); return s ? !!s.offhire : true; }

/* ప్రతి టాబ్ కు వేరే స్టేట్ (ఫారమ్ వాల్యూస్, వర్డ్ టెంప్లేట్, ఎంపిక చేసిన Excel/Template, డ్యామేజెస్, ఎక్స్ట్రా ఫీల్డ్స్) */
let sectionState = {};
SECTIONS.forEach(s => {
    sectionState[s.id] = {
        formValues: {},
        wordTemplateBuffer: null,
        selectedExcelUploadId: null,
        selectedTemplateId: null,
        damages: [],
        extra: {}
    };
    (SECTION_EXTRA_FIELDS[s.id] || []).forEach(f => sectionState[s.id].extra[f.id] = '');
});

let excelOptions = [];
let templateOptions = [];
let activeTab = 'offhire';

/* ============================================================
   టాబ్ బార్ + సెక్షన్ షెల్ బిల్డింగ్
   ============================================================ */
function buildTabsBar() {
    let bar = document.getElementById('reportTabsBar');
    bar.innerHTML = SECTIONS.map(s => {
        let cls = 'report-tab-btn ' + (s.offhire ? 'offhire-tab' : 'onhire-tab') + (s.id === activeTab ? ' active' : '');
        return `<button type="button" class="${cls}" id="tabBtn_${s.id}" onclick="showTab('${s.id}')" data-testid="report-tab-${s.id}">
                    <span class="tab-dot"></span>${s.tabLabel}
                </button>`;
    }).join('');
}

function showTab(sid) {
    activeTab = sid;
    document.querySelectorAll('.report-tab-btn').forEach(b => b.classList.remove('active'));
    let btn = document.getElementById('tabBtn_' + sid);
    if (btn) btn.classList.add('active');

    document.querySelectorAll('.report-section-block').forEach(el => el.classList.remove('active'));
    let block = document.getElementById('sectionBlock_' + sid);
    if (!block) {
        block = buildSectionBlock(sid);
        document.getElementById('sectionsRoot').appendChild(block);
        initSectionDefaults(sid);
    }
    block.classList.add('active');
}

function initSectionDefaults(sid) {
    let today = new Date().toISOString().slice(0, 10);
    sectionState[sid].formValues['REPORT_DATE'] = today;
    sectionState[sid].formValues['HOLDS_ALPHA'] = sectionState[sid].formValues['HOLDS_ALPHA'] || "";
    renderGroupedGrids(sid);
    preselectExcelForSection(sid);
    preselectTemplateForSection(sid);
}

/* ============================================================
   🌟 ఏ vessel నుండి link open చేసామో (?id=) ఆ Excel ని ప్రతి టాబ్ లోనూ
   డిఫాల్ట్‌గా dropdown లో select చేసి చూపించడం (ఇంకా లోడ్ చేయదు —
   Upload బటన్ నొక్కితేనే డేటా ఫారంలోకి వస్తుంది)
   ============================================================ */
function preselectExcelForSection(sid) {
    if (!PRESELECT_SURVEY_ID || !excelOptions.length) return;
    if (sectionState[sid].selectedExcelUploadId) return;
    let match = excelOptions.find(it => it.survey_id === PRESELECT_SURVEY_ID);
    if (match) selectExcelOption(sid, match.upload_id);
}

/* ============================================================
   🌟 Word Template dropdown — Admin "Manage Word Templates" లో అప్‌లోడ్
   చేసిన templates అన్నీ ప్రతి టాబ్ లోనూ (Admin & Surveyor ఇద్దరికీ) ఒకే
   ఫిక్స్‌డ్ లిస్ట్ గా కనిపిస్తాయి. అయితే, ఆ template యొక్క "Survey Type"
   (Manage Word Templates లో ఇచ్చిన టెక్స్ట్) ఆ టాబ్ పేరుతో మ్యాచ్ అయితే —
   ఆ టెంప్లేట్ ని ఆటోమేటిక్‌గా డిఫాల్ట్‌గా సెలెక్ట్ చేస్తుంది
   (ఉదా: "Offhire" టాబ్ ఓపెన్ అవ్వగానే survey_type లో "Offhire" ఉన్న
   టెంప్లేట్ డిఫాల్ట్‌గా సెలెక్ట్ అవుతుంది).
   ============================================================ */
function normTemplateText(s) {
    return String(s || '').toLowerCase().replace(/[^a-z0-9]/g, '');
}

function findMatchingTemplate(sid) {
    if (!templateOptions.length) return null;
    let sec = sectionDef(sid);
    if (!sec) return null;

    let targetTab = normTemplateText(sec.tabLabel);
    let targetTitle = normTemplateText(sec.title);
    let withType = templateOptions.filter(it => it.survey_type);

    // 1️⃣ Survey Type ఖచ్చితంగా ఆ టాబ్ పేరుతో సరిపోతే (ఉదా: "Offhire", "Onhire B+C")
    let exact = withType.find(it => {
        let t = normTemplateText(it.survey_type);
        return t === targetTab || t === targetTitle;
    });
    if (exact) return exact;

    // 2️⃣ Survey Type లో టాబ్ పేరు కొంత భాగం ఉందేమో (ఉదా: survey_type = "Offhire Report")
    let partial = withType.find(it => {
        let t = normTemplateText(it.survey_type);
        return t.includes(targetTab) || targetTab.includes(t);
    });
    if (partial) return partial;

    // 3️⃣ ఫాల్‌బ్యాక్: "Survey Type" ఖాళీగా వదిలేసిన టెంప్లేట్‌లకు కూడా — Survey Type +
    // Template Name రెండూ కలిపి కీవర్డ్‌ల ద్వారా మ్యాచ్ చేయడం (Offhire/Onhire/B+C/RRR/RRD)
    let hasCond = t => t.includes('condition') || t.includes('bc');
    let hasRRR = t => t.includes('rrr');
    let hasRRD = t => t.includes('rrd');
    let combined = templateOptions.map(it => ({
        it,
        t: normTemplateText((it.survey_type || '') + ' ' + (it.template_name || ''))
    }));

    let pick = null;
    if (sid === '3party-rrr') pick = combined.find(c => hasRRR(c.t));
    else if (sid === '3party-rrd') pick = combined.find(c => hasRRD(c.t));
    else if (sid === 'offhire-condition') pick = combined.find(c => c.t.includes('offhire') && hasCond(c.t));
    else if (sid === 'onhire-condition') pick = combined.find(c => c.t.includes('onhire') && hasCond(c.t));
    else if (sid === 'offhire') pick = combined.find(c => c.t.includes('offhire') && !hasCond(c.t) && !hasRRR(c.t) && !hasRRD(c.t));
    else if (sid === 'onhire') pick = combined.find(c => c.t.includes('onhire') && !c.t.includes('offhire') && !hasCond(c.t) && !hasRRR(c.t) && !hasRRD(c.t));

    return pick ? pick.it : null;
}

function preselectTemplateForSection(sid) {
    if (!templateOptions.length) return;
    if (sectionState[sid].selectedTemplateId) return;
    let match = findMatchingTemplate(sid);
    if (match) selectTemplateOption(sid, match.id);
}

/* ============================================================
   ఒక టాబ్ యొక్క పూర్తి HTML షెల్ (upload zones + accordion grid +
   damage widget [B+C మాత్రమే] + extra fields [3 party మాత్రమే] + submit)
   ============================================================ */
function buildSectionBlock(sid) {
    let sec = sectionDef(sid);
    let isOffhire = sec.offhire;
    let typeClass = isOffhire ? 'offhire' : 'onhire';
    let typeLabel = isOffhire ? 'Offhire' : 'Onhire';
    let calcFormula = isOffhire
        ? '⚡ Consumption = <strong>Survey</strong> − Redelivery'
        : '⚡ Consumption = <strong>Redelivery</strong> − Survey';
    let calcClass = isOffhire ? 'offhire-calc' : 'onhire-calc';

    let wrap = document.createElement('div');
    wrap.className = 'report-section-block';
    wrap.id = 'sectionBlock_' + sid;

    let html = '';
    html += `<div style="margin:15px 15px 0;font-size:15px;font-weight:800;color:#0f172a;display:flex;align-items:center;flex-wrap:wrap">
                ${sec.title}<span class="type-badge-pro ${typeClass}">${isOffhire ? '🔴' : '🟢'} ${typeLabel}</span>
              </div>`;
    html += `<div class="calc-info-pro ${calcClass}" style="margin-top:10px">${calcFormula} &nbsp;(all three fuel types)</div>`;

    html += `<div class="upload-box-grid">
        <div class="upload-zone-pro" id="excelZone_${sid}">
            <div style="font-size:24px;">📊</div>
            <div class="fw-bold text-dark mt-1" style="font-size:13px;">Select Excel File <span class="badge bg-warning text-dark" style="font-size:9px;">OPTIONAL</span></div>
            <div class="pro-dropdown" id="excelDropdown_${sid}" data-testid="excel-searchable-dropdown-${sid}">
                <button type="button" class="pro-dropdown-trigger" id="excelDropdownTrigger_${sid}" onclick="toggleDropdown('excel','${sid}')" data-testid="excel-dropdown-trigger-${sid}">
                    <span class="pro-dropdown-trigger-text" id="excelTriggerText_${sid}">Select vessel / IMO...</span>
                    <i class="fa-solid fa-chevron-down pro-dropdown-caret"></i>
                </button>
                <div class="pro-dropdown-panel" id="excelDropdownPanel_${sid}" data-testid="excel-dropdown-panel-${sid}">
                    <div class="pro-dropdown-search" id="excelSearchWrap_${sid}">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="excelSearchInput_${sid}" placeholder="Search vessel / IMO..." autocomplete="off"
                               oninput="filterDropdown('excel','${sid}', this.value)" data-testid="excel-search-input-${sid}">
                        <button type="button" class="pro-dropdown-clear" onclick="clearDropdownSearch('excel','${sid}')" data-testid="excel-search-clear-${sid}">✕</button>
                    </div>
                    <div class="pro-dropdown-options" id="excelOptionsList_${sid}" data-testid="excel-options-list-${sid}"></div>
                </div>
            </div>
            <div class="text-muted" id="excelName_${sid}" style="font-size:11px; margin-top:3px;">Auto-fill form fields from "REPORT" sheet</div>
            <button type="button" class="zone-load-btn" id="excelLoadBtn_${sid}" onclick="loadSelectedExcel('${sid}')" data-testid="excel-upload-button-${sid}">Upload</button>
        </div>
        <div class="upload-zone-pro" id="wordZone_${sid}">
            <div style="font-size:24px;">📄</div>
            <div class="fw-bold text-dark mt-1" style="font-size:13px;">Select Word Template <span class="badge bg-danger" style="font-size:9px;">REQUIRED</span></div>
            <div class="pro-dropdown" id="templateDropdown_${sid}" data-testid="template-searchable-dropdown-${sid}">
                <button type="button" class="pro-dropdown-trigger" id="templateDropdownTrigger_${sid}" onclick="toggleDropdown('template','${sid}')" data-testid="template-dropdown-trigger-${sid}">
                    <span class="pro-dropdown-trigger-text" id="templateTriggerText_${sid}">Select template...</span>
                    <i class="fa-solid fa-chevron-down pro-dropdown-caret"></i>
                </button>
                <div class="pro-dropdown-panel" id="templateDropdownPanel_${sid}" data-testid="template-dropdown-panel-${sid}">
                    <div class="pro-dropdown-search" id="templateSearchWrap_${sid}">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="templateSearchInput_${sid}" placeholder="Search template..." autocomplete="off"
                               oninput="filterDropdown('template','${sid}', this.value)" data-testid="template-search-input-${sid}">
                        <button type="button" class="pro-dropdown-clear" onclick="clearDropdownSearch('template','${sid}')" data-testid="template-search-clear-${sid}">✕</button>
                    </div>
                    <div class="pro-dropdown-options" id="templateOptionsList_${sid}" data-testid="template-options-list-${sid}"></div>
                </div>
            </div>
            <div class="text-muted" id="wordName_${sid}" style="font-size:11px; margin-top:3px;">Uses a predefined template containing {PLACEHOLDERS}</div>
            <button type="button" class="zone-load-btn" id="templateLoadBtn_${sid}" onclick="loadSelectedTemplate('${sid}')" data-testid="template-upload-button-${sid}">Upload</button>
        </div>
    </div>`;

    html += `<div class="accordion-group-card">
        <div class="accordion-header-bar" onclick="toggleAccordion('missingSection_${sid}', 'arrow1_${sid}')">
            <span class="accordion-header-title text-danger"><i class="fa-solid fa-circle-exclamation"></i> 1. Missing Details (<span id="missingBadge_${sid}">0</span>)</span>
            <span class="toggle-arrow-icon" id="arrow1_${sid}">▲</span>
        </div>
        <div class="accordion-body-content" id="missingSection_${sid}">
            <div class="form-grid-layout" id="missingGrid_${sid}"></div>
        </div>
    </div>

    <div class="accordion-group-card">
        <div class="accordion-header-bar" onclick="toggleAccordion('filledSection_${sid}', 'arrow2_${sid}')">
            <span class="accordion-header-title text-success"><i class="fa-solid fa-circle-check"></i> 2. Filled Details (<span id="filledBadge_${sid}">0</span>)</span>
            <span class="toggle-arrow-icon" id="arrow2_${sid}">▼</span>
        </div>
        <div class="accordion-body-content collapsed" id="filledSection_${sid}">
            <div class="form-grid-layout" id="filledGrid_${sid}"></div>
        </div>
    </div>`;

    wrap.innerHTML = html;

    if (CONDITION_SECTIONS.indexOf(sid) !== -1) {
        wrap.appendChild(buildDamageWidget(sid));
    }
    if (SECTION_EXTRA_FIELDS[sid]) {
        wrap.appendChild(buildExtraFieldsCard(sid));
    }

    let btn = document.createElement('button');
    btn.className = 'btn-submit-pro';
    btn.id = 'btnSubmitEngine_' + sid;
    btn.textContent = 'Generate Final Formal Report (.docx)';
    btn.onclick = function () { triggerReportGeneration(sid); };
    wrap.appendChild(btn);

    return wrap;
}

/* ============================================================
   🌟 డ్యామేజ్ విడ్జెట్ — "Offhire B+C" & "Onhire B+C" టాబ్స్ లో మాత్రమే
   ============================================================ */
function buildDamageWidget(sid) {
    let card = document.createElement('div');
    card.className = 'accordion-group-card damage-widget-card';
    card.innerHTML = `
        <div class="accordion-header-bar">
            <span class="accordion-header-title"><i class="fa-solid fa-triangle-exclamation text-danger"></i> Any Damages</span>
        </div>
        <div class="accordion-body-content">
            <div class="damage-trigger-row">
                <span class="damage-trigger-label">Any Damages?</span>
                <button type="button" class="damage-yn-btn" id="dmgBtnYes_${sid}" onclick="damageToggle('${sid}', true)">Yes — Add Damages</button>
                <button type="button" class="damage-yn-btn" id="dmgBtnNo_${sid}" onclick="damageToggle('${sid}', false)">No Damages</button>
            </div>
            <div class="damage-entry-panel" id="dmgPanel_${sid}">
                <div class="damage-entry-row">
                    <select class="damage-hold-select" id="dmgHoldSelect_${sid}">
                        <option value="">— Select Hold —</option>
                        <option>Hold #1</option><option>Hold #2</option><option>Hold #3</option>
                        <option>Hold #4</option><option>Hold #5</option><option>Hold #6</option><option>Hold #7</option>
                    </select>
                    <textarea class="damage-textarea" id="dmgText_${sid}" placeholder="Describe the damage…"></textarea>
                    <button type="button" class="damage-save-btn" onclick="damageSave('${sid}')">Save Entry</button>
                </div>
                <div class="damage-list" id="dmgList_${sid}"></div>
            </div>
        </div>`;
    return card;
}

function damageToggle(sid, showPanel) {
    let panel = document.getElementById('dmgPanel_' + sid);
    let btnYes = document.getElementById('dmgBtnYes_' + sid);
    let btnNo = document.getElementById('dmgBtnNo_' + sid);
    if (!panel) return;
    if (showPanel) {
        panel.classList.add('visible');
        btnYes.classList.add('active-yes'); btnNo.classList.remove('active-no');
    } else {
        panel.classList.remove('visible');
        btnNo.classList.add('active-no'); btnYes.classList.remove('active-yes');
    }
}

function damageSave(sid) {
    let holdEl = document.getElementById('dmgHoldSelect_' + sid);
    let textEl = document.getElementById('dmgText_' + sid);
    if (!holdEl || !textEl) return;
    let hold = holdEl.value.trim();
    let text = textEl.value.trim();
    if (!hold) { Swal.fire({ icon:'warning', title:'Select a Hold', text:'Please select a Hold number.' }); return; }
    if (!text) { Swal.fire({ icon:'warning', title:'Enter description', text:'Please enter a damage description.' }); return; }
    sectionState[sid].damages.push({ hold: hold, text: text });
    holdEl.value = ''; textEl.value = '';
    renderDamageList(sid);
}

function removeDamage(sid, idx) {
    sectionState[sid].damages.splice(idx, 1);
    renderDamageList(sid);
}

function renderDamageList(sid) {
    let listEl = document.getElementById('dmgList_' + sid);
    if (!listEl) return;
    listEl.innerHTML = sectionState[sid].damages.map((e, i) =>
        `<div class="damage-entry-item">
            <span><span class="damage-entry-hold">${e.hold}:</span>${e.text}</span>
            <button type="button" class="damage-remove-btn" onclick="removeDamage('${sid}',${i})" title="Remove">✕</button>
        </div>`
    ).join('');
}

function getDamagesText(sid) {
    let list = sectionState[sid].damages;
    if (!list || list.length === 0) return 'No Major Apparant damages reported.';
    return list.map((e, i) => (i + 1) + '. ' + e.hold + ': ' + e.text).join('\n');
}

/* ============================================================
   🌟 ఎక్స్ట్రా ఫీల్డ్స్ కార్డ్ — "3 Party RRR/RRD Offhire" టాబ్స్ లో మాత్రమే
   (RRR = Sub Charterer, RRD = Next Charterer)
   ============================================================ */
function buildExtraFieldsCard(sid) {
    let fields = SECTION_EXTRA_FIELDS[sid] || [];
    let card = document.createElement('div');
    card.className = 'accordion-group-card extra-field-card';
    let inner = `<div class="accordion-header-bar">
            <span class="accordion-header-title"><i class="fa-solid fa-plus text-primary"></i> Additional Fields <span class="badge bg-warning text-dark" style="font-size:9px;">OPTIONAL</span></span>
        </div>
        <div class="accordion-body-content"><div class="form-grid-layout">`;
    fields.forEach(f => {
        inner += `<div class="form-group">
                <label class="control-label">${f.label}</label>
                <input type="text" class="generator-input" id="extra_${sid}_${f.id}" placeholder="Enter ${f.label}..."
                       style="border-color:#e2e8f0;background:#f8fafc"
                       oninput="onExtraFieldChange('${sid}','${f.id}', this.value)"
                       onchange="onExtraFieldChange('${sid}','${f.id}', this.value)">
            </div>`;
    });
    inner += `</div></div>`;
    card.innerHTML = inner;
    return card;
}

function onExtraFieldChange(sid, fieldId, val) {
    sectionState[sid].extra[fieldId] = val;
}

/* ============================================================
   అకార్డియన్ టోగుల్
   ============================================================ */
function toggleAccordion(sectionId, arrowId) {
    let el = document.getElementById(sectionId);
    let arrow = document.getElementById(arrowId);
    el.classList.toggle("collapsed");
    arrow.innerText = el.classList.contains("collapsed") ? "▼" : "▲";
}

/* ============================================================
   మిస్సింగ్ / ఫిల్డ్ గ్రిడ్ రెండరింగ్ (per-section, label overrides తో)
   ============================================================ */
function renderGroupedGrids(sid) {
    let missingGrid = document.getElementById("missingGrid_" + sid);
    let filledGrid = document.getElementById("filledGrid_" + sid);
    if (!missingGrid || !filledGrid) return;

    let isOffhire = isOffhireSection(sid);
    let formValues = sectionState[sid].formValues;

    let missingHtml = "";
    let filledHtml = "";
    let missingCount = 0;
    let filledCount = 0;

    REPORT_FIELDS.forEach(f => {
        let isCalc = f.type === 'calc';
        let isReadonly = f.readonly || isCalc;
        let curVal = formValues[f.id] || "";

        let isFilled = curVal.trim() !== "";
        let fieldClass = isCalc ? 'auto-calc' : (isFilled ? 'fc-valid' : 'fc-invalid');

        if (!isCalc && f.id !== 'HOLDS_ALPHA') {
            if (isFilled) filledCount++; else missingCount++;
        } else {
            filledCount++;
        }

        let effectiveLabel = (!isOffhire && ONHIRE_LABEL_OVERRIDES[f.id]) ? ONHIRE_LABEL_OVERRIDES[f.id] : f.label;
        // 🌟 index (1).html లాజిక్: Onhire సెక్షన్స్ లో REDELIVERY_DATE_LT ఇన్‌పుట్ టైప్ 'text' గా మారుతుంది
        let effectiveType = f.type;
        if (!isOffhire && f.id === 'REDELIVERY_DATE_LT') effectiveType = 'text';

        let inputHtml = "";
        if (effectiveType === 'select') {
            inputHtml = `
                <select id="input_${sid}_${f.id}" class="generator-input ${fieldClass}"
                        onchange="handleDropdownInput('${sid}','${f.id}', this.value)">
                    <option value="">— Select —</option>
                    ${f.options.map(o => `<option value="${o}" ${o===curVal?'selected':''}>${o}</option>`).join('')}
                </select>
            `;
        } else {
            let inputType = (f.id === 'SURVEY_DATE') ? 'text' : (effectiveType === 'time' ? 'time' : (effectiveType === 'date' ? 'date' : 'text'));
            inputHtml = `
                <input type="${inputType}"
                       id="input_${sid}_${f.id}"
                       class="generator-input ${fieldClass}"
                       ${isReadonly ? 'readonly' : ''}
                       value="${curVal}"
                       placeholder="Enter ${effectiveLabel}..."
                       oninput="handleLiveTyping('${sid}','${f.id}', this.value)"
                       onchange="handleDateTimeChange('${sid}','${f.id}', this.value)"
                       onblur="handleFieldBlur('${sid}','${f.id}', this.value)">
            `;
        }

        let block = `
            <div class="form-group" id="container_${sid}_${f.id}">
                <label class="control-label">${effectiveLabel}</label>
                ${inputHtml}
            </div>
        `;

        if (isCalc || f.id === 'HOLDS_ALPHA' || isFilled) {
            filledHtml += block;
        } else {
            missingHtml += block;
        }
    });

    missingGrid.innerHTML = missingHtml;
    filledGrid.innerHTML = filledHtml;

    document.getElementById("missingBadge_" + sid).innerText = missingCount;
    document.getElementById("filledBadge_" + sid).innerText = filledCount;
    validateFormState(sid);
}

function handleLiveTyping(sid, id, val) {
    sectionState[sid].formValues[id] = val;
    let el = document.getElementById(`input_${sid}_${id}`);
    if (el) {
        if (val.trim() !== "") {
            el.classList.remove("fc-invalid");
            el.classList.add("fc-valid");
        } else {
            el.classList.remove("fc-valid");
            el.classList.add("fc-invalid");
        }
    }
    if (['SURVEY_VLSFO', 'REDELIVERY_VLSFO', 'SURVEY_LSMGO', 'REDELIVERY_LSMGO', 'SURVEY_HSFO', 'REDELIVERY_HSFO'].includes(id)) {
        calculateConsumption(sid);
    }
    validateFormState(sid);
}

function handleDropdownInput(sid, id, val) {
    sectionState[sid].formValues[id] = val;
    if (id === 'HOLDS') {
        sectionState[sid].formValues['HOLDS_ALPHA'] = NUMS_TO_WORDS[val] || '';
    }
    renderGroupedGrids(sid);
}

function handleDateTimeChange(sid, id, val) {
    sectionState[sid].formValues[id] = val;
    let el = document.getElementById(`input_${sid}_${id}`);
    if (el && (el.type === 'date' || el.type === 'time')) {
        renderGroupedGrids(sid);
    }
}

function handleFieldBlur(sid, id, val) {
    sectionState[sid].formValues[id] = val;
    forcePrecisionFormat(sid, id);
    renderGroupedGrids(sid);
}

function forcePrecisionFormat(sid, id) {
    let f = REPORT_FIELDS.find(field => field.id === id);
    if (f && f.precision && sectionState[sid].formValues[id] && sectionState[sid].formValues[id].trim() !== "") {
        let num = parseFloat(sectionState[sid].formValues[id].replace(/,/g, ''));
        if (!isNaN(num)) {
            sectionState[sid].formValues[id] = num.toFixed(f.precision);
        }
    }
}

/* ============================================================
   🌟 కన్సంప్షన్ కాల్క్యులేషన్ — Offhire = Survey − Redelivery,
   Onhire = Redelivery − Survey (index (1).html లోని అదే ఫార్ములా)
   ============================================================ */
function calculateConsumption(sid) {
    let isOffhire = isOffhireSection(sid);
    let fv = sectionState[sid].formValues;
    const types = ['VLSFO', 'LSMGO', 'HSFO'];
    types.forEach(type => {
        let sv = parseFloat(fv[`SURVEY_${type}`]) || 0;
        let rd = parseFloat(fv[`REDELIVERY_${type}`]) || 0;
        let result = isOffhire ? (sv - rd).toFixed(3) : (rd - sv).toFixed(3);
        fv[`CONS_${type}`] = result;

        let consEl = document.getElementById(`input_${sid}_CONS_${type}`);
        if (consEl) consEl.value = result;
    });
}

/* ============================================================
   Excel తేదీ / సమయం ఫార్మాటర్స్
   ============================================================ */
function formatExcelDateText(excelValue) {
    if (!excelValue || String(excelValue).trim() === "") return "";
    if (!isNaN(excelValue)) {
        try {
            let parsed = XLSX.SSF.parse_date_code(parseFloat(excelValue));
            if (parsed) return String(parsed.d).padStart(2, '0') + "-" + String(parsed.m).padStart(2, '0') + "-" + parsed.y;
        } catch(e) {}
    }
    return String(excelValue).trim();
}

function formatExcelDate(excelValue) {
    if (!excelValue || String(excelValue).trim() === "") return "";
    if (typeof excelValue === 'string' && excelValue.includes('-') && excelValue.length === 10) return excelValue;
    if (!isNaN(excelValue)) {
        try {
            let parsed = XLSX.SSF.parse_date_code(parseFloat(excelValue));
            if (parsed) return parsed.y + "-" + String(parsed.m).padStart(2, '0') + "-" + String(parsed.d).padStart(2, '0');
        } catch(e) {}
    }
    let cleanStr = String(excelValue).trim().replace(/\//g, '-');
    let parts = cleanStr.split('-');
    if (parts.length === 3) {
        if (parts[0].length === 4) return cleanStr;
        if (parts[2].length === 4) return parts[2] + "-" + String(parts[1]).padStart(2, '0') + "-" + String(parts[0]).padStart(2, '0');
    }
    let d = new Date(excelValue);
    return isNaN(d.getTime()) ? "" : d.toISOString().slice(0, 10);
}

function formatExcelTime(excelSerial) {
    if (isNaN(excelSerial) || String(excelSerial).trim() === "") return excelSerial;
    try {
        let parsed = XLSX.SSF.parse_date_code(parseFloat(excelSerial));
        if (parsed) return String(parsed.H).padStart(2, '0') + ":" + String(parsed.M).padStart(2, '0');
    } catch(e) {}
    let totalSeconds = Math.round(excelSerial * 24 * 3600);
    let hours = Math.floor(totalSeconds / 3600);
    let minutes = Math.floor((totalSeconds % 3600) / 60);
    return String(hours).padStart(2, '0') + ":" + String(minutes).padStart(2, '0');
}

function base64ToArrayBuffer(base64) {
    let binary = atob(base64);
    let bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes.buffer;
}

/* ============================================================
   వర్డ్ టెంప్లేట్ వర్తింపజేయడం (per-section)
   ============================================================ */
function applyWordTemplateBuffer(sid, arrayBuffer, label) {
    sectionState[sid].wordTemplateBuffer = arrayBuffer;
    let zone = document.getElementById("wordZone_" + sid);
    zone.classList.remove("required-err");
    zone.classList.add("uploaded");
    document.getElementById("wordName_" + sid).innerText = "✓ " + label;
    document.getElementById("templateLoadBtn_" + sid).classList.remove("ready");
    validateFormState(sid);
}

/* ============================================================
   ఎక్సెల్ డేటా ఫారంలోకి చదవడం (per-section, 3-party ఎక్స్ట్రా ఫీల్డ్ తో సహా)
   ============================================================ */
function applyExcelArrayBuffer(sid, arrayBuffer, label) {
    try {
        let workbook = XLSX.read(arrayBuffer, { type: 'array' });
        let worksheet = workbook.Sheets['REPORT'] || workbook.Sheets[workbook.SheetNames[0]];
        if (!worksheet) return;

        let rows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
        let excelMap = {};
        rows.forEach(row => {
            if (row[0] !== undefined && row[1] !== undefined) {
                excelMap[String(row[0]).trim()] = row[1];
            }
        });

        let formValues = sectionState[sid].formValues;

        REPORT_FIELDS.forEach(f => {
            if (excelMap[f.id] !== undefined) {
                let val = excelMap[f.id];

                if (f.type === 'time') {
                    val = formatExcelTime(val);
                } else if (f.id === 'SURVEY_DATE') {
                    val = formatExcelDateText(val);
                } else if (f.type === 'date') {
                    val = formatExcelDate(val);
                } else if (f.precision && !isNaN(val)) {
                    val = parseFloat(val).toFixed(f.precision);
                } else {
                    val = String(val).trim();
                }
                formValues[f.id] = val;
            }
        });

        let holdsVal = formValues['HOLDS'];
        if (holdsVal) formValues['HOLDS_ALPHA'] = NUMS_TO_WORDS[holdsVal] || '';

        ['VLSFO','LSMGO','HSFO'].forEach(t => {
            let sv = parseFloat(formValues[`SURVEY_${t}`]) || 0;
            let rd = parseFloat(formValues[`REDELIVERY_${t}`]) || 0;
            formValues[`CONS_${t}`] = isOffhireSection(sid) ? (sv - rd).toFixed(3) : (rd - sv).toFixed(3);
        });

        // 🌟 3 Party టాబ్స్ లో Sub/Next Charterer ఫీల్డ్ ను కూడా ఎక్సెల్ నుండి ఆటో-ఫిల్ చేయడం
        let extras = SECTION_EXTRA_FIELDS[sid];
        if (extras) {
            extras.forEach(f => {
                if (excelMap[f.id] !== undefined) {
                    sectionState[sid].extra[f.id] = String(excelMap[f.id]).trim();
                    let el = document.getElementById('extra_' + sid + '_' + f.id);
                    if (el) el.value = sectionState[sid].extra[f.id];
                }
            });
        }

        document.getElementById("excelZone_" + sid).classList.add("uploaded");
        document.getElementById("excelName_" + sid).innerText = "✓ Data Imported: " + label;
        document.getElementById("excelLoadBtn_" + sid).classList.remove("ready");
        renderGroupedGrids(sid);
    } catch (err) { Swal.fire({ icon:'error', title:'Excel Read Error', text: err.message }); }
}

/* ============================================================
   సెర్చబుల్ డ్రాప్‌డౌన్‌లు (per-section)
   ============================================================ */
function toggleDropdown(kind, sid) {
    let dd = document.getElementById(kind + "Dropdown_" + sid);
    let isOpen = dd.classList.contains("open");
    closeAllDropdowns();
    if (!isOpen) {
        dd.classList.add("open");
        let input = document.getElementById(kind + "SearchInput_" + sid);
        setTimeout(() => input && input.focus(), 50);
    }
}

function closeAllDropdowns() {
    document.querySelectorAll('.pro-dropdown.open').forEach(dd => dd.classList.remove('open'));
}

function clearDropdownSearch(kind, sid) {
    let input = document.getElementById(kind + "SearchInput_" + sid);
    input.value = "";
    filterDropdown(kind, sid, "");
    input.focus();
}

document.addEventListener("click", function (e) {
    document.querySelectorAll('.pro-dropdown.open').forEach(dd => {
        if (!dd.contains(e.target)) dd.classList.remove('open');
    });
});

function renderExcelOptions(sid, items) {
    let list = document.getElementById("excelOptionsList_" + sid);
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<div class="pro-dropdown-empty"><i class="fa-regular fa-folder-open me-1"></i> No Excel files found</div>';
        return;
    }
    let selectedId = sectionState[sid].selectedExcelUploadId;
    list.innerHTML = items.map(it => {
        let title = (it.vessel_name || 'Unknown Vessel') + (it.imo_number ? (' — IMO ' + it.imo_number) : '');
        let subParts = [];
        if (it.survey_type) subParts.push(it.survey_type);
        if (it.upload_date) subParts.push(it.upload_date);
        let isSelected = selectedId === it.upload_id;
        return `<div class="pro-dropdown-option ${isSelected ? 'selected' : ''}" onclick="selectExcelOption('${sid}', ${it.upload_id})" data-testid="excel-option-${sid}-${it.upload_id}">
                    <div>
                        <div class="opt-title">${title}</div>
                        <div class="opt-sub">${subParts.join(' • ')}</div>
                    </div>
                    <i class="fa-solid fa-check opt-check"></i>
                </div>`;
    }).join('');
}

function renderTemplateOptions(sid, items) {
    let list = document.getElementById("templateOptionsList_" + sid);
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<div class="pro-dropdown-empty"><i class="fa-regular fa-folder-open me-1"></i> No templates uploaded yet</div>';
        return;
    }
    let selectedId = sectionState[sid].selectedTemplateId;
    list.innerHTML = items.map(it => {
        let isSelected = selectedId === it.id;
        return `<div class="pro-dropdown-option ${isSelected ? 'selected' : ''}" onclick="selectTemplateOption('${sid}', ${it.id})" data-testid="template-option-${sid}-${it.id}">
                    <div>
                        <div class="opt-title">${it.template_name}</div>
                        ${it.survey_type ? `<div class="opt-sub">${it.survey_type}</div>` : ''}
                    </div>
                    <i class="fa-solid fa-check opt-check"></i>
                </div>`;
    }).join('');
}

function filterDropdown(kind, sid, term) {
    term = (term || '').toLowerCase();
    let searchWrap = document.getElementById(kind + "SearchWrap_" + sid);
    searchWrap.classList.toggle("has-text", term.trim() !== "");

    let source = kind === 'excel' ? excelOptions : templateOptions;
    let filtered = source.filter(it => {
        let haystack = kind === 'excel'
            ? [it.vessel_name, it.imo_number, it.survey_type].join(' ')
            : [it.template_name, it.survey_type].join(' ');
        return haystack.toLowerCase().includes(term);
    });
    if (kind === 'excel') renderExcelOptions(sid, filtered); else renderTemplateOptions(sid, filtered);
}

function selectExcelOption(sid, uploadId) {
    let item = excelOptions.find(it => it.upload_id === uploadId);
    if (!item) return;
    sectionState[sid].selectedExcelUploadId = uploadId;
    document.getElementById("excelTriggerText_" + sid).innerText = (item.vessel_name || '') + (item.imo_number ? (' — IMO ' + item.imo_number) : '');
    document.getElementById("excelDropdown_" + sid).classList.add("has-value");
    document.getElementById("excelDropdown_" + sid).classList.remove("open");
    document.getElementById("excelLoadBtn_" + sid).classList.add("ready");
    renderExcelOptions(sid, excelOptions);
}

function selectTemplateOption(sid, templateId) {
    let item = templateOptions.find(it => it.id === templateId);
    if (!item) return;
    sectionState[sid].selectedTemplateId = templateId;
    document.getElementById("templateTriggerText_" + sid).innerText = item.template_name;
    document.getElementById("templateDropdown_" + sid).classList.add("has-value");
    document.getElementById("templateDropdown_" + sid).classList.remove("open");
    document.getElementById("templateLoadBtn_" + sid).classList.add("ready");
    renderTemplateOptions(sid, templateOptions);
}

function loadExcelDropdownData() {
    fetch('ajax/report_generator_data.php?action=list_excels')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Database error', text: res.message || 'Could not load Excel files.' });
                return;
            }
            excelOptions = res.data || [];
            SECTIONS.forEach(s => {
                renderExcelOptions(s.id, excelOptions);
                // ఈ టాబ్ ఇప్పటికే బిల్డ్ అయ్యి ఉంటే (యూజర్ ఇప్పటికే ఆ tab ని క్లిక్ చేసుంటే),
                // ఈ డేటా వచ్చాక కూడా ఆ vessel ని preselect చేయడం
                if (document.getElementById('sectionBlock_' + s.id)) preselectExcelForSection(s.id);
            });
        })
        .catch(() => Swal.fire({ icon: 'error', title: 'Database error', text: 'Could not load Excel files.' }));
}

function loadTemplateDropdownData() {
    fetch('ajax/report_generator_data.php?action=list_templates')
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Database error', text: res.message || 'Could not load templates.' });
                return;
            }
            templateOptions = res.data || [];
            SECTIONS.forEach(s => {
                renderTemplateOptions(s.id, templateOptions);
                // ఈ టాబ్ ఇప్పటికే బిల్డ్ అయ్యి ఉంటే, ఈ డేటా వచ్చాక కూడా మ్యాచింగ్ template ని preselect చేయడం
                if (document.getElementById('sectionBlock_' + s.id)) preselectTemplateForSection(s.id);
            });
        })
        .catch(() => Swal.fire({ icon: 'error', title: 'Database error', text: 'Could not load templates.' }));
}

function loadSelectedExcel(sid) {
    let uploadId = sectionState[sid].selectedExcelUploadId;
    if (!uploadId) {
        Swal.fire({ icon: 'warning', title: 'No Excel selected', text: 'Please choose an Excel file from the list first.' });
        return;
    }
    fetch('ajax/report_generator_data.php?action=get_excel&upload_id=' + uploadId)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Excel not found', text: (res.message || 'The selected Excel file could not be loaded.') + (res.debug ? ('\n\n' + res.debug) : '') });
                return;
            }
            let item = excelOptions.find(it => it.upload_id === uploadId);
            applyExcelArrayBuffer(sid, base64ToArrayBuffer(res.data), item ? item.file_name : 'Excel file');
        })
        .catch(() => Swal.fire({ icon: 'error', title: 'Database error', text: 'Could not load the selected Excel file.' }));
}

function loadSelectedTemplate(sid) {
    let templateId = sectionState[sid].selectedTemplateId;
    if (!templateId) {
        Swal.fire({ icon: 'warning', title: 'No Template selected', text: 'Please choose a Word template from the list first.' });
        return;
    }
    fetch('ajax/report_generator_data.php?action=get_template&template_id=' + templateId)
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                Swal.fire({ icon: 'error', title: 'Template missing', text: (res.message || 'The selected template could not be loaded.') + (res.debug ? ('\n\n' + res.debug) : '') });
                return;
            }
            let item = templateOptions.find(it => it.id === templateId);
            applyWordTemplateBuffer(sid, base64ToArrayBuffer(res.data), item ? item.template_name : 'Word Template');
        })
        .catch(() => Swal.fire({ icon: 'error', title: 'Database error', text: 'Could not load the selected template.' }));
}

/* ============================================================
   సబ్మిట్ బటన్ రెడీ స్టేట్
   ============================================================ */
function validateFormState(sid) {
    let isWordReady = !!sectionState[sid].wordTemplateBuffer;
    let missingCount = 0;
    let formValues = sectionState[sid].formValues;

    REPORT_FIELDS.forEach(f => {
        if (f.type === 'calc' || f.readonly) return;
        let val = formValues[f.id] || "";
        if (val.trim() === "") missingCount++;
    });

    let btn = document.getElementById("btnSubmitEngine_" + sid);
    if (!btn) return;
    if (isWordReady && missingCount === 0) {
        btn.classList.add("ready");
    } else {
        btn.classList.remove("ready");
    }
}

/* ============================================================
   🌟 రిపోర్ట్ జనరేషన్ — Damages [B+C టాబ్స్] + Extra Fields [3 Party టాబ్స్] +
   ఫైల్ నేమింగ్ కన్వెన్షన్ — index (1).html లో ఉన్న అదే లాజిక్
   ============================================================ */
function triggerReportGeneration(sid) {
    let btn = document.getElementById("btnSubmitEngine_" + sid);
    if (!btn.classList.contains("ready")) {
        let el = document.getElementById("missingSection_" + sid);
        el.classList.remove("collapsed");
        document.getElementById("arrow1_" + sid).innerText = "▲";
        Swal.fire({ icon: 'warning', title: 'Missing details', text: 'Please review and fill all required configurations highlighted in red inside "1. Missing Details".' });
        return;
    }

    try {
        let zip = new PizZip(sectionState[sid].wordTemplateBuffer);
        let doc = new docxtemplater(zip, { paragraphLoop: true, linebreaks: true, nullGetter: function() { return ''; } });

        let formValues = sectionState[sid].formValues;
        let exportData = { ...formValues };
        REPORT_FIELDS.forEach(f => {
            if (f.type === 'date' && f.id !== 'SURVEY_DATE' && exportData[f.id]) {
                exportData[f.id] = formatDateForExport(exportData[f.id]);
            }
        });

        // 🌟 Damages injection — Offhire/Onhire B+C టాబ్స్ కు మాత్రమే
        if (CONDITION_SECTIONS.indexOf(sid) !== -1) {
            let dmgText = getDamagesText(sid);
            exportData['Damages_1']   = dmgText;
            exportData['DAMAGES_1']   = dmgText;
            exportData['DAMAGE_LIST'] = dmgText;
            exportData['damages_1']   = dmgText;
        }

        // 🌟 ఎక్స్ట్రా ఫీల్డ్స్ — 3 Party RRR/RRD టాబ్స్ కు మాత్రమే
        let extras = SECTION_EXTRA_FIELDS[sid];
        if (extras) {
            extras.forEach(f => {
                exportData[f.id] = (sectionState[sid].extra && sectionState[sid].extra[f.id]) || '';
            });
        }

        doc.setData(exportData);
        doc.render();

        let outputBlob = doc.getZip().generate({
            type: 'blob',
            mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            compression: 'DEFLATE'
        });

        let vesselName = (formValues['SHIP_NAME'] || 'VESSEL').trim().toUpperCase();
        let sectionLabel;
        if (sid === 'offhire')                              sectionLabel = 'OFFHIRE';
        else if (sid === 'onhire')                           sectionLabel = 'ONHIRE';
        else if (sid === 'offhire-condition')                sectionLabel = 'OFFHIRE CONDITION';
        else if (sid === 'onhire-condition')                 sectionLabel = 'ONHIRE CONDITION';
        else if (sid === '3party-rrr' || sid === '3party-rrd') sectionLabel = 'OFFHIRE';
        else sectionLabel = sid.toUpperCase();
        let finalFileName = vesselName + " " + sectionLabel + " FINAL FORMAL REPORT.docx";

        let url = URL.createObjectURL(outputBlob);
        let a = document.createElement('a');
        a.href = url; a.download = finalFileName;
        document.body.appendChild(a); a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 1000);
    } catch (err) { Swal.fire({ icon: 'error', title: 'Template Render Error', text: err.message }); }
}

function ordinalSuffix(n) {
    n = parseInt(n, 10);
    var s = ['th','st','nd','rd'], v = n % 100;
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

function formatDateForExport(raw) {
    if (!raw) return '';
    let m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (m) return ordinalSuffix(parseInt(m[3],10)) + ' ' + MONTH_NAMES[parseInt(m[2],10)-1] + ' ' + m[1];
    return raw;
}

/* ============================================================
   INIT
   ============================================================ */
buildTabsBar();
showTab('offhire');
loadExcelDropdownData();
loadTemplateDropdownData();
</script>

<div class="fixed-bottom-nav">
    <?php include 'includes/nav.php'; ?>
</div>

<?php
// పాత పద్ధతిలో ఇక్కడ nav.php ఉంటే తీసేయండి
include 'includes/footer.php';
?>
