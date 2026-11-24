// The survey report as the website's editor keeps it (assets/js/survey-report.js): the server's data
// `{fields, tanks: {grade: [rows]}, grades, step, extra}` and the report type's config, with the same
// checks (issues), tank rules and readings format. Kept as plain maps so it is saved exactly as the
// website saves it (the same report opens on both).
import 'dart:math';

import 'sr_calc.dart';

const srRowKeys = [
  'name',
  'ref_height',
  'sounding',
  'volume',
  'density',
  'temp',
  'mode',
  'ullage',
  'su',
  'done',
  'id',
  'copy',
];
const srMonths = [
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
  'DECEMBER', //
];

const srLabels = {
  'vessel_name': 'Vessel Name', 'flag': 'Flag', 'port_of_registry': 'Port of Registry', 'grt': 'GRT', 'nrt': 'NRT', //
  'owners': 'Owner Name', 'holds': 'Number of Holds', 'builder': 'Builder', 'keel_month': 'Keel Laid Month',
  'keel_year': 'Keel Laid Year', 'operator': 'Manager / Operator Name', 'callsign': 'Callsign', 'loa': 'LOA (m)',
  'breadth': 'Breadth Moulded (m)', 'depth': 'Depth Moulded (m)', 'summer_draft': 'Summer Draft (m)',
  'summer_dwt': 'Summer Deadweight (MT)', 'class': 'Class', 'imo': 'IMO Number', 'fwe_date': 'FWE Date',
  'fwe_time': 'FWE Time (LT)', 'started_date': 'Survey Started Date', 'started_time': 'Survey Started Time (LT)',
  'completed_date': 'Survey Completed Date', 'completed_time': 'Survey Completed Time (LT)',
  'surveyor_name': 'Surveyor Name', 'chief_engineer': 'Chief Engineer Name', 'master': 'Master Name',
  'port_name': 'Port Name', 'place': 'Place of Survey', 'draft_fwd': 'Forward Draft (m)', 'draft_aft': 'Aft Draft (m)',
  'list': 'List (degrees)', 'voyage_no': 'Voyage No.', 'charterers': 'Charterers Name', 'alongside': 'Alongside',
};

/// The steps of the editor.
const srSteps = [
  ('vessel', 'Vessel Details'),
  ('survey', 'Survey Details'),
  ('tanks', 'Tank Details'),
  ('calc', 'Calculations'),
  ('generate', 'Report'),
];

/// The usual tanks of each grade, filled in once when its table is still empty (as the website does).
const _gauge = 'GAUGE';
const srDefaultTanks = <String, List<(String, bool)>>{
  'vlsfo': [
    ('1P', false), ('1S', false), ('2P', false), ('2S', false), ('3P', false), ('3S', false), //
    ('SETT 1', true), ('SERV 1', true), ('SETT 2', true), ('SERV 2', true), ('OVERFLOW', false),
  ],
  'hsfo': [('TANK NO.1', false), ('TANK NO.2', false), ('TANK NO.3', false)],
  'ulsfo': [('TANK NO.1', false), ('TANK NO.2', false), ('TANK NO.3', false)],
  'lsmgo': [
    ('MDO NO.1 P', false),
    ('MDO NO.1 S', false),
    ('MDO NO.2 P', false),
    ('MDO NO.2 S', false),
    ('SERV 1', true),
    ('SERV 2', true),
  ],
};

final _rnd = Random();
String srUid() =>
    't${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}${_rnd.nextInt(1 << 20).toRadixString(36)}';

Map<String, dynamic> _m(Object? v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
List<Map<String, dynamic>> _ml(Object? v) => ((v as List?) ?? const []).map(_m).toList();
String _s(Object? v) => v == null ? '' : '$v';

class SrIssue {
  const SrIssue(this.step, this.key, this.msg);
  final String step, key, msg;
}

class SrField {
  const SrField(this.key, this.label, this.type, {this.extra = false});
  final String key, label, type;
  final bool extra;
}

class SrGrade {
  const SrGrade(this.key, this.short, this.name);
  final String key, short, name;
}

/// A report: what the server sent (`get` / `starter`) and the data being edited.
class SrReport {
  SrReport(this.res) : cfg = _m(res['config']) {
    calc = SrCalc.fromMap(cfg['calc']);
    for (final f in _ml(cfg['fields'])) {
      _fields[_s(f['key'])] = SrField(_s(f['key']), _s(f['label']), _s(f['type']));
    }
    for (final f in _ml(cfg['extra_fields'])) {
      _fields.putIfAbsent(_s(f['key']), () => SrField(_s(f['key']), _s(f['label']), _s(f['type']), extra: true));
    }
    grades = [for (final g in _ml(cfg['grades'])) SrGrade(_s(g['key']), _s(g['short']), _s(g['name']))];
    data = normalise(_m(report['data']));
  }

  final Map<String, dynamic> res;
  final Map<String, dynamic> cfg;
  late final SrCalc? calc;
  late final List<SrGrade> grades;
  final _fields = <String, SrField>{};
  late Map<String, dynamic> data;

  Map<String, dynamic> get report => _m(res['report']);
  String get kind => _s(report['kind']);
  String get kindLabel => _s(report['kind_label']).isEmpty ? 'Survey' : _s(report['kind_label']);
  String get title => _s(report['vessel']);
  int get version => (report['version'] as num?)?.toInt() ?? 0;
  bool get canEdit => res['can_edit'] != false;
  bool get locked => res['locked'] == true;
  List<Map<String, dynamic>> get files => _ml(res['files']);
  List<String> get vesselKeys => ((cfg['vessel_keys'] as List?) ?? const []).map((e) => '$e').toList();
  List<String> get surveyRequired => ((cfg['survey_required'] as List?) ?? const []).map((e) => '$e').toList();
  Map<String, dynamic> get cert => _m(cfg['cert']);

  Map<String, dynamic> normalise(Map<String, dynamic> d) {
    d['fields'] = _m(d['fields']);
    d['extra'] = _m(d['extra']);
    final tanks = _m(d['tanks']);
    d['grades'] = ((d['grades'] as List?) ?? const []).map((e) => '$e').toList();
    d['step'] = _s(d['step']).isEmpty ? 'vessel' : _s(d['step']);
    for (final g in grades) {
      tanks[g.key] = [
        for (final r in _ml(tanks[g.key]))
          () {
            final o = <String, dynamic>{for (final k in srRowKeys) k: _s(r[k])};
            o['mode'] = o['mode'] == 'ullage' ? 'ullage' : 'sounding';
            if (_s(o['id']).isEmpty) o['id'] = srUid();
            o['su'] = o['mode'] == 'ullage' ? 'u' : (o['su'] == 's' ? 's' : '');
            return o;
          }(),
      ];
    }
    d['tanks'] = tanks;
    // the website's own steps: a grade step or the summary of older reports
    final st = _s(d['step']);
    if (st.startsWith('g:')) d['step'] = 'tanks';
    if (st == 'summary' || st == 'docs') d['step'] = st == 'docs' ? 'generate' : 'calc';
    if (!srSteps.any((x) => x.$1 == d['step'])) d['step'] = 'vessel';
    return d;
  }

  // ---------- fields ----------
  Map<String, dynamic> get fields => data['fields'] as Map<String, dynamic>;
  Map<String, dynamic> get extra => data['extra'] as Map<String, dynamic>;
  List<String> get chosenRaw => (data['grades'] as List).cast<String>();
  Map<String, dynamic> get tanks => data['tanks'] as Map<String, dynamic>;
  List<Map<String, dynamic>> rows(String g) => ((tanks[g] as List?) ?? const []).cast<Map<String, dynamic>>();

  SrField? field(String k) => _fields[k];
  bool isExtra(String k) => _fields[k]?.extra == true;
  String val(String k) => _s(isExtra(k) ? extra[k] : fields[k]).trim();
  void setVal(String k, String v) => isExtra(k) ? extra[k] = v : fields[k] = v;
  SrGrade? grade(String g) => grades.where((x) => x.key == g).firstOrNull;

  /// The chosen grades, in the config's order.
  List<String> chosen() => [
    for (final g in grades)
      if (chosenRaw.contains(g.key)) g.key,
  ];

  String label(String k) {
    final m = RegExp(r'^brob_(fwe|started)_(\w+)$').firstMatch(k);
    if (m != null) {
      return '${m.group(1) == 'fwe' ? 'FWE ROB ' : 'Log Book ROB '}${grade(m.group(2)!)?.short ?? m.group(2)} (MT)';
    }
    return srLabels[k] ?? _fields[k]?.label ?? k;
  }

  /// The certificate type: the Survey Type chosen, else the report kind's own.
  String certType() {
    final c = cert;
    if (c.isEmpty) return 'none';
    final t = val('survey_type');
    final choices = _m(c['choices']);
    if (t.isNotEmpty && choices.containsKey(t)) return t;
    return _s(_m(c['by_kind'])[_s(_m(cfg['template'])['kind'])]).isEmpty
        ? 'none'
        : _s(_m(c['by_kind'])[_s(_m(cfg['template'])['kind'])]);
  }

  // ---------- checks: the same rules as the website (and the server's srValidate) ----------
  static String typeError(String type, String v) {
    if (v.isEmpty) return '';
    final n = v.replaceAll(',', '');
    if (type == 'imo') {
      final d = v.replaceFirst(RegExp(r'^IMO\s*', caseSensitive: false), '');
      if (!RegExp(r'^\d{7}$').hasMatch(d)) return 'IMO number has 7 digits.';
      var s = 0;
      for (var i = 0; i < 6; i++) {
        s += int.parse(d[i]) * (7 - i);
      }
      return s % 10 == int.parse(d[6]) ? '' : 'IMO check digit does not match — please re-check the number.';
    }
    if (type == 'int') return RegExp(r'^\d+$').hasMatch(n) ? '' : 'Whole number only.';
    if (type.startsWith('num')) {
      if (double.tryParse(n.trim()) == null) return 'Numbers only.';
      final dec = int.tryParse(type.split(':').length > 1 ? type.split(':')[1] : '') ?? 3;
      final p = n.split('.');
      return p.length > 1 && p[1].length > dec ? 'Use at most $dec decimals.' : '';
    }
    if (type == 'time') {
      return RegExp(r'^([01]\d|2[0-3]):[0-5]\d$').hasMatch(v) ? '' : 'Enter the time as HH:MM (24 h).';
    }
    if (type == 'date') return RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v) ? '' : 'Enter a valid date.';
    return '';
  }

  static bool blankRow(Map r) =>
      !['name', 'ref_height', 'sounding', 'ullage', 'volume', 'density', 'temp'].any((k) => _s(r[k]).trim().isNotEmpty);

  static Map<String, dynamic> emptyRow() => {
    'name': '', 'ref_height': '', 'sounding': '', 'volume': '', 'density': '', 'temp': '', //
    'mode': 'sounding', 'ullage': '', 'su': '', 'done': '', 'id': srUid(), 'copy': '',
  };

  static Map<String, String> tankErrors(Map r) {
    final e = <String, String>{};
    final ull = r['mode'] == 'ullage';
    final need = [
      ('name', 'Tank name', 'text'),
      ('ref_height', 'Reference height', 'height'),
      (ull ? 'ullage' : 'sounding', ull ? 'Ullage' : 'Sounding', ull ? 'num:3' : 'height'),
      ('volume', 'Volume', 'num:3'),
      ('density', 'Density @15°C', 'num:4'),
      ('temp', 'Temperature', 'num:1'),
    ];
    for (final n in need) {
      final v = _s(r[n.$1]).trim();
      if (v.isEmpty) {
        e[n.$1] = '${n.$2} is missing.';
        continue;
      }
      if (n.$3 == 'height' || n.$3 == 'text') continue;
      final t = typeError(n.$3, v);
      if (t.isNotEmpty) {
        e[n.$1] = '${n.$2}: $t';
        continue;
      }
      final x = srNum(v)!;
      if (n.$1 == 'density' && (x < 0.6 || x > 1.1)) e[n.$1] = 'Density @15°C is normally between 0.6000 and 1.1000.';
      if (n.$1 == 'temp' && (x < -10 || x > 150)) e[n.$1] = 'Temperature looks wrong (−10 to 150 °C).';
      if (n.$1 == 'volume' && x < 0) e[n.$1] = 'Volume must be 0 or more.';
    }
    if (ull && !e.containsKey('ref_height') && !e.containsKey('ullage')) {
      if (srNum(r['ref_height']) == null) {
        e['ref_height'] = 'Reference height must be a number to work out the sounding from the ullage.';
      } else if (srNum(r['ullage'])! > srNum(r['ref_height'])!) {
        e['ullage'] = 'Ullage is more than the reference height.';
      }
    }
    return e;
  }

  List<SrIssue> issues([String? stepId]) {
    final out = <SrIssue>[];
    void add(String step, String key, String msg) {
      if (stepId == null || stepId == step) out.add(SrIssue(step, key, msg));
    }

    for (final k in ['imo', ...vesselKeys]) {
      final f = field(k);
      if (f == null) continue;
      final v = val(k), t = typeError(f.type, v);
      if (v.isEmpty) {
        add('vessel', 'f:$k', '${label(k)} is missing.');
      } else if (t.isNotEmpty) {
        add('vessel', 'f:$k', '${label(k)}: $t');
      }
    }
    final g = chosen();
    if (g.isEmpty) add('survey', 'grades', 'Choose the fuel grades on board.');
    final req = [
      ...surveyRequired,
      for (final x in g) ...['brob_fwe_$x', 'brob_started_$x'],
    ];
    for (final k in [...req, 'voyage_no', 'charterers', 'alongside', 'list']) {
      final f = field(k);
      if (f == null) continue;
      final v = val(k), t = typeError(f.type, v);
      if (v.isEmpty && req.contains(k)) {
        add('survey', 'f:$k', '${label(k)} is missing.');
      } else if (t.isNotEmpty) {
        add('survey', 'f:$k', '${label(k)}: $t');
      }
    }
    final s = '${val('started_date')} ${val('started_time')}', c = '${val('completed_date')} ${val('completed_time')}';
    if (s.length == 16 && c.length == 16 && c.compareTo(s) < 0) {
      add('survey', 'f:completed_time', 'Survey Completed is before Survey Started.');
    }
    for (final x in g) {
      final list = rows(x), short = grade(x)?.short ?? x;
      if (!list.any((r) => !blankRow(r))) {
        add('tanks', '', '$short: add at least one tank.');
        continue;
      }
      for (var i = 0; i < list.length; i++) {
        final r = list[i];
        if (blankRow(r)) continue;
        final tn = '$short tank ${i + 1}${_s(r['name']).isNotEmpty ? ' (${r['name']})' : ''}';
        tankErrors(r).forEach((col, msg) => add('tanks', 't:$x:$i:$col', '$tn: $msg'));
      }
    }
    return out;
  }

  bool stepDone(String id) {
    if (id == 'generate') return false;
    if (id == 'calc') return issues().isEmpty;
    return issues(id).isEmpty && (id != 'survey' || chosen().isNotEmpty);
  }

  // ---------- tank readings ----------
  static const _tfmt = {'sounding': 3, 'density': 4, 'temp': 1};

  /// Readings as the surveyors write them: sounding 0.000, density 0.0000, temperature 00.0.
  static String fmtT(String col, Object? v) {
    final n = srNum(v);
    if (n == null || !_tfmt.containsKey(col)) return _s(v);
    var t = n.toStringAsFixed(_tfmt[col]!);
    if (col == 'temp' && n >= 0 && n < 10) t = '0$t';
    return t;
  }

  static void fmtRow(Map r) {
    for (final c in _tfmt.keys) {
      if (c != 'sounding' || r['mode'] != 'ullage') r[c] = fmtT(c, r[c]);
    }
  }

  /// The sounding: typed, or reference height − ullage.
  static String sounding(Map r) {
    if (r['mode'] != 'ullage') return _s(r['sounding']);
    final a = srNum(r['ref_height']), b = srNum(r['ullage']);
    return a == null || b == null ? '' : (((a - b) * 1000).round() / 1000).toStringAsFixed(3);
  }

  /// The ullage: typed, or reference height − sounding.
  static String ullageOf(Map r) {
    if (r['mode'] == 'ullage') return _s(r['ullage']);
    final a = srNum(r['ref_height']), b = srNum(r['sounding']);
    return a == null || b == null ? '' : (((a - b) * 1000).round() / 1000).toStringAsFixed(3);
  }

  TankResult calcRow(Map r) => tankCalc(r, calc);

  ({double mt, double gsv, bool ok, int n}) gradeTotals(String g) {
    var mt = 0.0, gsv = 0.0, ok = true;
    final list = rows(g).where((r) => !blankRow(r)).toList();
    for (final r in list) {
      final t = calcRow(r);
      if (t.mt == null) {
        ok = false;
      } else {
        mt += t.mt!;
        gsv += t.gsv!;
      }
    }
    return (mt: xround(mt, 3), gsv: xround(gsv, 3), ok: ok && list.isNotEmpty, n: list.length);
  }

  ({int n, double mt, double gsv}) allTotals() {
    var n = 0, mt = 0.0, gsv = 0.0;
    for (final g in chosen()) {
      final t = gradeTotals(g);
      n += t.n;
      mt += t.mt;
      gsv += t.gsv;
    }
    return (n: n, mt: xround(mt, 3), gsv: xround(gsv, 3));
  }

  /// The ROB comparison: survey completed (server's result when checked) vs. the log book ROB at start.
  List<({String g, String short, double? completed, double? logbook, double? diff})> summaryRows([Map? server]) {
    final out = <({String g, String short, double? completed, double? logbook, double? diff})>[];
    for (final g in chosen()) {
      if (rows(g).isEmpty) continue;
      final tot = gradeTotals(g), log = srNum(val('brob_started_$g'));
      final sv = server is Map && server['grades'] is Map ? (server['grades'] as Map)[g] : null;
      final done = sv is Map ? (sv['completed'] as num?)?.toDouble() : (tot.ok ? tot.mt : null);
      out.add((
        g: g,
        short: grade(g)?.short ?? g,
        completed: done,
        logbook: log,
        diff: done != null && log != null ? xround(done - log, 3) : null,
      ));
    }
    return out;
  }

  /// Adds the usual tanks of each chosen grade whose table is still empty (once per grade).
  bool addDefaultTanks(Set<String> done) {
    var added = false;
    for (final g in chosen()) {
      if (done.contains(g) || !srDefaultTanks.containsKey(g)) continue;
      done.add(g);
      if (rows(g).any((r) => !blankRow(r))) continue;
      tanks[g] = [
        for (final t in srDefaultTanks[g]!)
          {
            ...emptyRow(),
            'name': t.$1,
            'ref_height': t.$2 ? _gauge : '0.000',
            'sounding': t.$2 ? _gauge : '0.000',
            'volume': '0.000',
          },
      ];
      added = true;
    }
    return added;
  }

  /// What is saved: the data without empty rows and without this app's own marks (keys starting "_").
  Map<String, dynamic> payload() => payloadOf(data);

  static Map<String, dynamic> payloadOf(Map<String, dynamic> d) {
    final t = <String, dynamic>{};
    ((d['tanks'] as Map?) ?? const {}).forEach((g, list) {
      t['$g'] = [
        for (final r in ((list as List?) ?? const []).cast<Map>())
          if (!blankRow(r))
            {
              for (final e in r.entries)
                if (!'${e.key}'.startsWith('_')) '${e.key}': e.value,
            },
      ];
    });
    return {...d, 'tanks': t};
  }
}
