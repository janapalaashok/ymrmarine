<?php
/**
 * Survey certificates: one engine, the wording of each survey type in one place.
 *
 * The certificate is the first page of the survey report PDF (includes/survey_report_pdf.php), on the
 * same YMR letterhead as the tank report; the page made offline (assets/js/survey-report.js) draws the
 * same model from the same wording, sent to the page with the editor config.
 *
 * To change the wording, edit srCertTypes() below. To add a survey type, add an entry there (and, if a
 * report type should open with it, a line in SR_CERT_BY_KIND). Placeholders in the texts:
 *   {vessel}      vessel name as typed, in capitals            {particulars} ", under X Flag, Port of Registry Y, GRT n, NRT n"
 *   {owners}      Owners, in capitals                          (only the particulars that were entered; nothing is made up)
 *   {charterers}  Charterers, in capitals
 * A value that was not entered prints as "As per Master's Report" in the delivery / redelivery box and is
 * left out of the sentences; no value is ever invented.
 */
declare(strict_types=1);

/** Survey type a new report opens with, by report type (the user can change it in Survey Details). */
const SR_CERT_BY_KIND = ['offhire' => 'offhire', 'onhire' => 'onhire', 'offhire3' => 'offhire3', 'onhire3' => 'onhire3', 'offhire4' => 'offhire3', 'rob' => 'none'];
const SR_CERT_ASPER = "As per Master's Report";

function srCertTypes(): array {
    $calib = "as calculated from the vessel's calibration tables";
    $base = [
        // the three boxes at the top: the event's place / date / time (fields used when the report has them)
        'boxes' => ['place' => 'redelivery_place', 'date' => 'redelivery_date', 'time' => 'redelivery_time'],
        'sign_left' => ['Signed On behalf of', '{owners}'],
        'sign_right' => ['Signed On behalf of', '{charterers}'],
        'surveyor_title' => 'Independent Marine Surveyor',
        'without_prejudice' => '',
    ];
    return [
        'onhire' => [
            'label' => 'On-Hire Survey',
            'title' => 'CERTIFICATE OF ON-HIRE BUNKER QUANTITY',
            'event' => 'Delivery',
            'boxes' => ['place' => '', 'date' => '', 'time' => ''],
            'certify' => 'This is to certify that {vessel}{particulars}, Delivered by Messrs {owners} to Messrs {charterers}, subject to all terms, conditions and exceptions agreed between Owners and Charterers as per governing Charter Party.',
            'survey' => "The undersigned Independent Marine Surveyor, acting on behalf of Messrs {charterers}, attended on board and carried out an On-Hire Bunker Survey in order to determine the quantity of bunkers remaining on board on delivery of the vessel into the Charterers' service. All bunker tanks were carefully sounded in the presence of the Chief Engineer and the following quantities were found and agreed, $calib.",
            'consumption' => 'Consumption from time of survey to time of Delivery is calculated and verified with the Chief Engineer to be:',
            'onboard' => 'Consequently, the following Bunkers were on board at the time of Delivery:',
            'remark' => 'Master to confirm by E-Mail [ops@ymrmarine.com] Exact Bunker Quantities ROB at the time of delivery.',
        ] + $base,
        'offhire' => [
            'label' => 'Off-Hire Survey',
            'title' => 'CERTIFICATE OF OFF-HIRE BUNKER QUANTITY',
            'event' => 'Redelivery',
            'certify' => 'This is to certify that {vessel}{particulars}, Redelivered to Messrs {owners}, by Messrs {charterers}, subject to all terms, conditions and exceptions agreed between Owners and Charterers as per governing Charter Party.',
            'survey' => "The undersigned Independent Marine Surveyor, acting on behalf of Messrs {charterers}, carried out a Bunker Survey in order to determine the amount of bunkers remaining on board. All bunker tanks were carefully sounded in the presence of the Chief Engineer and the following quantities were found and agreed, $calib.",
            'consumption' => 'Consumption from time of survey to time of Redelivery is calculated and verified with the Chief Engineer to be:',
            'onboard' => 'Consequently, the following Bunkers were on board at the time of Redelivery:',
            'remark' => 'Master to confirm by E-Mail [ops@ymrmarine.com] Exact Bunker Quantities ROB at the time of redelivery.',
        ] + $base,
        'onhire3' => [
            'label' => 'Third Party On-Hire Survey',
            'title' => 'THIRD PARTY ON-HIRE BUNKER SURVEY CERTIFICATE',
            'event' => 'Delivery',
            'boxes' => ['place' => '', 'date' => '', 'time' => ''],
            'certify' => 'This is to certify that {vessel}{particulars}, was Delivered by Messrs {owners} (Owners) to Messrs {charterers} (Charterers), subject to all terms, conditions and exceptions agreed between Owners and Charterers as per governing Charter Party.',
            'survey' => "The undersigned Independent Marine Surveyor, attending as a neutral third party surveyor on behalf of both Owners and Charterers, carried out a joint On-Hire Bunker Survey in order to determine the quantity of bunkers remaining on board on delivery of the vessel. All bunker tanks were carefully sounded in the presence of the Chief Engineer and the following quantities were found and agreed between the parties, $calib.",
            'consumption' => 'Consumption from time of survey to time of Delivery is calculated and verified with the Chief Engineer to be:',
            'onboard' => 'Consequently, the following Bunkers were on board at the time of Delivery:',
            'remark' => 'Master to confirm by E-Mail [ops@ymrmarine.com] Exact Bunker Quantities ROB at the time of delivery.',
            'without_prejudice' => 'This certificate is issued by the undersigned as an independent third party, without prejudice to the rights of Owners and Charterers under the governing Charter Party.',
            'sign_left' => ['Signed On behalf of Owners', '{owners}'],
            'sign_right' => ['Signed as Independent Third Party', 'for Owners and Charterers'],
            'surveyor_title' => 'Independent Third Party Marine Surveyor',
        ] + $base,
        'offhire3' => [
            'label' => 'Third Party Off-Hire Survey',
            'title' => 'THIRD PARTY OFF-HIRE BUNKER SURVEY CERTIFICATE',
            'event' => 'Redelivery',
            'certify' => 'This is to certify that {vessel}{particulars}, was Redelivered by Messrs {charterers} (Charterers) to Messrs {owners} (Owners), subject to all terms, conditions and exceptions agreed between Owners and Charterers as per governing Charter Party.',
            'survey' => "The undersigned Independent Marine Surveyor, attending as a neutral third party surveyor on behalf of both Owners and Charterers, carried out a joint Off-Hire Bunker Survey in order to determine the quantity of bunkers remaining on board on redelivery of the vessel. All bunker tanks were carefully sounded in the presence of the Chief Engineer and the following quantities were found and agreed between the parties, $calib.",
            'consumption' => 'Consumption from time of survey to time of Redelivery is calculated and verified with the Chief Engineer to be:',
            'onboard' => 'Consequently, the following Bunkers were on board at the time of Redelivery:',
            'remark' => 'Master to confirm by E-Mail [ops@ymrmarine.com] Exact Bunker Quantities ROB at the time of redelivery.',
            'without_prejudice' => 'This certificate is issued by the undersigned as an independent third party, without prejudice to the rights of Owners and Charterers under the governing Charter Party.',
            'sign_left' => ['Signed On behalf of Owners', '{owners}'],
            'sign_right' => ['Signed as Independent Third Party', 'for Owners and Charterers'],
            'surveyor_title' => 'Independent Third Party Marine Surveyor',
        ] + $base,
    ];
}

/** Choices of the Survey Type list: the certificate types and "no certificate" (tank report only). */
function srCertChoices(): array {
    return array_map(fn($t) => $t['label'], srCertTypes()) + ['none' => 'No certificate (tank report only)'];
}

/** The survey type of a report: as chosen in Survey Details, else the one its report type opens with. */
function srCertType(array $data, string $kind): string {
    $t = (string)($data['extra']['survey_type'] ?? '');
    if ($t !== '' && isset(srCertChoices()[$t])) return $t;
    return SR_CERT_BY_KIND[$kind] ?? 'none';
}

/**
 * The certificate as a model both renderers draw (dompdf here, jsPDF on the page offline):
 * null when the report has no certificate. $mt: [grade => metric tons].
 */
function srCertModel(array $data, string $kind, array $grades, array $mt): ?array {
    $type = srCertType($data, $kind);
    $cfg = srCertTypes()[$type] ?? null;
    if (!$cfg) return null;
    $v = fn($k) => trim(srVal($data, $k));
    $up = fn($k) => mb_strtoupper($v($k), 'UTF-8');
    $parts = [];
    if ($v('flag') !== '') $parts[] = 'under ' . $up('flag') . ' Flag';
    if ($v('port_of_registry') !== '') $parts[] = 'Port of Registry ' . $up('port_of_registry');
    if ($v('grt') !== '') $parts[] = 'GRT ' . $v('grt');
    if ($v('nrt') !== '') $parts[] = 'NRT ' . $v('nrt');
    $rep = ['{vessel}' => $up('vessel_name'), '{particulars}' => $parts ? ', ' . implode(', ', $parts) : '', '{owners}' => $up('owners'), '{charterers}' => $up('charterers')];
    $fill = fn(string $s) => strtr($s, $rep);
    $box = function (string $f) use ($v): string {
        $x = $f !== '' ? $v($f) : '';
        if ($x !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) $x = sfDateText($x);
        return $x !== '' ? $x : SR_CERT_ASPER;
    };
    $date = $v('completed_date');
    return [
        'type' => $type, 'title' => $cfg['title'],
        'boxes' => [['Place of ' . $cfg['event'] . ':', $box($cfg['boxes']['place'])], ['Date of ' . $cfg['event'] . ':', $box($cfg['boxes']['date'])], ['Time of ' . $cfg['event'] . ':', $box($cfg['boxes']['time'])]],
        'completed' => [preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? sfDateText($date) : $date, $v('completed_time') !== '' ? $v('completed_time') . '  Hrs LT' : ''],
        'paras' => [$fill($cfg['certify']), $fill($cfg['survey'])],
        'qty' => array_map(fn($g) => [SR_GRADES[$g][1] ?? strtoupper($g), isset($mt[$g]) && is_numeric($mt[$g]) ? number_format((float)$mt[$g], 3, '.', '') : '—', 'M. Tons'], $grades),
        'consumption' => $fill($cfg['consumption']),
        'onboard' => $fill($cfg['onboard']),
        'asper' => array_map(fn($g) => [SR_GRADES[$g][1] ?? strtoupper($g), SR_CERT_ASPER], $grades),
        'remark' => $fill($cfg['remark']),
        'without_prejudice' => $fill($cfg['without_prejudice']),
        'sign_left' => array_map($fill, $cfg['sign_left']),
        'sign_right' => array_map($fill, $cfg['sign_right']),
        'master' => 'Capt. ' . $up('master'), 'chief' => 'Mr. ' . $up('chief_engineer'),
        'surveyor_title' => $cfg['surveyor_title'], 'surveyor' => 'Mr. ' . $up('surveyor_name'),
    ];
}

/** The certificate page (HTML for dompdf; $lh: the letterhead block of the report). Its sizes do not scale. */
function srCertHtml(array $m, string $lh): string {
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $rows = fn(array $list, string $cls) => '<table class="' . $cls . '">' . implode('', array_map(fn($r) => '<tr>' . implode('', array_map(fn($c, $i) => '<td class="c' . $i . '">' . $e($c) . '</td>', $r, array_keys($r))) . '</tr>', $list)) . '</table>';
    $sig = fn(string $title, array $lines) => '<div class="sb"></div><div class="sl"></div><div class="st">' . $e($title) . '</div>' . implode('', array_map(fn($l) => '<div class="sn">' . $e($l) . '</div>', $lines));
    return '<div class="cert">' . $lh .
        '<h2 class="ct">' . $e($m['title']) . '</h2>' .
        $rows($m['boxes'], 'cb') .
        '<table class="cc"><tr><td class="c0">Date / Time of Survey Completed:</td><td class="c1">' . $e($m['completed'][0]) . '</td><td class="c2">' . $e($m['completed'][1]) . '</td></tr></table>' .
        implode('', array_map(fn($p) => '<p class="cp">' . $e($p) . '</p>', $m['paras'])) .
        $rows($m['qty'], 'cq') .
        '<p class="cp">' . $e($m['consumption']) . '</p>' . $rows($m['asper'], 'ca') .
        '<p class="cp">' . $e($m['onboard']) . '</p>' . $rows($m['asper'], 'ca') .
        '<p class="cp cr"><b>REMARK:</b> ' . $e($m['remark']) . '</p>' .
        ($m['without_prejudice'] !== '' ? '<p class="cp cw">' . $e($m['without_prejudice']) . '</p>' : '') .
        '<table class="cs"><tr><td class="l"><div class="sh">' . $e($m['sign_left'][0]) . '<br>' . $e($m['sign_left'][1]) . '</div>' .
            $sig('The Master', [$m['master']]) . $sig('The Chief Engineer', [$m['chief']]) . '</td><td class="g"></td>' .
            '<td class="r"><div class="sh">' . $e($m['sign_right'][0]) . '<br>' . $e($m['sign_right'][1]) . '</div>' .
            $sig($m['surveyor_title'], ['For YMR MARINE SOLUTIONS LLP', $m['surveyor']]) . '</td></tr></table>' .
        '</div>';
}

/** Styles of the certificate page (not scaled with the tank report). */
function srCertCss(): string {
    return '
.cert { page-break-after: always; font-size: 9.4pt; }
.cert .ct { text-align: center; font-size: 13.5pt; text-decoration: underline; margin: 2pt 0 9pt; letter-spacing: .3pt; color: #000; }
.cert table { border-collapse: collapse; }
.cert .cb { width: 60%; margin: 0 0 7pt; }
.cert .cb td { border: 0.7pt solid #000; padding: 1.6pt 6pt; }
.cert .cb .c0 { width: 45%; text-align: center; } .cert .cb .c1 { text-align: center; }
.cert .cc { width: 100%; margin: 0 0 9pt; }
.cert .cc td { border: 0.9pt solid #000; padding: 2.2pt 6pt; font-weight: bold; white-space: nowrap; }
.cert .cc .c0 { width: 36%; } .cert .cc .c1 { width: 32%; text-align: center; } .cert .cc .c2 { text-align: left; }
.cert .cp { margin: 0 0 7pt; line-height: 1.34; text-align: justify; }
.cert .cq, .cert .ca { margin: 0 0 7pt 12%; }
.cert .cq td, .cert .ca td { padding: 0.8pt 8pt; }
.cert .cq .c0, .cert .ca .c0 { text-align: right; min-width: 52mm; }
.cert .cq .c1 { font-weight: bold; text-align: right; width: 24mm; } .cert .cq .c2 { padding-left: 14pt; }
.cert .cr { margin-top: 2pt; }
.cert .cw { font-style: italic; color: #1f2937; }
.cert .cs { width: 100%; margin-top: 4pt; page-break-inside: avoid; }
.cert .cs td { vertical-align: top; width: 46%; }
.cert .cs td.g { width: 8%; }
.cert .sh { line-height: 1.4; margin-bottom: 2pt; }
.cert .sb { height: 40pt; } /* room to sign and stamp */
.cert .sl { border-top: 0.8pt solid #000; height: 1pt; margin-bottom: 2pt; }
.cert .st { font-weight: bold; } .cert .sn { font-weight: bold; }
';
}
