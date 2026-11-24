// The survey report editor, as the website's (survey_report_public.php): 1 Vessel Details (IMO
// lookup, the particulars), 2 Survey Details (fuel grades, FWE / start / completion with the ROB,
// owners, place, drafts, staff), 3 Tank Details, 4 Calculations (the ROB comparison, checked on the
// server), 5 Report (make the PDF, the reports made). Saved by itself; offline it is kept on this phone
// and sent when the connection is back.
import 'dart:async';

import 'package:flutter/material.dart';

import 'dart:io';

import 'package:flutter/services.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../ui.dart';
import 'sr_calc.dart';
import 'sr_fields.dart';
import 'sr_model.dart';
import 'sr_pdf.dart';
import 'sr_session.dart';
import 'sr_tanks.dart';

class SrEditorScreen extends StatefulWidget {
  const SrEditorScreen({super.key, required this.id, this.session});
  final String id;
  final SrSession? session; // tests
  @override
  State<SrEditorScreen> createState() => _SrEditorScreenState();
}

class _SrEditorScreenState extends State<SrEditorScreen> {
  late final SrSession s = widget.session ?? SrSession(widget.id, onConflict: _askConflict);
  final _boxes = SrBoxes();
  final _scroll = ScrollController();
  final _stepKeys = [for (final _ in srSteps) GlobalKey()];
  String _shownStep = '';
  final _showErr = <String>{};
  final _ai = <String>{};
  bool _manual = false, _looking = false, _making = false;
  String _lookupMsg = '', _autoImo = '';
  Map<String, dynamic>? _lookup; // {imo, prev, sources, from, at}

  @override
  void initState() {
    super.initState();
    s.addListener(_changed);
    s.onFinal ??= _finalReady;
    s.load().then((_) {
      final r = s.report;
      if (r != null && r.vesselKeys.any((k) => k != 'vessel_name' && r.val(k).isNotEmpty)) _manual = true;
      if (mounted) setState(() {});
    });
  }

  void _changed() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    s.removeListener(_changed);
    unawaited(s.flush().whenComplete(s.dispose));
    _boxes.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<bool> _askConflict(String msg) async {
    if (!mounted) return true;
    final keep = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (c) => AlertDialog(
        title: const Text('Changed elsewhere'),
        content: Text(msg),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Load the saved report')),
          FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Keep my changes')),
        ],
      ),
    );
    return keep ?? true;
  }

  /// Synced after a PDF made offline: the final report is ready.
  Future<void> _finalReady(Object? id) async {
    if (!mounted) return;
    final open = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: const Text('Synced — final report ready'),
        content: const Text('Your offline changes are saved and the final PDF was made on the YMR letterhead.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Later')),
          if (id != null) FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Open final PDF')),
        ],
      ),
    );
    if (open == true && id != null && mounted) await downloadAndOpen(context, s.fileUrl(id), name: 'Survey report.pdf');
  }

  /// The report drawn on this phone (no internet needed); the server's final report follows when online.
  Future<void> _offlinePdf() async {
    final all = r.issues();
    if (all.isNotEmpty) {
      toast(context, 'Please complete the report first: ${all.first.msg}', bad: true);
      return;
    }
    setState(() => _making = true);
    try {
      String? logo;
      try {
        logo = await rootBundle.loadString('assets/images/ymr-logo-horizontal.svg');
      } catch (_) {}
      final pdf = SrPdf(r, logoSvg: logo);
      final bytes = await pdf.build();
      final dir = await getTemporaryDirectory();
      final f = File('${dir.path}/${pdf.fileName}');
      await f.writeAsBytes(bytes, flush: true);
      await s.markOfflinePdf();
      if (!mounted) return;
      toast(context, 'PDF saved on this phone — the final report is made when you are online', ok: true);
      await OpenFilex.open(f.path);
    } catch (e) {
      if (mounted) toast(context, 'Could not make the PDF: $e', bad: true);
    } finally {
      if (mounted) setState(() => _making = false);
    }
  }

  SrReport get r => s.report!;
  String get _step => '${r.data['step']}';
  int get _stepIndex => srSteps.indexWhere((x) => x.$1 == _step).clamp(0, srSteps.length - 1);

  void _set(String k, String v) {
    r.setVal(k, v);
    _ai.remove(k);
    s.serverErrors.remove('f:$k');
    s.changed();
    if (k == 'imo') _autoLookup(v);
  }

  /// The message under a box: wrong values at once, "Required." after Next, the server's own.
  String? _err(String k) {
    final srv = s.serverErrors['f:$k'];
    if (srv != null) return srv;
    final list = r.issues(_step).where((x) => x.key == 'f:$k');
    if (list.isEmpty) return null;
    final x = list.first;
    final show =
        _showErr.contains(_step) || (_step == 'vessel' && (_lookup != null || _lookupMsg.isNotEmpty || _manual));
    final wrong = x.msg.contains(': ');
    if (!show && !wrong) return null;
    if (_step == 'vessel' && k == 'imo' && r.val('imo').isEmpty && !_showErr.contains('vessel')) return null;
    return x.msg.replaceFirst('${r.label(k)}: ', '').replaceFirst('${r.label(k)} is missing.', 'Required.');
  }

  Widget _f(String k, {String? label, bool req = false}) => SrFieldBox(
    r: r,
    k: k,
    boxes: _boxes,
    onChanged: _set,
    err: _err,
    label: label,
    req: req,
    enabled: r.canEdit,
    highlight: _ai.contains(k),
  );

  // ---------- steps ----------
  Future<void> _go(String id) async {
    await s.go(id);
    if (_scroll.hasClients) _scroll.jumpTo(0);
  }

  Future<void> _next() async {
    final id = _step;
    if (id == 'tanks') {
      var marked = false;
      for (final g in r.chosen()) {
        for (final row in r.rows(g)) {
          if (row['done'] != '1' && row['copy'] != '1' && SrReport.tankErrors(row).isEmpty && !SrReport.blankRow(row)) {
            SrReport.fmtRow(row);
            row['done'] = '1';
            row.remove('_editing');
            marked = true;
          }
        }
      }
      if (marked) s.changed(quiet: true);
    }
    final list = r.issues(id);
    if (list.isNotEmpty || (id == 'survey' && r.chosen().isEmpty)) {
      setState(() => _showErr.add(id));
      await showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          title: const Text('Please complete this step'),
          content: SingleChildScrollView(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                for (final x in list.take(8))
                  Padding(padding: const EdgeInsets.only(bottom: 4), child: Text('• ${x.msg}')),
                if (list.length > 8) Text('… and ${list.length - 8} more'),
              ],
            ),
          ),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('OK'))],
        ),
      );
      return;
    }
    if (_stepIndex < srSteps.length - 1) await _go(srSteps[_stepIndex + 1].$1);
  }

  bool _reachable(int i) {
    for (var j = 0; j < i; j++) {
      if (!r.stepDone(srSteps[j].$1)) return false;
    }
    return true;
  }

  // ---------- vessel lookup ----------
  void _autoLookup(String v) {
    final d = v.replaceAll(RegExp(r'\D'), '');
    if (!r.canEdit || d.length != 7 || _autoImo == d || SrReport.typeError('imo', d).isNotEmpty || s.isDraft) return;
    Timer(const Duration(milliseconds: 400), () {
      if (mounted && r.val('imo') == d && !_looking) {
        _autoImo = d;
        _doLookup();
      }
    });
  }

  Future<void> _doLookup() async {
    if (_looking) return;
    final imo = r.val('imo');
    final e = SrReport.typeError('imo', imo);
    if (imo.isEmpty || e.isNotEmpty) {
      setState(() => _showErr.add('vessel'));
      toast(context, e.isNotEmpty ? e : 'Enter the 7-digit IMO number first.', bad: true);
      return;
    }
    setState(() {
      _looking = true;
      _lookupMsg = '';
      _autoImo = imo;
    });
    final res = await s.lookup(imo);
    if (!mounted) return;
    setState(() {
      _looking = false;
      _manual = true;
      if (res['ok'] != true) {
        _lookupMsg = '${res['error'] ?? 'Nothing found for this IMO.'}';
        _lookup = null;
        return;
      }
      final prev = <String, String>{};
      final found = Map<String, dynamic>.from((res['fields'] as Map?) ?? const {});
      for (final e in found.entries) {
        if (!r.vesselKeys.contains(e.key) && e.key != 'owners') continue;
        final old = r.val(e.key);
        if (old == '${e.value}') continue;
        prev[e.key] = old;
        r.setVal(e.key, '${e.value}');
        _ai.add(e.key);
      }
      _lookup = {
        'imo': res['imo'],
        'prev': prev,
        'from': res['from'] ?? 'web',
        'at': res['at'] ?? '',
        'sources': res['sources'] ?? [],
      };
    });
    if (((_lookup?['prev'] as Map?) ?? const {}).isNotEmpty) s.changed();
  }

  void _undoLookup() {
    final l = _lookup;
    if (l == null) return;
    (l['prev'] as Map).forEach((k, v) {
      r.setVal('$k', '$v');
      _ai.remove('$k');
    });
    setState(() => _lookup = null);
    s.changed();
  }

  // ---------- step 1 ----------
  Widget _vessel() {
    final show = _manual || _lookup != null;
    final miss = show ? r.issues('vessel').where((x) => x.key != 'f:imo').toList() : const <SrIssue>[];
    final l = _lookup;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SrCard(
          title: 'IMO Number',
          icon: Icons.search_rounded,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(child: _f('imo', label: 'IMO Number', req: true)),
                if (r.canEdit) ...[
                  const SizedBox(width: 8),
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: FilledButton.icon(
                      key: const ValueKey('sr-lookup'),
                      onPressed: _looking || s.isDraft ? null : _doLookup,
                      icon: _looking
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Icon(Icons.search_rounded),
                      label: Text(_looking ? 'Looking up…' : 'Lookup'),
                    ),
                  ),
                ],
              ],
            ),
            if (_looking)
              _note(
                'Finding the particulars of IMO ${r.val('imo')}… You can carry on; they are filled in when found.',
                blue,
              ),
            if (_lookupMsg.isNotEmpty) _note('$_lookupMsg You can type the particulars in below.', orange),
            if (l != null)
              _note(
                '${(l['prev'] as Map).length} field${(l['prev'] as Map).length == 1 ? '' : 's'} filled for IMO ${l['imo']} — '
                '${l['from'] == 'report'
                    ? "from this vessel's last survey report"
                    : l['from'] == 'saved'
                    ? 'found earlier for this IMO'
                    : 'found on the web'}'
                '; please check the highlighted values against the ship\'s documents.',
                purple,
                action: r.canEdit
                    ? TextButton(key: const ValueKey('sr-undo'), onPressed: _undoLookup, child: const Text('Undo'))
                    : null,
              ),
            if (s.isDraft) _note('Offline: Lookup works once the report is sent. Type the particulars below.', orange),
            if (!show && r.canEdit)
              TextButton(
                key: const ValueKey('sr-manual'),
                onPressed: () => setState(() => _manual = true),
                child: const Text('or enter the particulars by hand'),
              ),
          ],
        ),
        if (show)
          SrCard(
            title: 'Vessel particulars',
            icon: Icons.directions_boat_outlined,
            children: [
              if (miss.isNotEmpty)
                _note(
                  '${miss.length} particular${miss.length == 1 ? '' : 's'} still to enter: ${miss.map((x) => r.label(x.key.substring(2))).join(', ')}.',
                  red,
                ),
              SrGrid(children: [for (final k in r.vesselKeys) _f(k, req: true)]),
            ],
          ),
      ],
    );
  }

  Widget _note(String text, Color c, {Widget? action}) => Container(
    margin: const EdgeInsets.only(top: 10),
    padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
    decoration: BoxDecoration(
      color: c.withValues(alpha: .08),
      border: Border.all(color: c.withValues(alpha: .35)),
      borderRadius: BorderRadius.circular(12),
    ),
    child: Row(
      children: [
        Expanded(child: Text(text, style: const TextStyle(fontSize: 13, height: 1.35))),
        ?action,
      ],
    ),
  );

  // ---------- step 2 ----------
  Future<void> _grade(String g, bool on) async {
    if (!on && r.rows(g).isNotEmpty) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text('Remove ${r.grade(g)?.short ?? g}?'),
          content: Text(
            'Its ${r.rows(g).length} tank(s) are kept but left out of the report. Tick it again to bring them back.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
            FilledButton(onPressed: () => Navigator.pop(c, true), child: const Text('Remove')),
          ],
        ),
      );
      if (ok != true) return;
    }
    if (on && !r.chosenRaw.contains(g)) r.chosenRaw.add(g);
    if (!on) r.chosenRaw.remove(g);
    s.changed();
  }

  Widget _event(String title, String sub, IconData icon, String d, String t, String? rob, String robTitle) {
    final g = r.chosen();
    return SrCard(
      title: title,
      icon: icon,
      trailing: r.canEdit
          ? TextButton(
              key: ValueKey('sr-now-$d'),
              onPressed: () {
                final n = srNow();
                _set(d, n.$1);
                _set(t, n.$2);
              },
              child: const Text('Now'),
            )
          : null,
      children: [
        Text(sub, style: TextStyle(fontSize: 12, color: mutedText(context))),
        const SizedBox(height: 8),
        SrGrid(
          children: [
            _f(d, label: 'Date', req: true),
            _f(t, label: 'Time (LT)', req: true),
          ],
        ),
        if (rob != null) ...[
          const SizedBox(height: 12),
          Text('$robTitle (MT)', style: const TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          if (g.isEmpty)
            Text('Choose the fuel grades above first.', style: TextStyle(color: mutedText(context)))
          else
            SrGrid(children: [for (final x in g) _f('brob_${rob}_$x', label: r.grade(x)?.short ?? x, req: true)]),
        ],
      ],
    );
  }

  Widget _survey() {
    final g = r.chosen();
    final fwd = srNum(r.val('draft_fwd')), aft = srNum(r.val('draft_aft'));
    final trim = fwd != null && aft != null ? (aft - fwd).toStringAsFixed(3) : '';
    final gradeErr = _showErr.contains('survey') && g.isEmpty;
    const keys = [
      'owners',
      'charterers',
      'port_name',
      'place',
      'draft_aft',
      'draft_fwd',
      'master',
      'chief_engineer',
      'surveyor_name',
    ];
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SrCard(
          title: 'Fuel grades on board *',
          icon: Icons.local_gas_station_outlined,
          trailing: Text(
            g.isEmpty ? 'tap the grades' : '${g.length} selected',
            style: TextStyle(color: mutedText(context)),
          ),
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final x in r.grades)
                  FilterChip(
                    key: ValueKey('sr-grade-${x.key}'),
                    selected: g.contains(x.key),
                    label: Text(x.short, style: const TextStyle(fontWeight: FontWeight.w800)),
                    tooltip: x.name,
                    onSelected: r.canEdit ? (on) => _grade(x.key, on) : null,
                  ),
              ],
            ),
            if (gradeErr)
              const Padding(
                padding: EdgeInsets.only(top: 6),
                child: Text('Choose the fuel grades on board.', style: TextStyle(color: red)),
              ),
          ],
        ),
        _event(
          'Finish With Engine (FWE)',
          'As per Engine Logbook',
          Icons.settings_outlined,
          'fwe_date',
          'fwe_time',
          'fwe',
          'FWE ROB',
        ),
        _event(
          'Survey Start Time',
          'As per Engine Logbook',
          Icons.play_arrow_rounded,
          'started_date',
          'started_time',
          'started',
          'Log Book ROB',
        ),
        _event(
          'Survey Completed Time',
          '1-2 hours from start time',
          Icons.flag_outlined,
          'completed_date',
          'completed_time',
          null,
          '',
        ),
        SrCard(
          title: 'Survey particulars',
          icon: Icons.assignment_outlined,
          trailing: srDoneMark(r, keys),
          children: [
            SrGrid(
              children: [
                if (r.cert.isNotEmpty) _f('survey_type', label: 'Survey Type'),
                _f('owners', label: 'Owners', req: true),
                _f('charterers', label: 'Charterers', req: true),
                _f('port_name', req: true),
                _f('place', label: 'Place of Survey', req: true),
                _f('voyage_no', label: 'Voyage No.'),
                _f('draft_aft', label: 'Draft Aft (m)', req: true),
                _f('draft_fwd', label: 'Draft Fwd (m)', req: true),
                InputDecorator(
                  decoration: const InputDecoration(labelText: 'Trim (m) — Aft − Fwd, worked out', filled: true),
                  child: Text(trim.isEmpty ? '—' : trim, key: const ValueKey('sr-trim')),
                ),
                _f('list', label: 'List (degrees)'),
                _f('master', label: 'Master (Capt.)', req: true),
                _f('chief_engineer', label: 'Chief Engineer', req: true),
                _f('surveyor_name', label: 'Surveyor Name', req: true),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              'Names print in capital letters on the report, however they are typed.',
              style: TextStyle(fontSize: 12, color: mutedText(context)),
            ),
          ],
        ),
      ],
    );
  }

  // ---------- step 4 ----------
  Widget _problems() {
    final all = r.issues();
    if (all.isEmpty) return const SizedBox.shrink();
    final by = <String, List<SrIssue>>{};
    for (final x in all) {
      (by[x.step] ??= []).add(x);
    }
    final names = {for (final x in srSteps) x.$1: x.$2};
    return SrCard(
      key: const ValueKey('sr-problems'),
      title: 'Before making the report, complete:',
      icon: Icons.warning_amber_rounded,
      children: [
        for (final e in by.entries)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(names[e.key] ?? e.key, style: const TextStyle(fontWeight: FontWeight.w800)),
                      for (final x in e.value.take(6)) Text('• ${x.msg}', style: const TextStyle(fontSize: 13)),
                      if (e.value.length > 6)
                        Text('… and ${e.value.length - 6} more', style: const TextStyle(fontSize: 13)),
                    ],
                  ),
                ),
                TextButton(
                  onPressed: () => _go(names.containsKey(e.key) ? e.key : 'survey'),
                  child: const Text('Go to step'),
                ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _calc() {
    final rows = r.summaryRows(s.server);
    var tot = 0.0;
    var any = false;
    for (final x in rows) {
      if (x.diff != null) {
        tot += x.diff!;
        any = true;
      }
    }
    tot = xround(tot, 3);
    Color dc(double? v) => v == null || v == 0 ? Colors.black87 : (v < 0 ? red : green);
    TableRow head(List<String> l) => TableRow(
      children: [
        for (final x in l)
          Padding(
            padding: const EdgeInsets.all(6),
            child: Text(
              x,
              style: TextStyle(fontSize: 11.5, fontWeight: FontWeight.w800, color: mutedText(context)),
            ),
          ),
      ],
    );
    Widget cell(String t, {Color? c, bool b = false}) => Padding(
      padding: const EdgeInsets.all(6),
      child: Text(
        t,
        style: TextStyle(fontWeight: b ? FontWeight.w800 : FontWeight.w600, color: c),
      ),
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SrCard(
          title: 'ROB comparison',
          icon: Icons.balance_rounded,
          trailing: s.verifying
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : (s.server != null
                    ? const Tooltip(
                        message: 'Calculated on the server',
                        child: Icon(Icons.verified_user_rounded, color: green),
                      )
                    : null),
          children: [
            if (rows.isEmpty)
              Text('No fuel grade with tanks yet.', style: TextStyle(color: mutedText(context)))
            else ...[
              Table(
                key: const ValueKey('sr-cmp'),
                columnWidths: const {0: FlexColumnWidth(1.1)},
                children: [
                  head(['Grade', 'Survey', 'Log Book', 'Difference']),
                  for (final x in rows)
                    TableRow(
                      children: [
                        cell(x.short, b: true),
                        cell(f3(x.completed)),
                        cell(f3(x.logbook)),
                        cell(sgn(x.diff), c: dc(x.diff), b: true),
                      ],
                    ),
                ],
              ),
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: navy, borderRadius: BorderRadius.circular(12)),
                child: Row(
                  children: [
                    const Expanded(
                      child: Text(
                        'TOTAL DIFFERENCE',
                        style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w800),
                      ),
                    ),
                    Text(
                      any ? '${sgn(tot)} MT' : '—',
                      key: const ValueKey('sr-total-diff'),
                      style: TextStyle(
                        color: tot < 0 ? const Color(0xFFFCA5A5) : (tot > 0 ? const Color(0xFF86EFAC) : Colors.white),
                        fontWeight: FontWeight.w800,
                        fontSize: 17,
                      ),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 8),
              Text(
                'Survey = sum of MT in air of the tanks of each grade. Log Book = ROB at survey start. Difference = Survey − Log Book.',
                style: TextStyle(fontSize: 12, color: mutedText(context)),
              ),
              const SizedBox(height: 8),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  key: const ValueKey('sr-share'),
                  onPressed: () => _shareText(rows, any ? tot : null),
                  icon: const Icon(Icons.copy_all_rounded),
                  label: const Text('Copy for WhatsApp'),
                ),
              ),
            ],
          ],
        ),
        SrCard(
          title: 'Totals per grade',
          icon: Icons.calculate_outlined,
          children: [
            Table(
              key: const ValueKey('sr-gtotals'),
              children: [
                head(['Grade', 'Tanks', 'G.S.V. m³', 'MT (air)']),
                for (final g in r.chosen())
                  TableRow(
                    children: [
                      cell(r.grade(g)?.short ?? g, b: true),
                      cell('${r.rows(g).length}'),
                      cell(f3c(r.gradeTotals(g).gsv)),
                      cell(f3c(r.gradeTotals(g).mt), b: true),
                    ],
                  ),
              ],
            ),
          ],
        ),
        _problems(),
      ],
    );
  }

  Future<void> _shareText(
    List<({String g, String short, double? completed, double? logbook, double? diff})> rows,
    double? tot,
  ) async {
    final b = StringBuffer('${r.val('vessel_name').toUpperCase()} — ${r.kindLabel} ROB comparison\n');
    for (final x in rows) {
      b.writeln(
        '${x.short}: survey ${f3(x.completed)} MT · log book ${f3(x.logbook)} MT · difference ${sgn(x.diff)} MT',
      );
    }
    if (tot != null) b.writeln('TOTAL DIFFERENCE: ${sgn(tot)} MT');
    await Clipboard.setData(ClipboardData(text: b.toString().trim()));
    if (mounted) toast(context, 'Copied — paste it in the WhatsApp chat', ok: true);
  }

  // ---------- step 5 ----------
  Future<void> _make() async {
    setState(() => _making = true);
    final res = await s.generate();
    if (!mounted) return;
    setState(() => _making = false);
    if (res['ok'] != true) {
      final issues = ((res['issues'] as List?) ?? const []).whereType<Map>().toList();
      await showDialog<void>(
        context: context,
        builder: (c) => AlertDialog(
          title: const Text('Report not made'),
          content: Text(
            issues.isNotEmpty
                ? issues.take(8).map((x) => '• ${x['msg']}').join('\n')
                : '${res['error'] ?? 'Please try again.'}',
          ),
          actions: [FilledButton(onPressed: () => Navigator.pop(c), child: const Text('OK'))],
        ),
      );
      return;
    }
    if ('${res['pdf_error'] ?? ''}'.isNotEmpty) {
      toast(context, '${res['pdf_error']}', bad: true);
      return;
    }
    final id = (res['new_files'] as Map?)?['pdf'];
    if (id != null) {
      toast(context, 'PDF report made', ok: true);
      final f = r.files.where((x) => '${x['id']}' == '$id').firstOrNull;
      await downloadAndOpen(context, s.fileUrl(id), name: '${f?['file_name'] ?? 'Survey report.pdf'}');
    }
  }

  Widget _generate() {
    final all = r.issues();
    final ct = r.certType();
    final types = Map<String, dynamic>.from((r.cert['types'] as Map?) ?? const {});
    final choices = Map<String, dynamic>.from((r.cert['choices'] as Map?) ?? const {});
    final certTitle = ct != 'none' && types[ct] is Map ? '${(types[ct] as Map)['title']}' : '';
    final pdfs = r.files.where((f) => f['type'] == 'pdf').toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        _problems(),
        SrCard(
          title: 'Make the report',
          icon: Icons.picture_as_pdf_outlined,
          children: [
            FilledButton.icon(
              key: const ValueKey('sr-download-pdf'),
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              onPressed: all.isEmpty && !_making && r.canEdit ? _make : null,
              icon: _making
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                    )
                  : const Icon(Icons.picture_as_pdf_rounded),
              label: Text(_making ? 'Making the report…' : 'Download PDF Report'),
            ),
            const SizedBox(height: 8),
            Text(
              'The PDF is made in the YMR report format on the company letterhead'
              '${certTitle.isNotEmpty ? ': page 1 the $certTitle (${choices[ct] ?? ct}), then the tank report' : ''}'
              '; its Excel is kept in the system for the Word report.',
              style: TextStyle(fontSize: 12.5, color: mutedText(context)),
            ),
            if (all.isEmpty)
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  key: const ValueKey('sr-offline-pdf'),
                  onPressed: _making ? null : _offlinePdf,
                  icon: const Icon(Icons.airplanemode_active_rounded),
                  label: const Text('No internet? Make the PDF on this phone'),
                ),
              ),
            if (s.isDraft || s.fromCache)
              _note('Offline: the final report is made on the server once this phone is online.', orange),
          ],
        ),
        SrCard(
          title: 'Reports made',
          icon: Icons.folder_open_outlined,
          children: [
            if (pdfs.isEmpty)
              Text('No report made yet.', style: TextStyle(color: mutedText(context)))
            else
              for (final f in pdfs)
                ListTile(
                  key: ValueKey('sr-file-${f['id']}'),
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.picture_as_pdf_rounded, color: red),
                  title: Text('${f['file_name']}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                  subtitle: Text(
                    '${f['created_at'] ?? ''}'.length >= 16
                        ? '${f['created_at']}'.substring(0, 16)
                        : '${f['created_at'] ?? ''}',
                  ),
                  trailing: const Icon(Icons.download_rounded),
                  onTap: () => downloadAndOpen(context, s.fileUrl(f['id']), name: '${f['file_name']}'),
                ),
          ],
        ),
      ],
    );
  }

  // ---------- page ----------
  @override
  Widget build(BuildContext context) {
    if (s.loading || s.report == null) {
      return Scaffold(
        backgroundColor: pageColor(context),
        appBar: AppBar(title: const Text('Survey report')),
        body: s.error.isNotEmpty && s.report == null
            ? ListView(
                children: [EmptyState(icon: Icons.cloud_off_rounded, color: orange, text: s.error)],
              )
            : const CardSkeletons(count: 4),
      );
    }
    final i = _stepIndex;
    final st = srSteps[i];
    if (_shownStep != st.$1) {
      // the step bar shows the current step
      _shownStep = st.$1;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        final c = _stepKeys[i].currentContext;
        if (c != null && c.mounted) {
          Scrollable.ensureVisible(c, alignment: .5, duration: const Duration(milliseconds: 250));
        }
      });
    }
    final saveColor = s.saveState == 'Saved' ? green : (s.saveState.startsWith('Save failed') ? red : orange);
    final body = switch (st.$1) {
      'vessel' => _vessel(),
      'survey' => _survey(),
      'tanks' => SrTanks(s: s, boxes: _boxes, showAll: _showErr.contains('tanks')),
      'calc' => _calc(),
      _ => _generate(),
    };
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              r.val('vessel_name').isNotEmpty ? r.val('vessel_name').toUpperCase() : r.title,
              overflow: TextOverflow.ellipsis,
            ),
            Text('${r.kindLabel} report', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Center(
              child: Tag(s.saveState.isEmpty ? 'Saved' : s.saveState, saveColor, key: const ValueKey('sr-save-state')),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          if (!r.canEdit) _banner(r.locked ? 'This report is locked by the Admin — read only.' : 'View only.', orange),
          if (s.isDraft || s.fromCache)
            _banner('Offline — saved on this phone, sent when you are back online.', orange),
          if (s.error.isNotEmpty) _banner(s.error, red),
          SizedBox(
            height: 56,
            child: ListView(
              key: const ValueKey('sr-steps'),
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              children: [
                for (var j = 0; j < srSteps.length; j++)
                  Padding(
                    padding: const EdgeInsets.only(right: 6),
                    key: _stepKeys[j],
                    child: ChoiceChip(
                      key: ValueKey('sr-step-${srSteps[j].$1}'),
                      selected: j == i,
                      avatar: r.stepDone(srSteps[j].$1) && j != i
                          ? const Icon(Icons.check_circle_rounded, color: green, size: 18)
                          : null,
                      label: Text('${j + 1}. ${srSteps[j].$2}'),
                      onSelected: !_reachable(j) && j > i
                          ? null
                          : (_) {
                              if (j != i) _go(srSteps[j].$1);
                            },
                    ),
                  ),
              ],
            ),
          ),
          Expanded(
            child: ListView(
              controller: _scroll,
              padding: const EdgeInsets.fromLTRB(14, 4, 14, 24),
              children: [
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Text(
                    st.$2,
                    key: const ValueKey('sr-step-title'),
                    style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
                  ),
                ),
                body,
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 6, 12, 8),
          child: Row(
            children: [
              OutlinedButton(
                key: const ValueKey('sr-back'),
                onPressed: i > 0 ? () => _go(srSteps[i - 1].$1) : null,
                child: const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [Icon(Icons.chevron_left_rounded), Text('Previous')],
                ),
              ),
              const SizedBox(width: 10),
              if (i < srSteps.length - 1)
                Expanded(
                  child: FilledButton(
                    key: const ValueKey('sr-next'),
                    onPressed: _next,
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Flexible(child: Text('Next: ${srSteps[i + 1].$2}', overflow: TextOverflow.ellipsis)),
                        const SizedBox(width: 6),
                        const Icon(Icons.arrow_forward_rounded, size: 18),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _banner(String text, Color c) => Container(
    width: double.infinity,
    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
    color: c.withValues(alpha: .12),
    child: Text(
      text,
      style: TextStyle(color: c, fontWeight: FontWeight.w700, fontSize: 13),
    ),
  );
}
