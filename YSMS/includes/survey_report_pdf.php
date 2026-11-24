<?php
/**
 * Survey report PDF in YMR's own format (no company Excel layout): the YMR letterhead
 * (assets/images/survey-letterhead.png) on every page, the vessel name as the title with the
 * report type and IMO number under it, then as the field report: owners, charterers, FWE /
 * survey started (with the log book BROB of each grade) / survey completed, place, voyage,
 * drafts with trim and list; one table per grade (Tank, Ref. Height, Sdg. Corr'd, Volume,
 * Density, Temp, V.C.F., G.S.V., Metric Tons) with its totals; the remark, the MT of each grade
 * and the signatures of the Master, the Chief Engineer and the surveyor.
 * Rendered by dompdf (installed by the Docker image, as for the invoices).
 *
 * The calculation methodology is not changed: V.C.F., G.S.V. and MT come from the report
 * template's own formulas (srBuildBook + srResults), exactly as in the Excel kept for the Word
 * report. Only the printed layout is YMR's.
 */
require_once __DIR__ . '/survey_reports.php';
require_once __DIR__ . '/survey_certificates.php';

/** dompdf ready on this server? */
function srOwnPdfAvailable(): bool {
    @include_once dirname(__DIR__) . '/vendor/autoload.php';
    return class_exists(\Dompdf\Dompdf::class);
}

/** Make the PDF of a report into $dest. → ['ok' => true] or ['ok' => false, 'error']. */
function srOwnPdf(array $tpl, array $report, string $dest): array {
    if (!srOwnPdfAvailable()) return ['ok' => false, 'error' => 'PDF engine (dompdf) is not installed on this server.'];
    try {
        [$book, $eff, , $where] = srBuildBook($tpl, $report['data']);
        $results = srResults($book, $eff, $report['data'], $where);
        $book->disconnectWorksheets();
        $opt = new \Dompdf\Options();
        $opt->set('isRemoteEnabled', false);
        $opt->set('isPhpEnabled', false);
        $opt->set('defaultFont', 'DejaVu Sans');
        $fontTmp = sys_get_temp_dir() . '/ysms-dompdf-fonts';
        if (!is_dir($fontTmp)) @mkdir($fontTmp, 0775, true);
        if (is_dir($fontTmp) && is_writable($fontTmp)) $opt->setFontCache($fontTmp);
        // the whole report on one A4 page, as the company's field report: printed at full size first,
        // then smaller (text, spacing, letterhead) until it fits on one page
        $pages = isset(srCertTypes()[srCertType($report['data'], (string)$tpl['kind'])]) ? 2 : 1; // the certificate page (survey type), then the tank report on one page
        $cs = 1.0; // the certificate on its one page: full size, else a little smaller (a long text, many grades)
        if ($pages === 2) foreach ([1, 0.95, 0.9, 0.85, 0.8, 0.75, 0.7] as $cs) {
            $pdf = new \Dompdf\Dompdf($opt);
            $pdf->loadHtml(srOwnPdfHtml($eff, $report, $tpl, $results, 1.0, $cs, true), 'UTF-8');
            $pdf->setPaper('A4', 'portrait'); $pdf->render();
            if ($pdf->getCanvas()->get_page_count() <= 1) break;
        }
        foreach ([1, 0.9, 0.8, 0.72, 0.65, 0.58, 0.52, 0.46, 0.41, 0.36, 0.32] as $k) {
            $pdf = new \Dompdf\Dompdf($opt);
            $pdf->loadHtml(srOwnPdfHtml($eff, $report, $tpl, $results, $k, $cs), 'UTF-8');
            $pdf->setPaper('A4', 'portrait');
            $pdf->render();
            if ($pdf->getCanvas()->get_page_count() <= $pages) break;
        }
        if ($pdf->getCanvas()->get_page_count() > $pages) // only past 60 tanks or so: page numbers then
            $pdf->getCanvas()->page_text(520, 818, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.39, 0.45, 0.55]);
        if (@file_put_contents($dest, $pdf->output()) === false) return ['ok' => false, 'error' => 'The PDF could not be saved.'];
        return ['ok' => true];
    } catch (\Throwable $e) {
        error_log('survey report pdf: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The PDF could not be made. Please try again.'];
    }
}

function srPdfE($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function srPdfNum($v, int $dec = 3): string { return $v === null || $v === '' || !is_numeric($v) ? '—' : number_format((float)$v, $dec, '.', ','); }
/** A tank reading as it is shown on the report (0.000 m, 0.0000, 00.0 °C), text such as GAUGE as typed. */
function srPdfReading(string $col, string $v): string {
    $n = str_replace(',', '', $v);
    if ($v === '') return '—';
    if (!is_numeric($n)) return strtoupper($v);
    $dec = ['ref_height' => 3, 'sounding' => 3, 'volume' => 3, 'density' => 4, 'temp' => 1][$col] ?? null;
    return $dec === null ? $v : number_format((float)$n, $dec, '.', ',');
}

/** The HTML dompdf prints. */
/** $k: print scale (1 = full size); text, spacing and the letterhead shrink with it. */
/** $cs: print scale of the certificate page; $certOnly: only that page (to find its scale). */
function srOwnPdfHtml(array $eff, array $report, array $tpl, array $results, float $k = 1.0, float $cs = 1.0, bool $certOnly = false): string {
    $data = $report['data'];
    $e = 'srPdfE';
    $val = fn($k) => srVal($data, $k);
    $date = fn($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $val($k)) ? sfDateText($val($k)) : $val($k);
    $grades = array_values(array_filter(srChosenGrades($eff, $data), fn($g) => srTankRows($data, $g)));
    $num = fn($k) => is_numeric($val($k)) ? (float)$val($k) : null;
    $up = fn($k) => mb_strtoupper($val($k), 'UTF-8'); // names print in capitals however they were typed

    // header block: the field report's own lines; the log book BROB of each grade beside FWE and Survey Started
    $brobHead = ''; $brobRow = ['fwe' => '', 'started' => ''];
    foreach ($grades as $g) $brobHead .= '<td class="bh">' . $e(SR_GRADES[$g][0]) . '</td>';
    foreach (['fwe', 'started'] as $p) foreach ($grades as $g) $brobRow[$p] .= '<td class="bv">' . ($val('brob_' . $p . '_' . $g) === '' ? '' : srPdfNum($val('brob_' . $p . '_' . $g))) . '</td>';
    $nb = count($grades);
    $line = function (string $label, string $v, string $time = '', string $right = '', bool $bold = false) use ($e) {
        $mid = $time === '' ? '<td class="hv" colspan="2">' . $e($v) . '</td>' : '<td class="hv">' . $e($v) . '</td><td class="ht">' . $e($time) . '</td>'; // no time: the value takes its box
        return '<tr' . ($bold ? ' class="bd"' : '') . '><td class="hl">' . $e($label) . '</td>' . $mid . '<td class="hu">' . ($time !== '' ? 'Hrs LT' : '') . '</td>' . $right . '</tr>';
    };
    $brobCells = fn(string $p) => $nb ? '<td class="bl">BROB:</td>' . $brobRow[$p] . '<td class="bu">M.Tons</td>' : '';
    $blank = $nb ? '<td></td>' . str_repeat('<td></td>', $nb) . '<td></td>' : '';
    $head = '<table class="hd">' .
        $line('Owners', $up('owners'), '', $blank) .
        $line('Charterers', $up('charterers'), '', $nb ? '<td></td>' . $brobHead . '<td></td>' : '') . // the grade names right above their BROB values
        $line('FWE', $date('fwe_date'), $val('fwe_time'), $brobCells('fwe')) .
        $line('Survey Started', $date('started_date'), $val('started_time'), $brobCells('started')) .
        $line('Survey Completed', $date('completed_date'), $val('completed_time'), $blank, true) .
        $line('Place of Survey', $val('place'), '', $blank) .
        $line('Voyage No.', $val('voyage_no'), '', $blank) . '</table>';
    $fa = $num('draft_aft'); $ff = $num('draft_fwd');
    $dr = fn($l, $v, $u) => '<td class="hl">' . $l . '</td><td class="dv">' . ($v === null ? '' : $e($v)) . '</td><td class="du">' . $u . '</td>';
    $drafts = '<table class="dr"><tr>' . $dr('Draft Aft', $fa === null ? null : number_format($fa, 2, '.', ''), 'Meters') . '<td class="gap"></td>' .
        $dr('Trim:', $fa !== null && $ff !== null ? number_format(round($fa - $ff, 3), 2, '.', '') : null, 'Meters') . '</tr><tr>' .
        $dr('Draft Fwd', $ff === null ? null : number_format($ff, 2, '.', ''), 'Meters') . '<td class="gap"></td>' .
        $dr('List', $num('list') === null ? null : number_format($num('list'), 2, '.', ''), 'Degrees') . '</tr></table>';

    // one table per grade, as the field report
    $tanks = '';
    foreach ($grades as $g) {
        $res = $results['tanks'][$g] ?? []; $tot = $results['grades'][$g] ?? [];
        $rows = srTankRows($data, $g);
        $anyU = (bool)array_filter($rows, fn($r) => $r['su'] === 'u');
        $h = '<div class="grade"><h3>' . $e(SR_GRADES[$g][1]) . '</h3><table class="tk"><thead><tr>' .
            '<th class="tn">Tank<br>Name:</th><th>Ref. Height<br>(m)</th><th>' . ($anyU ? 'Sdg. / Ullage' : 'Sdg. Corr\'d') . '<br>(m)</th><th>Volume<br>Cubic Metres</th><th>Density<br>@15°C</th><th>Temp<br>Deg(C)</th><th>V.C.F.<br>T(54B)</th><th>G.S.V<br>m³ @ 15°C</th><th>Metric<br>Tons</th></tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            $x = $res[$i] ?? [];
            $h .= '<tr><td class="tn">' . $e(strtoupper($r['name'])) . '</td>';
            foreach (['ref_height', 'sounding', 'volume', 'density', 'temp'] as $c) {
                // the reading as chosen on the computer: "0.012 S" (sounding) / "1.250 U" (the ullage typed); none chosen, the plain value
                if ($c === 'sounding' && $r['su'] === 'u') { $h .= '<td>' . $e(srPdfReading($c, $r['ullage'])) . ' U</td>'; continue; }
                $h .= '<td>' . $e(srPdfReading($c, $r[$c])) . ($c === 'sounding' && $r['su'] === 's' ? ' S' : '') . '</td>';
            }
            $h .= '<td>' . srPdfNum($x['vcf'] ?? null, 4) . '</td><td>' . srPdfNum($x['gsv'] ?? null) . '</td><td>' . srPdfNum($x['mt'] ?? null) . '</td></tr>';
        }
        $h .= '</tbody></table><table class="tt"><tr><td class="sp"></td><td class="lb r">TOTAL Metric Tons</td><td class="v">' . srPdfNum($tot['completed'] ?? null) . '</td></tr></table></div>';
        $tanks .= $h;
    }

    // the remark, the MT of each grade, the signatures
    $remark = $val('remarks') !== '' ? $val('remarks') : 'All bunker tanks were carefully sounded together with Ch. Engineer/his representative and the quantities, as calculated from the vessels calibration tables, were found and agreed to be at the time of survey :';
    $sum = '';
    foreach ($grades as $g) $sum .= '<tr><td class="g">' . $e(SR_GRADES[$g][0]) . '</td><td class="m">' . srPdfNum($results['grades'][$g]['completed'] ?? null) . '</td><td class="u">MT</td></tr>';
    $sig = fn(array $lines) => '<div class="box"></div><div class="ln"></div><b>' . $e($lines[0]) . '</b><br>' . implode('<br>', array_map($e, array_slice($lines, 1)));
    $end = '<div class="end"><p class="rem">' . nl2br($e($remark)) . '</p>' . ($sum ? '<table class="sum">' . $sum . '</table>' : '') .
        '<table class="sig"><tr><td>' . $sig(['The Master', 'CAPT. ' . $up('master')]) . '</td><td class="g"></td>' .
        '<td>' . $sig(['The Chief Engineer', 'MR. ' . $up('chief_engineer')]) . '</td><td class="g"></td>' .
        '<td>' . $sig(['Independent Marine Surveyor', 'MR. ' . $up('surveyor_name'), 'For YMR MARINE SOLUTIONS LLP']) . '</td></tr></table></div>';

    $kind = RT_KINDS[$tpl['kind']] ?? $tpl['kind'];
    $vessel = strtoupper($val('vessel_name'));
    $sub = strtoupper($kind) . ' - BUNKER SURVEY REPORT';
    $logo = dirname(__DIR__) . '/assets/images/ymr-logo-horizontal.svg'; // the vector logo: sharp at any size
    $lh = is_file($logo) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($logo)) : '';

    $css = '
@page { margin: 7mm 10mm 11mm 10mm; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 8.2pt; color: #000; }
#lh { text-align: center; margin: 0 0 6pt; padding: 0 0 4pt; border-bottom: 0.9pt solid #3d8bd6; }
#ft { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 7pt; color: #64748b; border-top: 0.5pt solid #cbd5e1; padding-top: 3pt; }
.ttl { text-align: center; margin: 0 0 9pt; }
.ttl h1 { font-size: 18.5pt; margin: 0; color: #0b3c7a; letter-spacing: .8pt; }
.ttl div { font-size: 9.5pt; font-weight: bold; color: #1e3a8a; margin-top: 3pt; }
.ttl div span { color: #000; font-weight: normal; }
table { border-collapse: collapse; }
.hd { width: 100%; margin-bottom: 6pt; }
.hd td { padding: 1.6pt 3pt; white-space: nowrap; }
.hd .hl { width: 22%; border: 0.5pt solid #000; }
.hd .hv { width: 22%; border: 0.5pt solid #000; border-left: 0; }
.hd .ht { width: 8%; text-align: center; border: 0.5pt solid #000; border-left: 0; }
.hd .hu { width: 7%; }
.hd .bl { text-align: right; width: 6%; }
.hd .bh, .hd .bv { border: 0.5pt solid #000; width: 10%; }
.hd .bu { width: 8%; }
.hd tr.bd td { font-weight: bold; }
.dr { width: 100%; margin-bottom: 10pt; }
.dr td { padding: 1.6pt 3pt; }
.dr .hl { border: 0.5pt solid #000; width: 13%; }
.dr .dv { border: 0.5pt solid #000; width: 14%; }
.dr .du { border: 0.5pt solid #000; width: 10%; }
.dr .gap { width: 26%; }
h3 { font-size: 11pt; margin: 8pt 0 3pt; page-break-after: avoid; }
.tk { width: 100%; }
.tk th { font-weight: normal; font-size: 7.6pt; text-align: center; border: 0.5pt solid #000; padding: 2pt; }
.tk td { border: 0.5pt solid #000; border-top: 0; border-bottom: 0.3pt solid #b4bfcc; text-align: center; padding: 1.5pt 2pt; font-size: 7.8pt; }
.tk tbody tr:last-child td { border-bottom: 0.5pt solid #000; }
.tk .tn { text-align: left; width: 15%; }
.tk th.tn { text-align: center; }
.tt { width: 100%; }
.tt td { padding: 1.5pt 3pt; font-size: 7.8pt; }
.tt .sp { width: 58%; }
.tt .lb { font-weight: bold; border: 0.5pt solid #000; border-top: 0; width: 28%; }
.tt .lb.r { text-align: right; }
.tt .v { font-weight: bold; text-align: right; border: 0.5pt solid #000; border-top: 0; width: 14%; font-size: 8.6pt; }
.grade { margin-bottom: 8pt; }
.end { page-break-inside: avoid; margin-top: 6pt; }
.rem { margin: 0 0 10pt; line-height: 1.35; }
.sum { margin: 0 0 6pt; width: 46%; }
.sum td { border: 0.5pt solid #000; padding: 1.6pt 4pt; }
.sum .g { text-align: center; width: 45%; } .sum .m { text-align: center; font-weight: bold; width: 35%; } .sum .u { text-align: center; }
.sig { width: 100%; margin-top: 10pt; page-break-inside: avoid; }
.sig td { width: 30%; vertical-align: top; font-size: 8pt; line-height: 1.35; }
.sig td.g { width: 5%; }
.sig .box { height: 62pt; } /* room to sign and stamp */
.sig .ln { border-top: 0.7pt solid #000; height: 1pt; margin-bottom: 2pt; }
';
    // scaled print: every pt size (fonts, spacing, borders) × $k, borders never thinner than 0.3pt
    $css = preg_replace_callback('/(\d+(?:\.\d+)?)pt\b/', fn($m) => max((float)$m[1] < 1 ? 0.3 : 0, round((float)$m[1] * $k, 2)) . 'pt', $css);
    $lhW = $k >= 0.5 ? 182 : 160; // the letterhead across the page (only a very long report makes it a little smaller)
    // the letterhead keeps its size at any scale: the vector logo across the page, the office address under it
    $lhCss = '#lh img { width: ' . $lhW . 'mm; } #lh .ad { color: #3b5f95; font-size: 8.4pt; margin-top: 5pt; line-height: 1.45; } #lh .ad u { text-decoration: underline; }
#lh .ad table { width: 92%; margin: 0 auto; border-collapse: collapse; } #lh .ad td { padding: 0; font-size: 8.4pt; color: #3b5f95; }';
    $lhBlock = $lh ? '<div id="lh"><img src="' . $lh . '" alt="YMR Marine Solutions"><div class="ad"><u>REGISTERED OFFICE ADDRESS:</u> Runwal Elegante, A2605, Lokhandwala, Andheri West, Mumbai, Maharashtra- 400058' .
        '<table><tr><td style="text-align:left">e-mail: ops@ymrmarine.com</td><td style="text-align:center">Phone: +919820482713</td><td style="text-align:right">http://www.ymrmarine.com</td></tr></table></div></div>' : '';
    // the certificate of the survey type first (same letterhead, its own page), then the tank report
    $cm = srCertModel($data, (string)$tpl['kind'], $grades, array_map(fn($g) => $results['grades'][$g]['completed'] ?? null, array_combine($grades, $grades) ?: []));
    $cert = $cm ? srCertHtml($cm, $lhBlock) : '';
    $certCss = preg_replace_callback('/(\d+(?:\.\d+)?)pt\b/', fn($m) => max((float)$m[1] < 1 ? 0.3 : 0, round((float)$m[1] * $cs, 2)) . 'pt', srCertCss()) . '.cert #lh img { width: 182mm; }' . ($certOnly ? '.cert { page-break-after: auto; }' : '');
    $foot = '<div id="ft">' . $e($vessel !== '' ? $vessel : $sub) . ' · Generated by YMR Marine YSMS on ' . $e(date('d M Y H:i')) . '</div>';
    if ($certOnly) return '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . $lhCss . $certCss . '</style></head><body>' . $foot . $cert . '</body></html>';
    return '<!doctype html><html><head><meta charset="utf-8"><style>' . $css . $lhCss . $certCss . '
</style></head><body>' . $foot .
        $cert . $lhBlock .
        '<div class="ttl"><h1>' . $e($vessel !== '' ? $vessel : 'BUNKER SURVEY REPORT') . '</h1>' . ($val('imo') !== '' ? '<div><span>IMO No.</span> ' . $e($val('imo')) . '</div>' : '') . '</div>' .
        $head . $drafts . $tanks . $end .
        '</body></html>';
}
