<?php
/**
 * Static marketing pages (About, Ports, Contact) – content + SEO in DB.
 */

function ensurePageContentTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS page_content (
            page_key VARCHAR(40) NOT NULL PRIMARY KEY,
            meta_title VARCHAR(200) DEFAULT NULL,
            meta_description TEXT,
            meta_keywords VARCHAR(255) DEFAULT NULL,
            hero_image VARCHAR(255) DEFAULT NULL,
            hero_subtitle TEXT,
            body TEXT,
            body2 TEXT,
            cta_text VARCHAR(120) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log('ensurePageContentTable: ' . $e->getMessage());
    }
}

function getPageContent(PDO $pdo, string $key): array {
    ensurePageContentTable($pdo);
    seedPageContentIfNeeded($pdo);
    try {
        $stmt = $pdo->prepare('SELECT * FROM page_content WHERE page_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function savePageContent(PDO $pdo, string $key, array $data): void {
    ensurePageContentTable($pdo);
    $stmt = $pdo->prepare("INSERT INTO page_content
        (page_key, meta_title, meta_description, meta_keywords, hero_image, hero_subtitle, body, body2, cta_text)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
        meta_title=VALUES(meta_title),
        meta_description=VALUES(meta_description),
        meta_keywords=VALUES(meta_keywords),
        hero_image=VALUES(hero_image),
        hero_subtitle=VALUES(hero_subtitle),
        body=VALUES(body),
        body2=VALUES(body2),
        cta_text=VALUES(cta_text)");
    $stmt->execute([
        $key,
        $data['meta_title'] ?? '',
        $data['meta_description'] ?? '',
        $data['meta_keywords'] ?? '',
        $data['hero_image'] ?? '',
        $data['hero_subtitle'] ?? '',
        $data['body'] ?? '',
        $data['body2'] ?? '',
        $data['cta_text'] ?? '',
    ]);
}

function pageDefaultHero(string $key): string {
    $map = [
        'about'   => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?q=80&w=1600&auto=format&fit=crop',
        'ports'   => 'https://images.unsplash.com/photo-1494412574643-ff11b5a2ec63?q=80&w=1600&auto=format&fit=crop',
        'contact' => 'https://images.unsplash.com/photo-1578575437130-527eed3abbec?q=80&w=1600&auto=format&fit=crop',
    ];
    return $map[$key] ?? $map['about'];
}

function seedPageContentIfNeeded(PDO $pdo): void {
    ensurePageContentTable($pdo);
    $defaults = [
        'about' => [
            'meta_title' => 'About YMR Marine Solutions | Marine Survey Company Since 2006',
            'meta_description' => 'YMR Marine Solutions LLP provides independent marine surveys across India and selected international ports — bunker, draft, cargo, condition and pre-purchase inspections since 2006.',
            'meta_keywords' => 'YMR Marine, About YMR Marine Solutions, Marine Survey Company India, Ship Surveyors Mumbai, Marine Inspection Company',
            'hero_subtitle' => 'Independent marine surveyors delivering clear, commercial-grade reports for owners, charterers, traders and insurers.',
            'body' => "YMR Marine Solutions LLP is a marine surveying and consultancy firm based in Mumbai, serving clients at major Indian ports and selected locations overseas. Since 2006 we have supported shipowners, charterers, cargo interests and underwriters with independent inspection and quantity determination.\n\nOur work covers bunker surveys, draft surveys, cargo condition and tally, on-hire and off-hire inspections, condition surveys and pre-purchase assessments. Reports are written for commercial use — accurate, timely and easy to share with counterparties and claims teams.",
            'body2' => "We mobilise experienced surveyors who understand port operations and commercial deadlines. Whether the attendance is a single bunker ROB or a multi-day pre-purchase inspection, the priority is a defensible result and clear communication with the instructing party.",
            'cta_text' => 'Request a Survey',
        ],
        'ports' => [
            'meta_title' => 'Ports Covered | Marine Survey Attendance Across India | YMR Marine',
            'meta_description' => 'YMR Marine surveyors attend major Indian ports for bunker, draft, cargo and condition surveys. Port coverage list and enquiry for mobilisation.',
            'meta_keywords' => 'Marine Survey Ports India, Bunker Survey Visakhapatnam, Draft Survey Mumbai, Cargo Survey Chennai, Ship Survey Ports India, YMR Marine Coverage',
            'hero_subtitle' => 'Survey attendance at major Indian ports — with international mobilisation arranged on request.',
            'body' => "YMR Marine provides survey services at principal commercial ports across India. Attendance is scheduled around vessel ETAs, bunker windows and cargo operations so owners and charterers get coverage without unnecessary delay.\n\nThe list below reflects ports where we regularly mobilise. If your vessel is calling a location not listed, contact operations — coverage can often be arranged through our network.",
            'body2' => 'For urgent call-outs, share vessel name, port, survey type and required time. Our team confirms availability as quickly as possible, including nights and weekends where operations demand it.',
            'cta_text' => 'Enquire for Your Port',
        ],
        'contact' => [
            'meta_title' => 'Contact YMR Marine Solutions | Request a Marine Survey',
            'meta_description' => 'Contact YMR Marine Solutions LLP for bunker, draft, cargo, condition and pre-purchase surveys. Phone, email and enquiry form — response within the hour where possible.',
            'meta_keywords' => 'Contact YMR Marine, Marine Survey Enquiry, Ship Surveyor Contact India, Request Bunker Survey, YMR Marine Mumbai',
            'hero_subtitle' => 'Tell us the vessel, port and survey type — we confirm scope and timing promptly.',
            'body' => "Use the form on this page or reach us by phone or email. Include vessel name, port or anchorage, survey type and preferred dates so we can respond with a clear plan.",
            'body2' => '',
            'cta_text' => 'Send Enquiry',
        ],
    ];

    foreach ($defaults as $key => $d) {
        try {
            $stmt = $pdo->prepare('SELECT page_key, body FROM page_content WHERE page_key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['body'])) continue;
            $hero = pageDefaultHero($key);
            $pdo->prepare("INSERT INTO page_content
                (page_key, meta_title, meta_description, meta_keywords, hero_image, hero_subtitle, body, body2, cta_text)
                VALUES (?,?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                meta_title=IFNULL(NULLIF(meta_title,''), VALUES(meta_title)),
                meta_description=IFNULL(NULLIF(meta_description,''), VALUES(meta_description)),
                meta_keywords=IFNULL(NULLIF(meta_keywords,''), VALUES(meta_keywords)),
                hero_image=IFNULL(NULLIF(hero_image,''), VALUES(hero_image)),
                hero_subtitle=IFNULL(NULLIF(hero_subtitle,''), VALUES(hero_subtitle)),
                body=IFNULL(NULLIF(body,''), VALUES(body)),
                body2=IFNULL(NULLIF(body2,''), VALUES(body2)),
                cta_text=IFNULL(NULLIF(cta_text,''), VALUES(cta_text))")
                ->execute([
                    $key, $d['meta_title'], $d['meta_description'], $d['meta_keywords'],
                    $hero, $d['hero_subtitle'], $d['body'], $d['body2'], $d['cta_text'],
                ]);
        } catch (Exception $e) {
            error_log('seedPageContent ' . $key . ': ' . $e->getMessage());
        }
    }
}

/** Shared site chrome values */
function loadSiteChrome(): array {
    return [
        'siteName'   => getSetting('site_name', 'YMR Marine Solutions LLP'),
        'phone'      => getSetting('phone', '+91 982 048 2713'),
        'email'      => getSetting('email', 'ops@ymrmarine.com'),
        'logo'       => getSetting('logo', 'ymr_logo.png'),
        'empLogin'   => getSetting('employee_login_url', '#'),
        'primary'    => getSetting('primary_color', '#02bbff'),
        'address'    => getSetting('address'),
        'footerText' => getSetting('footer_text'),
        'copyright'  => getSetting('copyright'),
        'mapEmbed'   => getSetting('map_embed'),
    ];
}
