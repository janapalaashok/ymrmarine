// The survey report made on this phone without internet — the website's offline PDF (jsPDF in
// assets/js/survey-report.js) in Dart: the YMR letterhead, the certificate of the survey type (from
// the wording the server sent with the report), then the tank report — vessel and IMO, owners /
// charterers / FWE / start / completion with the BROB of each grade, drafts, one tank table per grade
// (readings with S / U), TOTAL Metric Tons, the remark, the MT of each grade and the signatures — on
// one page when it fits (smaller print as needed). The final report is always the server's: it is made
// as soon as the connection is back.
import 'dart:typed_data';

import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;

import 'sr_calc.dart';
import 'sr_model.dart';

const _months = [
  'JANUARY',
  'FEBRUARY',
  'MARCH',
  'APRIL',
  'MAY',
  'JUNE',
  'JULY',
  'AUGUST',
  'SEPTEMBER',
  'OCTOBER',
  'NOVEMBER',
  'DECEMBER',
];
const _defaultRemark =
    'All bunker tanks were carefully sounded together with Ch. Engineer/his representative and the quantities, as calculated from the vessels calibration tables, were found and agreed to be at the time of survey :';

/// "15th NOVEMBER 2026" for 2026-11-15 (other values as they are).
String srPdfDate(String v) {
  final m = RegExp(r'^(\d{4})-(\d{2})-(\d{2})$').firstMatch(v);
  if (m == null) return v;
  final d = int.parse(m.group(3)!);
  final s = d % 100 >= 11 && d % 100 <= 13
      ? 'th'
      : const ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][d % 10];
  return '$d$s ${_months[int.parse(m.group(2)!) - 1]} ${m.group(1)}';
}

String _pNum(Object? v, [int d = 3]) {
  final n = v is num ? v.toDouble() : srNum(v);
  if (n == null || n.isNaN) return '-';
  final neg = n < 0;
  final p = n.abs().toStringAsFixed(d).split('.');
  final i = p[0].replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  return '${neg ? '-' : ''}$i${d > 0 ? '.${p[1]}' : ''}';
}

/// A reading as printed: numbers in the report's decimals, words (GAUGE) in capitals.
String srPdfRead(String col, Object? v) {
  final t = '${v ?? ''}'.trim();
  if (t.isEmpty) return '-';
  final n = srNum(t);
  if (n == null) return t.toUpperCase();
  const d = {'ref_height': 3, 'sounding': 3, 'volume': 3, 'density': 4, 'temp': 1};
  return d.containsKey(col) ? _pNum(n, d[col]!) : t;
}

/// The Sounding column: "0.012 S", "1.250 U" (the ullage typed), or the plain value.
String srPdfSdg(Map r) {
  if (r['mode'] == 'ullage') return '${srPdfRead('sounding', r['ullage'])} U';
  final s = SrReport.sounding(r);
  return '${srPdfRead('sounding', s)}${r['su'] == 's' && srNum(s) != null ? ' S' : ''}';
}

class SrPdf {
  SrPdf(this.r, {this.logoSvg, DateTime? now}) : now = now ?? DateTime.now();
  final SrReport r;
  final String? logoSvg;
  final DateTime now;

  static const _blue = PdfColor.fromInt(0xFF3B5F95);
  static const _rule = PdfColor.fromInt(0xFF3D8BD6);
  static const _title = PdfColor.fromInt(0xFF0B3C7A);
  static const _imo = PdfColor.fromInt(0xFF1E3A8A);
  static const _grey = PdfColor.fromInt(0xFFB4BFCC);
  static const _foot = PdfColor.fromInt(0xFF64748B);

  String _up(String k) => r.val(k).toUpperCase();
  List<String> get _grades => [
    for (final g in r.chosen())
      if (r.rows(g).any((x) => !SrReport.blankRow(x))) g,
  ];

  String get vessel {
    final v = r.val('vessel_name').isNotEmpty ? r.val('vessel_name') : r.title;
    return v.isEmpty ? 'BUNKER SURVEY REPORT' : v.toUpperCase();
  }

  String get fileName =>
      '${'$vessel - ${r.kindLabel} REPORT'.toUpperCase().replaceAll(RegExp(r'[^A-Z0-9 .\-]+'), '').trim()}.pdf';

  // ---------- the certificate (as srCertModel() on the server) ----------
  Map<String, dynamic>? certModel() {
    final c = r.cert;
    final types = (c['types'] as Map?) ?? const {};
    final cfg = types[r.certType()];
    if (cfg is! Map) return null;
    final parts = <String>[
      if (r.val('flag').isNotEmpty) 'under ${_up('flag')} Flag',
      if (r.val('port_of_registry').isNotEmpty) 'Port of Registry ${_up('port_of_registry')}',
      if (r.val('grt').isNotEmpty) 'GRT ${r.val('grt')}',
      if (r.val('nrt').isNotEmpty) 'NRT ${r.val('nrt')}',
    ];
    final rep = {
      '{vessel}': _up('vessel_name'),
      '{particulars}': parts.isEmpty ? '' : ', ${parts.join(', ')}',
      '{owners}': _up('owners'),
      '{charterers}': _up('charterers'),
    };
    String fill(Object? t) =>
        '${t ?? ''}'.replaceAllMapped(RegExp(r'\{(vessel|particulars|owners|charterers)\}'), (m) => rep[m.group(0)]!);
    final asperWord = '${c['asper'] ?? ''}';
    String box(Object? fk) {
      var x = fk == null || '$fk'.isEmpty ? '' : '${r.fields['$fk'] ?? r.extra['$fk'] ?? ''}'.trim();
      if (RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(x)) x = srPdfDate(x);
      return x.isEmpty ? asperWord : x;
    }

    final boxes = (cfg['boxes'] as Map?) ?? const {};
    final event = '${cfg['event'] ?? ''}';
    String gname(String g) => r.grade(g)?.name.isNotEmpty == true ? r.grade(g)!.name : (r.grade(g)?.short ?? g);
    final asper = [
      for (final g in _grades) [gname(g), asperWord],
    ];
    return {
      'title': '${cfg['title'] ?? ''}',
      'boxes': [
        ['Place of $event:', box(boxes['place'])],
        ['Date of $event:', box(boxes['date'])],
        ['Time of $event:', box(boxes['time'])],
      ],
      'completed': [
        srPdfDate(r.val('completed_date')),
        r.val('completed_time').isEmpty ? '' : '${r.val('completed_time')}  Hrs LT',
      ],
      'paras': [fill(cfg['certify']), fill(cfg['survey'])],
      'qty': [
        for (final g in _grades) [gname(g), r.gradeTotals(g).mt.toStringAsFixed(3), 'M. Tons'],
      ],
      'consumption': fill(cfg['consumption']),
      'onboard': fill(cfg['onboard']),
      'asper': asper,
      'remark': fill(cfg['remark']),
      'without_prejudice': fill(cfg['without_prejudice']),
      'sign_left': [for (final x in (cfg['sign_left'] as List?) ?? const []) fill(x)],
      'sign_right': [for (final x in (cfg['sign_right'] as List?) ?? const []) fill(x)],
      'master': 'Capt. ${_up('master')}',
      'chief': 'Mr. ${_up('chief_engineer')}',
      'surveyor_title': '${cfg['surveyor_title'] ?? 'Independent Marine Surveyor'}',
      'surveyor': 'Mr. ${_up('surveyor_name')}',
    };
  }

  // ---------- pieces ----------
  pw.Widget _letterhead(double k) {
    const mm = PdfPageFormat.mm;
    final w = (k >= 0.5 ? 182 : 160) * mm;
    const lab = 'REGISTERED OFFICE ADDRESS:';
    final st = pw.TextStyle(fontSize: 8.4, color: _blue);
    return pw.Column(
      children: [
        if (logoSvg != null)
          pw.SvgImage(svg: logoSvg!, width: w)
        else
          pw.Text(
            'YMR MARINE SOLUTIONS',
            style: pw.TextStyle(
              fontSize: 20,
              fontWeight: pw.FontWeight.bold,
              color: const PdfColor.fromInt(0xFF00A0E2),
            ),
          ),
        pw.SizedBox(height: 2.4 * mm),
        pw.RichText(
          textAlign: pw.TextAlign.center,
          text: pw.TextSpan(
            style: st,
            children: [
              pw.TextSpan(
                text: lab,
                style: const pw.TextStyle(decoration: pw.TextDecoration.underline),
              ),
              const pw.TextSpan(
                text: ' Runwal Elegante, A2605, Lokhandwala, Andheri West, Mumbai, Maharashtra- 400058',
              ),
            ],
          ),
        ),
        pw.SizedBox(height: 1.2 * mm),
        pw.Padding(
          padding: pw.EdgeInsets.symmetric(horizontal: 190 * mm * 0.04),
          child: pw.Row(
            mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
            children: [
              pw.Text('e-mail: ops@ymrmarine.com', style: st),
              pw.Text('Phone: +919820482713', style: st),
              pw.Text('http://www.ymrmarine.com', style: st),
            ],
          ),
        ),
        pw.SizedBox(height: 1.2 * mm),
        pw.Divider(color: _rule, thickness: 0.9, height: 0),
        pw.SizedBox(height: 2.1 * mm),
      ],
    );
  }

  /// A row of cells: (text, flex, border, align, bold).
  pw.Widget _row(List<_C> cells, {required double size, required double lw, double padV = 1.6, double padH = 3}) =>
      pw.Table(
        columnWidths: {for (var i = 0; i < cells.length; i++) i: pw.FlexColumnWidth(cells[i].flex)},
        defaultVerticalAlignment: pw.TableCellVerticalAlignment.full,
        children: [
          pw.TableRow(
            children: [
              for (final c in cells)
                pw.Container(
                  padding: pw.EdgeInsets.symmetric(
                    vertical: padV * PdfPageFormat.point,
                    horizontal: padH * PdfPageFormat.point,
                  ),
                  decoration: c.border ? pw.BoxDecoration(border: pw.Border.all(width: lw)) : null,
                  alignment: c.align == 'center'
                      ? pw.Alignment.center
                      : (c.align == 'right' ? pw.Alignment.centerRight : pw.Alignment.centerLeft),
                  child: pw.Text(
                    c.text,
                    style: pw.TextStyle(fontSize: c.size ?? size, fontWeight: c.bold ? pw.FontWeight.bold : null),
                  ),
                ),
            ],
          ),
        ],
      );

  pw.Widget _cert(Map<String, dynamic> m) {
    const mm = PdfPageFormat.mm, fs = 9.4, lw = 0.7;
    pw.Widget para(String t, {bool italic = false}) => pw.Padding(
      padding: const pw.EdgeInsets.only(bottom: 2.6 * mm),
      child: pw.Text(
        t,
        textAlign: pw.TextAlign.justify,
        style: pw.TextStyle(
          fontSize: fs,
          lineSpacing: fs * 0.34,
          fontStyle: italic ? pw.FontStyle.italic : null,
          color: italic ? const PdfColor.fromInt(0xFF1F2937) : PdfColors.black,
        ),
      ),
    );
    pw.Widget list(List rows, {bool bold = false}) => pw.Padding(
      padding: const pw.EdgeInsets.only(left: 190 * mm * 0.12, bottom: 2.6 * mm),
      child: pw.Table(
        defaultColumnWidth: const pw.IntrinsicColumnWidth(),
        children: [
          for (final x in rows)
            pw.TableRow(
              children: [
                pw.Container(
                  constraints: const pw.BoxConstraints(minWidth: 52 * mm),
                  padding: const pw.EdgeInsets.symmetric(vertical: 0.55 * mm, horizontal: 2.8 * mm),
                  alignment: pw.Alignment.centerRight,
                  child: pw.Text('${x[0]}', style: const pw.TextStyle(fontSize: fs)),
                ),
                pw.Container(
                  constraints: const pw.BoxConstraints(minWidth: 24 * mm),
                  padding: const pw.EdgeInsets.symmetric(vertical: 0.55 * mm, horizontal: 2.8 * mm),
                  alignment: bold ? pw.Alignment.centerRight : pw.Alignment.centerLeft,
                  child: pw.Text(
                    '${x[1]}',
                    style: pw.TextStyle(fontSize: fs, fontWeight: bold ? pw.FontWeight.bold : null),
                  ),
                ),
                if (x.length > 2)
                  pw.Padding(
                    padding: const pw.EdgeInsets.only(left: 5 * mm, top: 0.55 * mm, bottom: 0.55 * mm),
                    child: pw.Text('${x[2]}', style: const pw.TextStyle(fontSize: fs)),
                  ),
              ],
            ),
        ],
      ),
    );
    pw.Widget sig(String title, List<String> names) => pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.start,
      children: [
        pw.SizedBox(height: 40),
        pw.Container(height: 0.8, color: PdfColors.black),
        pw.SizedBox(height: 1 * mm),
        for (final t in [title, ...names])
          pw.Text(
            t,
            style: pw.TextStyle(fontSize: fs, fontWeight: pw.FontWeight.bold),
          ),
        pw.SizedBox(height: 1 * mm),
      ],
    );
    pw.Widget head(List lines) => pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.start,
      children: [for (final t in lines) pw.Text('$t', style: const pw.TextStyle(fontSize: fs))],
    );
    final boxes = (m['boxes'] as List).cast<List>();
    final done = (m['completed'] as List).cast<String>();
    return pw.Column(
      crossAxisAlignment: pw.CrossAxisAlignment.stretch,
      children: [
        _letterhead(1),
        pw.SizedBox(height: 2.5 * mm),
        pw.Center(
          child: pw.Text(
            '${m['title']}',
            style: pw.TextStyle(
              fontSize: 13.5,
              fontWeight: pw.FontWeight.bold,
              decoration: pw.TextDecoration.underline,
            ),
          ),
        ),
        pw.SizedBox(height: 4 * mm),
        pw.SizedBox(
          width: 190 * mm * 0.6,
          child: pw.Column(
            children: [
              for (final b in boxes)
                _row(
                  [_C('${b[0]}', .45, true, 'center'), _C('${b[1]}', .55, true, 'center')],
                  size: fs,
                  lw: lw,
                  padV: 2.3,
                  padH: 6,
                ),
            ],
          ),
        ),
        pw.SizedBox(height: 2.5 * mm),
        _row(
          [
            _C('Date / Time of Survey Completed:', .36, true, 'left', true),
            _C(done[0], .32, true, 'center', true),
            _C(done[1], .32, true, 'left', true),
          ],
          size: fs,
          lw: 0.9,
          padV: 3.1,
          padH: 6,
        ),
        pw.SizedBox(height: 3.2 * mm),
        for (final t in (m['paras'] as List)) para('$t'),
        list(m['qty'] as List, bold: true),
        para('${m['consumption']}'),
        list(m['asper'] as List),
        para('${m['onboard']}'),
        list(m['asper'] as List),
        pw.Padding(
          padding: const pw.EdgeInsets.only(bottom: 2.6 * mm),
          child: pw.RichText(
            text: pw.TextSpan(
              style: const pw.TextStyle(fontSize: fs),
              children: [
                pw.TextSpan(
                  text: 'REMARK: ',
                  style: pw.TextStyle(fontWeight: pw.FontWeight.bold),
                ),
                pw.TextSpan(text: '${m['remark']}'),
              ],
            ),
          ),
        ),
        if ('${m['without_prejudice']}'.isNotEmpty) para('${m['without_prejudice']}', italic: true),
        pw.SizedBox(height: 2 * mm),
        pw.Row(
          crossAxisAlignment: pw.CrossAxisAlignment.start,
          children: [
            pw.Expanded(
              flex: 46,
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                children: [
                  head(m['sign_left'] as List),
                  sig('The Master', ['${m['master']}']),
                  sig('The Chief Engineer', ['${m['chief']}']),
                ],
              ),
            ),
            pw.Spacer(flex: 8),
            pw.Expanded(
              flex: 46,
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.stretch,
                children: [
                  head(m['sign_right'] as List),
                  sig('${m['surveyor_title']}', ['For YMR MARINE SOLUTIONS LLP', '${m['surveyor']}']),
                ],
              ),
            ),
          ],
        ),
      ],
    );
  }

  List<pw.Widget> _report(double k) {
    const mm = PdfPageFormat.mm;
    double f(double pt) => pt * k;
    final lw = (0.5 * k < 0.3 ? 0.3 : 0.5 * k);
    final g = _grades, nb = g.length;
    final size = f(8.2);
    List<_C> blank() => nb == 0 ? [] : [_C('', 6), for (final _ in g) _C('', 10), _C('', 8)];
    List<_C> brob(String p) => nb == 0
        ? []
        : [
            _C('BROB:', 6, false, 'right'),
            for (final x in g) _C(r.val('brob_${p}_$x').isEmpty ? '' : _pNum(r.val('brob_${p}_$x')), 10, true),
            _C('M.Tons', 8),
          ];
    List<_C> heads() => nb == 0 ? [] : [_C('', 6), for (final x in g) _C(r.grade(x)?.short ?? x, 10, true), _C('', 8)];
    List<_C> line(String label, String v, String? time, List<_C> right, {bool bold = false}) => [
      _C(label, 22, true, 'left', bold),
      if (time == null)
        _C(v, 30, true, 'left', bold)
      else ...[
        _C(v, 22, true, 'left', bold),
        _C(time, 8, true, 'center', bold),
      ],
      _C(time != null ? 'Hrs LT' : '', 7, false, 'left', bold),
      ...right,
    ];
    final fa = srNum(r.val('draft_aft')), ff = srNum(r.val('draft_fwd')), li = srNum(r.val('list'));
    String d2(double? n) => n == null ? '' : n.toStringAsFixed(2);
    final out = <pw.Widget>[
      _letterhead(k),
      pw.Center(
        child: pw.Text(
          vessel,
          style: pw.TextStyle(fontSize: f(18.5), fontWeight: pw.FontWeight.bold, color: _title),
        ),
      ),
      if (r.val('imo').isNotEmpty) ...[
        pw.SizedBox(height: f(3)),
        pw.Center(
          child: pw.RichText(
            text: pw.TextSpan(
              style: pw.TextStyle(fontSize: f(9.5)),
              children: [
                const pw.TextSpan(text: 'IMO No. '),
                pw.TextSpan(
                  text: r.val('imo'),
                  style: pw.TextStyle(fontWeight: pw.FontWeight.bold, color: _imo),
                ),
              ],
            ),
          ),
        ),
      ],
      pw.SizedBox(height: f(12)),
      _row(line('Owners', _up('owners'), null, blank()), size: size, lw: lw, padV: f(1.6), padH: f(3)),
      _row(line('Charterers', _up('charterers'), null, heads()), size: size, lw: lw, padV: f(1.6), padH: f(3)),
      _row(
        line('FWE', srPdfDate(r.val('fwe_date')), r.val('fwe_time'), brob('fwe')),
        size: size,
        lw: lw,
        padV: f(1.6),
        padH: f(3),
      ),
      _row(
        line('Survey Started', srPdfDate(r.val('started_date')), r.val('started_time'), brob('started')),
        size: size,
        lw: lw,
        padV: f(1.6),
        padH: f(3),
      ),
      _row(
        line('Survey Completed', srPdfDate(r.val('completed_date')), r.val('completed_time'), blank(), bold: true),
        size: size,
        lw: lw,
        padV: f(1.6),
        padH: f(3),
      ),
      _row(line('Place of Survey', r.val('place'), null, blank()), size: size, lw: lw, padV: f(1.6), padH: f(3)),
      _row(line('Voyage No.', r.val('voyage_no'), null, blank()), size: size, lw: lw, padV: f(1.6), padH: f(3)),
      pw.SizedBox(height: f(6) * PdfPageFormat.point),
      _row(
        [
          _C('Draft Aft', 13, true),
          _C(d2(fa), 14, true),
          _C('Meters', 10, true),
          _C('', 26),
          _C('Trim:', 13, true),
          _C(fa != null && ff != null ? (((fa - ff) * 1000).round() / 1000).toStringAsFixed(2) : '', 14, true),
          _C('Meters', 10, true),
        ],
        size: size,
        lw: lw,
        padV: f(1.6),
        padH: f(3),
      ),
      _row(
        [
          _C('Draft Fwd', 13, true),
          _C(d2(ff), 14, true),
          _C('Meters', 10, true),
          _C('', 26),
          _C('List', 13, true),
          _C(d2(li), 14, true),
          _C('Degrees', 10, true),
        ],
        size: size,
        lw: lw,
        padV: f(1.6),
        padH: f(3),
      ),
      pw.SizedBox(height: f(10)),
    ];
    // one table per grade: thin grey lines between the tanks, only TOTAL Metric Tons
    for (var gi = 0; gi < g.length; gi++) {
      final x = g[gi];
      final rows = r.rows(x).where((e) => !SrReport.blankRow(e)).toList();
      final anyU = rows.any((e) => e['mode'] == 'ullage');
      final name = r.grade(x)?.name.isNotEmpty == true ? r.grade(x)!.name : (r.grade(x)?.short ?? x);
      final heads = [
        'Tank\nName:',
        'Ref. Height\n(m)',
        '${anyU ? 'Sdg. / Ullage' : "Sdg. Corr'd"}\n(m)',
        'Volume\nCubic Metres',
        'Density\n@15°C',
        'Temp\nDeg(C)',
        'V.C.F.\nT(54B)',
        'G.S.V\nm³ @ 15°C',
        'Metric\nTons',
      ];
      const flex = [15.0, 10.625, 10.625, 10.625, 10.625, 10.625, 10.625, 10.625, 10.625];
      final ts = pw.TextStyle(fontSize: f(7.8));
      pw.Widget c(String t, int i, {pw.TextStyle? style, bool head = false, bool lastRow = false}) => pw.Container(
        padding: pw.EdgeInsets.symmetric(vertical: f(head ? 2 : 1.5), horizontal: f(2)),
        alignment: i == 0 && !head ? pw.Alignment.centerLeft : pw.Alignment.center,
        decoration: pw.BoxDecoration(
          border: head
              ? pw.Border.all(width: lw)
              : pw.Border(
                  left: pw.BorderSide(width: lw),
                  right: pw.BorderSide(width: lw),
                  bottom: lastRow
                      ? pw.BorderSide(width: lw)
                      : pw.BorderSide(width: (0.3 * k < 0.3 ? 0.3 : 0.3 * k), color: _grey),
                ),
        ),
        child: pw.Text(t, textAlign: i == 0 && !head ? pw.TextAlign.left : pw.TextAlign.center, style: style ?? ts),
      );
      out.addAll([
        if (gi > 0) pw.SizedBox(height: f(8)),
        pw.Text(
          name,
          style: pw.TextStyle(fontSize: f(11), fontWeight: pw.FontWeight.bold),
        ),
        pw.SizedBox(height: f(3)),
        pw.Table(
          columnWidths: {for (var i = 0; i < flex.length; i++) i: pw.FlexColumnWidth(flex[i])},
          defaultVerticalAlignment: pw.TableCellVerticalAlignment.full,
          children: [
            pw.TableRow(
              repeat: true,
              children: [
                for (var i = 0; i < heads.length; i++)
                  c(heads[i], i, head: true, style: pw.TextStyle(fontSize: f(7.6))),
              ],
            ),
            for (var ri = 0; ri < rows.length; ri++)
              () {
                final row = rows[ri];
                final t = r.calcRow(row);
                final v = [
                  '${row['name'] ?? ''}'.toUpperCase(),
                  srPdfRead('ref_height', row['ref_height']),
                  srPdfSdg(row),
                  srPdfRead('volume', row['volume']),
                  srPdfRead('density', row['density']),
                  srPdfRead('temp', row['temp']),
                  _pNum(t.vcf, 4),
                  _pNum(t.gsv),
                  _pNum(t.mt),
                ];
                return pw.TableRow(
                  children: [for (var i = 0; i < v.length; i++) c(v[i], i, lastRow: ri == rows.length - 1)],
                );
              }(),
          ],
        ),
        _row(
          [
            _C('', 58),
            _C('TOTAL Metric Tons', 28, true, 'right', true),
            _C(_pNum(r.gradeTotals(x).mt), 14, true, 'right', true, f(8.6)),
          ],
          size: f(7.8),
          lw: lw,
          padV: f(1.5),
          padH: f(3),
        ),
      ]);
    }
    final remark = r.val('remarks').isNotEmpty ? r.val('remarks') : _defaultRemark;
    out.addAll([
      pw.SizedBox(height: f(14)),
      pw.Text(
        remark,
        style: pw.TextStyle(fontSize: f(8.2), lineSpacing: f(8.2) * 0.35),
      ),
      pw.SizedBox(height: f(10)),
      if (nb > 0)
        pw.SizedBox(
          width: 190 * mm * 0.46,
          child: pw.Column(
            children: [
              for (final x in g)
                _row(
                  [
                    _C(r.grade(x)?.short ?? x, 45, true, 'center'),
                    _C(_pNum(r.gradeTotals(x).mt), 35, true, 'center', true),
                    _C('MT', 20, true, 'center'),
                  ],
                  size: size,
                  lw: lw,
                  padV: f(1.6),
                  padH: f(3),
                ),
            ],
          ),
        ),
      pw.SizedBox(height: f(6) + f(10) + f(62)),
      pw.Row(
        crossAxisAlignment: pw.CrossAxisAlignment.start,
        children: [
          for (final (i, lines) in [
            ['The Master', 'CAPT. ${_up('master')}'],
            ['The Chief Engineer', 'MR. ${_up('chief_engineer')}'],
            ['Independent Marine Surveyor', 'MR. ${_up('surveyor_name')}', 'For YMR MARINE SOLUTIONS LLP'],
          ].indexed) ...[
            if (i > 0) pw.Spacer(flex: 5),
            pw.Expanded(
              flex: 30,
              child: pw.Column(
                crossAxisAlignment: pw.CrossAxisAlignment.start,
                children: [
                  pw.Container(height: 0.7 * k, color: PdfColors.black),
                  pw.SizedBox(height: f(2)),
                  for (final (j, t) in lines.indexed)
                    pw.Text(
                      t,
                      style: pw.TextStyle(fontSize: f(8), fontWeight: j == 0 ? pw.FontWeight.bold : null),
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    ]);
    return out;
  }

  String get _stamp {
    String p2(int n) => n.toString().padLeft(2, '0');
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${p2(now.day)} ${m[now.month - 1]} ${now.year} ${p2(now.hour)}:${p2(now.minute)}';
  }

  Future<(Uint8List, int)> _build(double k, Map<String, dynamic>? cm) async {
    const mm = PdfPageFormat.mm;
    final doc = pw.Document(title: fileName, author: 'YMR Marine YSMS');
    final theme = pw.ThemeData.withFont(
      base: pw.Font.helvetica(),
      bold: pw.Font.helveticaBold(),
      italic: pw.Font.helveticaOblique(),
    );
    final stamp = _stamp;
    var total = 0;
    pw.Widget footer(pw.Context c) => pw.Column(
      children: [
        pw.Divider(color: const PdfColor.fromInt(0xFFCBD5E1), thickness: 0.5, height: 0),
        pw.SizedBox(height: 1.2 * mm),
        pw.Row(
          children: [
            pw.Expanded(
              child: pw.Text(
                '$vessel · Generated by YMR Marine YSMS on $stamp (offline)',
                style: const pw.TextStyle(fontSize: 7, color: _foot),
              ),
            ),
            pw.Text('Page ${c.pageNumber} of ${c.pagesCount}', style: const pw.TextStyle(fontSize: 7, color: _foot)),
          ],
        ),
      ],
    );
    const fmt = PdfPageFormat(
      210 * mm,
      297 * mm,
      marginLeft: 10 * mm,
      marginRight: 10 * mm,
      marginTop: 7 * mm,
      marginBottom: 4 * mm,
    );
    if (cm != null) {
      doc.addPage(
        pw.Page(
          pageFormat: fmt,
          theme: theme,
          build: (c) => pw.Column(
            children: [
              pw.Expanded(child: _cert(cm)),
              footer(c),
            ],
          ),
        ),
      );
    }
    doc.addPage(pw.MultiPage(pageFormat: fmt, theme: theme, footer: footer, build: (c) => _report(k)));
    final bytes = await doc.save();
    total = doc.document.pdfPageList.pages.length;
    return (bytes, total);
  }

  /// The PDF: the certificate (when the survey type has one), then the tank report on one page when it
  /// fits at some print size (as the website tries 1 … 0.32).
  Future<Uint8List> build() async {
    final cm = certModel();
    final want = cm != null ? 2 : 1;
    (Uint8List, int)? out;
    for (final k in const [1.0, 0.9, 0.8, 0.72, 0.65, 0.58, 0.52, 0.46, 0.41, 0.36, 0.32]) {
      out = await _build(k, cm);
      if (out.$2 <= want) break;
    }
    return out!.$1;
  }
}

class _C {
  const _C(this.text, this.flex, [this.border = false, this.align = 'left', this.bold = false, this.size]);
  final String text;
  final double flex;
  final bool border, bold;
  final String align;
  final double? size;
}
