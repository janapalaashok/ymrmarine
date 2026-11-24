// The survey report's calculation and checks are the website's (assets/js/report-calc.js,
// assets/js/survey-report.js): the same results for the same readings.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/sr/sr_calc.dart';
import 'package:ysms_app/sr/sr_model.dart';

const calcCfg = {
  'k0': 186.9696,
  'k1': 0.4862,
  'wcf': 0.0011,
  'base': 15,
  'alpha_dp': 8,
  'vcf_dp': 4,
  'gsv_dp': 3,
  'mt_dp': 3,
};

Map<String, dynamic> starter() => {
  'ok': true,
  'report': {
    'id': '',
    'version': 0,
    'kind': 'offhire',
    'kind_label': 'Offhire',
    'vessel': 'New Offhire report',
    'data': {
      'fields': {
        'imo': '',
        'vessel_name': '',
        'started_date': '',
        'started_time': '',
        'completed_date': '',
        'completed_time': '',
      },
      'tanks': {'vlsfo': [], 'lsmgo': []},
      'grades': ['vlsfo'],
      'step': 'vessel',
      'extra': {'port_name': ''},
    },
  },
  'can_edit': true,
  'config': {
    'template': {'kind': 'offhire'},
    'fields': [
      {'key': 'imo', 'label': 'IMO number', 'type': 'imo'},
      {'key': 'vessel_name', 'label': 'Vessel name', 'type': 'text'},
      {'key': 'started_date', 'label': 'Started', 'type': 'date'},
      {'key': 'started_time', 'label': 'Started', 'type': 'time'},
      {'key': 'completed_date', 'label': 'Completed', 'type': 'date'},
      {'key': 'completed_time', 'label': 'Completed', 'type': 'time'},
      {'key': 'brob_started_vlsfo', 'label': 'ROB', 'type': 'num:3'},
    ],
    'extra_fields': [
      {'key': 'port_name', 'label': 'Port Name', 'type': 'text'},
    ],
    'grades': [
      {'key': 'vlsfo', 'short': 'VLSFO', 'name': 'Very Low Sulphur Fuel Oil'},
      {'key': 'lsmgo', 'short': 'LSMGO', 'name': 'Low Sulphur Marine Gas Oil'},
    ],
    'vessel_keys': ['vessel_name'],
    'survey_required': ['started_date', 'port_name'],
    'calc': calcCfg,
    'cert': {
      'choices': {'offhire': 'Off-Hire Survey', 'none': 'No certificate'},
      'by_kind': {'offhire': 'offhire'},
    },
  },
};

void main() {
  test('54B results are the website\'s for 303 readings', () {
    final cases = jsonDecode(File('test/sr_calc_cases.json').readAsStringSync()) as List;
    final c = SrCalc.fromMap(calcCfg);
    for (final x in cases) {
      final row = Map<String, dynamic>.from(x[0] as Map), want = x[1] as Map;
      final got = tankCalc(row, c);
      expect(got.vcf, want['vcf'] == null ? isNull : closeTo((want['vcf'] as num).toDouble(), 1e-12), reason: '$row');
      expect(got.gsv, want['gsv'] == null ? isNull : closeTo((want['gsv'] as num).toDouble(), 1e-12), reason: '$row');
      expect(got.mt, want['mt'] == null ? isNull : closeTo((want['mt'] as num).toDouble(), 1e-12), reason: '$row');
    }
  });

  test('Excel rounding: half away from zero on the decimal value', () {
    expect(xround(1.005, 2), 1.01);
    expect(xround(-1.005, 2), -1.01);
    expect(xround(2.5, 0), 3);
    expect(f3c(1234567.5), '1,234,567.500');
    expect(sgn(-1.5), '−1.500');
    expect(sgn(0), '0.000');
  });

  test('IMO check digit, numbers, times', () {
    expect(SrReport.typeError('imo', '9074729'), '');
    expect(SrReport.typeError('imo', '9074728'), contains('check digit'));
    expect(SrReport.typeError('imo', '123'), 'IMO number has 7 digits.');
    expect(SrReport.typeError('num:3', '1.2345'), 'Use at most 3 decimals.');
    expect(SrReport.typeError('num:3', 'abc'), 'Numbers only.');
    expect(SrReport.typeError('int', '12'), '');
    expect(SrReport.typeError('time', '24:00'), contains('HH:MM'));
  });

  test('tank rules: missing, ranges, ullage', () {
    final r = SrReport.emptyRow()
      ..addAll({'name': '1P', 'ref_height': '10', 'sounding': '1.2', 'volume': '-1', 'density': '1.3', 'temp': '30'});
    final e = SrReport.tankErrors(r);
    expect(e['volume'], 'Volume must be 0 or more.');
    expect(e['density'], contains('0.6000 and 1.1000'));
    final u = SrReport.emptyRow()
      ..addAll({
        'name': '1P',
        'ref_height': '2',
        'mode': 'ullage',
        'ullage': '3',
        'volume': '1',
        'density': '0.9',
        'temp': '30',
      });
    expect(SrReport.tankErrors(u)['ullage'], 'Ullage is more than the reference height.');
    u['ullage'] = '0.75';
    expect(SrReport.sounding(u), '1.250');
    expect(SrReport.fmtT('temp', '5'), '05.0');
    expect(SrReport.fmtT('density', '.9'), '0.9000');
  });

  test('issues per step, default tanks, payload without empty rows or marks', () {
    final r = SrReport(starter());
    expect(r.issues('vessel').map((x) => x.key), ['f:imo', 'f:vessel_name']);
    r.setVal('imo', '9074729');
    r.setVal('vessel_name', 'Blue');
    expect(r.stepDone('vessel'), isTrue);
    expect(r.issues('survey').map((x) => x.msg), contains('Port Name is missing.'));
    expect(r.issues('survey').map((x) => x.msg), contains('Log Book ROB VLSFO (MT) is missing.'));
    expect(r.issues('tanks').single.msg, 'VLSFO: add at least one tank.');
    expect(r.addDefaultTanks({}), isTrue);
    expect(r.rows('vlsfo').map((x) => x['name']).take(3), ['1P', '1S', '2P']);
    expect(r.rows('vlsfo')[6]['ref_height'], 'GAUGE');
    r.rows('vlsfo').add(SrReport.emptyRow()..['_tried'] = true);
    final p = r.payload();
    expect((p['tanks']['vlsfo'] as List).length, 11);
    expect(jsonEncode(p).contains('_tried'), isFalse);
    expect(r.certType(), 'offhire');
    r.setVal('port_name', 'Vizag');
    expect(r.extra['port_name'], 'Vizag');
  });
}
