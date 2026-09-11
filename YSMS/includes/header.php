<?php
require_once __DIR__ . '/../config/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?></title>
    <!-- Bootstrap 5 & FontAwesome Icons Direct CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-bg: #0b1e46;
            --app-bg: #f4f6fa;
            --card-bg: #ffffff;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --accent-purple: #3b32b3;
            --btn-blue: #1e3a8a;
            --border-color: #e2e8f0;
            --font-primary: 'Lexend', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }

        * { -webkit-tap-highlight-color: transparent; }

        body {
            font-family: var(--font-primary);
            background-color: #223156;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            overflow-x: hidden;
        }

       .mobile-container {
    width: 100%;
    margin: 0 auto;
    background-color: var(--app-bg);
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

        .scroll-content {
            flex: 1;
            min-height: 0; /* flex ఐటమ్ కంటెంట్ ఎక్కువైతే కంటైనర్ పెరగకుండా ఆపడానికి */
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: 90px;
        }

        .scroll-content::-webkit-scrollbar {
            width: 0;
            background: transparent;
        }

        /* Dashboard Header */
        .dash-header {
            background: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .user-welcome h2 {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            color: var(--text-dark);
        }

        .user-welcome p {
            font-size: 13px;
            color: var(--text-muted);
            margin: 0;
        }

        .profile-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
        }

        .profile-menu-wrap { position: relative; flex: 0 0 45px; z-index: 1200; }
        .profile-menu-trigger { width: 45px; height: 45px; padding: 0; border: 0; border-radius: 50%; background: transparent; cursor: pointer; transition: transform .2s ease, box-shadow .2s ease; }
        .profile-menu-trigger:hover, .profile-menu-trigger:focus-visible { transform: translateY(-1px); box-shadow: 0 0 0 3px rgba(59,50,179,.14); outline: none; }
        .profile-dropdown-menu { position: absolute; top: calc(100% + 10px); right: 0; min-width: 178px; background: #fff; border: 1px solid var(--border-color); border-radius: 14px; padding: 7px; box-shadow: 0 16px 34px rgba(15,23,42,.16); opacity: 0; visibility: hidden; transform: translateY(-8px) scale(.98); transform-origin: top right; transition: opacity .2s ease, transform .2s ease, visibility .2s ease; }
        .profile-menu-wrap.open .profile-dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .profile-dropdown-item { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border-radius: 10px; color: var(--text-dark); text-decoration: none; font-size: 13px; font-weight: 600; transition: background-color .18s ease, transform .18s ease; }
        .profile-dropdown-item:hover { background: #f1f5f9; color: var(--accent-purple); transform: translateX(2px); }
        .profile-dropdown-item i { width: 18px; text-align: center; }
        .profile-dropdown-item.logout-item { color: #dc2626; }
        .profile-dropdown-item.logout-item:hover { background: #fef2f2; color: #b91c1c; }

        .top-app-bar { position: sticky; top: 0; z-index: 850; min-height: 70px; background: #fff; border-bottom: 1px solid var(--border-color); padding: 12px 20px; display: grid; grid-template-columns: minmax(76px, 1fr) auto minmax(76px, 1fr); align-items: center; }
        .top-app-bar-left { display: flex; align-items: center; gap: 15px; justify-self: start; }
        .top-app-bar-title { margin: 0; color: var(--text-dark); font-size: 18px; font-weight: 700; text-align: center; white-space: nowrap; }
        .top-app-bar > .profile-menu-wrap { justify-self: end; }

        .top-app-bar-right {
            justify-self: end;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Dismissible top alert banner (replaces the old bell/panel) ──
           One banner at a time: "New vessel assigned" for a Surveyor, or
           "Report uploaded by X" for an Admin. Clicking the link or the X
           marks the underlying notification read, so it never reappears. */
        .top-alert-banner {
            position: absolute;
            top: 14px; left: 14px; right: 14px;
            z-index: 9998;
            display: flex;
            align-items: center;
            gap: 10px;
            background: #1d3a8a;
            color: #fff;
            padding: 12px 14px;
            border-radius: 14px;
            box-shadow: 0 10px 25px -5px rgba(15,23,42,.35);
            animation: slideDownToast .3s ease-out;
        }
        .top-alert-banner-icon { font-size: 16px; flex-shrink: 0; opacity: .9; }
        .top-alert-banner-link { flex: 1; min-width: 0; color: #fff; text-decoration: none; font-size: 12.5px; font-weight: 600; line-height: 1.35; }
        .top-alert-banner-link:hover { text-decoration: underline; }
        .top-alert-banner-close {
            border: 0; background: rgba(255,255,255,.15); color: #fff;
            width: 26px; height: 26px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; cursor: pointer; flex-shrink: 0;
        }
        .top-alert-banner-close:hover { background: rgba(255,255,255,.28); }
        @media (min-width: 992px) {
            .top-alert-banner { left: 268px; }
        }
        @keyframes slideDownToast {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }


        .hamburger-menu-btn { width: 42px; height: 42px; border: 1px solid var(--border-color); border-radius: 12px; background: #fff; color: var(--text-dark); display: inline-flex; align-items: center; justify-content: center; font-size: 17px; cursor: pointer; transition: background-color .2s ease, transform .2s ease; }
        .hamburger-menu-btn:hover { background: #f1f5f9; transform: translateY(-1px); }
        .detail-back-btn { width: 28px; height: 38px; display: inline-flex; align-items: center; text-decoration: none; }
        .sidebar-screen-overlay { display: none; }

        /* Overview Grid */
        .overview-section {
            padding: 0 20px;
            margin-top: 15px;
        }

        .section-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
        }

        .date-select-badge {
            background: #ffffff;
            border: 1px solid var(--border-color);
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 12px;
            color: var(--text-dark);
        }

        .overview-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .ov-card {
            background: white;
            border-radius: 16px;
            padding: 15px 10px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            cursor: pointer;
        }

        .ov-card.pending-vessel { background: #eff6ff; }
        .ov-card.pending-report { background: #fff7ed; }
        .ov-card.completed { background: #f0fdf4; }

        .ov-count {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .ov-card.pending-vessel .ov-count { color: #1e40af; }
        .ov-card.pending-report .ov-count { color: #c2410c; }
        .ov-card.completed .ov-count { color: #166534; }

        .ov-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-dark);
            line-height: 1.2;
        }

        /* Statistics Grid */
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 0 20px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .stat-title {
            font-size: 11px;
            color: #166534;
            background: #f0fdf4;
            padding: 3px 8px;
            border-radius: 6px;
            display: inline-block;
            font-weight: 600;
        }

        .stat-card:nth-child(2n) .stat-title {
            color: #1e40af;
            background: #eff6ff;
        }

        .stat-val {
            font-size: 18px;
            font-weight: 700;
            margin: 8px 0 2px 0;
            color: var(--text-dark);
        }

        .stat-change {
            font-size: 11px;
            font-weight: 600;
            color: #1e40af;
        }

        /* Bottom Nav Fixed - విండో/స్క్రీన్ బాటమ్‌కి ఎప్పుడూ ఫిక్స్‌గా ఉండటానికి position: fixed వాడాం
           (position: absolute వాడితే పేజీ కంటెంట్ ఎక్కువగా ఉన్నప్పుడు నావ్‌బార్ కిందకి జారిపోతుంది) */
        .bottom-nav-bar {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            max-width: 100%;
            margin: 0 auto;
            height: 70px;
            background: #ffffff;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-around;
            align-items: center;
            z-index: 900;
            padding-bottom: env(safe-area-inset-bottom, 5px);
        }

        .nav-item-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: var(--text-muted);
            font-size: 10px;
            font-weight: 600;
            text-decoration: none;
        }

        .nav-item-btn.active {
            color: var(--accent-purple);
        }

        .nav-item-btn i {
            font-size: 18px;
            margin-bottom: 3px;
        }

        .center-fab-btn {
            width: 56px;
            height: 56px;
            background: var(--accent-purple);
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 24px;
            margin-top: -32px;
            box-shadow: 0 4px 10px rgba(59, 50, 179, 0.4);
            cursor: pointer;
            border: none;
        }

        /* List Items CSS */
        .search-filter-row { display: flex; gap: 10px; padding: 15px 20px; }
        .search-input-wrapper { position: relative; flex: 1; }
        .search-input-wrapper i { position: absolute; left: 12px; top: 12px; color: var(--text-muted); }
        .search-control { width: 100%; background: white; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 10px 10px 35px; font-size: 13px; }
        .filter-btn { background: white; border: 1px solid var(--border-color); width: 42px; height: 42px; border-radius: 12px; display: flex; justify-content: center; align-items: center; color: var(--text-dark); }
        .vessel-list-container { padding: 0 20px; }
        .vessel-card { background: white; border-radius: 16px; padding: 15px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 4px rgba(0,0,0,0.01); cursor: pointer; }
        .vessel-avatar-info { display: flex; align-items: center; gap: 12px; }
        .vessel-initial-avatar { width: 42px; height: 42px; border-radius: 50%; background: #eff6ff; color: #1e40af; display: flex; justify-content: center; align-items: center; font-weight: 700; font-size: 16px; }
        .vessel-name-title { font-size: 14px; font-weight: 700; color: var(--text-dark); margin: 0; }
        .vessel-client-sub { font-size: 12px; color: var(--text-muted); margin: 2px 0 0 0; }
        .vessel-badge-date { text-align: right; }
        .badge-status { font-size: 10px; padding: 3px 8px; border-radius: 6px; font-weight: 600; display: inline-block; }
        .badge-assigned { background: #eff6ff; color: #1e40af; }
        .badge-due { background: #fff7ed; color: #c2410c; }
        .badge-overdue { background: #fef2f2; color: #dc2626; }
        .badge-date-text { font-size: 11px; font-weight: 600; color: var(--text-dark); margin-top: 4px; }
        .badge-place { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; font-size: 10px; padding: 4px 9px; border-radius: 50px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; margin-top: 6px; }
        .list-controls-panel { display: none; margin: -5px 20px 15px; padding: 12px; background: #fff; border: 1px solid var(--border-color); border-radius: 12px; box-shadow: 0 8px 20px rgba(15,23,42,.08); grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .list-controls-panel.open { display: grid; animation: controlsReveal .2s ease-out; }
        .list-controls-panel select { min-width: 0; width: 100%; padding: 9px 10px; border: 1px solid var(--border-color); border-radius: 9px; color: var(--text-dark); background: #f8fafc; font-size: 12px; }
        @keyframes controlsReveal { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        .list-empty-state { display: none; }

        @media (max-width: 991px) {
            .mobile-container.sidebar-page .bottom-nav-bar { display: flex; position: fixed; top: 0; bottom: 0; left: 0; right: auto; width: min(82vw, 300px); height: 100vh; padding: 86px 20px 30px; flex-direction: column; justify-content: flex-start; align-items: stretch; gap: 10px; border: 0; box-shadow: 14px 0 35px rgba(15,23,42,.2); transform: translateX(-105%); transition: transform .3s ease; z-index: 1100; }
            .mobile-container.sidebar-page .bottom-nav-bar.open { transform: translateX(0); }
            .mobile-container.sidebar-page .nav-item-btn { flex-direction: row; justify-content: flex-start; gap: 14px; width: 100%; padding: 12px 14px; border-radius: 10px; font-size: 14px; }
            .mobile-container.sidebar-page .nav-item-btn i { margin: 0; width: 22px; }
            .mobile-container.sidebar-page .center-fab-btn { order: 5; width: 100%; height: 45px; margin: 12px 0 0; border-radius: 10px; }
            .mobile-container.sidebar-page .sidebar-screen-overlay { position: fixed; inset: 0; display: block; background: rgba(11,30,70,.52); opacity: 0; visibility: hidden; transition: opacity .3s ease, visibility .3s ease; z-index: 1050; }
            .mobile-container.sidebar-page .sidebar-screen-overlay.open { opacity: 1; visibility: visible; }

            /* Admin: hide Cancelled on mobile bottom bar only; show again in open sidebar drawer */
            .bottom-nav-bar:not(.open) .hide-cancelled-on-mobile-bn {
                display: none !important;
            }
            .bottom-nav-bar.open .hide-cancelled-on-mobile-bn {
                display: flex !important;
            }
        }

        @media (max-width: 480px) {
            .top-app-bar { padding: 10px 14px; grid-template-columns: minmax(58px, 1fr) auto minmax(58px, 1fr); }
            .top-app-bar-title { font-size: 16px; max-width: 52vw; overflow: hidden; text-overflow: ellipsis; }
            .top-app-bar-left { gap: 8px; }
            .hamburger-menu-btn { width: 38px; height: 38px; }
            .top-app-bar .profile-avatar, .top-app-bar .profile-menu-trigger { width: 40px; height: 40px; }
            .top-app-bar .profile-menu-wrap { flex-basis: 40px; }
            .list-controls-panel { grid-template-columns: 1fr; }
        }

        /* ==========================================================================
           🌟 DETAILS SCREENS PIXEL-PERFECT CSS (Vessel, Report & Completed Details)
           ========================================================================== */
        .detail-header-card {
            background: white;
            padding: 22px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-color);
        }

        .info-table-list {
            background: white;
            margin: 15px 20px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 20px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13.5px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .info-label i {
            font-size: 15px;
            width: 18px;
            text-align: center;
        }

        .info-value {
            color: var(--text-dark);
            font-weight: 600;
            text-align: right;
            max-width: 60%;
        }

        .action-btn-container {
            padding: 0 20px;
            margin-top: 15px;
        }

        .blue-action-btn {
            background: #1e3a8a;
            color: white;
            width: 100%;
            border: none;
            padding: 14px;
            border-radius: 12px;
            font-weight: 600;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            box-shadow: 0 4px 6px -1px rgba(30, 58, 138, 0.2);
        }

        /* Popups / Drawers
           🌟 position: fixed (not absolute) — the overlay markup in footer.php
           sits as a sibling of .mobile-container, not a descendant, so with
           `absolute` it had no positioned ancestor and was being sized to the
           full scrollable document instead of the viewport: scrolling the
           page while the sheet was open dragged the whole sheet up with it.
           `fixed` pins it to the viewport regardless of page scroll. The
           sheet itself also gets its own max-height + scroll so a long
           option list scrolls internally instead of growing off-screen. */
        .fab-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(11, 30, 70, 0.6); z-index: 999; display: none; justify-content: center; align-items: flex-end; }
        .fab-popup-sheet { background: white; width: 100%; border-top-left-radius: 30px; border-top-right-radius: 30px; padding: 25px; box-shadow: 0 -10px 25px rgba(0,0,0,0.1); transform: translateY(100%); transition: transform 0.3s ease-out; max-height: 80vh; overflow-y: auto; }
        .fab-option-item { display: flex; align-items: center; gap: 15px; padding: 15px 10px; border-bottom: 1px solid var(--border-color); color: var(--text-dark); text-decoration: none; font-weight: 600; }

/* Desktop-only helpers (hidden on mobile by default) */
.desktop-only-nav,
.desktop-sidebar-brand,
.desktop-sidebar-footer,
.desktop-sidebar-section-label {
    display: none !important;
}
/* On mobile, unwrap scroll container so bottom-nav stays horizontal flex */
.desktop-sidebar-nav-scroll {
    display: contents;
}

/* 🖥️ Desktop Version Styles — professional admin panel layout */
@media (min-width: 992px) {
    body {
        align-items: stretch;
        justify-content: flex-start;
        background-color: #f1f5f9;
        min-height: 100vh;
        overflow-x: hidden;
    }
    .mobile-container {
        max-width: 100%;
        width: 100%;
        height: auto;
        min-height: 100vh;
        border: none;
        box-shadow: none;
        border-radius: 0;
        background-color: #f1f5f9;
    }
    .scroll-content {
        padding-bottom: 24px;
        padding-left: 268px; /* left sidebar width + gap */
        padding-right: 24px;
        padding-top: 0;
        max-width: 100%;
        min-height: 100vh;
        overflow-y: auto;
    }
    .hamburger-menu-btn { display: none !important; }
    .sidebar-screen-overlay { display: none !important; }

    /* Top app bar is inside .scroll-content (already padded for sidebar) */
    .top-app-bar {
        margin-left: 0;
        margin-right: 0;
        padding: 12px 16px;
        min-height: 60px;
        border-bottom: 1px solid #e2e8f0;
        background: #fff;
        position: sticky;
        top: 0;
        z-index: 850;
        box-shadow: 0 1px 0 rgba(15,23,42,.04);
        border-radius: 0 0 0 0;
    }
    .top-app-bar-title { font-size: 17px; font-weight: 700; }
    .dash-header {
        margin-left: 0;
        padding: 18px 8px 14px 8px;
    }

    /* ── Left sidebar (from bottom-nav-bar) ── */
    .bottom-nav-bar {
        position: fixed;
        top: 0; left: 0; right: auto; bottom: 0;
        width: 252px;
        height: 100vh;
        flex-direction: column;
        justify-content: flex-start;
        align-items: stretch;
        padding: 0;
        gap: 0;
        border-right: 1px solid rgba(255,255,255,.08);
        border-top: none;
        background: linear-gradient(180deg, #0f172a 0%, #0b1e46 55%, #071428 100%);
        z-index: 1100;
        box-shadow: 4px 0 24px rgba(15,23,42,.18);
        overflow: hidden;
    }

    /* Brand block */
    .desktop-sidebar-brand {
        display: flex !important;
        align-items: center;
        gap: 12px;
        padding: 22px 18px 18px;
        border-bottom: 1px solid rgba(255,255,255,.08);
        flex: 0 0 auto;
    }
    .desktop-sidebar-mark {
        width: 40px; height: 40px; border-radius: 12px;
        background: linear-gradient(135deg, #6366f1, #3b32b3);
        color: #fff; font-weight: 800; font-size: 18px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 6px 16px rgba(59,50,179,.45);
        flex: none;
        overflow: hidden;
    }
    /* Logo badge gets a white background (the logo itself has its own color),
       instead of the purple gradient meant for the fallback anchor icon. */
    .desktop-sidebar-mark.has-logo {
        background: #fff;
        box-shadow: 0 2px 8px rgba(15,23,42,.18);
    }
    .desktop-sidebar-mark i { font-size: 18px; color: #fff; }
    .desktop-sidebar-logo-img {
        width: 100%; height: 100%; object-fit: contain; display: block; padding: 4px;
    }
    .desktop-sidebar-brand-text b {
        display: block; color: #fff; font-size: 15px; font-weight: 700; line-height: 1.2;
    }
    .desktop-sidebar-brand-text span {
        display: block; color: rgba(255,255,255,.5); font-size: 10.5px;
        letter-spacing: .06em; text-transform: uppercase; margin-top: 2px;
    }

    .desktop-sidebar-nav-scroll {
        display: flex !important;
        flex: 1 1 auto;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 12px 12px 8px;
        flex-direction: column;
        gap: 2px;
        min-height: 0;
    }
    .desktop-sidebar-nav-scroll::-webkit-scrollbar { width: 4px; }
    .desktop-sidebar-nav-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 4px; }

    .desktop-sidebar-section-label {
        display: block !important;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .12em;
        text-transform: uppercase;
        color: rgba(255,255,255,.35);
        padding: 14px 12px 6px;
        margin: 0;
    }

    .desktop-only-nav { display: flex !important; }

    /* Hide mobile FAB + on desktop — actions are in sidebar */
    .center-fab-btn { display: none !important; }

    .nav-item-btn {
        flex-direction: row;
        justify-content: flex-start;
        gap: 12px;
        font-size: 13.5px;
        font-weight: 550;
        width: 100%;
        padding: 11px 14px;
        border-radius: 10px;
        color: rgba(255,255,255,.72);
        margin: 0;
        min-height: 0;
    }
    .nav-item-btn i {
        font-size: 15px;
        margin: 0;
        width: 20px;
        text-align: center;
        color: rgba(255,255,255,.5);
    }
    .nav-item-btn:hover {
        background: rgba(255,255,255,.07);
        color: #fff;
    }
    .nav-item-btn:hover i { color: #a5b4fc; }
    .nav-item-btn.active {
        background: linear-gradient(90deg, rgba(59,50,179,.35), rgba(59,50,179,.08));
        color: #fff;
        box-shadow: inset 3px 0 0 #818cf8;
    }
    .nav-item-btn.active i { color: #a5b4fc; }

    /* Footer: name + logout */
    .desktop-sidebar-footer {
        display: flex !important;
        flex-direction: column;
        gap: 10px;
        padding: 14px 14px 18px;
        border-top: 1px solid rgba(255,255,255,.08);
        flex: 0 0 auto;
        background: rgba(0,0,0,.15);
    }
    .desktop-sidebar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        text-decoration: none;
        color: inherit;
        border-radius: 10px;
        padding: 4px;
        margin: -4px;
        transition: background .15s ease;
    }
    .desktop-sidebar-user:hover {
        background: rgba(255,255,255,.06);
    }
    .desktop-sidebar-user-avatar {
        width: 36px; height: 36px; border-radius: 10px;
        background: linear-gradient(135deg, #6366f1, #3b32b3);
        color: #fff; font-weight: 700; font-size: 14px;
        display: flex; align-items: center; justify-content: center;
        flex: none;
    }
    .desktop-sidebar-user-avatar-img {
        width: 36px; height: 36px; border-radius: 10px;
        object-fit: cover; flex: none;
        border: 2px solid rgba(255,255,255,.2);
    }
    .desktop-sidebar-user-meta { min-width: 0; flex: 1; }
    .desktop-sidebar-user-name {
        color: #fff; font-size: 13px; font-weight: 650;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .desktop-sidebar-user-role {
        color: rgba(255,255,255,.45); font-size: 11px; margin-top: 1px;
    }
    .desktop-sidebar-logout {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        padding: 10px 12px; border-radius: 10px;
        background: rgba(239,68,68,.12);
        border: 1px solid rgba(239,68,68,.25);
        color: #fca5a5; text-decoration: none;
        font-size: 13px; font-weight: 650;
        transition: background .18s ease, color .18s ease;
    }
    .desktop-sidebar-logout:hover {
        background: rgba(239,68,68,.22);
        color: #fecaca;
    }

    /* ── Content area polish (less empty space, professional density) ── */
    .overview-section { padding: 12px 8px 0; margin-top: 8px; max-width: 1200px; }
    .section-title-row { margin-bottom: 10px; }
    .overview-grid {
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
    }
    .ov-card { padding: 18px 14px; border-radius: 14px; }
    .ov-count { font-size: 30px; }
    .stat-grid {
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        padding: 12px 8px 0;
        max-width: 1200px;
    }
    .stat-card { padding: 16px; border-radius: 14px; }
    .search-filter-row { padding: 14px 16px 10px; max-width: none; width: 100%; }
    .vessel-list-container { padding: 0 16px 12px; max-width: none; width: 100%; }
    .list-controls-panel {
        margin: 0 16px 12px;
        max-width: none;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    /* List cards: left-aligned; column only when status bar exists (vessels/reports) */
    .vessel-card {
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        text-align: left;
        gap: 12px;
    }
    .vessel-card:has(.vessel-live-status-bar),
    .vessel-card:has(.vessel-card-top-content),
    .vessel-card.vessel-card--stacked {
        flex-direction: column !important;
        align-items: stretch !important;
        justify-content: flex-start !important;
    }
    /* Fallback for browsers without :has() support (e.g. older Firefox) so the
       "Latest Update / updated by / date" block on vessels.php desktop is never
       squeezed out of view next to the top content row. */
    .vessel-card--stacked .vessel-card-top-content {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        width: 100%;
    }
    .vessel-card--stacked .vessel-live-status-bar {
        display: block !important;
        width: 100%;
        text-align: left;
    }
    .vessel-card-top-content {
        display: flex !important;
        flex-direction: row !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        width: 100%;
        text-align: left;
    }
    .vessel-avatar-info,
    .vessel-name-title,
    .vessel-client-sub {
        text-align: left !important;
    }
    .vessel-badge-date {
        flex: 0 0 auto;
        text-align: right !important;
    }
    .vessel-live-status-bar {
        width: 100%;
        text-align: left;
    }

    /* Detail pages denser two-column layout on desktop */
    .detail-desktop-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
        align-items: start;
        max-width: 1100px;
        padding: 0 8px 16px;
    }
    .detail-desktop-grid .info-table-list,
    .detail-desktop-grid .status-update-card,
    .detail-desktop-grid .form-box,
    .detail-desktop-grid .detail-header-card {
        margin-left: 0 !important;
        margin-right: 0 !important;
        max-width: none;
    }
    .scroll-content .detail-header-card {
        max-width: 1100px;
        margin: 0 8px 10px;
        border-radius: 14px;
        border: 1px solid var(--border-color);
        text-align: left;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
    }
    .scroll-content .detail-header-card .vessel-initial-avatar {
        margin: 0 !important;
        flex: none;
    }
    .scroll-content .detail-header-card h3,
    .scroll-content .detail-header-card p {
        text-align: left;
    }
    .scroll-content .info-table-list {
        margin: 8px !important;
        max-width: 1100px;
    }
    .scroll-content .status-update-card {
        margin: 8px !important;
        max-width: 1100px;
        padding: 14px 16px;
    }
    .scroll-content .form-box {
        margin: 8px !important;
        max-width: 1100px;
    }
    .scroll-content .action-btn-container {
        max-width: 1100px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        padding: 8px !important;
    }
    .scroll-content .action-btn-container .blue-action-btn {
        width: auto;
        max-width: none;
        padding: 12px 20px;
    }
    .scroll-content .info-row {
        padding: 10px 16px;
    }
    /* Detail bottom action buttons in a row */
    .detail-actions-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        max-width: 1100px;
        margin: 8px;
        padding: 0;
    }
    .detail-actions-row > a,
    .detail-actions-row > button {
        flex: 1 1 200px;
        width: auto !important;
        max-width: 280px;
    }
    .info-table-list { margin: 12px 8px; max-width: 900px; }
    .detail-header-card { padding: 20px 24px; }
    .action-btn-container { padding: 0 8px; max-width: 900px; }
    .blue-action-btn { max-width: 320px; }

    /* Forms / admin cards denser on desktop */
    .surveyor-form-card,
    .admin-module-panel { max-width: 720px; }
    .admin-tabs-bar { padding: 12px 8px 4px; max-width: 1100px; }
    .admin-module-panel.active { padding: 12px 8px 20px; max-width: 1100px; }

    /* FAB overlay stays available but rarely needed on desktop */
    .fab-overlay { left: 252px; }

    /* Profile dropdown still usable in top bar */
    .profile-menu-wrap { z-index: 1200; }

    /* Dashboard quick links: Formats / Permission Copy / Vessel Lineups — one neat row */
    .dashboard-action-links {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        padding: 12px 8px 8px;
        max-width: 1200px;
        margin: 0;
    }
    .dashboard-action-links .dashboard-action-item {
        margin: 0 !important;
        padding: 0 !important;
        max-width: none;
    }
    .dashboard-action-links .dashboard-action-item > a {
        min-height: 72px;
        transition: box-shadow .18s ease, transform .18s ease;
    }
    .dashboard-action-links .dashboard-action-item > a:hover {
        box-shadow: 0 8px 20px rgba(15,23,42,.08) !important;
        transform: translateY(-1px);
    }
}

/* 🌟 Global mobile-friendliness & polish additions */
html { -webkit-text-size-adjust: 100%; overflow-x: hidden; }
*, *::before, *::after { box-sizing: border-box; }
img, svg { max-width: 100%; height: auto; }
a, button, .nav-item-btn, .hamburger-menu-btn, .profile-menu-trigger, .btn {
    transition: background-color .18s ease, transform .18s ease, box-shadow .18s ease, opacity .18s ease;
}
a:active, button:active, .btn:active { transform: scale(0.97); }
.btn, button, input[type="submit"], input[type="button"], .nav-item-btn, .fab-option-item, a.info-value, .info-row {
    min-height: 40px;
}
input, select, textarea, .form-control { font-size: 16px; } /* prevents iOS auto-zoom on focus */
@media (max-width: 480px) {
    .btn, button:not(.profile-menu-trigger):not(.hamburger-menu-btn) { min-height: 44px; }
}
    </style>
</head>
<body>
<div class="mobile-container">
    <!-- 🌟 ఆండ్రాయిడ్ నేటివ్ స్టైల్ టోస్ట్ అలర్ట్ ఇంజన్ -->
    <?php if (isset($_SESSION['flash_msg'])): ?>
        <div id="nativeAppToast" style="position: absolute; top: 20px; left: 20px; right: 20px; background: #1e293b; color: #ffffff; padding: 12px 16px; border-radius: 12px; font-size: 13px; font-weight: 600; text-align: center; z-index: 9999; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); display: flex; align-items: center; justify-content: center; gap: 8px; animation: slideDownToast 0.3s ease-out;">
            <i class="fa-solid fa-circle-check text-success fs-5"></i>
            <span><?= $_SESSION['flash_msg']; ?></span>
        </div>
        <style>
            @keyframes slideDownToast {
                from { transform: translateY(-50px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
        </style>
        <script>
            // 3 సెకన్ల తర్వాత అలర్ట్ ఆటోమేటిక్‌గా మాయమవ్వడం
            setTimeout(function() {
                var toast = document.getElementById('nativeAppToast');
                if(toast) {
                    toast.style.transition = 'opacity 0.5s ease';
                    toast.style.opacity = '0';
                    setTimeout(function() { toast.remove(); }, 500);
                }
            }, 3000);
        </script>
        <?php unset($_SESSION['flash_msg']); // చూపించిన తర్వాత క్లియర్ చేయడం ?>
    <?php endif; ?>

    <?php
    // 🌟 Dismissible top alert banner — replaces the old bell/notification
    // panel entirely. Only two alerts ever show here: "New vessel assigned"
    // to a Surveyor, and "Report uploaded by X" to an Admin. Opening the
    // link or tapping X marks the notification read, so it never shows
    // again (a fresh assignment/upload creates a new one).
    if (!empty($_SESSION['user_id'])) {
        $__bannerNotif = null;
        try {
            if (!function_exists('getTopBannerNotification')) {
                require_once __DIR__ . '/notifications.php';
            }
            $__bannerRole = $_SESSION['role'] ?? '';
            $__bannerTypes = $__bannerRole === 'Admin' ? ['upload', 'report'] : ($__bannerRole === 'Surveyor' ? ['assign'] : []);
            if (!empty($__bannerTypes) && function_exists('getDB')) {
                $__bannerDb = getDB();
                $__bannerNotif = getTopBannerNotification($__bannerDb, (int)$_SESSION['user_id'], $__bannerTypes);
            }
        } catch (Throwable $e) {
            $__bannerNotif = null;
        }
        if ($__bannerNotif) {
            $__bannerIsUpload = in_array($__bannerNotif['type'], ['upload', 'report'], true);
            if ($__bannerIsUpload) {
                $__bannerText = 'Uploaded by ' . ($__bannerNotif['creator_name'] ?: 'Surveyor') . ' — ' . $__bannerNotif['title'];
            } else {
                $__bannerText = $__bannerNotif['title'] . ($__bannerNotif['message'] !== '' ? ' — ' . $__bannerNotif['message'] : '');
            }
            $__bannerLink = !empty($__bannerNotif['link']) ? $__bannerNotif['link'] : 'index.php';
            $__bannerIcon = $__bannerIsUpload ? 'fa-file-arrow-up' : 'fa-ship';
            ?>
            <div class="top-alert-banner" id="topAlertBanner" data-notif-id="<?= (int)$__bannerNotif['id'] ?>" data-testid="top-alert-banner">
                <i class="fa-solid <?= $__bannerIcon ?> top-alert-banner-icon"></i>
                <a href="<?= sanitize($__bannerLink) ?>" class="top-alert-banner-link" id="topAlertBannerLink" data-testid="top-alert-banner-link"><?= sanitize($__bannerText) ?></a>
                <button type="button" class="top-alert-banner-close" id="topAlertBannerClose" aria-label="Dismiss" data-testid="top-alert-banner-close">&times;</button>
            </div>
            <script>
            (function() {
                var wrap = document.getElementById('topAlertBanner');
                if (!wrap) return;
                var id = wrap.getAttribute('data-notif-id');
                function dismiss() {
                    try {
                        var body = 'action=read&id=' + encodeURIComponent(id);
                        if (navigator.sendBeacon) {
                            navigator.sendBeacon('ajax/notifications.php', new Blob([body], {type: 'application/x-www-form-urlencoded'}));
                        } else {
                            fetch('ajax/notifications.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body, keepalive: true }).catch(function(){});
                        }
                    } catch (e) {}
                }
                var closeBtn = document.getElementById('topAlertBannerClose');
                if (closeBtn) {
                    closeBtn.addEventListener('click', function() {
                        dismiss();
                        wrap.style.transition = 'opacity .25s ease';
                        wrap.style.opacity = '0';
                        setTimeout(function() { wrap.remove(); }, 250);
                    });
                }
                var link = document.getElementById('topAlertBannerLink');
                if (link) {
                    link.addEventListener('click', function() { dismiss(); });
                }
            })();
            </script>
            <?php
        }
    }
    ?>