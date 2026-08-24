<?php
require_once 'config/config.php';
checkAuth();

// 🌟 9. Vessel Lineups (Indian Ports) - Ports index page
// ----------------------------------------------------------------------
// ఇది "Vessel Lineups (Indian Ports)" మెయిన్ ఆప్షన్ క్లిక్ చేస్తే వచ్చే కొత్త
// పేజీ. పోర్ట్‌ల లిస్ట్ + వాటి సోర్స్ URL లు includes/indian_ports.php లో
// ఉంటాయి - ఇది, vessel_line_up.php రెండూ అదే ఫైల్‌ను వాడతాయి కాబట్టి కొత్త
// పోర్ట్ యాడ్ చేయాలన్నా అక్కడ ఒక్కసారి మార్చితే సరిపోతుంది.
//
// 🌟 Major Ports (West Coast + East Coast, భారత ప్రభుత్వం గుర్తించిన మేజర్
// పోర్ట్‌లు) ముందుగా ఒక ప్రత్యేక సెక్షన్‌లో, మిగతా అన్ని పోర్ట్‌లు "Other Ports"
// సెక్షన్‌లో కింద చూపిస్తాం. ఈ స్లగ్‌ల లిస్ట్ ఇక్కడే (indian_ports.php లో ఉన్న
// స్లగ్‌లతో సరిపోల్చి) డిఫైన్ చేస్తున్నాం - దీన్లో లేనివి అన్నీ ఆటోమేటిక్‌గా Other Ports లోకి వెళ్తాయి.
// ----------------------------------------------------------------------

$major_port_slugs = [
    // West Coast
    'kandla-port', 'mumbai-port', 'goa-port-mormugao', 'new-mangalore-port', 'cochin-port',
    // East Coast
    'tuticorin-port', 'chennai-port', 'ennore-kamarajar-port', 'visakhapatnam-port', 'paradip-port', 'haldia-port',
];

$indian_ports = require __DIR__ . '/includes/indian_ports.php';

// అల్ఫాబెటికల్ ఆర్డర్ (కేస్-ఇన్సెన్సిటివ్) లో సార్ట్ చేయడం
usort($indian_ports, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});

$major_ports = [];
$other_ports = [];
foreach ($indian_ports as $port) {
    if (in_array($port['slug'], $major_port_slugs, true)) {
        $major_ports[] = $port;
    } else {
        $other_ports[] = $port;
    }
}
// Major Ports లిస్ట్‌ను, పైన ఇచ్చిన West Coast → East Coast క్రమంలోనే చూపించడానికి
usort($major_ports, function ($a, $b) use ($major_port_slugs) {
    return array_search($a['slug'], $major_port_slugs) <=> array_search($b['slug'], $major_port_slugs);
});

include 'includes/header.php';
?>
<style>
    .vl-page { padding: 22px 18px 110px; }
    .vl-heading { font-size: 20px; font-weight: 750; color: var(--text-dark); margin: 0 0 6px; }
    .vl-subtitle { color: var(--text-muted); font-size: 12.5px; margin-bottom: 16px; }
    .vl-search-wrap { position: relative; margin-bottom: 18px; }
    .vl-search-wrap i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
    .vl-search-input {
        width: 100%; border: 1px solid var(--border-color); border-radius: 12px; padding: 10px 14px 10px 36px;
        font-size: 13px; color: var(--text-dark); background: #fff; box-shadow: 0 2px 8px rgba(15,23,42,.03);
    }
    .vl-search-input:focus { outline: none; border-color: var(--accent-purple); }
    .vl-group-title { font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: .03em; margin: 18px 0 10px; }
    .vl-group-title:first-of-type { margin-top: 0; }
    .vl-port-list { display: flex; flex-direction: column; gap: 10px; }
    .vl-port-link {
        background: #fff; border: 1px solid var(--border-color); border-radius: 14px;
        padding: 13px 14px; display: flex; justify-content: space-between; align-items: center;
        text-decoration: none; box-shadow: 0 2px 8px rgba(15,23,42,.03);
    }
    .vl-port-left { display: flex; align-items: center; gap: 12px; }
    .vl-port-icon {
        width: 36px; height: 36px; border-radius: 10px; flex: 0 0 auto;
        background: rgba(59,50,179,.1); color: var(--accent-purple);
        display: flex; align-items: center; justify-content: center; font-size: 14px;
    }
    .vl-port-name { font-size: 13.5px; font-weight: 650; color: var(--text-dark); }
    .vl-no-results { font-size: 12.5px; color: var(--text-muted); padding: 10px 2px; display: none; }
</style>
<div class="scroll-content">
    <?php $page_title = 'Vessel Lineups (Indian Ports)'; $back_url = 'index.php'; $page_testid = 'vessel-lineups-list'; include 'includes/top_app_bar.php'; ?>
    <main class="vl-page" data-testid="vessel-lineups-list-page">
        <h2 class="vl-heading">Vessel Lineups (Indian Ports)</h2>
        <p class="vl-subtitle">Select a port to view its vessel schedule line-up.</p>

        <div class="vl-search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="vl-search-input" id="vl-port-search" placeholder="Search port..." data-testid="vessel-lineups-search-input" oninput="vlFilterPorts(this.value)">
        </div>

        <?php if (!empty($major_ports)): ?>
            <div class="vl-group-title" data-testid="vessel-lineups-group-major">Major Ports</div>
            <div class="vl-port-list" data-testid="vessel-lineups-port-list-major">
                <?php foreach ($major_ports as $port): ?>
                    <a href="vessel_line_up.php?port=<?= sanitize($port['slug']) ?>" class="vl-port-link vl-port-item" data-port-name="<?= sanitize(strtolower($port['name'])) ?>" data-testid="vessel-lineups-port-<?= sanitize($port['slug']) ?>">
                        <div class="vl-port-left">
                            <div class="vl-port-icon"><i class="fa-solid fa-anchor"></i></div>
                            <div class="vl-port-name"><?= sanitize($port['name']) ?></div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-secondary" style="font-size: 13px;"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($other_ports)): ?>
            <div class="vl-group-title" data-testid="vessel-lineups-group-other">Other Ports</div>
            <div class="vl-port-list" data-testid="vessel-lineups-port-list-other">
                <?php foreach ($other_ports as $port): ?>
                    <a href="vessel_line_up.php?port=<?= sanitize($port['slug']) ?>" class="vl-port-link vl-port-item" data-port-name="<?= sanitize(strtolower($port['name'])) ?>" data-testid="vessel-lineups-port-<?= sanitize($port['slug']) ?>">
                        <div class="vl-port-left">
                            <div class="vl-port-icon"><i class="fa-solid fa-anchor"></i></div>
                            <div class="vl-port-name"><?= sanitize($port['name']) ?></div>
                        </div>
                        <i class="fa-solid fa-chevron-right text-secondary" style="font-size: 13px;"></i>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p class="vl-no-results" id="vl-no-results" data-testid="vessel-lineups-no-results">No ports match your search.</p>
    </main>
</div>

<script>
    // 🌟 Client-side search: Major/Other రెండు గ్రూప్‌లలో ఉన్న పోర్ట్‌లను పేరు ఆధారంగా ఫిల్టర్ చేస్తుంది.
    // ఏ గ్రూప్ టైటిల్‌కైనా, ఆ గ్రూప్‌లో కనీసం ఒక్క పోర్ట్ కనిపిస్తేనే ఆ టైటిల్ చూపిస్తుంది.
    function vlFilterPorts(query) {
        query = query.trim().toLowerCase();
        var groups = document.querySelectorAll('.vl-port-list');
        var totalVisible = 0;

        groups.forEach(function (group) {
            var visibleInGroup = 0;
            group.querySelectorAll('.vl-port-item').forEach(function (item) {
                var matches = item.getAttribute('data-port-name').indexOf(query) !== -1;
                item.style.display = matches ? '' : 'none';
                if (matches) visibleInGroup++;
            });
            totalVisible += visibleInGroup;

            var heading = group.previousElementSibling;
            if (heading && heading.classList.contains('vl-group-title')) {
                heading.style.display = visibleInGroup > 0 ? '' : 'none';
            }
            group.style.display = visibleInGroup > 0 ? '' : 'none';
        });

        document.getElementById('vl-no-results').style.display = totalVisible === 0 ? 'block' : 'none';
    }
</script>

<?php include 'includes/nav.php'; include 'includes/footer.php'; ?>
