<?php
require_once "../config/auth.php";
require_once "../config/database.php";


?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>YMR Marine Report Dashboard</title>
<script src="https://cdn.jsdelivr.net/npm/pizzip@3.1.4/dist/pizzip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docxtemplater@3.44.0/build/docxtemplater.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@100..900&display=swap" rel="stylesheet"><style>

.premium-modal{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.65);
    backdrop-filter:blur(10px);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:999999;
    animation:fadeIn .4s ease;
}

.premium-card{
    width:500px;
    max-width:90%;
    background:linear-gradient(
        135deg,
        #0f172a,
        #1e293b
    );
    border:1px solid rgba(255,255,255,.15);
    border-radius:24px;
    padding:35px;
    text-align:center;
    color:white;
    box-shadow:
    0 25px 60px rgba(0,0,0,.4);
    animation:popup .45s ease;
}

.live-badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 16px;
    border-radius:50px;
    background:rgba(34,197,94,.15);
    color:#4ade80;
    font-weight:700;
    margin-bottom:20px;
}

.pulse-dot{
    width:10px;
    height:10px;
    background:#22c55e;
    border-radius:50%;
    animation:pulse 1.5s infinite;
}

.icon-circle{
    width:90px;
    height:90px;
    margin:auto;
    border-radius:50%;
    background:linear-gradient(
        135deg,
        #0093C8,
        #00A3DF
    );
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:40px;
    margin-bottom:20px;
    box-shadow:0 0 30px rgba(0,163,223,.5);
}

.premium-card h1{
    font-size:30px;
    margin-bottom:10px;
    font-family:'Lexend',sans-serif;
}

.premium-card p{
    color:#cbd5e1;
    margin-bottom:20px;
    line-height:1.7;
}

.feature-list{
    text-align:left;
    background:rgba(255,255,255,.05);
    border-radius:12px;
    padding:15px;
    margin-bottom:25px;
}

.feature-list div{
    padding:6px 0;
    color:#e2e8f0;
}

.btn-group{
    display:flex;
    gap:12px;
}

.open-btn{
    flex:1;
    text-decoration:none;
    background:linear-gradient(
        135deg,
        #0093C8,
        #00A3DF
    );
    color:white;
    padding:14px;
    border-radius:12px;
    font-weight:700;
    transition:.3s;
}

.open-btn:hover{
    transform:translateY(-3px);
}

.later-btn{
    background:#334155;
    color:white;
    border:none;
    padding:14px 24px;
    border-radius:12px;
    cursor:pointer;
}

@keyframes popup{
    from{
        opacity:0;
        transform:scale(.8);
    }
    to{
        opacity:1;
        transform:scale(1);
    }
}

@keyframes fadeIn{
    from{opacity:0;}
    to{opacity:1;}
}

@keyframes pulse{
    0%{box-shadow:0 0 0 0 rgba(34,197,94,.6);}
    100%{box-shadow:0 0 0 15px rgba(34,197,94,0);}
}
:root {
  --bg: #f0f4f8;
  --surface: #ffffff;
  --surface2: #f7f9fc;
  --panel: #1a2540;
  --panel-hover: #243355;
  --panel-active: #2e4080;
  --accent: #00A3DF;
  --accent2: #0093C8;
  --accent-glow: rgba(0,163,223,0.12);
  --offhire-color: #dc2626;
  --offhire-light: rgba(220,38,38,0.1);
  --offhire-border: rgba(220,38,38,0.3);
  --onhire-color: #16a34a;
  --onhire-light: rgba(22,163,74,0.1);
  --onhire-border: rgba(22,163,74,0.3);
  --text: #1e293b;
  --text-muted: #64748b;
  --text-panel: #c8d6f0;
  --text-panel-muted: #6b82aa;
  --border: #e2e8f0;
  --border-focus: #00A3DF;
  --error: #ef4444;
  --success: #22c55e;
  --warning: #f59e0b;
  --shadow: 0 4px 24px rgba(0,0,0,0.07);
  --shadow-lg: 0 12px 48px rgba(0,0,0,0.12);
  --radius: 12px;
  --radius-sm: 8px;
  --radius-xs: 6px;
  --transition: 0.22s cubic-bezier(.4,0,.2,1);
}
[data-theme="dark"] {
  --bg:#0f1623;--surface:#1a2335;--surface2:#141e2e;
  --panel:#0d1525;--panel-hover:#172030;--panel-active:#1e2f50;
  --text:#e2eaf8;--text-muted:#8da0be;--border:#2a3a55;
  --shadow:0 4px 24px rgba(0,0,0,0.35);--shadow-lg:0 12px 48px rgba(0,0,0,0.45);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);background:var(--bg);transition:background var(--transition),color var(--transition)}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px}
.app-shell{display:flex;flex-direction:column;height:100vh;overflow:hidden}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:0 28px;height:62px;background:var(--surface);border-bottom:1px solid var(--border);box-shadow:var(--shadow);z-index:100;flex-shrink:0}
.topbar-left{display:flex;align-items:center;gap:16px}
.logo-wrap{display:flex;align-items:center;gap:12px}
.logo-img{width:42px;height:42px;border-radius:10px;background:linear-gradient(135deg,#1a2540,#2563eb);display:flex;align-items:center;justify-content:center;font-family:'Lexend',sans-serif;font-weight:800;font-size:17px;color:#fff}
.logo-text{font-family:'Lexend',sans-serif;font-weight:700;font-size:16px;color:var(--text)}
.logo-text span{display:block;font-size:11px;font-weight:400;color:var(--text-muted);letter-spacing:0.5px}
.topbar-divider{width:1px;height:28px;background:var(--border)}
.topbar-right{display:flex;align-items:center;gap:20px}
.time-display{font-family:'Lexend',sans-serif;font-size:13px;font-weight:600;color:var(--text-muted);letter-spacing:0.5px}
.status-pill{display:flex;align-items:center;gap:8px;padding:6px 14px;border-radius:999px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);font-size:12px;font-weight:600;color:#16a34a}
[data-theme="dark"] .status-pill{background:rgba(34,197,94,0.08);color:#4ade80}
.status-dot{width:8px;height:8px;border-radius:50%;background:#22c55e;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,0.5)}50%{box-shadow:0 0 0 5px rgba(34,197,94,0)}}
.lib-status{display:flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:0.3px}
.lib-status.ok{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#16a34a}
.lib-status.err{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#dc2626}
.lib-dot{width:6px;height:6px;border-radius:50%}
.lib-status.ok .lib-dot{background:#22c55e}
.lib-status.err .lib-dot{background:#ef4444}
.dm-toggle{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;color:var(--text-muted);font-weight:500;user-select:none}
.dm-track{width:40px;height:22px;border-radius:11px;background:var(--border);transition:background var(--transition);position:relative;flex-shrink:0}
.dm-track.on{background:var(--accent)}
.dm-thumb{position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform var(--transition);box-shadow:0 1px 4px rgba(0,0,0,0.2)}
.dm-track.on .dm-thumb{transform:translateX(18px)}

/* LAYOUT */
.body-area{display:flex;flex:1;overflow:hidden}
.left-panel{width:248px;flex-shrink:0;background:var(--panel);display:flex;flex-direction:column;overflow-y:auto;overflow-x:hidden;padding:16px 0}
.panel-section-label{font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:var(--text-panel-muted);padding:0 18px;margin:16px 0 6px}

/* NAV CATEGORY HEADERS (Offhire / Onhire) */
.nav-category{margin:6px 12px 2px;border-radius:8px;overflow:hidden}
.nav-category-header{display:flex;align-items:center;gap:10px;padding:10px 14px;cursor:pointer;font-family:'Lexend',sans-serif;font-size:12px;font-weight:700;letter-spacing:0.4px;text-transform:uppercase;border-radius:8px;transition:all var(--transition);user-select:none;position:relative}
.nav-category-header.offhire-cat{color:#fca5a5;background:rgba(220,38,38,0.12);border:1px solid rgba(220,38,38,0.2)}
.nav-category-header.offhire-cat:hover{background:rgba(220,38,38,0.2)}
.nav-category-header.offhire-cat.open{background:rgba(220,38,38,0.18);border-color:rgba(220,38,38,0.35)}
.nav-category-header.onhire-cat{color:#86efac;background:rgba(22,163,74,0.12);border:1px solid rgba(22,163,74,0.2)}
.nav-category-header.onhire-cat:hover{background:rgba(22,163,74,0.2)}
.nav-category-header.onhire-cat.open{background:rgba(22,163,74,0.18);border-color:rgba(22,163,74,0.35)}
.nav-cat-icon{font-size:15px}
.nav-cat-arrow{margin-left:auto;font-size:11px;transition:transform var(--transition);opacity:0.7}
.nav-category-header.open .nav-cat-arrow{transform:rotate(90deg)}
.nav-cat-body{overflow:hidden;max-height:0;transition:max-height 0.32s ease}
.nav-cat-body.open{max-height:200px}
.nav-sub-item{display:flex;align-items:center;gap:9px;padding:9px 14px 9px 38px;cursor:pointer;color:var(--text-panel-muted);font-size:12.5px;font-weight:500;border-left:3px solid transparent;transition:all var(--transition);margin:1px 0}
.nav-sub-item:hover{color:#fff;background:var(--panel-hover)}
.nav-sub-item.active{color:#fff;background:var(--panel-active)}
.nav-sub-item.active-offhire{border-left-color:var(--offhire-color)!important;color:#fca5a5}
.nav-sub-item.active-onhire{border-left-color:var(--onhire-color)!important;color:#86efac}

/* standalone nav items */
.nav-item{display:flex;align-items:center;gap:10px;padding:10px 18px;cursor:pointer;color:var(--text-panel);font-size:13px;font-weight:500;border-left:3px solid transparent;transition:all var(--transition);user-select:none}
.nav-item:hover{background:var(--panel-hover);color:#fff}
.nav-item.active{background:var(--panel-active);color:#fff;border-left-color:var(--accent2)}
.nav-item .nav-icon{font-size:16px;flex-shrink:0;width:20px;text-align:center}

.main-content{flex:1;overflow-y:auto;padding:28px 32px;background:var(--bg)}
.content-section{display:none}
.content-section.active{display:block;animation:fadeIn 0.3s ease}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.page-header{margin-bottom:24px}
.page-title{font-family:'Lexend',sans-serif;font-weight:800;font-size:26px;color:var(--text);line-height:1.2;display:flex;align-items:center;gap:10px}
.page-subtitle{font-size:13px;color:var(--text-muted);margin-top:4px}

/* TYPE BADGE */
.type-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase}
.type-badge.offhire{background:var(--offhire-light);color:var(--offhire-color);border:1px solid var(--offhire-border)}
.type-badge.onhire{background:var(--onhire-light);color:var(--onhire-color);border:1px solid var(--onhire-border)}

.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);margin-bottom:20px;transition:background var(--transition),border-color var(--transition)}
.card-title{font-family:'Lexend',sans-serif;font-weight:700;font-size:14px;color:var(--text);margin-bottom:16px;display:flex;align-items:center;gap:8px}

/* UPLOAD */
.upload-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:4px}
.upload-zone{border:2px dashed var(--border);border-radius:var(--radius);padding:20px;text-align:center;cursor:pointer;transition:all var(--transition);background:var(--surface2);position:relative}
.upload-zone:hover,.upload-zone.drag-over{border-color:var(--accent);background:var(--accent-glow)}
.upload-zone.uploaded{border-color:var(--success);background:rgba(34,197,94,0.07)}
.upload-zone.required-err{border-color:var(--error)!important;background:rgba(239,68,68,0.05)!important}
.upload-icon{font-size:28px;margin-bottom:8px}
.upload-label{font-size:13px;font-weight:600;color:var(--text);margin-bottom:4px}
.upload-hint{font-size:11px;color:var(--text-muted)}
.upload-filename{font-size:11px;color:var(--success);font-weight:600;margin-top:6px}
.upload-zone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.badge-optional{font-size:10px;font-weight:700;background:rgba(251,191,36,0.15);color:#d97706;border-radius:4px;padding:1px 6px;margin-left:6px}
.badge-required{font-size:10px;font-weight:700;background:rgba(239,68,68,0.1);color:#dc2626;border-radius:4px;padding:1px 6px;margin-left:6px}

/* FORM */
.form-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media(max-width:1200px){.form-grid{grid-template-columns:repeat(2,1fr)}}
.form-group{display:flex;flex-direction:column;gap:5px;position:relative}
.form-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px}
.form-control{padding:9px 12px;border-radius:var(--radius-xs);border:1.5px solid var(--border);background:var(--surface2);color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;transition:border-color var(--transition),box-shadow var(--transition);outline:none;width:100%}
.form-control:focus{border-color:var(--border-focus);box-shadow:0 0 0 3px var(--accent-glow)}
.form-control.fc-invalid{border-color:var(--error)!important;box-shadow:0 0 0 3px rgba(239,68,68,0.10)!important}
.form-control.fc-valid{border-color:rgba(34,197,94,0.75)!important;box-shadow:0 0 0 2px rgba(34,197,94,0.10)!important}
.form-control.auto-calc{background:rgba(37,99,235,0.06)!important;color:var(--accent)!important;font-weight:600;border-style:dashed!important}
select.form-control{cursor:pointer}
input[type="date"].form-control{cursor:pointer}
[data-theme="dark"] .form-control{background:var(--surface);border-color:var(--border)}
[data-theme="dark"] select.form-control option{background:#1a2335}

/* Calc info box */
.calc-info{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:var(--radius-xs);font-size:12px;font-weight:500;margin-bottom:16px}
.calc-info.offhire-calc{background:rgba(220,38,38,0.07);border:1px solid rgba(220,38,38,0.2);color:#b91c1c}
.calc-info.onhire-calc{background:rgba(22,163,74,0.07);border:1px solid rgba(22,163,74,0.2);color:#15803d}
[data-theme="dark"] .calc-info.offhire-calc{color:#fca5a5}
[data-theme="dark"] .calc-info.onhire-calc{color:#86efac}

/* TOOLTIP */
.field-tooltip{display:none;position:absolute;bottom:calc(100% + 7px);left:0;background:#1e293b;color:#f1f5f9;font-size:11px;font-weight:500;line-height:1.5;padding:7px 11px;border-radius:7px;z-index:600;pointer-events:none;box-shadow:0 4px 18px rgba(0,0,0,0.25);max-width:270px;white-space:normal}
.field-tooltip::after{content:'';position:absolute;top:100%;left:14px;border:5px solid transparent;border-top-color:#1e293b}
[data-theme="dark"] .field-tooltip{background:#0d1525;color:#c8d6f0}
[data-theme="dark"] .field-tooltip::after{border-top-color:#0d1525}
.form-group:focus-within .field-tooltip{display:block;animation:ttFade 0.15s ease}
@keyframes ttFade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}

/* SECTION DIVIDER */
.form-section-divider{grid-column:1/-1;display:flex;align-items:center;gap:12px;margin:8px 0 4px}
.form-section-divider-label{font-family:'Lexend',sans-serif;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);white-space:nowrap}
.form-section-divider-line{flex:1;height:1px;background:var(--border)}

/* GENERATE BUTTON */
.btn-generate{display:inline-flex;align-items:center;gap:10px;padding:12px 28px;border-radius:var(--radius-sm);background:var(--border);color:var(--text-muted);font-family:'Lexend',sans-serif;font-weight:700;font-size:14px;border:none;cursor:not-allowed;transition:all var(--transition);margin-top:24px}
.btn-generate.ready{background:linear-gradient(135deg,#1d4ed8,#0ea5e9);color:#fff;cursor:pointer;box-shadow:0 4px 20px rgba(37,99,235,0.35)}
.btn-generate.ready:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(37,99,235,0.45)}
.btn-generate.ready:active{transform:translateY(0)}

/* TOAST */
.toast-container{position:fixed;top:72px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none}
.toast{display:flex;align-items:center;gap:12px;padding:12px 18px;border-radius:var(--radius-sm);background:var(--surface);border:1px solid var(--border);box-shadow:var(--shadow-lg);font-size:13px;font-weight:500;color:var(--text);pointer-events:all;animation:slideIn 0.3s ease;min-width:260px;max-width:380px}
.toast.success{border-color:rgba(34,197,94,0.4);background:rgba(240,253,244,1)}
[data-theme="dark"] .toast.success{background:#0f2318}
.toast.error{border-color:rgba(239,68,68,0.4);background:rgba(254,242,242,1)}
[data-theme="dark"] .toast.error{background:#2d0a0a}
.toast.info{border-color:rgba(37,99,235,0.3);background:rgba(239,246,255,1)}
[data-theme="dark"] .toast.info{background:#0c1a35}
.toast-icon{font-size:18px;flex-shrink:0}
.toast-msg{flex:1;line-height:1.4}
.toast-close{cursor:pointer;color:var(--text-muted);font-size:16px;flex-shrink:0}
@keyframes slideIn{from{opacity:0;transform:translateX(24px)}to{opacity:1;transform:none}}

/* PHOTO REPORT */
.photo-upload-area{border:2px dashed var(--border);border-radius:var(--radius);padding:32px;text-align:center;cursor:pointer;transition:all var(--transition);background:var(--surface2);position:relative}
.photo-upload-area:hover,.photo-upload-area.drag-over{border-color:var(--accent);background:var(--accent-glow)}
.photo-upload-area input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:20px}
.photo-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:box-shadow var(--transition)}
.photo-item:hover{box-shadow:var(--shadow)}
.photo-thumb{width:100%;height:130px;object-fit:cover;display:block}
.photo-desc-wrap{padding:10px}
.photo-desc{width:100%;padding:7px 10px;border:1.5px solid var(--border);border-radius:var(--radius-xs);background:var(--surface2);color:var(--text);font-family:'DM Sans',sans-serif;font-size:12px;resize:vertical;min-height:56px;outline:none;transition:border-color var(--transition)}
.photo-desc:focus{border-color:var(--accent)}
.photo-index{font-size:10px;font-weight:700;color:var(--text-muted);padding:6px 10px 0;letter-spacing:0.5px}
.photo-remove{display:block;width:100%;padding:6px;background:none;border:none;border-top:1px solid var(--border);color:var(--error);font-size:11px;font-weight:600;cursor:pointer;transition:background var(--transition)}
.photo-remove:hover{background:rgba(239,68,68,0.07)}

/* STAT STRIP */
.stat-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;transition:all var(--transition)}
.stat-card:hover{box-shadow:var(--shadow)}
.stat-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px}
.stat-value{font-family:'Lexend',sans-serif;font-size:22px;font-weight:800;color:var(--text);margin-top:4px}
.stat-icon{font-size:20px;margin-bottom:6px}
.welcome-banner{background:linear-gradient(135deg,#1a2540 60%,#1d4ed8);border-radius:var(--radius);padding:28px 32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden}
.welcome-banner::after{content:'⚓';position:absolute;right:28px;bottom:-12px;font-size:88px;opacity:0.08}
.welcome-title{font-family:'Lexend',sans-serif;font-weight:800;font-size:22px;margin-bottom:6px}
.welcome-sub{font-size:13px;opacity:0.75}
.alert-box{padding:14px 18px;border-radius:var(--radius-sm);margin-bottom:16px;font-size:13px;line-height:1.6;display:flex;gap:10px;align-items:flex-start}
.alert-box.warning{background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);color:#92400e}
[data-theme="dark"] .alert-box.warning{color:#fbbf24}
.alert-box.success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#166534}
[data-theme="dark"] .alert-box.success{color:#4ade80}

/* ── ADDON: DAMAGE WIDGET ── */
.damage-widget{grid-column:1/-1;margin-top:4px}
.damage-trigger-row{display:flex;align-items:center;gap:12px}
.damage-trigger-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap}
.damage-yn-btn{padding:6px 16px;border-radius:var(--radius-xs);border:1.5px solid var(--border);background:var(--surface2);color:var(--text-muted);font-family:'DM Sans',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--transition)}
.damage-yn-btn:hover{border-color:var(--accent);color:var(--accent)}
.damage-yn-btn.active-yes{border-color:var(--offhire-color);background:rgba(220,38,38,0.08);color:var(--offhire-color)}
.damage-yn-btn.active-no{border-color:var(--success);background:rgba(34,197,94,0.08);color:#16a34a}
.damage-entry-panel{display:none;margin-top:14px;padding:16px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius-sm)}
.damage-entry-panel.visible{display:block}
.damage-entry-row{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px}
.damage-hold-select{padding:8px 10px;border-radius:var(--radius-xs);border:1.5px solid var(--border);background:var(--surface);color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;outline:none;cursor:pointer;min-width:130px;transition:border-color var(--transition)}
.damage-hold-select:focus{border-color:var(--border-focus)}
.damage-textarea{flex:1;min-width:200px;padding:8px 10px;border-radius:var(--radius-xs);border:1.5px solid var(--border);background:var(--surface);color:var(--text);font-family:'DM Sans',sans-serif;font-size:13px;resize:vertical;min-height:60px;outline:none;transition:border-color var(--transition)}
.damage-textarea:focus{border-color:var(--border-focus)}
.damage-save-btn{padding:8px 18px;border-radius:var(--radius-xs);border:none;background:linear-gradient(135deg,#1d4ed8,#0ea5e9);color:#fff;font-family:'Lexend',sans-serif;font-weight:700;font-size:12px;cursor:pointer;transition:all var(--transition);white-space:nowrap;align-self:flex-end}
.damage-save-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.35)}
.damage-list{margin-top:10px;display:flex;flex-direction:column;gap:6px}
.damage-entry-item{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:9px 13px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xs);font-size:13px;color:var(--text);line-height:1.5}
.damage-entry-item .damage-entry-text{flex:1}
.damage-entry-item .damage-entry-hold{font-weight:700;color:var(--offhire-color);margin-right:4px}
.damage-remove-btn{background:none;border:none;color:var(--text-muted);font-size:14px;cursor:pointer;padding:0 4px;transition:color var(--transition);flex-shrink:0}
.damage-remove-btn:hover{color:var(--error)}
[data-theme="dark"] .damage-hold-select,[data-theme="dark"] .damage-textarea{background:var(--surface2);border-color:var(--border)}

/* ============================================================
   ── ADDON: MOBILE RESPONSIVENESS (ADD-ON ONLY, NO HTML CHANGES) ──
   All changes below are purely CSS media queries.
   Desktop layout (≥769px) is completely unaffected.
   ============================================================ */

/* ── Mobile hamburger button (hidden on desktop) ── */
.mob-menu-btn {
  display: none;
  background: none;
  border: none;
  cursor: pointer;
  padding: 6px 8px;
  border-radius: var(--radius-xs);
  color: var(--text);
  font-size: 22px;
  line-height: 1;
  transition: background var(--transition);
  flex-shrink: 0;
}
.mob-menu-btn:hover { background: var(--border); }

/* Overlay backdrop for mobile panel */
.mob-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 299;
  transition: opacity 0.25s ease;
}

@media (max-width: 768px) {

  /* ── TOPBAR ── */
  .topbar {
    padding: 0 14px;
    height: 56px;
    flex-wrap: nowrap;
    gap: 8px;
  }
  .topbar-left { gap: 8px; flex: 1; min-width: 0; }
  .logo-text span { display: none; }          /* hide sub-label on very small screens */
  .topbar-divider { display: none; }
  .time-display { display: none; }            /* hide clock — saves space */
  .topbar-right { gap: 10px; flex-shrink: 0; }
  .status-pill span { display: none; }        /* keep dot only */
  .status-pill { padding: 6px 8px; }
  .lib-status span#libStatusText { display: none; }   /* keep dot only */
  .lib-status { padding: 5px 8px; }
  .dm-toggle span { display: none; }          /* keep track only */

  /* ── Show hamburger ── */
  .mob-menu-btn { display: flex; align-items: center; justify-content: center; }

  /* ── BODY AREA: stack layout ── */
  .body-area { position: relative; overflow: hidden; }

  /* ── LEFT PANEL: slides in from left ── */
  .left-panel {
    position: fixed;
    top: 56px;              /* below new topbar height */
    left: 0;
    bottom: 0;
    width: 260px;
    z-index: 300;
    transform: translateX(-100%);
    transition: transform 0.28s cubic-bezier(.4,0,.2,1);
    box-shadow: var(--shadow-lg);
  }
  .left-panel.mob-open {
    transform: translateX(0);
  }
  .mob-overlay { display: block; opacity: 0; pointer-events: none; }
  .mob-overlay.mob-open { opacity: 1; pointer-events: all; }

  /* ── MAIN CONTENT: full width ── */
  .main-content {
    padding: 16px 14px 24px;
    width: 100%;
  }

  /* ── PAGE TITLE ── */
  .page-title { font-size: 20px; flex-wrap: wrap; }

  /* ── WELCOME BANNER ── */
  .welcome-banner { padding: 20px 18px; }
  .welcome-title { font-size: 18px; }
  .welcome-banner::after { font-size: 56px; right: 12px; bottom: -6px; opacity: 0.06; }

  /* ── STAT STRIP: 2 columns on mobile ── */
  .stat-strip { grid-template-columns: 1fr 1fr; gap: 10px; }
  .stat-value { font-size: 18px; }

  /* ── UPLOAD GRID: stack to single column ── */
  .upload-grid { grid-template-columns: 1fr; }

  /* ── FORM GRID: single column ── */
  .form-grid { grid-template-columns: 1fr !important; gap: 10px; }

  /* ── FORM CONTROLS ── */
  .form-control {
    font-size: 15px;   /* bigger tap targets */
    padding: 11px 12px;
  }
  select.form-control { font-size: 15px; }
  input[type="date"].form-control,
  input[type="time"].form-control { font-size: 15px; }

  /* ── GENERATE BUTTON: full width ── */
  .btn-generate {
    width: 100%;
    justify-content: center;
    font-size: 15px;
    padding: 14px 20px;
    margin-top: 18px;
  }

  /* ── CARD ── */
  .card { padding: 16px 14px; }

  /* ── DAMAGE WIDGET mobile tweaks ── */
  .damage-trigger-row { flex-wrap: wrap; gap: 8px; }
  .damage-entry-row { flex-direction: column; gap: 8px; }
  .damage-hold-select { width: 100%; min-width: unset; }
  .damage-textarea { min-width: unset; width: 100%; }
  .damage-save-btn { width: 100%; text-align: center; }
  .damage-widget { grid-column: 1 / -1; }

  /* ── PHOTO GRID: single column ── */
  .photo-grid { grid-template-columns: 1fr; }
  .photo-upload-area { padding: 20px 14px; }

  /* ── TOOLTIPS: hide on mobile (tap-unfriendly) ── */
  .form-group:focus-within .field-tooltip { display: none; }

  /* ── TOAST: full-width at bottom on mobile ── */
  .toast-container {
    top: auto;
    bottom: 14px;
    right: 14px;
    left: 14px;
  }
  .toast { min-width: unset; width: 100%; max-width: 100%; }

  /* ── FORM SECTION DIVIDER: ensure full span ── */
  .form-section-divider { grid-column: 1 / -1; }

  /* ── CALC INFO ── */
  .calc-info { font-size: 11px; flex-wrap: wrap; }

  /* ── EXTRA FIELDS CARD ── */
  #extraCard_3party-rrr .form-grid,
  #extraCard_3party-rrd .form-grid { grid-template-columns: 1fr !important; }
}

/* ── Very small phones (≤380px) ── */
@media (max-width: 380px) {
  .stat-strip { grid-template-columns: 1fr; }
  .logo-img { width: 34px; height: 34px; font-size: 14px; }
  .logo-text { font-size: 14px; }
  .page-title { font-size: 17px; }
  .type-badge { font-size: 10px; padding: 3px 8px; }
}
/* ── End Mobile Responsiveness ── */
</style>
</head>
<body>
<!-- PREMIUM PHOTO REPORT LIVE POPUP -->
<div id="photoReportModal" class="premium-modal">
    <div class="premium-card">

        <div class="live-badge">
            <span class="pulse-dot"></span>
            LIVE NOW
        </div>

        <div class="icon-circle">
            📷
        </div>

        <h1>Photo Report Generator</h1>

        <p>
            Professional Vessel Survey Photo Report Generation Module
            is now available.
        </p>

        <div class="feature-list">
            <div>✅ Automatic Photo Report Generation</div>
            <div>✅ Category Wise Photo Upload</div>
            <div>✅ New Professional Survey Format</div>
            <div>✅ Easy way for upload photos in bulk (Max.500 Photos at a time)</div>
        </div>

        <div class="btn-group">
            <a href="photo-report.php" class="open-btn">
                🚀 Open Photo Report Generator
            </a>

            <button class="later-btn"
                onclick="document.getElementById('photoReportModal').style.display='none'">
                Close
            </button>
        </div>

    </div>
</div>

<div class="app-shell" id="appShell">

  <!-- Mobile overlay backdrop -->
  <div class="mob-overlay" id="mobOverlay" onclick="closeMobileMenu()"></div>

  <header class="topbar">
    <div class="topbar-left">
      <!-- Hamburger — visible only on mobile via CSS -->
      <button class="mob-menu-btn" id="mobMenuBtn" onclick="toggleMobileMenu()" aria-label="Open menu">☰</button>
      <div class="logo-wrap">
        <div class="logo-img">YMR</div>
        <div class="logo-text">YMR Marine<span>Solutions LLP</span></div>
      </div>
      <div class="topbar-divider"></div>
      <div class="time-display" id="timeLive">--:--:--</div>
    </div>
    <div class="topbar-right">
      <div class="lib-status" id="libStatus"><div class="lib-dot"></div><span id="libStatusText">Checking…</span></div>
      <label class="dm-toggle" title="Toggle dark mode">
        <span>Light</span>
        <div class="dm-track" id="dmTrack"><div class="dm-thumb"></div></div>
        <span>Dark</span>
      </label>
      <div class="status-pill"><div class="status-dot"></div>You are Online</div>
    </div>
  </header>

  <div class="body-area">
    <nav class="left-panel" id="leftPanel">

<!-- <div class="panel-section-label" style="margin-top:12px">Full Dashboard</div> -->
      <div class="nav-item active" id="nav-home" onclick="showSection('home');closeMobileMenu()"><span class="nav-icon">🏠</span> Home</div>
      <div class="panel-section-label">Types of Reports</div>

      <!-- OFFHIRE CATEGORY -->
      <div class="nav-category" id="catOffhire">
        <div class="nav-category-header offhire-cat open" id="catOffhireHeader" onclick="toggleCategory('Offhire')">
          <span class="nav-cat-icon">🔴</span> Offhire Reports
          <span class="nav-cat-arrow">›</span>
        </div>
        <div class="nav-cat-body open" id="catOffhireBody">
          <div class="nav-sub-item" id="nav-offhire" onclick="showSection('offhire');closeMobileMenu()">📋 Offhire </div>
          <div class="nav-sub-item" id="nav-offhire-condition" onclick="showSection('offhire-condition');closeMobileMenu()">📋 Offhire Condition</div>
          <div class="nav-sub-item" id="nav-3party-rrr" onclick="showSection('3party-rrr');closeMobileMenu()">📋 3 Party — RRR</div>
          <div class="nav-sub-item" id="nav-3party-rrd" onclick="showSection('3party-rrd');closeMobileMenu()">📋3 Party — RRD</div>
        </div>
      </div>

      <!-- ONHIRE CATEGORY -->
      <div class="nav-category" style="margin-top:8px" id="catOnhire">
        <div class="nav-category-header onhire-cat open" id="catOnhireHeader" onclick="toggleCategory('Onhire')">
          <span class="nav-cat-icon">🟢</span> Onhire Reports
          <span class="nav-cat-arrow">›</span>
        </div>
        <div class="nav-cat-body open" id="catOnhireBody">
          <div class="nav-sub-item" id="nav-onhire" onclick="showSection('onhire');closeMobileMenu()">📄 Onhire Report</div>
          <div class="nav-sub-item" id="nav-onhire-condition" onclick="showSection('onhire-condition');closeMobileMenu()">📄	Onhire Condition</div>
        </div>
      </div>
      <div class="panel-section-label" style="margin-top:12px">Tools</div>
    <a href="photo-report.php">  <div class="nav-item" id="nav-photo-report"><span class="nav-icon">📷</span> Photo Report</div></a>
    </nav>

    <main class="main-content">
      <!-- HOME -->
      <section class="content-section active" id="sec-home">
        <div class="welcome-banner">
          <div class="welcome-title">Welcome to YMR Marine Report Dashboard</div>
          <div class="welcome-sub">Generate professional marine survey reports quickly and accurately.</div>
        </div>
        <div id="homeLibAlert"></div>
        <div class="stat-strip">
          <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-label">No. of reports you can generate</div><div class="stat-value">7</div></div>
          <div class="stat-card"><div class="stat-icon">📝</div><div class="stat-label">Total form fields Needed</div><div class="stat-value">46</div></div>
          <div class="stat-card"><div class="stat-icon">📷</div><div class="stat-label">Photo Report generation</div> <div class="stat-value">is <a href="photo-report.php"><span style="color:var(--onhire-color);font-weight:600">LIVE Now </span></div> </a></div>
        <!--  <div class="stat-card"><div class="stat-icon">⚡</div><div class="stat-label">Engine</div><div class="stat-value" id="homeEngine">—</div></div> -->
        </div>
       <!--  <div class="card" style="border-left:4px solid var(--offhire-color)">
          <div class="card-title">🔴 Offhire Consumption Formula</div>
          <p style="font-size:13px;line-height:1.9;color:var(--text-muted)">
            <strong style="color:var(--offhire-color)">CONS = Survey − Redelivery</strong><br>
            Offhire reports calculate consumption as fuel <em>at survey</em> minus fuel <em>at redelivery</em>. This reflects fuel consumed (or returned) during the charter period from the owner's perspective.
          </p>
        </div>
        <div class="card" style="border-left:4px solid var(--onhire-color)">
          <div class="card-title">🟢 Onhire Consumption Formula</div>
          <p style="font-size:13px;line-height:1.9;color:var(--text-muted)">
            <strong style="color:var(--onhire-color)">CONS = Redelivery − Survey</strong><br>
            Onhire reports calculate consumption as fuel <em>at redelivery</em> minus fuel <em>at survey</em>. This reflects what was consumed during the hire period from the charterer's perspective.
          </p>
        </div> -->
        <div class="card">
          <div class="card-title">ℹ️ How to Use</div>
          <p style="font-size:13px;color:var(--text-muted);line-height:1.9">
           <strong> Step 1:- </strong>  Select a report type from the left panel — <span style="color:var(--offhire-color);font-weight:600">Offhire</span> or <span style="color:var(--onhire-color);font-weight:600">Onhire</span> categories are clearly separated. For Offhire choose Offhire Report on the left panel, for Offhire Condition select Offhire Condition, 3 Party-RRR is for Redelivery from all parties (Eg. Redelivered from sub charteres to Charterers and back to back Redelivered to Owners) In this case Use 3 party -RRR, 3 Party-RRD is for Redelivery from Charters to Owners and Delivery to Next charterers (Eg. Redelivered from Charterers to Owners and back to back Delivered to Next Charterer) In this case Use 3 party -RRD.<br><br>
           <strong> Step 2:- </strong> Please Upload Excel <span style="color:var(--onhire-color);font-weight:600">(This is optional)</span> (If you are using a new updated Format which is having "REPORT" Tab in Excel), Dont worry if you have not used New format You can enter details Manually .<br><br>
            <strong> Step 3:- </strong>  Upload your Word template (which is given in folder REPORT TEMPLATES.) <span style="color:var(--offhire-color);font-weight:600">(Mandatory) </span><br><br>
             <strong> Step 4:- </strong>Please fill all the details in the form <br><br>
             <strong> Step 5:- </strong>Enter Redelivery Details for Offhire including DATE,TIME(both LT & UTC) and ROBs<br><br>
             <strong> Step 6:- </strong> Click <strong>Generate Report</strong> in the bottom to download the formatted .docx.
          </p>
        </div>
      </section>

      <!-- Report sections (built dynamically) -->
      <section class="content-section" id="sec-offhire"></section>
      <section class="content-section" id="sec-onhire"></section>
      <section class="content-section" id="sec-offhire-condition"></section>
      <section class="content-section" id="sec-onhire-condition"></section>
      <section class="content-section" id="sec-3party-rrr"></section>
      <section class="content-section" id="sec-3party-rrd"></section>

      <!-- PHOTO REPORT -->
      <section class="content-section" id="sec-photo-report">
        <div class="page-header">
          <div class="page-title" >📷 Photo Report Generator</div>
          <div class="page-subtitle">Upload images, add descriptions, generate a photo report Word document</div>
        </div>
        <div class="card">
          <div class="card-title">📁 Word Template <span class="badge-required">REQUIRED</span></div>
          <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">Use <code style="background:var(--surface2);padding:1px 5px;border-radius:3px">{TEXT1}</code>, <code style="background:var(--surface2);padding:1px 5px;border-radius:3px">{TEXT2}</code> etc. for image captions.</p>
          <div class="upload-zone" id="photoTemplateZone" style="max-width:440px">
            <input type="file" id="photoTemplateInput" accept=".docx">
            <div class="upload-icon">📄</div>
            <div class="upload-label">Upload Word Template (.docx)</div>
            <div class="upload-hint">Must contain {TEXT1}, {TEXT2}… placeholders</div>
            <div class="upload-filename" id="photoTemplateName"></div>
          </div>
        </div>
        <div class="card">
          <div class="card-title">🖼️ Upload Images</div>
          <div class="photo-upload-area" id="photoBulkZone">
            <input type="file" id="photoBulkInput" accept="image/*" multiple>
            <div class="upload-icon">🖼️</div>
            <div class="upload-label">Click or drag images here</div>
            <div class="upload-hint">Supports JPG, PNG, WebP — multiple files allowed</div>
          </div>
          <div class="photo-grid" id="photoGrid"></div>
        </div>
        <div style="display:flex;gap:12px;flex-wrap:wrap">
          <button class="btn-generate" id="btnPhotoGenerate" onclick="generatePhotoReport()">🖨️ Generate Photo Report</button>
          <button class="btn-generate ready" onclick="clearPhotoReport()" style="background:linear-gradient(135deg,#64748b,#475569);margin-top:24px">🗑️ Clear All</button>
        </div>
      </section>
    </main>
  </div>
</div>
<div class="toast-container" id="toastContainer"></div>

<script>
/* ============================================================
   MOBILE MENU TOGGLE (ADD-ON)
   ============================================================ */
function toggleMobileMenu() {
  var panel   = document.getElementById('leftPanel');
  var overlay = document.getElementById('mobOverlay');
  var isOpen  = panel.classList.contains('mob-open');
  if (isOpen) {
    panel.classList.remove('mob-open');
    overlay.classList.remove('mob-open');
  } else {
    panel.classList.add('mob-open');
    overlay.classList.add('mob-open');
  }
}
function closeMobileMenu() {
  document.getElementById('leftPanel').classList.remove('mob-open');
  document.getElementById('mobOverlay').classList.remove('mob-open');
}

/* ============================================================
   LIBRARY CHECK
   ============================================================ */
var LIBS_OK = false;
function checkLibs() {
  var pizzipOk = typeof PizZip !== 'undefined';
  var docxOk   = typeof docxtemplater !== 'undefined';
  var xlsxOk   = typeof XLSX !== 'undefined';
  LIBS_OK = pizzipOk && docxOk;
  var statusEl  = document.getElementById('libStatus');
  var statusTxt = document.getElementById('libStatusText');
  var homeAlert = document.getElementById('homeLibAlert');
  var engineEl  = document.getElementById('homeEngine');
  if (LIBS_OK) {
    statusEl.className = 'lib-status ok';
    statusTxt.textContent = 'System is Ready';
    if (engineEl)  engineEl.textContent = 'Docxtemplater';
    if (homeAlert) homeAlert.innerHTML = '<div class="alert-box success">✅ <div><strong>Everything looks good, You can start working...............</div></div>';
  } else {
    statusEl.className = 'lib-status err';
    var missing = [];
    if (!pizzipOk) missing.push('PizZip');
    if (!docxOk)   missing.push('Docxtemplater');
    if (!xlsxOk)   missing.push('SheetJS');
    statusTxt.textContent = 'Library Error';
    if (engineEl) engineEl.textContent = '✗ Error';
    if (homeAlert) homeAlert.innerHTML = '<div class="alert-box warning">⚠️ <div><strong>Library load failed: ' + missing.join(', ') + '</strong><br>Check internet and refresh.</div></div>';
  }
}
setTimeout(checkLibs, 800);

/* ============================================================
   DOCX GENERATION
   ============================================================ */
function processDocx(arrayBuffer, data, filename) {
  if (typeof PizZip === 'undefined' || typeof docxtemplater === 'undefined')
    throw new Error('PizZip or Docxtemplater not loaded.');
  var zip = new PizZip(arrayBuffer);
  var Docxtemplater = window.Docxtemplater || window.docxtemplater;
  if (!Docxtemplater) throw new Error('Docxtemplater not found.');
  var doc;
  try {
    doc = new Docxtemplater(zip, { paragraphLoop:true, linebreaks:true, nullGetter: function(){ return ''; } });
  } catch(e) { throw new Error('Docxtemplater init: ' + e.message); }
  doc.setData(data);
  try { doc.render(); } catch(err) {
    if (err.properties && err.properties.errors)
      throw new Error('Template: ' + err.properties.errors.map(function(e){ return e.message; }).join('; '));
    throw new Error('Render: ' + err.message);
  }
  var output = doc.getZip().generate({ type:'blob', mimeType:'application/vnd.openxmlformats-officedocument.wordprocessingml.document', compression:'DEFLATE' });
  saveBlob(output, filename);
}

/* ============================================================
   EXCEL PARSING
   ============================================================ */
function parseExcel(arrayBuffer, callback) {
  if (typeof XLSX === 'undefined') { callback(null, 'SheetJS not loaded.'); return; }
  try {
    var wb = XLSX.read(arrayBuffer, { type:'array' });
    var ws = wb.Sheets['REPORT'];
    if (!ws) { callback(null, 'Sheet "REPORT" not found.'); return; }
    var rows = XLSX.utils.sheet_to_json(ws, { header:1 });
    var map = {};
    rows.forEach(function(row) {
      if (row[0] !== undefined && row[1] !== undefined)
        map[String(row[0]).trim()] = String(row[1]).trim();
    });
    callback(map, null);
  } catch(e) { callback(null, 'Excel read error: ' + e.message); }
}

/* ============================================================
   DATE UTILITIES
   ============================================================ */
var MONTH_NAMES = ['January','February','March','April','May','June',
                   'July','August','September','October','November','December'];

function ordinalSuffix(n) {
  n = parseInt(n, 10);
  var s = ['th','st','nd','rd'], v = n % 100;
  return n + (s[(v - 20) % 10] || s[v] || s[0]);
}

function todayISO() {
  var n = new Date();
  return n.getFullYear() + '-' + String(n.getMonth()+1).padStart(2,'0') + '-' + String(n.getDate()).padStart(2,'0');
}

function formatDateForExport(raw) {
  if (!raw || !String(raw).trim()) return '';
  var s = String(raw).trim();
  var m = s.match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (m) return ordinalSuffix(parseInt(m[3],10)) + ' ' + MONTH_NAMES[parseInt(m[2],10)-1] + ' ' + m[1];
  m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
  if (m) return ordinalSuffix(parseInt(m[1],10)) + ' ' + MONTH_NAMES[parseInt(m[2],10)-1] + ' ' + m[3];
  if (/\d+(st|nd|rd|th)\s+\w+\s+\d{4}/.test(s)) return s;
  var dt = new Date(s);
  if (!isNaN(dt.getTime())) return ordinalSuffix(dt.getDate()) + ' ' + MONTH_NAMES[dt.getMonth()] + ' ' + dt.getFullYear();
  return s;
}

function parseExcelDate(value) {
  if (value === null || value === undefined || value === '') return null;
  if (!isNaN(value)) {
    var d = new Date(new Date(1899,11,30).getTime() + value * 86400000);
    if (!isNaN(d.getTime())) return d;
  }
  var str = String(value).trim();
  var m = str.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
  if (m) {
    var dt = new Date(parseInt(m[3]), parseInt(m[2])-1, parseInt(m[1]));
    if (!isNaN(dt.getTime())) return dt;
  }
  var d2 = new Date(str);
  if (!isNaN(d2.getTime())) return d2;
  return null;
}

function excelDateToISO(raw) {
  var parsed = parseExcelDate(raw);
  if (!parsed) return '';
  return parsed.getFullYear() + '-' + String(parsed.getMonth()+1).padStart(2,'0') + '-' + String(parsed.getDate()).padStart(2,'0');
}

function excelDateToTime(raw) {
  var parsed = parseExcelDate(raw);
  if (!parsed) return '';
  return String(parsed.getHours()).padStart(2,'0') + ':' + String(parsed.getMinutes()).padStart(2,'0');
}

/* ============================================================
   DECIMAL FORMATTING
   ============================================================ */
var FIELDS_2DP = ['LENGTH_OVERALL','BREDTH_MOULDED','DEPTH_MOULDED','SUMMER_DRAFT','SUMMER_DEADWEIGHT'];
var FIELDS_3DP = ['SURVEY_VLSFO','SURVEY_LSMGO','SURVEY_HSFO',
                  'REDELIVERY_VLSFO','REDELIVERY_LSMGO','REDELIVERY_HSFO',
                  'CONS_VLSFO','CONS_LSMGO','CONS_HSFO'];
var DATE_FIELDS = ['REPORT_DATE','SURVEY_DATE','REDELIVERY_DATE_LT','REDELIVERY_DATE_UTC'];

function getDecimalPrecision(id) {
  if (FIELDS_2DP.indexOf(id) !== -1) return 2;
  if (FIELDS_3DP.indexOf(id) !== -1) return 3;
  return 0;
}
function applyDecimalFormat(val, precision) {
  if (val === null || val === undefined || String(val).trim() === '') return '';
  var n = parseFloat(String(val).replace(/,/g,''));
  if (isNaN(n)) return String(val);
  return n.toFixed(precision);
}

/* ============================================================
   TOOLTIP DEFINITIONS
   ============================================================ */
var FIELD_TOOLTIPS = {
  REPORT_DATE:'Auto-set to today. Exports as "2nd March 2026".',
  REPORT_PLACE:'City or port where this report is issued.',
  SHIP_NAME:'Full registered name of the vessel.',
  VOY_NO:'Voyage reference number for this survey.',
  IMO:'IMO number — 7-digit unique ship identifier.',
  GRT:'Gross Register Tonnage.',
  PORT_OF_REGISTRY:'Port where the vessel is officially registered.',
  ALONGSIDE:'Select the vessel\'s berthing or anchorage position.',
  HOLDS:'Total number of cargo holds.',
  HOLDS_ALPHA:'Auto-filled from number of holds (e.g. THREE). Read-only.',
  KEEL_LAID_MONTH:'Month the keel was laid.',
  KEEL_LAID_YEAR:'Year the keel was laid (4 digits).',
  CLASS:'Classification society (e.g. DNV, Lloyd\'s, BV, ABS).',
  CALL_SIGN:'Radio call sign of the vessel.',
  LENGTH_OVERALL:'Length overall in metres — 2 decimal places.',
  BREDTH_MOULDED:'Moulded breadth in metres — 2 decimal places.',
  DEPTH_MOULDED:'Moulded depth in metres — 2 decimal places.',
  SUMMER_DRAFT:'Summer draft in metres — 2 decimal places.',
  SUMMER_DEADWEIGHT:'Summer deadweight in metric tonnes — 2 decimal places.',
  CHARTERER_NAME:'Full legal name of the charterer.',
  OWNER_NAME:'Full legal name of the registered owner.',
  BUILDER_NAME:'Name of the shipyard or builder.',
  CAPT_NAME:'Full name of the captain / master.',
  CHIEF_ENGINEER_NAME:'Full name of the chief engineer.',
  OPERATOR_NAME:'Name of the operator or ship management company.',
  SURVEYOR_NAME:'Full name of the attending marine surveyor.',
  SURVEYOR_SIGN:'Surveyor\'s signature name or initials.',
  SURVEY_DATE:'Date of survey — exports as "2nd March 2026".',
  SURVEY_TIME:'Local time the survey was conducted.',
  SURVEY_PLACE:'Port or anchorage where survey took place.',
  SURVEY_VLSFO:'VLSFO on board at survey time — 3 decimal places (MT).',
  SURVEY_LSMGO:'LSMGO on board at survey time — 3 decimal places (MT).',
  SURVEY_HSFO:'HSFO on board at survey time — 3 decimal places (MT).',
  SURVEY_TIME_FROM:'Start time of survey attendance.',
  REDELIVERY_DATE_LT:'Redelivery date in local time — exports as "2nd March 2026".',
  REDELIVERY_TIME_LT:'Redelivery time in local time.',
  REDELIVERY_DATE_UTC:'Redelivery date in UTC.',
  REDELIVERY_TIME_UTC:'Redelivery time in UTC.',
  REDELIVERY_PLACE:'Port or location of redelivery.',
  REDELIVERY_VLSFO:'VLSFO on board at redelivery — 3 decimal places (MT).',
  REDELIVERY_LSMGO:'LSMGO on board at redelivery — 3 decimal places (MT).',
  REDELIVERY_HSFO:'HSFO on board at redelivery — 3 decimal places (MT).',
  CONS_VLSFO:'⚡ Auto-calculated consumption (see formula at top of form).',
  CONS_LSMGO:'⚡ Auto-calculated consumption (see formula at top of form).',
  CONS_HSFO:'⚡ Auto-calculated consumption (see formula at top of form).'
};

/* ============================================================
   SECTION CONFIG
   ============================================================ */
var SECTION_TITLES = {
  'offhire':'Offhire Report','onhire':'Onhire Report',
  'offhire-condition':'Offhire Condition Report','onhire-condition':'Onhire Condition Report',
  '3party-rrr':'3 Party Offhire — RRR','3party-rrd':'3 Party Offhire — RRD'
};
var OFFHIRE_SECTIONS = ['offhire','offhire-condition','3party-rrr','3party-rrd'];

function isOffhireSection(sectionId) {
  return OFFHIRE_SECTIONS.indexOf(sectionId) !== -1;
}

var FORM_FIELDS = [
  {id:'REPORT_DATE',          label:'Report Date',         type:'date'},
  {id:'REPORT_PLACE',         label:'Report Place',        type:'text'},
  {id:'SHIP_NAME',            label:'Ship Name',           type:'text'},
  {id:'VOY_NO',               label:'Voyage No.',          type:'text'},
  {id:'IMO',                  label:'IMO Number',          type:'text'},
  {id:'GRT',                  label:'GRT',                 type:'text'},
  {id:'PORT_OF_REGISTRY',     label:'Port of Registry',    type:'text'},
  {id:'ALONGSIDE',            label:'Alongside',           type:'select', options:['allfast port side alongside to','allfast starboard side alongside to','Anchored at']},
  {id:'HOLDS',                label:'No. of Holds',        type:'select', options:['1','2','3','4','5','6','7','8','9','10']},
  {id:'HOLDS_ALPHA',          label:'Holds (In Words)',    type:'text',   readonly:true},
  {id:'KEEL_LAID_MONTH',      label:'Keel Laid Month',     type:'text'},
  {id:'KEEL_LAID_YEAR',       label:'Keel Laid Year',      type:'text'},
  {id:'CLASS',                label:'Class',               type:'text'},
  {id:'CALL_SIGN',            label:'Call Sign',           type:'text'},
  {id:'LENGTH_OVERALL',       label:'Length Overall (m)',  type:'text'},
  {id:'BREDTH_MOULDED',       label:'Breadth Moulded (m)', type:'text'},
  {id:'DEPTH_MOULDED',        label:'Depth Moulded (m)',   type:'text'},
  {id:'SUMMER_DRAFT',         label:'Summer Draft (m)',    type:'text'},
  {id:'SUMMER_DEADWEIGHT',    label:'Summer Deadweight',   type:'text'},
  {id:'CHARTERER_NAME',       label:'Charterer Name',      type:'text'},
  {id:'OWNER_NAME',           label:'Owner Name',          type:'text'},
  {id:'BUILDER_NAME',         label:'Builder Name',        type:'text'},
  {id:'CAPT_NAME',            label:'Captain Name',        type:'text'},
  {id:'CHIEF_ENGINEER_NAME',  label:'Chief Engineer',      type:'text'},
  {id:'OPERATOR_NAME',        label:'Operator Name',       type:'text'},
  {id:'SURVEYOR_NAME',        label:'Surveyor Name',       type:'text'},
  {id:'SURVEYOR_SIGN',        label:'Surveyor Sign',       type:'text'},
  {id:'SURVEY_DATE',          label:'Survey Date',         type:'text'},
  {id:'SURVEY_TIME',          label:'Survey Time',         type:'time'},
  {id:'SURVEY_PLACE',         label:'Survey Place',        type:'text'},
  {id:'SURVEY_VLSFO',         label:'Survey VLSFO (MT)',   type:'text'},
  {id:'SURVEY_LSMGO',         label:'Survey LSMGO (MT)',   type:'text'},
  {id:'SURVEY_HSFO',          label:'Survey HSFO (MT)',    type:'text'},
  {id:'SURVEY_TIME_FROM',     label:'Survey Time From',    type:'time'},
  {id:'REDELIVERY_DATE_LT',   label:'Redelivery Date LT',  type:'date'},
  {id:'REDELIVERY_TIME_LT',   label:'Redelivery Time LT',  type:'time'},
  {id:'REDELIVERY_DATE_UTC',  label:'Redelivery Date UTC', type:'date'},
  {id:'REDELIVERY_TIME_UTC',  label:'Redelivery Time UTC', type:'time'},
  {id:'REDELIVERY_PLACE',     label:'Redelivery Place',    type:'text'},
  {id:'REDELIVERY_VLSFO',     label:'Redelivery VLSFO (MT)', type:'text'},
  {id:'REDELIVERY_LSMGO',     label:'Redelivery LSMGO (MT)', type:'text'},
  {id:'REDELIVERY_HSFO',      label:'Redelivery HSFO (MT)', type:'text'},
  {id:'CONS_VLSFO',           label:'Consumption VLSFO',   type:'text', autoCalc:true},
  {id:'CONS_LSMGO',           label:'Consumption LSMGO',   type:'text', autoCalc:true},
  {id:'CONS_HSFO',            label:'Consumption HSFO',    type:'text', autoCalc:true}
];

var NUMS_TO_WORDS = {'1':'ONE','2':'TWO','3':'THREE','4':'FOUR','5':'FIVE','6':'SIX','7':'SEVEN','8':'EIGHT','9':'NINE','10':'TEN'};
var sectionState = {};
Object.keys(SECTION_TITLES).forEach(function(s) { sectionState[s] = { excelFile:null, wordFile:null, fieldValues:{} }; });

var photoTemplateFile = null;
var photoItems = [];

/* ============================================================
   ADDON GLOBALS — damage widget state + extra section fields
   ============================================================ */
var damageState = {};
['offhire-condition','onhire-condition'].forEach(function(s){ damageState[s] = []; });

var SECTION_EXTRA_FIELDS = {
  '3party-rrr': [{ id:'NEXT_CHARTERER_NAME', label:'Sub Charterer', type:'text' }],
  '3party-rrd': [{ id:'NEXT_CHARTERER_NAME', label:'Next Charterer', type:'text' }]
};

var extraFieldValues = {};
Object.keys(SECTION_EXTRA_FIELDS).forEach(function(s){
  extraFieldValues[s] = {};
  SECTION_EXTRA_FIELDS[s].forEach(function(f){ extraFieldValues[s][f.id] = ''; });
});

/* ============================================================
   TIME & DARK MODE
   ============================================================ */
function updateTime() {
  var now = new Date();
  var d = now.toLocaleDateString('en-GB', { day:'2-digit', month:'short', year:'numeric' });
  var t = [now.getHours(),now.getMinutes(),now.getSeconds()].map(function(n){ return String(n).padStart(2,'0'); }).join(':');
  document.getElementById('timeLive').textContent = d + '  ' + t;
}
setInterval(updateTime, 1000); updateTime();

var dmTrack = document.getElementById('dmTrack');
var darkMode = false;
dmTrack.addEventListener('click', function() {
  darkMode = !darkMode;
  document.documentElement.setAttribute('data-theme', darkMode ? 'dark' : '');
  dmTrack.classList.toggle('on', darkMode);
});

/* ============================================================
   NAVIGATION
   ============================================================ */
function toggleCategory(cat) {
  var body = document.getElementById('cat' + cat + 'Body');
  var header = document.getElementById('cat' + cat + 'Header');
  if (!body) return;
  var isOpen = body.classList.contains('open');
  body.classList.toggle('open', !isOpen);
  header.classList.toggle('open', !isOpen);
}

function setNavActive(id) {
  document.querySelectorAll('.nav-sub-item,.nav-item').forEach(function(el) {
    el.classList.remove('active','active-offhire','active-onhire');
  });
  var el = document.getElementById('nav-' + id);
  if (el) {
    el.classList.add('active');
    if (isOffhireSection(id)) el.classList.add('active-offhire');
    else if (id !== 'home' && id !== 'photo-report') el.classList.add('active-onhire');
  }
}

function showSection(id) {
  document.querySelectorAll('.content-section').forEach(function(s) { s.classList.remove('active'); });
  var target = document.getElementById('sec-' + id);
  if (!target) return;
  if (SECTION_TITLES[id] && !target.dataset.built) {
    buildReportSection(id, target);
    target.dataset.built = '1';
    initSectionDefaults(id);
  }
  target.classList.add('active');
  setNavActive(id);
}

/* ============================================================
   AUTO-DATE DEFAULT
   ============================================================ */
function initSectionDefaults(sectionId) {
  var rdEl = document.getElementById('field_' + sectionId + '_REPORT_DATE');
  if (rdEl && !rdEl.value) {
    var today = todayISO();
    rdEl.value = today;
    sectionState[sectionId].fieldValues['REPORT_DATE'] = today;
    updateFieldValidation(sectionId, 'REPORT_DATE', today);
  }
}

/* ============================================================
   BUILD REPORT SECTION
   ============================================================ */
function buildReportSection(id, container) {
  var title = SECTION_TITLES[id];
  var isOffhire = isOffhireSection(id);
  var typeClass = isOffhire ? 'offhire' : 'onhire';
  var typeLabel = isOffhire ? 'Offhire' : 'Onhire';
  var calcFormula = isOffhire
    ? '⚡ Consumption = <strong>Survey</strong> − Redelivery'
    : '⚡ Consumption = <strong>Redelivery</strong> − Survey';
  var calcClass = isOffhire ? 'offhire-calc' : 'onhire-calc';

  var html = '<div class="page-header">' +
    '<div class="page-title">' + title +
    ' <span class="type-badge ' + typeClass + '">' + (isOffhire ? '🔴' : '🟢') + ' ' + typeLabel + '</span></div>' +
    '<div class="page-subtitle">Fill all fields and upload your Word template to generate the report</div>' +
    '</div>';

  html += '<div class="card"><div class="card-title">📁 File Uploads</div>' +
    '<div class="upload-grid">' +
    '<div><div class="upload-zone" id="excelZone_' + id + '">' +
    '<input type="file" id="excelInput_' + id + '" accept=".xlsx,.xls" onchange="handleExcel(event,\'' + id + '\')">' +
    '<div class="upload-icon">📊</div>' +
    '<div class="upload-label">Excel File <span class="badge-optional">OPTIONAL</span></div>' +
    '<div class="upload-hint">Col A = Field Key, Col B = Value (Sheet: "REPORT")</div>' +
    '<div class="upload-filename" id="excelName_' + id + '"></div></div></div>' +
    '<div><div class="upload-zone" id="wordZone_' + id + '">' +
    '<input type="file" id="wordInput_' + id + '" accept=".docx" onchange="handleWord(event,\'' + id + '\')">' +
    '<div class="upload-icon">📄</div>' +
    '<div class="upload-label">Word Template (.docx) <span class="badge-required">REQUIRED</span></div>' +
    '<div class="upload-hint">Use {FIELD_ID} placeholders e.g. {SHIP_NAME}</div>' +
    '<div class="upload-filename" id="wordName_' + id + '"></div></div></div>' +
    '</div></div>';

  html += '<div class="card">' +
    '<div class="card-title">📝 Report Fields ' +
    '<span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:6px">' +
    '<span style="color:#ef4444">●</span> empty &nbsp;|&nbsp; ' +
    '<span style="color:#22c55e">●</span> filled &nbsp;|&nbsp; ' +
    '<span style="color:var(--accent)">⚡ dashed</span> = auto-calc' +
    '</span></div>' +
    '<div class="calc-info ' + calcClass + '">' + calcFormula + ' &nbsp;(all three fuel types)</div>' +
    '<div class="form-grid" id="formGrid_' + id + '">';

  var DIVIDERS = {
    'SHIP_NAME':          'Vessel Details',
    'CHARTERER_NAME':     'Parties',
    'SURVEYOR_NAME':      'Surveyor',
    'SURVEY_DATE':        'Survey Details',
    'REDELIVERY_DATE_LT': 'Redelivery Details',
    'CONS_VLSFO':         'Consumption (Auto-Calculated)'
  };

  var ONHIRE_LABEL_OVERRIDES = {
    'REDELIVERY_DATE_LT':  'Delivery Date LT',
    'REDELIVERY_TIME_LT':  'Delivery Time LT',
    'REDELIVERY_DATE_UTC': 'Delivery Date UTC',
    'REDELIVERY_TIME_UTC': 'Delivery Time UTC',
    'REDELIVERY_PLACE':    'Delivery Place',
    'REDELIVERY_VLSFO':    'Delivery VLSFO (MT)',
    'REDELIVERY_LSMGO':    'Delivery LSMGO (MT)',
    'REDELIVERY_HSFO':     'Delivery HSFO (MT)'
  };
  var ONHIRE_DIVIDER_OVERRIDE = { 'REDELIVERY_DATE_LT': 'Delivery Details' };

  FORM_FIELDS.forEach(function(f) {
    var effectiveLabel = f.label;
    if (!isOffhire && ONHIRE_LABEL_OVERRIDES[f.id]) effectiveLabel = ONHIRE_LABEL_OVERRIDES[f.id];

    var dividerLabel = DIVIDERS[f.id];
    if (!isOffhire && ONHIRE_DIVIDER_OVERRIDE[f.id]) dividerLabel = ONHIRE_DIVIDER_OVERRIDE[f.id];

    if (dividerLabel) {
      html += '<div class="form-section-divider">' +
        '<div class="form-section-divider-line"></div>' +
        '<div class="form-section-divider-label">' + dividerLabel + '</div>' +
        '<div class="form-section-divider-line"></div>' +
        '</div>';
    }

    var tooltip = FIELD_TOOLTIPS[f.id] || effectiveLabel;
    var isReadonly = f.readonly || f.autoCalc;

    var effectiveType = f.type;
    if (!isOffhire && f.id === 'REDELIVERY_DATE_LT') effectiveType = 'text';

    html += '<div class="form-group">';
    html += '<label class="form-label">' + effectiveLabel;
    if (f.autoCalc) html += ' <span style="font-size:9px;color:var(--accent);font-weight:700;letter-spacing:0.5px;margin-left:3px">⚡AUTO</span>';
    html += '</label>';
    html += '<div class="field-tooltip">' + tooltip + '</div>';

    if (effectiveType === 'select') {
      html += '<select class="form-control fc-invalid" id="field_' + id + '_' + f.id + '"' +
        ' onchange="onFieldChange(\'' + id + '\',\'' + f.id + '\',this.value)">' +
        '<option value="">— Select —</option>';
      f.options.forEach(function(o) { html += '<option value="' + o + '">' + o + '</option>'; });
      html += '</select>';
    } else if (effectiveType === 'date') {
      html += '<input type="date" class="form-control' + (isReadonly ? '' : ' fc-invalid') + '"' +
        ' id="field_' + id + '_' + f.id + '"' +
        (isReadonly ? ' readonly' : '') +
        ' onchange="onFieldChange(\'' + id + '\',\'' + f.id + '\',this.value)">';
    } else {
      html += '<input type="' + (effectiveType === 'time' ? 'time' : 'text') + '"' +
        ' class="form-control' + (isReadonly ? '' : ' fc-invalid') + (f.autoCalc ? ' auto-calc' : '') + '"' +
        ' id="field_' + id + '_' + f.id + '"' +
        (isReadonly ? ' readonly' : '') +
        ' onchange="onFieldChange(\'' + id + '\',\'' + f.id + '\',this.value)"' +
        ' oninput="onFieldChange(\'' + id + '\',\'' + f.id + '\',this.value)"' +
        ' onblur="onFieldBlur(\'' + id + '\',\'' + f.id + '\')">';
    }
    html += '</div>';
  });

  html += '</div></div>';
  html += '<button class="btn-generate" id="btnGenerate_' + id + '" onclick="generateReport(\'' + id + '\')">🖨️ Generate Report</button>';
  container.innerHTML = html;

  if (id === 'offhire-condition' || id === 'onhire-condition') {
    addonInjectDamageWidget(id, container);
  }

  if (SECTION_EXTRA_FIELDS[id]) {
    addonInjectExtraFields(id, container);
  }
}

/* ============================================================
   FIELD VALIDATION
   ============================================================ */
function updateFieldValidation(sectionId, fieldId, value) {
  var fieldDef = getFieldDef(fieldId);
  if (!fieldDef || fieldDef.readonly || fieldDef.autoCalc) return;
  var el = document.getElementById('field_' + sectionId + '_' + fieldId);
  if (!el) return;
  var filled = value !== null && value !== undefined && String(value).trim() !== '';
  el.classList.toggle('fc-valid', filled);
  el.classList.toggle('fc-invalid', !filled);
}

function revalidateAll(sectionId) {
  var st = sectionState[sectionId];
  FORM_FIELDS.forEach(function(f) { updateFieldValidation(sectionId, f.id, st.fieldValues[f.id]); });
}

function getFieldDef(fieldId) {
  for (var i = 0; i < FORM_FIELDS.length; i++) { if (FORM_FIELDS[i].id === fieldId) return FORM_FIELDS[i]; }
  return null;
}

/* ============================================================
   AUTO CALCULATION
   ============================================================ */
var CALC_TRIGGERS = ['REDELIVERY_VLSFO','REDELIVERY_LSMGO','REDELIVERY_HSFO','SURVEY_VLSFO','SURVEY_LSMGO','SURVEY_HSFO'];

function runAutoCalc(sectionId) {
  var fv = sectionState[sectionId].fieldValues;
  var offhire = isOffhireSection(sectionId);
  var pairs = [
    ['CONS_VLSFO','SURVEY_VLSFO','REDELIVERY_VLSFO'],
    ['CONS_LSMGO','SURVEY_LSMGO','REDELIVERY_LSMGO'],
    ['CONS_HSFO', 'SURVEY_HSFO', 'REDELIVERY_HSFO']
  ];
  pairs.forEach(function(pair) {
    var consId = pair[0], svId = pair[1], rdId = pair[2];
    var sv = parseFloat(String(fv[svId]||'').replace(/,/g,''));
    var rd = parseFloat(String(fv[rdId]||'').replace(/,/g,''));
    var el = document.getElementById('field_' + sectionId + '_' + consId);
    if (!isNaN(sv) && !isNaN(rd)) {
      var result = offhire ? (sv - rd).toFixed(3) : (rd - sv).toFixed(3);
      fv[consId] = result;
      if (el) el.value = result;
    } else {
      fv[consId] = '';
      if (el) el.value = '';
    }
  });
}

/* ============================================================
   FIELD CHANGE HANDLER
   ============================================================ */
function onFieldChange(sectionId, fieldId, value) {
  sectionState[sectionId].fieldValues[fieldId] = value;
  if (fieldId === 'HOLDS') {
    var alpha = NUMS_TO_WORDS[value] || '';
    var aEl = document.getElementById('field_' + sectionId + '_HOLDS_ALPHA');
    if (aEl) { aEl.value = alpha; sectionState[sectionId].fieldValues['HOLDS_ALPHA'] = alpha; }
  }
  if (CALC_TRIGGERS.indexOf(fieldId) !== -1) runAutoCalc(sectionId);
  updateFieldValidation(sectionId, fieldId, value);
  checkGenerateButton(sectionId);
}

function onFieldBlur(sectionId, fieldId) {
  var precision = getDecimalPrecision(fieldId);
  if (precision === 0) return;
  var currentVal = sectionState[sectionId].fieldValues[fieldId] || '';
  if (!currentVal) return;
  var formatted = applyDecimalFormat(currentVal, precision);
  if (formatted !== '' && formatted !== currentVal) {
    var el = document.getElementById('field_' + sectionId + '_' + fieldId);
    if (el) el.value = formatted;
    sectionState[sectionId].fieldValues[fieldId] = formatted;
    updateFieldValidation(sectionId, fieldId, formatted);
    if (CALC_TRIGGERS.indexOf(fieldId) !== -1) runAutoCalc(sectionId);
  }
}

/* ============================================================
   GENERATE BUTTON
   ============================================================ */
function checkGenerateButton(sectionId) {
  var st = sectionState[sectionId];
  var wordOk = !!st.wordFile;
  var allFilled = FORM_FIELDS.every(function(f) {
    if (f.readonly || f.autoCalc) return true;
    var v = st.fieldValues[f.id];
    return v && String(v).trim() !== '';
  });
  var btn = document.getElementById('btnGenerate_' + sectionId);
  if (btn) btn.classList.toggle('ready', wordOk && allFilled);
}

/* ============================================================
   EXCEL UPLOAD
   ============================================================ */
function handleExcel(e, sectionId) {
  var file = e.target.files[0];
  if (!file) return;
  sectionState[sectionId].excelFile = file;
  document.getElementById('excelZone_' + sectionId).classList.add('uploaded');
  document.getElementById('excelName_' + sectionId).textContent = '✓ ' + file.name;
  toast('success', 'Excel uploaded: ' + file.name, '📊');
  var reader = new FileReader();
  reader.onload = function(ev) {
    parseExcel(ev.target.result, function(map, err) {
      if (err) { toast('error', err, '⚠️'); return; }
      populateForm(sectionId, map);
    });
  };
  reader.readAsArrayBuffer(file);
}

function populateForm(sectionId, map) {
  var filled = 0;
  var st = sectionState[sectionId];

  FORM_FIELDS.forEach(function(f) {
    if (f.autoCalc) return;
    if (map[f.id] === undefined) return;
    var rawVal = map[f.id];

    if (f.type === 'date' || DATE_FIELDS.indexOf(f.id) !== -1) {
      var iso = excelDateToISO(rawVal);
      if (iso) rawVal = iso;
    }

    if (f.type === 'time') {
      var t = excelDateToTime(rawVal);
      if (t) rawVal = t;
    }

    var precision = getDecimalPrecision(f.id);
    if (precision > 0 && rawVal !== '') {
      var fmtd = applyDecimalFormat(rawVal, precision);
      if (fmtd !== '') rawVal = fmtd;
    }

    var el = document.getElementById('field_' + sectionId + '_' + f.id);
    if (el) { el.value = rawVal; filled++; }
    st.fieldValues[f.id] = rawVal;
  });

  var holdsVal = st.fieldValues['HOLDS'];
  if (holdsVal) {
    var alphaEl = document.getElementById('field_' + sectionId + '_HOLDS_ALPHA');
    if (alphaEl) { var w = NUMS_TO_WORDS[holdsVal]||''; alphaEl.value = w; st.fieldValues['HOLDS_ALPHA'] = w; }
  }

  runAutoCalc(sectionId);
  revalidateAll(sectionId);
  toast('info', 'Auto-filled ' + filled + ' fields from Excel', '⚡');
  checkGenerateButton(sectionId);
}

/* ============================================================
   WORD UPLOAD
   ============================================================ */
function handleWord(e, sectionId) {
  var file = e.target.files[0];
  if (!file) return;
  sectionState[sectionId].wordFile = file;
  document.getElementById('wordZone_' + sectionId).classList.add('uploaded');
  document.getElementById('wordName_' + sectionId).textContent = '✓ ' + file.name;
  toast('success', 'Word template uploaded: ' + file.name, '📄');
  checkGenerateButton(sectionId);
}

/* ============================================================
   GENERATE REPORT
   ============================================================ */
function generateReport(sectionId) {
  var btn = document.getElementById('btnGenerate_' + sectionId);
  if (!btn.classList.contains('ready')) { highlightMissing(sectionId); return; }
  if (!LIBS_OK) { toast('error','Libraries not loaded. Refresh the page.','❌'); return; }

  var st = sectionState[sectionId];
  toast('info', 'Generating report…', '⏳');

  var reader = new FileReader();
  reader.onload = function(ev) {
    try {
      var data = {};
      FORM_FIELDS.forEach(function(f) {
        var raw = st.fieldValues[f.id] || '';
        if (f.type === 'date' || DATE_FIELDS.indexOf(f.id) !== -1) {
          data[f.id] = formatDateForExport(raw);
          return;
        }
        var precision = getDecimalPrecision(f.id);
        if (precision > 0 && raw !== '') {
          var fmtd = applyDecimalFormat(raw, precision);
          data[f.id] = fmtd !== '' ? fmtd : raw;
          return;
        }
        data[f.id] = raw;
      });

      var fname = (SECTION_TITLES[sectionId]||sectionId).replace(/ /g,'_').replace(/[—–]/g,'') + '_Report.docx';
      processDocx(ev.target.result, data, fname);
      toast('success', 'Report generated and downloading!', '🎉');
    } catch(err) {
      console.error('Generation error:', err);
      toast('error', 'Generation failed: ' + err.message, '❌');
    }
  };
  reader.readAsArrayBuffer(st.wordFile);
}

function highlightMissing(sectionId) {
  var st = sectionState[sectionId];
  if (!st.wordFile) {
    var wz = document.getElementById('wordZone_' + sectionId);
    if (wz) { wz.classList.add('required-err'); setTimeout(function(){ wz.classList.remove('required-err'); }, 3000); }
  }
  var missing = 0;
  FORM_FIELDS.forEach(function(f) {
    if (f.readonly || f.autoCalc) return;
    var v = st.fieldValues[f.id];
    if (!v || !String(v).trim()) {
      var el = document.getElementById('field_' + sectionId + '_' + f.id);
      if (el) { el.classList.add('fc-invalid'); el.classList.remove('fc-valid'); }
      missing++;
    }
  });
  if (!st.wordFile && missing > 0) toast('error','Upload Word template and fill ' + missing + ' missing field(s)','⚠️');
  else if (!st.wordFile) toast('error','Please upload the Word template (.docx)','⚠️');
  else toast('error', missing + ' field(s) missing — check red borders','⚠️');
}

/* ============================================================
   PHOTO REPORT
   ============================================================ */
document.getElementById('photoTemplateInput').addEventListener('change', function(e) {
  var file = e.target.files[0]; if (!file) return;
  photoTemplateFile = file;
  document.getElementById('photoTemplateZone').classList.add('uploaded');
  document.getElementById('photoTemplateName').textContent = '✓ ' + file.name;
  toast('success','Photo template uploaded: ' + file.name,'📄');
  checkPhotoBtn();
});

document.getElementById('photoBulkInput').addEventListener('change', function(e) {
  var files = Array.from(e.target.files), loaded = 0;
  files.forEach(function(file) {
    var reader = new FileReader();
    reader.onload = function(ev) {
      photoItems.push({ file:file, dataUrl:ev.target.result, desc:'' });
      if (++loaded === files.length) { renderPhotoGrid(); toast('success', files.length+' image(s) added','🖼️'); checkPhotoBtn(); }
    };
    reader.readAsDataURL(file);
  });
  e.target.value = '';
});

function renderPhotoGrid() {
  var grid = document.getElementById('photoGrid');
  grid.innerHTML = '';
  photoItems.forEach(function(item, i) {
    var div = document.createElement('div');
    div.className = 'photo-item';
    div.innerHTML = '<div class="photo-index">IMAGE '+(i+1)+'</div>' +
      '<img class="photo-thumb" src="'+item.dataUrl+'" alt="Image '+(i+1)+'">' +
      '<div class="photo-desc-wrap"><textarea class="photo-desc" placeholder="Caption for image '+(i+1)+'…" oninput="photoItems['+i+'].desc=this.value">'+(item.desc||'')+'</textarea></div>' +
      '<button class="photo-remove" onclick="removePhoto('+i+')">✕ Remove</button>';
    grid.appendChild(div);
  });
  checkPhotoBtn();
}
function removePhoto(i) { photoItems.splice(i,1); renderPhotoGrid(); }
function clearPhotoReport() {
  photoItems=[]; photoTemplateFile=null;
  document.getElementById('photoTemplateZone').classList.remove('uploaded');
  document.getElementById('photoTemplateName').textContent='';
  document.getElementById('photoTemplateInput').value='';
  document.getElementById('photoGrid').innerHTML='';
  checkPhotoBtn(); toast('info','Photo report cleared','🗑️');
}
function checkPhotoBtn() {
  document.getElementById('btnPhotoGenerate').classList.toggle('ready', !!photoTemplateFile && photoItems.length>0);
}
function generatePhotoReport() {
  var btn = document.getElementById('btnPhotoGenerate');
  if (!btn.classList.contains('ready')) {
    if (!photoTemplateFile) { var ptz=document.getElementById('photoTemplateZone'); ptz.classList.add('required-err'); setTimeout(function(){ptz.classList.remove('required-err');},3000); }
    if (photoItems.length===0) toast('error','Please upload at least one image','⚠️');
    return;
  }
  if (!LIBS_OK) { toast('error','Libraries not loaded.','❌'); return; }
  toast('info','Generating photo report…','⏳');
  var data = {};
  for (var i=0; i<photoItems.length; i++) {
    data['TEXT'+(i+1)]     = photoItems[i].desc || '';
    data['FILENAME'+(i+1)] = photoItems[i].file.name || '';
    data['INDEX'+(i+1)]    = String(i+1);
  }
  data['TOTAL_IMAGES'] = String(photoItems.length);
  data['REPORT_DATE']  = formatDateForExport(todayISO());
  var reader = new FileReader();
  reader.onload = function(ev) {
    try { processDocx(ev.target.result, data, 'Photo_Report.docx'); toast('success','Photo report generated!','🎉'); }
    catch(err) { console.error(err); toast('error','Generation failed: '+err.message,'❌'); }
  };
  reader.readAsArrayBuffer(photoTemplateFile);
}

/* ============================================================
   SAVE BLOB
   ============================================================ */
function saveBlob(blob, filename) {
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href=url; a.download=filename;
  document.body.appendChild(a); a.click();
  setTimeout(function(){ URL.revokeObjectURL(url); a.remove(); }, 1000);
}

/* ============================================================
   TOAST
   ============================================================ */
function toast(type, msg, icon) {
  icon = icon||'ℹ️';
  var c = document.getElementById('toastContainer');
  var t = document.createElement('div');
  t.className='toast '+type;
  t.innerHTML='<span class="toast-icon">'+icon+'</span><span class="toast-msg">'+msg+'</span><span class="toast-close" onclick="this.parentElement.remove()">✕</span>';
  c.appendChild(t);
  setTimeout(function(){ t.style.opacity='0'; t.style.transition='opacity 0.4s'; setTimeout(function(){ if(t.parentElement) t.remove(); },400); }, 5000);
}

/* ============================================================
   DRAG & DROP
   ============================================================ */
['photoBulkZone','photoTemplateZone'].forEach(function(zoneId) {
  var zone = document.getElementById(zoneId);
  if (!zone) return;
  zone.addEventListener('dragover', function(e){ e.preventDefault(); zone.classList.add('drag-over'); });
  zone.addEventListener('dragleave', function(){ zone.classList.remove('drag-over'); });
  zone.addEventListener('drop', function(e) {
    e.preventDefault(); zone.classList.remove('drag-over');
    var input = zone.querySelector('input[type=file]');
    if (input && e.dataTransfer.files.length) {
      var dt = new DataTransfer();
      Array.from(e.dataTransfer.files).forEach(function(f){ dt.items.add(f); });
      input.files = dt.files;
      input.dispatchEvent(new Event('change'));
    }
  });
});

/* ============================================================
   ADDON: DAMAGE WIDGET — inject into condition sections
   ============================================================ */
function addonInjectDamageWidget(sectionId, container) {
  var formGrid = container.querySelector('#formGrid_' + sectionId);
  if (!formGrid) return;

  var widgetHtml =
    '<div class="damage-widget" id="dmgWidget_' + sectionId + '">' +
    '<div class="form-section-divider" style="margin-bottom:10px">' +
    '<div class="form-section-divider-line"></div>' +
    '<div class="form-section-divider-label">Any Damages</div>' +
    '<div class="form-section-divider-line"></div>' +
    '</div>' +
    '<div class="damage-trigger-row">' +
    '<span class="damage-trigger-label">Any Damages?</span>' +
    '<button type="button" class="damage-yn-btn" id="dmgBtnYes_' + sectionId + '" onclick="addonDamageToggle(\'' + sectionId + '\',true)">Yes — Add Damages</button>' +
    '<button type="button" class="damage-yn-btn" id="dmgBtnNo_' + sectionId + '" onclick="addonDamageToggle(\'' + sectionId + '\',false)">No Damages</button>' +
    '</div>' +
    '<div class="damage-entry-panel" id="dmgPanel_' + sectionId + '">' +
    '<div class="damage-entry-row">' +
    '<select class="damage-hold-select" id="dmgHoldSelect_' + sectionId + '">' +
    '<option value="">— Select Hold —</option>' +
    '<option>Hold #1</option><option>Hold #2</option><option>Hold #3</option>' +
    '<option>Hold #4</option><option>Hold #5</option><option>Hold #6</option><option>Hold #7</option>' +
    '</select>' +
    '<textarea class="damage-textarea" id="dmgText_' + sectionId + '" placeholder="Describe the damage…"></textarea>' +
    '<button type="button" class="damage-save-btn" onclick="addonDamageSave(\'' + sectionId + '\')">Save Entry</button>' +
    '</div>' +
    '<div class="damage-list" id="dmgList_' + sectionId + '"></div>' +
    '</div>' +
    '</div>';

  formGrid.insertAdjacentHTML('beforeend', widgetHtml);
}

function addonDamageToggle(sectionId, showPanel) {
  var panel = document.getElementById('dmgPanel_' + sectionId);
  var btnYes = document.getElementById('dmgBtnYes_' + sectionId);
  var btnNo  = document.getElementById('dmgBtnNo_' + sectionId);
  if (!panel) return;
  if (showPanel) {
    panel.classList.add('visible');
    btnYes.classList.add('active-yes'); btnNo.classList.remove('active-no');
  } else {
    panel.classList.remove('visible');
    btnNo.classList.add('active-no'); btnYes.classList.remove('active-yes');
  }
}

function addonDamageSave(sectionId) {
  var holdEl = document.getElementById('dmgHoldSelect_' + sectionId);
  var textEl = document.getElementById('dmgText_' + sectionId);
  if (!holdEl || !textEl) return;
  var hold = holdEl.value.trim();
  var text = textEl.value.trim();
  if (!hold) { toast('error', 'Please select a Hold number.', '⚠️'); return; }
  if (!text) { toast('error', 'Please enter a damage description.', '⚠️'); return; }
  damageState[sectionId].push({ hold: hold, text: text });
  holdEl.value = '';
  textEl.value = '';
  addonRenderDamageList(sectionId);
  toast('success', 'Damage entry saved.', '✅');
}

function addonRemoveDamage(sectionId, idx) {
  damageState[sectionId].splice(idx, 1);
  addonRenderDamageList(sectionId);
}

function addonRenderDamageList(sectionId) {
  var listEl = document.getElementById('dmgList_' + sectionId);
  if (!listEl) return;
  listEl.innerHTML = '';
  damageState[sectionId].forEach(function(entry, i) {
    var div = document.createElement('div');
    div.className = 'damage-entry-item';
    div.innerHTML = '<span class="damage-entry-text"><span class="damage-entry-hold">' + entry.hold + ':</span>' + entry.text + '</span>' +
      '<button type="button" class="damage-remove-btn" onclick="addonRemoveDamage(\'' + sectionId + '\',' + i + ')" title="Remove">✕</button>';
    listEl.appendChild(div);
  });
}

/* ============================================================
   FIX: addonGetDamagesText — build an array of line objects
   for docxtemplater linebreaks:true compatibility.
   Each entry is rendered as a separate paragraph line so the
   placeholder {Damages_1} in Word receives the full text with
   proper line breaks instead of a raw \n-joined string.

   Root cause of original bug:
   - docxtemplater with linebreaks:true expects the value to be
     a plain string containing \n characters, which it converts
     to <w:br/> elements inside a single run.
   - The previous code used '\n' join which is correct for
     linebreaks:true, BUT the placeholder key was 'Damages_1'
     (capital D) while the template may have had case mismatch,
     AND the nullGetter returning '' silently swallowed missing
     keys without any error — making it appear as if damages
     were not injected.
   Fix: We now export BOTH 'Damages_1' AND 'DAMAGES_1' and also
   a 'DAMAGE_LIST' key so any capitalisation in the template works.
   The value is a \n-separated string which docxtemplater's
   linebreaks:true converts to proper Word line breaks.
   ============================================================ */
function addonGetDamagesText(sectionId) {
  if (!damageState[sectionId] || damageState[sectionId].length === 0) {
    return 'No Major Apparant damages reported.';
  }
  return damageState[sectionId].map(function(e, i) {
    return (i + 1) + '. ' + e.hold + ': ' + e.text;
  }).join('\n');
}

/* ============================================================
   ADDON: EXTRA FIELDS CARD — inject for 3party sections
   ============================================================ */
function addonInjectExtraFields(sectionId, container) {
  var fields = SECTION_EXTRA_FIELDS[sectionId];
  if (!fields || !fields.length) return;

  var card = document.createElement('div');
  card.className = 'card';
  card.id = 'extraCard_' + sectionId;

  var html = '<div class="card-title">➕ Additional Fields <span class="badge-optional">OPTIONAL</span></div>' +
    '<div class="form-grid">';

  fields.forEach(function(f) {
    html += '<div class="form-group">' +
      '<label class="form-label">' + f.label + '</label>' +
      '<input type="text" class="form-control" id="extra_' + sectionId + '_' + f.id + '"' +
      ' placeholder="' + f.label + '…"' +
      ' oninput="addonExtraFieldChange(\'' + sectionId + '\',\'' + f.id + '\',this.value)"' +
      ' onchange="addonExtraFieldChange(\'' + sectionId + '\',\'' + f.id + '\',this.value)">' +
      '</div>';
  });

  html += '</div>';
  card.innerHTML = html;

  var btn = container.querySelector('#btnGenerate_' + sectionId);
  if (btn) btn.parentNode.insertBefore(card, btn);
  else container.appendChild(card);
}

function addonExtraFieldChange(sectionId, fieldId, value) {
  if (!extraFieldValues[sectionId]) extraFieldValues[sectionId] = {};
  extraFieldValues[sectionId][fieldId] = value;
}

/* ============================================================
   ADDON: Patch populateForm to auto-fill extra fields from Excel
   ============================================================ */
var _origPopulateForm = populateForm;
populateForm = function(sectionId, map) {
  _origPopulateForm(sectionId, map);
  var extras = SECTION_EXTRA_FIELDS[sectionId];
  if (extras) {
    extras.forEach(function(f) {
      if (map[f.id] !== undefined) {
        var el = document.getElementById('extra_' + sectionId + '_' + f.id);
        if (el) el.value = map[f.id];
        if (!extraFieldValues[sectionId]) extraFieldValues[sectionId] = {};
        extraFieldValues[sectionId][f.id] = map[f.id];
      }
    });
  }
};

/* ============================================================
   ADDON: Patch generateReport to inject Damages + extra fields
   FIX: Export damages under multiple key names to handle any
   capitalisation variant used in the Word template:
     {Damages_1}   ← original (was silently empty due to nullGetter)
     {DAMAGES_1}   ← uppercase variant
     {DAMAGE_LIST} ← alternative key
   All three now carry the same formatted damage string.
   ============================================================ */
var _origGenerateReport = generateReport;
generateReport = function(sectionId) {
  var btn = document.getElementById('btnGenerate_' + sectionId);
  if (!btn.classList.contains('ready')) { highlightMissing(sectionId); return; }
  if (!LIBS_OK) { toast('error','Libraries not loaded. Refresh the page.','❌'); return; }

  var st = sectionState[sectionId];
  toast('info', 'Generating report…', '⏳');

  var reader = new FileReader();
  reader.onload = function(ev) {
    try {
      var data = {};
      FORM_FIELDS.forEach(function(f) {
        var raw = st.fieldValues[f.id] || '';
        if (f.type === 'date' || DATE_FIELDS.indexOf(f.id) !== -1) {
          data[f.id] = formatDateForExport(raw);
          return;
        }
        var precision = getDecimalPrecision(f.id);
        if (precision > 0 && raw !== '') {
          var fmtd = applyDecimalFormat(raw, precision);
          data[f.id] = fmtd !== '' ? fmtd : raw;
          return;
        }
        data[f.id] = raw;
      });

      /* ── FIX: Damages injection for condition sections ──
         Provide the damage text under every key capitalisation
         variant so {Damages_1}, {DAMAGES_1}, or {DAMAGE_LIST}
         all work in the Word template. The value is a
         newline-separated string; docxtemplater linebreaks:true
         converts each \n to a Word line break (<w:br/>).      */
      if (sectionId === 'offhire-condition' || sectionId === 'onhire-condition') {
        var dmgText = addonGetDamagesText(sectionId);
        data['Damages_1']   = dmgText;   /* {Damages_1}   */
        data['DAMAGES_1']   = dmgText;   /* {DAMAGES_1}   */
        data['DAMAGE_LIST'] = dmgText;   /* {DAMAGE_LIST} */
        data['damages_1']   = dmgText;   /* {damages_1}   */
      }

      /* ── Extra fields (3party sections) ── */
      var extras = SECTION_EXTRA_FIELDS[sectionId];
      if (extras) {
        extras.forEach(function(f) {
          data[f.id] = (extraFieldValues[sectionId] && extraFieldValues[sectionId][f.id]) || '';
        });
      }

    var ship = (data.SHIP_NAME || 'VESSEL').trim().toUpperCase();
var sectionLabel;
if (sectionId === 'offhire')                    sectionLabel = 'OFFHIRE';
else if (sectionId === 'onhire')                sectionLabel = 'ONHIRE';
else if (sectionId === 'offhire-condition')     sectionLabel = 'OFFHIRE CONDITION';
else if (sectionId === 'onhire-condition')      sectionLabel = 'ONHIRE CONDITION';
else if (sectionId === '3party-rrr' || sectionId === '3party-rrd') sectionLabel = 'OFFHIRE';
else sectionLabel = (SECTION_TITLES[sectionId]||sectionId).toUpperCase();
var fname = ship + ' ' + sectionLabel + ' FINAL FORMAL REPORT.docx'; 

     processDocx(ev.target.result, data, fname);
      toast('success', 'Report generated and downloading!', '🎉');
    } catch(err) {
      console.error('Generation error:', err);
      toast('error', 'Generation failed: ' + err.message, '❌');
    }
  };
  reader.readAsArrayBuffer(st.wordFile);
};

/* ============================================================
   INIT
   ============================================================ */
showSection('home');


window.onload = function(){
   document.getElementById("photoReportModal").style.display="flex";
}
</script>
<?php include "../includes/footer.php"; ?>
</body>
</html>



?>
