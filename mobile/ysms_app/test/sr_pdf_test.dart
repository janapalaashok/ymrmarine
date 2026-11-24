// The PDF made on the phone without internet: built from a filled report, certificate first.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/sr/sr_model.dart';
import 'package:ysms_app/sr/sr_pdf.dart';

import 'sr_model_test.dart' show starter;

void main() {
  test('dates and readings as printed', () {
    expect(srPdfDate('2026-11-01'), '1st NOVEMBER 2026');
    expect(srPdfDate('2026-11-13'), '13th NOVEMBER 2026');
    expect(srPdfDate('2026-11-22'), '22nd NOVEMBER 2026');
    expect(srPdfRead('density', '0.95'), '0.9500');
    expect(srPdfRead('ref_height', 'gauge'), 'GAUGE');
    expect(srPdfRead('volume', '1234.5'), '1,234.500');
    expect(srPdfSdg({'mode': 'sounding', 'sounding': '0.012', 'su': 's'}), '0.012 S');
    expect(srPdfSdg({'mode': 'sounding', 'sounding': 'GAUGE', 'su': 's'}), 'GAUGE');
    expect(srPdfSdg({'mode': 'ullage', 'ullage': '1.25', 'ref_height': '3'}), '1.250 U');
  });

  test('a PDF with the certificate and the tank report', () async {
    final src = Platform.environment['SR_PDF_JSON'];
    final r = SrReport(src != null ? jsonDecode(File(src).readAsStringSync()) as Map<String, dynamic> : starter());
    if (src == null) {
      r.setVal('vessel_name', 'MV BLUE');
      r.tanks['vlsfo'] = [
        {...SrReport.emptyRow(), 'name': '1P', 'ref_height': '12', 'sounding': '2.3', 'su': 's', 'volume': '100', 'density': '0.95', 'temp': '30'},
      ];
    }
    final logo = File('assets/images/ymr-logo-horizontal.svg').readAsStringSync();
    final pdf = SrPdf(r, logoSvg: logo, now: DateTime(2026, 11, 16, 9, 5));
    final bytes = await pdf.build();
    expect(String.fromCharCodes(bytes.take(5)), '%PDF-');
    final out = Platform.environment['SR_PDF_OUT'];
    if (out != null) File(out).writeAsBytesSync(bytes);
    expect(pdf.fileName, endsWith('REPORT.pdf'));
  });
}
