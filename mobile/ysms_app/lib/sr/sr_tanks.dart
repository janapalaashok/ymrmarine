// Tank Details: one group per chosen fuel grade, one card per tank — name, reference height, sounding
// or ullage (S / U, the other worked out), volume, density @15°C, temperature, with V.C.F., W.C.F.,
// G.S.V. and MT in air as they are typed (Table 54B). Save tank checks it as the website does; copy,
// delete (asked), move up / down (the report keeps this order), and tanks read from an Excel file.
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../ui.dart';
import 'sr_calc.dart';
import 'sr_fields.dart';
import 'sr_model.dart';
import 'sr_session.dart';

class SrTanks extends StatefulWidget {
  const SrTanks({super.key, required this.s, required this.boxes, required this.showAll, this.pick});
  final SrSession s;
  final SrBoxes boxes;

  /// Show every tank's messages (after Next on an incomplete step).
  final bool showAll;
  final Future<PlatformFile?> Function()? pick; // tests

  @override
  State<SrTanks> createState() => _SrTanksState();
}

class _SrTanksState extends State<SrTanks> {
  final _open = <String>{};
  final _shut = <String>{};
  bool _firstOpened = false, _importing = false;

  SrReport get r => widget.s.report!;
  bool get _edit => r.canEdit;

  @override
  void initState() {
    super.initState();
    if (_edit && r.addDefaultTanks(widget.s.defaultsDone)) {
      WidgetsBinding.instance.addPostFrameCallback((_) => widget.s.changed(quiet: true));
    }
  }

  void _openFirst() {
    if (_firstOpened) return;
    _firstOpened = true;
    for (final g in r.chosen()) {
      final i = r.rows(g).indexWhere((x) => SrReport.tankErrors(x).isNotEmpty);
      if (i >= 0) _open.add('${r.rows(g)[i]['id']}');
    }
  }

  void _set(Map<String, dynamic> row, String col, String v) {
    if (row['done'] == '1') row['done'] = '';
    row['_editing'] = true;
    if (col == 'sounding') {
      row['mode'] = 'sounding';
      row['sounding'] = v;
      row['ullage'] = '';
      if (srNum(v) == null && row['su'] == 's') row['su'] = '';
    } else if (col == 'ullage') {
      row['mode'] = 'ullage';
      row['ullage'] = v;
      row['sounding'] = SrReport.sounding(row);
    } else {
      row[col] = v;
      if (col == 'ref_height' && row['mode'] == 'ullage') row['sounding'] = SrReport.sounding(row);
    }
    widget.s.changed();
    setState(() {});
  }

  /// Leaving a reading: shown in the report's format (0.000 / 0.0000 / 00.0).
  void _format(Map<String, dynamic> row, String col) {
    if (!const ['sounding', 'density', 'temp'].contains(col)) return;
    if (col == 'sounding' && row['mode'] == 'ullage') return;
    final v = SrReport.fmtT(col, row[col]);
    if (v != row[col]) {
      row[col] = v;
      widget.s.changed();
      setState(() {});
    }
  }

  void _su(Map<String, dynamic> row, String which) {
    final su = row['su'] == which ? '' : which;
    final m = su == 'u' ? 'ullage' : 'sounding';
    row['su'] = su;
    if (row['mode'] != m) {
      if (m == 'ullage') {
        row['ullage'] = SrReport.ullageOf(row);
        row['mode'] = 'ullage';
        row['sounding'] = SrReport.sounding(row);
      } else {
        row['sounding'] = SrReport.sounding(row);
        row['mode'] = 'sounding';
        row['ullage'] = '';
      }
    }
    if (row['done'] == '1') row['done'] = '';
    row['_editing'] = true;
    widget.s.changed();
    setState(() {});
  }

  void _save(String g, Map<String, dynamic> row) {
    row['_tried'] = true;
    if (SrReport.tankErrors(row).isNotEmpty) {
      setState(() {});
      toast(context, 'Complete the marked values of this tank.', bad: true);
      return;
    }
    SrReport.fmtRow(row);
    row['done'] = '1';
    row['copy'] = '';
    row.remove('_tried');
    row.remove('_editing');
    row.remove('_src');
    _open.remove('${row['id']}');
    widget.s.changed(quiet: true);
    setState(() {});
    toast(context, 'Tank saved', ok: true);
  }

  void _copy(String g, int i) {
    final src = r.rows(g)[i];
    final row = <String, dynamic>{for (final k in srRowKeys) k: '${src[k] ?? ''}'};
    row['id'] = srUid();
    row['done'] = '';
    row['copy'] = '1';
    (r.tanks[g] as List).insert(i + 1, row);
    _open.add('${row['id']}');
    widget.s.changed();
    setState(() {});
    toast(context, 'Tank copied — change the values and press Save tank', ok: true);
  }

  Future<void> _delete(String g, int i) async {
    final row = r.rows(g)[i];
    if (!SrReport.blankRow(row)) {
      final ok = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: Text('Delete tank ${i + 1}${'${row['name']}'.isNotEmpty ? ' "${row['name']}"' : ''}?'),
          content: const Text('Its readings are removed from the report.'),
          actions: [
            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
            FilledButton(
              key: const ValueKey('sr-del-ok'),
              style: FilledButton.styleFrom(backgroundColor: red),
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Delete'),
            ),
          ],
        ),
      );
      if (ok != true) return;
    }
    (r.tanks[g] as List).removeAt(i);
    widget.s.changed();
    if (mounted) setState(() {});
  }

  void _move(String g, int from, int to) {
    final a = r.tanks[g] as List;
    if (to < 0 || to >= a.length) return;
    final x = a.removeAt(from);
    a.insert(to, x);
    widget.s.changed(quiet: true);
    setState(() {});
  }

  void _add(String g) {
    final row = SrReport.emptyRow();
    (r.tanks[g] as List).add(row);
    _open.add('${row['id']}');
    widget.s.changed();
    setState(() {});
  }

  Future<void> _import() async {
    PlatformFile? f;
    try {
      f = widget.pick != null
          ? await widget.pick!()
          : await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['xlsx', 'xlsm', 'xls', 'csv']);
    } catch (_) {
      try {
        f = await FilePicker.pickFile();
      } catch (_) {
        return;
      }
    }
    if (f == null || !mounted) return;
    final file = f;
    setState(() => _importing = true);
    final size = await file.xFile.length();
    final res = await widget.s.importTanks(file.name, () => file.xFile.openRead(), size);
    if (!mounted) return;
    setState(() => _importing = false);
    if (res['ok'] != true) {
      toast(context, '${res['error'] ?? 'Not imported.'}', bad: true);
      return;
    }
    final rows = ((res['rows'] as List?) ?? const []).whereType<Map>().toList();
    if (rows.isEmpty) {
      toast(context, 'No tanks found in this file.', bad: true);
      return;
    }
    var have = 0;
    for (final g in r.chosen()) {
      have += r.rows(g).length;
    }
    final by = <String, int>{};
    for (final t in rows) {
      by['${t['grade']}'] = (by['${t['grade']}'] ?? 0) + 1;
    }
    final how = await showDialog<String>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text('Import ${rows.length} tank${rows.length == 1 ? '' : 's'}?'),
        content: Text(by.entries.map((e) => '${r.grade(e.key)?.short ?? e.key}: ${e.value}').join(' · ')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c), child: const Text('Cancel')),
          if (have > 0) TextButton(onPressed: () => Navigator.pop(c, 'replace'), child: const Text('Replace the list')),
          FilledButton(
            key: const ValueKey('sr-import-ok'),
            onPressed: () => Navigator.pop(c, 'add'),
            child: Text(have > 0 ? 'Add to the list' : 'Import'),
          ),
        ],
      ),
    );
    if (how == null || !mounted) return;
    if (how == 'replace') {
      for (final g in r.chosen()) {
        r.tanks[g] = <Map<String, dynamic>>[];
      }
    }
    for (final t in rows) {
      final g = '${t['grade']}';
      if (!r.tanks.containsKey(g)) continue;
      if (!r.chosenRaw.contains(g)) r.chosenRaw.add(g);
      final row = SrReport.emptyRow();
      for (final k in ['name', 'ref_height', 'volume', 'density', 'temp']) {
        row[k] = '${t[k] ?? ''}';
      }
      if ('${t['ullage'] ?? ''}'.isNotEmpty && '${t['sounding'] ?? ''}'.isEmpty) {
        row['mode'] = 'ullage';
        row['su'] = 'u';
        row['ullage'] = '${t['ullage']}';
        row['sounding'] = SrReport.sounding(row);
      } else {
        row['sounding'] = '${t['sounding'] ?? ''}';
      }
      if (SrReport.tankErrors(row).isEmpty) {
        SrReport.fmtRow(row);
        row['done'] = '1';
      }
      (r.tanks[g] as List).add(row);
    }
    widget.s.changed();
    setState(() {});
    toast(context, '${rows.length} tanks imported', ok: true);
  }

  Widget _box(Map<String, dynamic> row, String g, int i, String col, String label, {bool numeric = true}) {
    final id = '${row['id']}';
    final shown = switch (col) {
      'sounding' => row['mode'] == 'ullage' ? SrReport.sounding(row) : '${row['sounding']}',
      'ullage' => SrReport.ullageOf(row),
      _ => '${row[col] ?? ''}',
    };
    final readOnly = (col == 'sounding' && row['mode'] == 'ullage') || (col == 'ullage' && row['mode'] != 'ullage');
    final key = 't:$id:$col';
    final c = widget.boxes.ctl(key, shown);
    final f = widget.boxes.focus(key);
    final errs = SrReport.tankErrors(row);
    final show = widget.showAll || row['_tried'] == true || (errs[col] ?? '').contains(': ');
    final e = show && !SrReport.blankRow(row) ? errs[col] : null;
    final gauge = RegExp(r'^GAUGE$', caseSensitive: false).hasMatch(shown.trim());
    return Focus(
      onFocusChange: (has) {
        if (!has) _format(row, col);
      },
      child: TextField(
        key: ValueKey('sr-t-$g-$i-$col'),
        controller: c,
        focusNode: f,
        enabled: _edit,
        readOnly: readOnly,
        keyboardType: numeric && !gauge ? const TextInputType.numberWithOptions(decimal: true) : TextInputType.text,
        textCapitalization: TextCapitalization.characters,
        style: TextStyle(color: gauge ? purple : null, fontWeight: gauge ? FontWeight.w800 : null),
        decoration: InputDecoration(
          labelText: readOnly ? '$label (worked out)' : label,
          errorText: e?.replaceFirst(RegExp(r'^.*?: '), ''),
          errorMaxLines: 3,
          filled: readOnly,
        ),
        onChanged: readOnly ? null : (v) => _set(row, col, v),
      ),
    );
  }

  Widget _card(String g, int i) {
    final row = r.rows(g)[i];
    final id = '${row['id']}';
    final open = _open.contains(id);
    final t = r.calcRow(row);
    final done = row['done'] == '1' && row['_editing'] != true;
    final copy = row['copy'] == '1';
    final reading = row['mode'] == 'ullage' ? '${row['ullage']}' : '${row['sounding']}';
    final suShown = srNum(reading) != null || row['mode'] == 'ullage';
    final hasErr = SrReport.tankErrors(row).isNotEmpty && !SrReport.blankRow(row);
    final n = r.rows(g).length;
    Widget res(String l, String v, {bool strong = false}) => Expanded(
      child: Column(
        children: [
          Text(
            l,
            style: TextStyle(fontSize: 11, color: mutedText(context), fontWeight: FontWeight.w700),
          ),
          Text(
            v.isEmpty ? '—' : v,
            style: TextStyle(fontWeight: FontWeight.w800, color: strong ? blue : null, fontSize: strong ? 15 : 13),
          ),
        ],
      ),
    );
    return Box(
      key: ValueKey('sr-tank-$g-$i'),
      margin: const EdgeInsets.only(bottom: 8),
      padding: EdgeInsets.zero,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InkWell(
            key: ValueKey('sr-tc-toggle-$g-$i'),
            onTap: () => setState(() => open ? _open.remove(id) : _open.add(id)),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 8, 10),
              child: Row(
                children: [
                  Icon(open ? Icons.expand_more_rounded : Icons.chevron_right_rounded),
                  const SizedBox(width: 4),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${row['name']}'.isEmpty ? 'New tank' : '${row['name']}',
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                        Text(
                          done
                              ? 'Saved'
                              : copy
                              ? 'Copy — change the values, then Save tank'
                              : (hasErr ? 'To complete' : 'Not saved'),
                          style: TextStyle(
                            fontSize: 11.5,
                            fontWeight: FontWeight.w700,
                            color: done ? green : (copy ? purple : orange),
                          ),
                        ),
                      ],
                    ),
                  ),
                  Text('MT ', style: TextStyle(fontSize: 12, color: mutedText(context))),
                  Text(
                    t.mt == null ? '—' : f3c(t.mt),
                    key: ValueKey('sr-mt-$g-$i'),
                    style: const TextStyle(fontWeight: FontWeight.w800, color: blue),
                  ),
                  if (_edit) ...[
                    IconButton(
                      key: ValueKey('sr-up-$g-$i'),
                      tooltip: 'Move up',
                      visualDensity: VisualDensity.compact,
                      onPressed: i > 0 ? () => _move(g, i, i - 1) : null,
                      icon: const Icon(Icons.arrow_upward_rounded, size: 18),
                    ),
                    IconButton(
                      key: ValueKey('sr-down-$g-$i'),
                      tooltip: 'Move down',
                      visualDensity: VisualDensity.compact,
                      onPressed: i < n - 1 ? () => _move(g, i, i + 1) : null,
                      icon: const Icon(Icons.arrow_downward_rounded, size: 18),
                    ),
                  ],
                ],
              ),
            ),
          ),
          if (open)
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _box(row, g, i, 'name', 'Tank name', numeric: false),
                  const SizedBox(height: 10),
                  SrGrid(
                    children: [
                      _box(row, g, i, 'ref_height', 'Ref. height (m)'),
                      if (row['mode'] == 'ullage')
                        _box(row, g, i, 'ullage', 'Ullage (m)')
                      else
                        _box(row, g, i, 'sounding', 'Sounding (m)'),
                    ],
                  ),
                  if (suShown) ...[
                    const SizedBox(height: 6),
                    Row(
                      children: [
                        Text('Reading:', style: TextStyle(fontSize: 12.5, color: mutedText(context))),
                        const SizedBox(width: 8),
                        _suChip(row, g, i, 's', 'S', 'Sounding', sky),
                        const SizedBox(width: 6),
                        _suChip(row, g, i, 'u', 'U', 'Ullage', green),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            row['mode'] == 'ullage'
                                ? 'Sounding ${SrReport.sounding(row)}'
                                : (SrReport.ullageOf(row).isEmpty ? '' : 'Ullage ${SrReport.ullageOf(row)}'),
                            style: TextStyle(fontSize: 12, color: mutedText(context)),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                  ],
                  const SizedBox(height: 10),
                  SrGrid(
                    children: [
                      _box(row, g, i, 'volume', 'Volume (m³)'),
                      _box(row, g, i, 'density', 'Density @15°C'),
                      _box(row, g, i, 'temp', 'Temperature (°C)'),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    decoration: BoxDecoration(
                      color: blue.withValues(alpha: .06),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Row(
                      children: [
                        res('V.C.F.', t.vcf == null ? '' : t.vcf!.toStringAsFixed(4)),
                        res('W.C.F.', t.wcf == null ? '' : t.wcf!.toStringAsFixed(4)),
                        res('G.S.V. m³', f3(t.gsv)),
                        res('MT in air', f3(t.mt), strong: true),
                      ],
                    ),
                  ),
                  if (_edit) ...[
                    const SizedBox(height: 10),
                    Wrap(
                      alignment: WrapAlignment.end,
                      spacing: 8,
                      children: [
                        TextButton.icon(
                          key: ValueKey('sr-del-$g-$i'),
                          style: TextButton.styleFrom(foregroundColor: red),
                          onPressed: () => _delete(g, i),
                          icon: const Icon(Icons.delete_outline_rounded),
                          label: const Text('Delete'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('sr-copy-$g-$i'),
                          onPressed: () => _copy(g, i),
                          icon: const Icon(Icons.copy_rounded),
                          label: const Text('Copy'),
                        ),
                        FilledButton.icon(
                          key: ValueKey('sr-save-tank-$g-$i'),
                          onPressed: () => _save(g, row),
                          icon: const Icon(Icons.check_rounded),
                          label: const Text('Save tank'),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _suChip(Map<String, dynamic> row, String g, int i, String which, String text, String tip, Color color) {
    final on = row['su'] == which;
    return Tooltip(
      message: tip,
      child: ChoiceChip(
        key: ValueKey('sr-su-$g-$i-$which'),
        label: Text(
          text,
          style: TextStyle(fontWeight: FontWeight.w800, color: on ? Colors.white : color),
        ),
        selected: on,
        selectedColor: color,
        backgroundColor: color.withValues(alpha: .12),
        showCheckmark: false,
        visualDensity: VisualDensity.compact,
        onSelected: _edit ? (_) => _su(row, which) : null,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final g = r.chosen();
    if (g.isEmpty) {
      return Box(
        padding: const EdgeInsets.all(16),
        child: Column(
          children: [
            const Text('Choose the fuel grades on board in Survey Details first.'),
            TextButton(onPressed: () => widget.s.go('survey'), child: const Text('Go to Survey Details')),
          ],
        ),
      );
    }
    _openFirst();
    final all = r.allTotals();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (_edit)
          Align(
            alignment: Alignment.centerRight,
            child: TextButton.icon(
              key: const ValueKey('sr-import'),
              onPressed: _importing ? null : _import,
              icon: _importing
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.upload_file_rounded),
              label: const Text('Import tanks from Excel'),
            ),
          ),
        for (final x in g) ...[
          InkWell(
            key: ValueKey('sr-tcg-toggle-$x'),
            onTap: () => setState(() => _shut.contains(x) ? _shut.remove(x) : _shut.add(x)),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 10),
              child: Row(
                children: [
                  Icon(_shut.contains(x) ? Icons.chevron_right_rounded : Icons.expand_more_rounded),
                  const Icon(Icons.local_gas_station_outlined, color: blue, size: 20),
                  const SizedBox(width: 6),
                  Expanded(
                    child: Text(
                      '${r.grade(x)?.short ?? x} Tanks (${r.rows(x).length})',
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                    ),
                  ),
                  Text('Total MT ', style: TextStyle(fontSize: 12, color: mutedText(context))),
                  Text(
                    f3c(r.gradeTotals(x).mt),
                    key: ValueKey('sr-total-$x'),
                    style: const TextStyle(fontWeight: FontWeight.w800),
                  ),
                ],
              ),
            ),
          ),
          if (!_shut.contains(x)) ...[
            if (r.rows(x).isEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text('No tanks yet.', style: TextStyle(color: mutedText(context))),
              ),
            for (var i = 0; i < r.rows(x).length; i++) _card(x, i),
            if (_edit)
              OutlinedButton.icon(
                key: ValueKey('sr-add-$x'),
                onPressed: () => _add(x),
                icon: const Icon(Icons.add_rounded),
                label: Text('Add ${r.grade(x)?.short ?? x} tank'),
              ),
          ],
          const SizedBox(height: 8),
        ],
        Container(
          margin: const EdgeInsets.only(top: 4),
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: navy, borderRadius: BorderRadius.circular(14)),
          child: Row(
            children: [
              Text(
                'Tanks: ${all.n}',
                style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerRight,
                  child: Text.rich(
                    key: const ValueKey('sr-tot-mt'),
                    TextSpan(
                      children: [
                        const TextSpan(
                          text: 'Total MT  ',
                          style: TextStyle(color: Colors.white70),
                        ),
                        TextSpan(
                          text: '${f3c(all.mt)} MT',
                          style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}
