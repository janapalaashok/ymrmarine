<?php
/**
 * Service page helpers – auto-migrate columns, slugify, seed defaults.
 */

function ensureServicePageColumns(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = [
        'slug'             => "VARCHAR(160) NULL DEFAULT NULL",
        'meta_title'       => "VARCHAR(200) NULL DEFAULT NULL",
        'meta_description' => "TEXT NULL",
        'meta_keywords'    => "VARCHAR(255) NULL DEFAULT NULL",
        'hero_image'       => "VARCHAR(255) NULL DEFAULT NULL",
        'hero_subtitle'    => "TEXT NULL",
        'overview_title'   => "VARCHAR(200) NULL DEFAULT NULL",
        'overview_body'    => "TEXT NULL",
        'overview_body2'   => "TEXT NULL",
        'features_json'    => "MEDIUMTEXT NULL",
        'process_json'     => "MEDIUMTEXT NULL",
        'faq_json'         => "MEDIUMTEXT NULL",
        'who_body'         => "TEXT NULL",
        'page_image'       => "VARCHAR(255) NULL DEFAULT NULL",
        'cta_text'         => "VARCHAR(120) NULL DEFAULT NULL",
    ];

    $existing = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `services`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $existing[strtolower($c['Field'])] = true;
        }
    } catch (Exception $e) {
        return;
    }

    foreach ($cols as $name => $def) {
        if (!isset($existing[$name])) {
            try {
                $pdo->exec("ALTER TABLE `services` ADD COLUMN `$name` $def");
            } catch (Exception $e) {
                error_log("ensureServicePageColumns $name: " . $e->getMessage());
            }
        }
    }

    // Unique index on slug (ignore if exists)
    try {
        $pdo->exec("CREATE UNIQUE INDEX `uq_services_slug` ON `services` (`slug`)");
    } catch (Exception $e) { /* already exists */ }
}

function serviceSlugify(string $title): string {
    $s = strtolower(trim($title));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-') ?: 'service';
}

function servicePageUrl(array $s): string {
    $slug = $s['slug'] ?? '';
    if ($slug === '') {
        $slug = serviceSlugify($s['title'] ?? 'service');
    }
    return 'service.php?slug=' . rawurlencode($slug);
}

/** Default dummy hero images (Unsplash – free to use) */
function serviceDefaultHero(string $slug): string {
    $map = [
        'bunker-survey' => 'https://images.unsplash.com/photo-1605745341112-85968b19335b?q=80&w=1600&auto=format&fit=crop',
        'pre-purchase-inspection-survey' => 'https://images.unsplash.com/photo-1569263979104-865ab7cd8d13?q=80&w=1600&auto=format&fit=crop',
        'draft-survey' => 'https://images.unsplash.com/photo-1544551763-46a013bb70d5?q=80&w=1600&auto=format&fit=crop',
        'cargo-survey' => 'https://images.unsplash.com/photo-1494412574643-ff11b5a2ec63?q=80&w=1600&auto=format&fit=crop',
        'condition-survey' => 'https://images.unsplash.com/photo-1605745341112-85968b19335b?q=80&w=1600&auto=format&fit=crop',
        'on-hire-off-hire' => 'https://images.unsplash.com/photo-1578575437130-527eed3abbec?q=80&w=1600&auto=format&fit=crop',
    ];
    return $map[$slug] ?? 'https://images.unsplash.com/photo-1569263979104-865ab7cd8d13?q=80&w=1600&auto=format&fit=crop';
}

function serviceDefaultPageImage(string $slug): string {
    return 'https://images.unsplash.com/photo-1464037866556-6812c9d1c88e?q=80&w=1200&auto=format&fit=crop';
}

/**
 * Seed page content for a service if slug/overview are empty.
 * Content is original – written for YMR Marine.
 */
function serviceDefaultContent(array $s): array {
    $title = $s['title'] ?? 'Marine Survey';
    $slug  = $s['slug'] ?: serviceSlugify($title);
    $short = $s['description'] ?? '';

    $defaults = [
        'bunker-survey' => [
            'meta_title' => 'Bunker Survey Services | Fuel Quantity Verification | YMR Marine',
            'meta_description' => 'Professional bunker survey for accurate fuel measurement during bunkering, on-hire and off-hire. Independent quantity reports across Indian and international ports.',
            'meta_keywords' => 'Bunker Survey, Bunker Quantity Survey, ROB Survey, On Hire Bunker Survey, Marine Fuel Survey, Ship Bunker Inspection India',
            'hero_subtitle' => 'Independent measurement and verification of bunker fuel quantities — clear figures for owners, charterers and suppliers.',
            'overview_title' => 'Accurate bunker quantity surveys',
            'overview_body' => "Bunker fuel is one of the largest operating costs for any ship. Disputes over delivered quantity, density or temperature can quickly turn into claims. A bunker survey by an independent marine surveyor establishes the quantity of fuel on board or transferred during bunkering, using recognised measurement practices and calibrated equipment.\n\nYMR Marine surveyors attend tankers, bulk carriers and other commercial vessels at major Indian ports and selected international locations. We measure remaining on board (ROB), delivered quantity and, where required, sample for quality checks arranged by the client.",
            'overview_body2' => 'Reports are written for commercial use — owners, charterers, bunker suppliers and claims handlers — with tank tables, temperatures, densities and calculated volumes set out clearly.',
            'features' => [
                ['icon' => 'fa-gas-pump', 'title' => 'Delivery measurement', 'body' => 'Quantity of bunkers received during bunkering operations, with temperature and density applied correctly.'],
                ['icon' => 'fa-oil-can', 'title' => 'ROB surveys', 'body' => 'Remaining on board at delivery, redelivery or mid-voyage for charter accounting and consumption checks.'],
                ['icon' => 'fa-flask', 'title' => 'Sampling support', 'body' => 'Witnessing of bunker samples when required by charter party or commercial agreement.'],
                ['icon' => 'fa-file-alt', 'title' => 'Clear reports', 'body' => 'Structured quantity reports suitable for owners, charterers and suppliers within agreed turnaround times.'],
            ],
            'process' => [
                ['title' => 'Instruction', 'body' => 'Confirm vessel, port, timing and whether delivery, ROB or both are required.'],
                ['title' => 'Attendance', 'body' => 'Surveyor boards at the agreed time, reviews tank tables and carries out measurements.'],
                ['title' => 'Calculation', 'body' => 'Volumes corrected for temperature and density; figures agreed or noted with ship’s staff.'],
                ['title' => 'Report', 'body' => 'Written bunker survey report issued promptly after attendance.'],
            ],
            'faq' => [
                ['q' => 'When should I book a bunker survey?', 'a' => 'At the start and end of a time charter, during bunkering from barge or shore, and whenever remaining quantity needs independent verification for claims or accounting.'],
                ['q' => 'Do you survey at night or weekends?', 'a' => 'Yes. Bunker operations often run outside office hours. YMR Marine provides 24/7 attendance subject to prior notice and port access.'],
                ['q' => 'What information do you need before attendance?', 'a' => 'Vessel name, port or anchorage, ETA, bunker grade and approximate quantity, and whether the survey is for delivery, ROB or both.'],
            ],
            'who_body' => 'Bunker surveys are requested by shipowners, charterers, bunker traders and P&I clubs whenever fuel quantity must be established independently. Combining a bunker survey with an on-hire or off-hire condition survey is common at the same port call.',
            'cta_text' => 'Request a Bunker Survey',
        ],
        'pre-purchase-inspection-survey' => [
            'meta_title' => 'Pre Purchase Inspection Survey | Ship & Vessel Condition Assessment | YMR Marine',
            'meta_description' => 'Independent Pre-Purchase Inspection Survey for ships and vessels. Detailed technical condition reports for buyers, banks and insurers across Indian and international ports.',
            'meta_keywords' => 'Pre Purchase Inspection, Ship Pre Purchase Inspection, Vessel Pre Purchase Survey, Marine Condition Assessment, Second-hand Ship Inspection, YMR Marine',
            'hero_subtitle' => 'Independent condition assessment before you buy — hull, machinery, class status and documentation in one clear report.',
            'overview_title' => 'What a Pre-Purchase Inspection covers',
            'overview_body' => "Buying a second-hand vessel involves significant capital and operational risk. A Pre-Purchase Inspection Survey is an impartial technical review by an experienced marine surveyor. The goal is not to pass or fail the ship, but to document present condition, outstanding class items and likely near-term maintenance so commercial negotiations rest on facts.\n\nYMR Marine Solutions LLP has conducted Pre-Purchase inspections for bulk carriers, tankers, general cargo ships, offshore support vessels and smaller commercial craft at ports across India, with international mobilisation available when required.",
            'overview_body2' => 'Reports include photographs and prioritised observations suitable for buyers, lenders and underwriters.',
            'features' => [
                ['icon' => 'fa-ship', 'title' => 'Hull & structure', 'body' => 'External and accessible internal structure, coating condition, deformation, corrosion and previous repairs.'],
                ['icon' => 'fa-cogs', 'title' => 'Machinery & systems', 'body' => 'Main and auxiliary engines, pumps, electrical plant, steering and cargo-related machinery where accessible.'],
                ['icon' => 'fa-certificate', 'title' => 'Class & statutory status', 'body' => 'Class certificates, outstanding recommendations, survey windows and flag-state documentation.'],
                ['icon' => 'fa-life-ring', 'title' => 'Safety & LSA', 'body' => 'Life-saving appliances, fire-fighting equipment, navigation aids and general safety readiness.'],
                ['icon' => 'fa-folder-open', 'title' => 'Records & history', 'body' => 'Maintenance logs, dry-dock history, incident records and known commercial defects.'],
                ['icon' => 'fa-file-alt', 'title' => 'Written report', 'body' => 'Clear findings with photographs and prioritised points for commercial decision-makers.'],
            ],
            'process' => [
                ['title' => 'Brief & scope', 'body' => 'Share vessel particulars, intended trade and any specific concerns. We confirm scope, location and timeline.'],
                ['title' => 'On-board survey', 'body' => 'Surveyor attends at the agreed port or anchorage and carries out the physical and document review.'],
                ['title' => 'Findings discussion', 'body' => 'Key observations can be shared early so commercial teams are not left waiting.'],
                ['title' => 'Formal report', 'body' => 'Structured written report with photos, typically within 48–72 hours of attendance.'],
            ],
            'faq' => [
                ['q' => 'What is a Pre-Purchase Inspection Survey?', 'a' => 'An independent technical assessment of a vessel’s condition before ownership transfer, covering accessible structure, machinery, class status, safety equipment and documentation.'],
                ['q' => 'How long does it take?', 'a' => 'On-board time is usually one to three days depending on vessel size. A formal report is typically issued within 48–72 hours after attendance.'],
                ['q' => 'Do you survey outside India?', 'a' => 'Yes. Core coverage is major Indian ports; international attendance is arranged on request subject to logistics and local support.'],
            ],
            'who_body' => 'Pre-Purchase Inspection reports are commissioned by shipowners and asset managers, by banks and lessors as part of financing due diligence, and by insurers assessing risk on a change of ownership.',
            'cta_text' => 'Request a Pre-Purchase Inspection',
        ],
        'draft-survey' => [
            'meta_title' => 'Draft Survey Services | Cargo Weight Determination | YMR Marine',
            'meta_description' => 'Professional draft survey for accurate determination of cargo weight by displacement. Independent draft surveyors at Indian ports for bulk and breakbulk cargoes.',
            'meta_keywords' => 'Draft Survey, Cargo Weight Survey, Displacement Survey, Bulk Cargo Survey, Marine Draft Surveyor India, YMR Marine',
            'hero_subtitle' => 'Precise cargo weight by systematic draft readings — the industry standard for bulk commodity measurement.',
            'overview_title' => 'Draft survey for bulk and breakbulk cargo',
            'overview_body' => "A draft survey determines the weight of cargo loaded or discharged by measuring the vessel’s displacement before and after the operation. It is widely used for coal, iron ore, grains, fertilisers and other bulk commodities where shore scales are unavailable or commercial parties require an independent figure.\n\nYMR Marine surveyors apply recognised draft survey methods, including density of dock water, trim and list corrections, and consumable adjustments, to produce a defensible weight result.",
            'overview_body2' => 'Initial and final surveys can be combined with bunker ROB readings when charter or sales contracts require both.',
            'features' => [
                ['icon' => 'fa-ruler-combined', 'title' => 'Initial & final drafts', 'body' => 'Forward, aft and midship draft readings with trim and list accounted for correctly.'],
                ['icon' => 'fa-water', 'title' => 'Dock water density', 'body' => 'Measured density applied so displacement reflects actual water conditions.'],
                ['icon' => 'fa-balance-scale', 'title' => 'Cargo weight result', 'body' => 'Net cargo weight after deducting ballast, bunkers and other variables as applicable.'],
                ['icon' => 'fa-file-alt', 'title' => 'Survey report', 'body' => 'Full calculation sheet and summary suitable for shippers, receivers and traders.'],
            ],
            'process' => [
                ['title' => 'Instruction', 'body' => 'Confirm vessel, berth, cargo and whether initial, final or both surveys are needed.'],
                ['title' => 'Initial survey', 'body' => 'Drafts, density and tank soundings before loading or discharge begins.'],
                ['title' => 'Final survey', 'body' => 'Repeat measurements after cargo operations complete.'],
                ['title' => 'Report', 'body' => 'Calculated cargo weight issued in a clear draft survey report.'],
            ],
            'faq' => [
                ['q' => 'Is draft survey accurate enough for commercial settlement?', 'a' => 'When performed carefully with correct density and tank data, draft survey is the accepted method for bulk cargo weight in many trades and contracts.'],
                ['q' => 'Can you attend both ends of the voyage?', 'a' => 'Yes, subject to port coverage. Many clients book YMR at load and discharge ports for consistency.'],
            ],
            'who_body' => 'Draft surveys are used by cargo traders, shippers, receivers, shipowners and charterers whenever cargo weight must be established independently of shore scales.',
            'cta_text' => 'Request a Draft Survey',
        ],
        'cargo-survey' => [
            'meta_title' => 'Cargo Survey Services | Load, Discharge & Condition | YMR Marine',
            'meta_description' => 'Cargo survey for bulk, breakbulk, containers and project cargo — condition, tally and damage assessment at load and discharge ports across India.',
            'meta_keywords' => 'Cargo Survey, Cargo Condition Survey, Tally Survey, Load Discharge Inspection, Project Cargo Survey, Marine Cargo Surveyor India',
            'hero_subtitle' => 'Inspection, tally and condition assessment for bulk, breakbulk, container and project cargoes.',
            'overview_title' => 'Cargo surveys at load and discharge',
            'overview_body' => "Cargo can be damaged, short-landed or contaminated during loading, carriage or discharge. An independent cargo survey records condition, quantity and handling so responsibility can be allocated fairly under bills of lading, charter parties and insurance policies.\n\nYMR Marine attends bulk and breakbulk operations, general cargo, project and heavy-lift shipments, and containerised cargo when required for damage or shortage claims.",
            'overview_body2' => 'Surveyors document findings with photographs and, where appropriate, recommend practical steps to limit further loss.',
            'features' => [
                ['icon' => 'fa-boxes', 'title' => 'Condition on arrival', 'body' => 'Visual inspection of cargo and packaging at discharge or intermediate ports.'],
                ['icon' => 'fa-clipboard-list', 'title' => 'Tally & quantity', 'body' => 'Piece counts and quantity checks for bagged, unitised or breakbulk cargo.'],
                ['icon' => 'fa-exclamation-triangle', 'title' => 'Damage assessment', 'body' => 'Nature, extent and likely cause of damage for claims and recovery.'],
                ['icon' => 'fa-camera', 'title' => 'Photo evidence', 'body' => 'Systematic photography supporting written findings.'],
            ],
            'process' => [
                ['title' => 'Instruction', 'body' => 'Cargo type, vessel, port and whether load, discharge or damage survey is required.'],
                ['title' => 'Attendance', 'body' => 'Surveyor inspects cargo, holds or containers and relevant documents.'],
                ['title' => 'Documentation', 'body' => 'Notes, photographs and discussions with ship and terminal staff.'],
                ['title' => 'Report', 'body' => 'Written cargo survey report for principals and underwriters.'],
            ],
            'faq' => [
                ['q' => 'Do you survey project and heavy-lift cargo?', 'a' => 'Yes. We attend project cargo and heavy-lift shipments for condition before and after loading or discharge when instructed.'],
                ['q' => 'Can the survey support an insurance claim?', 'a' => 'Reports are prepared with underwriters and claims handlers in mind, including clear description of damage and supporting images.'],
            ],
            'who_body' => 'Cargo surveys are requested by cargo owners, freight forwarders, shipowners, charterers and insurers whenever condition or quantity needs independent record.',
            'cta_text' => 'Request a Cargo Survey',
        ],
        'condition-survey' => [
            'meta_title' => 'Condition Survey Services | Hull & Machinery Assessment | YMR Marine',
            'meta_description' => 'Marine condition survey for hull, machinery and equipment — for P&I, insurance, pre-entry or operational assessment at Indian ports.',
            'meta_keywords' => 'Condition Survey, Hull Condition Survey, Machinery Survey, P&I Condition Survey, Marine Condition Assessment, YMR Marine',
            'hero_subtitle' => 'Comprehensive assessment of hull, machinery and equipment for P&I, insurance or operational purposes.',
            'overview_title' => 'Condition surveys for risk and operations',
            'overview_body' => "A condition survey provides a structured snapshot of a vessel’s physical state. It may be required by P&I clubs for entry or follow-up, by hull underwriters after an incident, or by owners and managers as part of technical oversight.\n\nYMR Marine surveyors examine accessible areas of hull and structure, machinery spaces, deck equipment and safety systems, and review class and statutory status where relevant to the instruction.",
            'overview_body2' => 'Findings are prioritised so technical and commercial teams can act on the most important items first.',
            'features' => [
                ['icon' => 'fa-ship', 'title' => 'Hull & coatings', 'body' => 'Visible structure, coating condition and areas of concern above the waterline or in dry dock when arranged.'],
                ['icon' => 'fa-cogs', 'title' => 'Machinery spaces', 'body' => 'General condition of main and auxiliary machinery, cleanliness and obvious defects.'],
                ['icon' => 'fa-shield-alt', 'title' => 'Safety systems', 'body' => 'Life-saving and fire-fighting equipment readiness and certification status where checked.'],
                ['icon' => 'fa-file-alt', 'title' => 'Report for principals', 'body' => 'Clear written report with photographs for clubs, underwriters or owners.'],
            ],
            'process' => [
                ['title' => 'Scope agreement', 'body' => 'Confirm purpose (P&I, insurance, owner) and any focus areas.'],
                ['title' => 'On-board inspection', 'body' => 'Systematic walk-through of agreed spaces and systems.'],
                ['title' => 'Document review', 'body' => 'Class and statutory certificates and relevant logs as available.'],
                ['title' => 'Report delivery', 'body' => 'Condition survey report with prioritised findings.'],
            ],
            'faq' => [
                ['q' => 'Is a condition survey the same as a Pre-Purchase Inspection?', 'a' => 'They overlap in method but differ in purpose and depth. Pre-Purchase is tailored to acquisition risk; condition surveys often follow club or underwriter checklists.'],
                ['q' => 'Can you attend in dry dock?', 'a' => 'Yes, when the vessel is scheduled for docking and access is arranged through the yard and owners.'],
            ],
            'who_body' => 'Condition surveys are instructed by P&I clubs, hull underwriters, shipowners and technical managers for risk assessment and ongoing fleet quality control.',
            'cta_text' => 'Request a Condition Survey',
        ],
        'on-hire-off-hire' => [
            'meta_title' => 'On-Hire & Off-Hire Survey | Charter Condition & Bunkers | YMR Marine',
            'meta_description' => 'On-hire and off-hire surveys documenting vessel condition and bunker ROB at charter start and end — protecting owners and charterers from disputes.',
            'meta_keywords' => 'On Hire Survey, Off Hire Survey, Charter Survey, Delivery Redelivery Survey, Bunker ROB On Hire, YMR Marine',
            'hero_subtitle' => 'Condition and bunker surveys at charter delivery and redelivery — clear baselines for both parties.',
            'overview_title' => 'On-hire and off-hire surveys',
            'overview_body' => "When a vessel enters or leaves a time charter, both owner and charterer need an agreed record of condition and remaining bunkers. An on-hire survey at delivery and an off-hire survey at redelivery establish that baseline and reduce the scope for later disputes over damage or fuel.\n\nYMR Marine combines condition inspection of holds, decks and relevant spaces with bunker ROB measurement in a single attendance when required.",
            'overview_body2' => 'Reports are written so commercial and operational teams can compare delivery and redelivery status side by side.',
            'features' => [
                ['icon' => 'fa-exchange-alt', 'title' => 'Delivery & redelivery', 'body' => 'Surveys timed to charter commencement and termination at the agreed port.'],
                ['icon' => 'fa-door-open', 'title' => 'Hold & deck condition', 'body' => 'Visible condition of cargo spaces, hatches and working decks relevant to the charter.'],
                ['icon' => 'fa-gas-pump', 'title' => 'Bunker ROB', 'body' => 'Remaining fuel quantities measured and reported with the condition findings.'],
                ['icon' => 'fa-file-alt', 'title' => 'Comparable reports', 'body' => 'Consistent format so on-hire and off-hire results can be compared easily.'],
            ],
            'process' => [
                ['title' => 'Charter briefing', 'body' => 'Delivery/redelivery port, dates and whether bunkers and condition are both required.'],
                ['title' => 'On-board survey', 'body' => 'Condition walk-through and bunker measurement as agreed.'],
                ['title' => 'Agreement of figures', 'body' => 'ROB and key observations noted with ship’s staff where appropriate.'],
                ['title' => 'Report', 'body' => 'On-hire or off-hire report issued for both commercial parties.'],
            ],
            'faq' => [
                ['q' => 'Should on-hire and off-hire be done by the same company?', 'a' => 'Using the same survey firm for both ends improves consistency of method and reporting, which helps when comparing condition and bunkers.'],
                ['q' => 'Can you include hold cleanliness?', 'a' => 'Yes. Hold condition and cleanliness for the intended cargo are often part of the on-hire scope when requested.'],
            ],
            'who_body' => 'On-hire and off-hire surveys are standard for time-charter delivery and redelivery, requested by owners, charterers and their commercial operators.',
            'cta_text' => 'Request On-Hire / Off-Hire Survey',
        ],
    ];

    $d = $defaults[$slug] ?? null;
    if (!$d) {
        // Generic fallback for any new service added in admin
        $d = [
            'meta_title' => $title . ' | YMR Marine Solutions',
            'meta_description' => $short ?: ("Professional {$title} services by YMR Marine Solutions across Indian and international ports."),
            'meta_keywords' => $title . ', Marine Survey, YMR Marine, Ship Inspection India',
            'hero_subtitle' => $short ?: ("Professional {$title} by experienced marine surveyors."),
            'overview_title' => 'About this service',
            'overview_body' => $short ?: ("YMR Marine provides professional {$title} services with clear reporting and reliable port attendance."),
            'overview_body2' => '',
            'features' => [
                ['icon' => 'fa-check-circle', 'title' => 'Experienced surveyors', 'body' => 'Attendance by qualified marine surveyors familiar with commercial port operations.'],
                ['icon' => 'fa-file-alt', 'title' => 'Clear reporting', 'body' => 'Written reports suitable for owners, charterers and underwriters.'],
                ['icon' => 'fa-clock', 'title' => 'Responsive scheduling', 'body' => '24/7 mobilisation subject to notice and port access.'],
                ['icon' => 'fa-globe', 'title' => 'Port coverage', 'body' => 'Major Indian ports with international attendance on request.'],
            ],
            'process' => [
                ['title' => 'Enquire', 'body' => 'Share vessel, port and required timing.'],
                ['title' => 'Confirm', 'body' => 'We confirm scope, surveyor and schedule.'],
                ['title' => 'Survey', 'body' => 'On-board attendance and measurements or inspection as agreed.'],
                ['title' => 'Report', 'body' => 'Written report delivered within the agreed timeframe.'],
            ],
            'faq' => [
                ['q' => 'How do I book this survey?', 'a' => 'Use the request form on this page or email our operations team with vessel name, port and preferred dates.'],
                ['q' => 'Where do you operate?', 'a' => 'Primary coverage is major Indian ports; international locations are arranged on request.'],
            ],
            'who_body' => 'This service is available to shipowners, charterers, traders, insurers and other maritime stakeholders who need independent survey support.',
            'cta_text' => 'Request This Survey',
        ];
    }

    return [
        'slug' => $slug,
        'meta_title' => $s['meta_title'] ?: $d['meta_title'],
        'meta_description' => $s['meta_description'] ?: $d['meta_description'],
        'meta_keywords' => $s['meta_keywords'] ?: $d['meta_keywords'],
        'hero_image' => $s['hero_image'] ?: serviceDefaultHero($slug),
        'hero_subtitle' => $s['hero_subtitle'] ?: $d['hero_subtitle'],
        'overview_title' => $s['overview_title'] ?: $d['overview_title'],
        'overview_body' => $s['overview_body'] ?: $d['overview_body'],
        'overview_body2' => $s['overview_body2'] ?: ($d['overview_body2'] ?? ''),
        'features' => json_decode($s['features_json'] ?? '', true) ?: $d['features'],
        'process' => json_decode($s['process_json'] ?? '', true) ?: $d['process'],
        'faq' => json_decode($s['faq_json'] ?? '', true) ?: $d['faq'],
        'who_body' => $s['who_body'] ?: $d['who_body'],
        'page_image' => $s['page_image'] ?: serviceDefaultPageImage($slug),
        'cta_text' => $s['cta_text'] ?: $d['cta_text'],
    ];
}

/**
 * Backfill slug + empty page fields for all services (run from admin or public).
 */
function seedServicePagesIfNeeded(PDO $pdo): void {
    ensureServicePageColumns($pdo);
    try {
        $rows = $pdo->query('SELECT * FROM services')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return;
    }
    foreach ($rows as $row) {
        $slug = $row['slug'] ?: serviceSlugify($row['title'] ?? 'service');
        $content = serviceDefaultContent(array_merge($row, ['slug' => $slug]));
        $needsUpdate = empty($row['slug']) || empty($row['overview_body']);
        if (!$needsUpdate) continue;
        try {
            $stmt = $pdo->prepare("UPDATE services SET
                slug = COALESCE(NULLIF(slug,''), ?),
                meta_title = COALESCE(NULLIF(meta_title,''), ?),
                meta_description = COALESCE(NULLIF(meta_description,''), ?),
                meta_keywords = COALESCE(NULLIF(meta_keywords,''), ?),
                hero_image = COALESCE(NULLIF(hero_image,''), ?),
                hero_subtitle = COALESCE(NULLIF(hero_subtitle,''), ?),
                overview_title = COALESCE(NULLIF(overview_title,''), ?),
                overview_body = COALESCE(NULLIF(overview_body,''), ?),
                overview_body2 = COALESCE(NULLIF(overview_body2,''), ?),
                features_json = COALESCE(NULLIF(features_json,''), ?),
                process_json = COALESCE(NULLIF(process_json,''), ?),
                faq_json = COALESCE(NULLIF(faq_json,''), ?),
                who_body = COALESCE(NULLIF(who_body,''), ?),
                page_image = COALESCE(NULLIF(page_image,''), ?),
                cta_text = COALESCE(NULLIF(cta_text,''), ?)
                WHERE id = ?");
            $stmt->execute([
                $content['slug'],
                $content['meta_title'],
                $content['meta_description'],
                $content['meta_keywords'],
                $content['hero_image'],
                $content['hero_subtitle'],
                $content['overview_title'],
                $content['overview_body'],
                $content['overview_body2'],
                json_encode($content['features'], JSON_UNESCAPED_UNICODE),
                json_encode($content['process'], JSON_UNESCAPED_UNICODE),
                json_encode($content['faq'], JSON_UNESCAPED_UNICODE),
                $content['who_body'],
                $content['page_image'],
                $content['cta_text'],
                $row['id'],
            ]);
        } catch (Exception $e) {
            error_log('seedServicePagesIfNeeded: ' . $e->getMessage());
        }
    }
}
