// Assign vessel (Admin) / Request survey (Client) / Edit vessel (Admin): the choices of
// assign_vessel.php (app_api.php?a=assign_form), saved by posting assign_vessel.php itself (its checks,
// report number, emails and notifications). New vessels get the website's "already in Pending
// Vessels?" question (ajax/check_pending_vessel_duplicate.php); the next report number is shown
// (ajax/preview_report_number.php).
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'upload.dart' show mb;

const _maxTotal = 31 * 1024 * 1024;

class _Opt {
  const _Opt(this.value, this.label, {this.sub = '', this.warn = false});
  final String value, label, sub;
  final bool warn;
}

class AssignScreen extends StatefulWidget {
  const AssignScreen({super.key, this.editId});
  final int? editId;
  @override
  State<AssignScreen> createState() => _AssignScreenState();
}

class _AssignScreenState extends State<AssignScreen> {
  Map<String, dynamic>? _d;
  Object? _loadErr;
  final _vessel = TextEditingController(),
      _agent = TextEditingController(),
      _date = TextEditingController(),
      _remarks = TextEditingController();
  String _client = '', _port = '', _reportNo = '', _err = '';
  List<String> _types = [], _surveyors = [];
  final _files = <(PlatformFile, int)>[];
  bool _busy = false;

  bool get _edit => widget.editId != null;
  bool get _isClient => s(_d?['role']) == 'Client';

  List<Map<String, dynamic>> _list(String k) =>
      ((_d?[k] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [_vessel, _agent, _date, _remarks]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('assign_form', {if (_edit) 'edit_id': widget.editId});
      if (!mounted) return;
      final e = Map<String, dynamic>.from((d['edit'] as Map?) ?? const {});
      setState(() {
        _d = d;
        _loadErr = null;
        _vessel.text = s(e['vessel']);
        _agent.text = s(e['agent']);
        _date.text = s(e['appointment']);
        _remarks.text = s(e['remarks']);
        _client = e['client_id'] != null && e['client_id'] != 0 ? '${e['client_id']}' : '';
        if (_client.isEmpty && _isClient && _list('clients').isNotEmpty) _client = '${_list('clients').first['id']}';
        _port = e['port_id'] != null && e['port_id'] != 0 ? '${e['port_id']}' : '';
        _types = ((e['type_ids'] as List?) ?? const []).map((x) => '$x').toList();
        _surveyors = ((e['surveyor_ids'] as List?) ?? const []).map((x) => '$x').toList();
      });
      _preview();
    } catch (e) {
      if (mounted) setState(() => _loadErr = e);
    }
  }

  Future<void> _preview() async {
    if (_edit || _isClient || _client.isEmpty) return;
    try {
      final r = await Api.i.getJson('ajax/preview_report_number.php', {'client_id': _client});
      if (mounted) setState(() => _reportNo = s(r['report_number']));
    } catch (_) {
      if (mounted) setState(() => _reportNo = '—');
    }
  }

  String _name(String k, String id) {
    for (final x in _list(k)) {
      if ('${x['id']}' == id) return s(x['name']);
    }
    return '';
  }

  /// A list to choose from (search; one, or several — "Outsourcing" alone).
  Future<List<String>?> _pick(
    String title,
    List<_Opt> opts,
    List<String> sel, {
    bool multi = false,
    String exclusive = '',
  }) => showModalBottomSheet<List<String>>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (_) => _PickSheet(title: title, opts: opts, selected: sel, multi: multi, exclusive: exclusive),
  );

  Future<void> _choose(String k) async {
    switch (k) {
      case 'client':
        final v = await _pick(
          'Client',
          [for (final c in _list('clients')) _Opt('${c['id']}', s(c['name']))],
          [_client],
        );
        if (v != null && v.isNotEmpty) {
          setState(() => _client = v.first);
          _preview();
        }
      case 'port':
        final v = await _pick(
          'Port',
          [for (final p in _list('ports')) _Opt('${p['id']}', s(p['name']), sub: s(p['country']))],
          [_port],
        );
        if (v != null && v.isNotEmpty) setState(() => _port = v.first);
      case 'types':
        final v = await _pick(
          'Survey type',
          [for (final t in _list('types')) _Opt('${t['id']}', s(t['name']))],
          _types,
          multi: true,
        );
        if (v != null) setState(() => _types = v);
      default:
        final v = await _pick(
          'Surveyor(s)',
          [
            for (final x in _list('surveyors')) _Opt('${x['id']}', s(x['name'])),
            const _Opt('outsourcing', 'Outsourcing', warn: true),
          ],
          _surveyors,
          multi: true,
          exclusive: 'outsourcing',
        );
        if (v != null) setState(() => _surveyors = v);
    }
  }

  Future<void> _addFiles() async {
    List<PlatformFile> picked;
    try {
      picked = await FilePicker.pickFiles(
        type: FileType.custom,
        allowedExtensions: const ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'zip'],
      );
    } catch (_) {
      try {
        picked = await FilePicker.pickFiles();
      } catch (_) {
        return;
      }
    }
    for (final f in picked) {
      _files.add((f, await f.xFile.length()));
    }
    if (mounted) setState(() {});
  }

  Future<void> _save() async {
    String e = '';
    if (_vessel.text.trim().isEmpty) {
      e = 'Vessel name is required.';
    } else if (_client.isEmpty) {
      e = _isClient
          ? 'Your login is not linked to a client company. Please contact the admin.'
          : 'Please choose the client.';
    } else if (_agent.text.trim().isEmpty) {
      e = 'Agent name is required.';
    } else if (_port.isEmpty) {
      e = 'Please choose the port.';
    } else if (_types.isEmpty) {
      e = 'Please choose at least one survey type.';
    } else if (!_isClient && _surveyors.isEmpty) {
      e = 'Please choose a surveyor (or Outsourcing).';
    } else if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(_date.text.trim())) {
      e = 'Appointment date is required.';
    } else {
      final total = _files.fold<int>(0, (a, f) => a + f.$2);
      if (total > _maxTotal) e = 'The attachments add up to ${mb(total)} (limit 31 MB).';
    }
    setState(() => _err = e);
    if (e.isNotEmpty) return;
    if (!_edit) {
      // the website's "already in Pending Vessels?" question
      try {
        final r = await Api.i.getJson('ajax/check_pending_vessel_duplicate.php', {'vessel_name': _vessel.text.trim()});
        if (r['exists'] == true && mounted) {
          final x = Map<String, dynamic>.from((r['entry'] as Map?) ?? const {});
          final go = await showDialog<bool>(
            context: context,
            builder: (c) => AlertDialog(
              title: const Text('Already in Pending Vessels'),
              content: Text(
                'A vessel named ${s(x['vessel_name'])} is already pending:\n\n'
                'Report no.: ${s(x['report_number'])}\nClient: ${s(x['client_name'])}\nPort: ${s(x['port_name'])}',
              ),
              actions: [
                TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
                FilledButton(
                  key: const ValueKey('dup-yes'),
                  onPressed: () => Navigator.pop(c, true),
                  child: const Text('Yes, add it anyway'),
                ),
              ],
            ),
          );
          if (go != true) return;
        }
      } catch (_) {}
    }
    setState(() => _busy = true);
    try {
      final r = await Api.i.postPage(
        'assign_vessel.php${_edit ? '?edit_id=${widget.editId}' : ''}',
        (csrf) =>
            FormData.fromMap({
                if (_edit) 'edit_id': '${widget.editId}',
                'vessel_name': _vessel.text.trim(),
                'client_id': _client,
                'agent_name': _agent.text.trim(),
                'port_id': _port,
                'survey_type_ids': _types.join(','),
                'surveyor_ids': _surveyors.join(','),
                'appointment_date': _date.text.trim(),
                'remarks': _remarks.text.trim(),
                'csrf_token': csrf,
              })
              ..files.addAll([
                for (final f in _files)
                  MapEntry(
                    'assignment_attachment[]',
                    MultipartFile.fromStream(() => f.$1.xFile.openRead(), f.$2, filename: f.$1.name),
                  ),
              ]),
      );
      if (!mounted) return;
      if (!r.ok) {
        setState(() => _err = r.message.isEmpty ? 'Could not save. Please check the details.' : r.message);
        return;
      }
      toast(context, r.message.isEmpty ? 'Saved' : r.message, ok: true);
      Navigator.of(context).maybePop(true);
    } on ApiError catch (e) {
      if (mounted) setState(() => _err = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _sel(String k, String label, String value, String ph, {bool locked = false}) => Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: InkWell(
      key: ValueKey('as-$k'),
      onTap: locked ? null : () => _choose(k),
      borderRadius: BorderRadius.circular(12),
      child: InputDecorator(
        decoration: InputDecoration(
          labelText: '$label *',
          suffixIcon: Icon(locked ? Icons.lock_outline_rounded : Icons.expand_more_rounded),
        ),
        child: Text(value.isEmpty ? ph : value, style: TextStyle(color: value.isEmpty ? mutedText(context) : null)),
      ),
    ),
  );

  Widget _text(String k, String label, TextEditingController c, {bool req = true, int lines = 1, String hint = ''}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          key: ValueKey('as-$k'),
          controller: c,
          minLines: lines,
          maxLines: lines,
          textCapitalization: lines == 1 ? TextCapitalization.characters : TextCapitalization.sentences,
          decoration: InputDecoration(labelText: '$label${req ? ' *' : ''}', hintText: hint.isEmpty ? null : hint),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final title = _edit ? 'Edit vessel' : (_isClient ? 'Request survey' : 'Assign vessel');
    if (d == null) {
      return Scaffold(
        backgroundColor: pageColor(context),
        appBar: AppBar(title: Text(_edit ? 'Edit vessel' : 'Assign vessel')),
        body: _loadErr != null
            ? ListView(
                children: [ErrorState(error: _loadErr!, onRetry: _load)],
              )
            : const CardSkeletons(count: 3),
      );
    }
    final portName = _name('ports', _port);
    final portCountry =
        _list('ports').where((p) => '${p['id']}' == _port).map((p) => s(p['country'])).firstOrNull ?? '';
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(title: Text(title)),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: [
          const SectionTitle('Vessel'),
          Box(
            child: Column(
              children: [
                _text('vessel', 'Vessel name', _vessel, hint: 'e.g. MV. OCEAN STAR'),
                _sel('client', 'Client', _name('clients', _client), 'Choose client', locked: _isClient),
                if (!_edit && !_isClient && _client.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Row(
                      children: [
                        Text('Report no. ', style: TextStyle(color: mutedText(context))),
                        Text(
                          _reportNo.isEmpty ? '…' : _reportNo,
                          key: const ValueKey('as-rn'),
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                      ],
                    ),
                  ),
                _text('agent', 'Agent name', _agent),
              ],
            ),
          ),
          const SectionTitle('Survey'),
          Box(
            child: Column(
              children: [
                _sel('port', 'Port', portName.isEmpty ? '' : '$portName · $portCountry', 'Choose port'),
                _sel('types', 'Survey type', _types.map((t) => _name('types', t)).join(', '), 'Choose one or more'),
                if (!_isClient)
                  _sel(
                    'surveyors',
                    'Surveyor(s)',
                    _surveyors.map((x) => x == 'outsourcing' ? 'Outsourcing' : _name('surveyors', x)).join(', '),
                    'Choose one or more',
                  ),
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: TextField(
                    key: const ValueKey('as-date'),
                    controller: _date,
                    readOnly: true,
                    decoration: const InputDecoration(
                      labelText: 'Appointment date *',
                      suffixIcon: Icon(Icons.event_outlined),
                    ),
                    onTap: () async {
                      final now = DateTime.now();
                      final p = await showDatePicker(
                        context: context,
                        initialDate: DateTime.tryParse(_date.text) ?? now,
                        firstDate: DateTime(now.year - 5),
                        lastDate: DateTime(now.year + 3),
                      );
                      if (p != null) {
                        setState(
                          () => _date.text =
                              '${p.year}-${p.month.toString().padLeft(2, '0')}-${p.day.toString().padLeft(2, '0')}',
                        );
                      }
                    },
                  ),
                ),
                _text('remarks', 'Remarks', _remarks, req: false, lines: 3, hint: 'Any specific instructions or notes'),
              ],
            ),
          ),
          const SectionTitle('Attachments'),
          Box(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  _edit ? 'Optional · added to the files already there' : 'Optional · PDF, Word, Excel, images, ZIP',
                  style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                ),
                for (var i = 0; i < _files.length; i++)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    leading: const Icon(Icons.insert_drive_file_outlined),
                    title: Text(_files[i].$1.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                    subtitle: Text(mb(_files[i].$2)),
                    trailing: IconButton(
                      tooltip: 'Remove',
                      onPressed: () => setState(() => _files.removeAt(i)),
                      icon: const Icon(Icons.close_rounded),
                    ),
                  ),
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  key: const ValueKey('as-files'),
                  onPressed: _addFiles,
                  icon: const Icon(Icons.attach_file_rounded),
                  label: const Text('Add files'),
                ),
              ],
            ),
          ),
          if (_err.isNotEmpty)
            Container(
              key: const ValueKey('as-error'),
              margin: const EdgeInsets.only(top: 12),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(color: red.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
              child: Text(_err),
            ),
        ],
      ),
      bottomNavigationBar: SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: FilledButton.icon(
            key: const ValueKey('as-save'),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            onPressed: _busy ? null : _save,
            icon: _busy
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white),
                  )
                : Icon(_edit ? Icons.check_rounded : Icons.add_rounded),
            label: Text(_edit ? 'Save changes' : (_isClient ? 'Send request' : 'Assign & save vessel')),
          ),
        ),
      ),
    );
  }
}

class _PickSheet extends StatefulWidget {
  const _PickSheet({
    required this.title,
    required this.opts,
    required this.selected,
    required this.multi,
    required this.exclusive,
  });
  final String title, exclusive;
  final List<_Opt> opts;
  final List<String> selected;
  final bool multi;
  @override
  State<_PickSheet> createState() => _PickSheetState();
}

class _PickSheetState extends State<_PickSheet> {
  late final _sel = [...widget.selected.where((x) => x.isNotEmpty)];
  final _q = TextEditingController();

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  void _tap(_Opt o) {
    if (!widget.multi) {
      Navigator.pop(context, [o.value]);
      return;
    }
    setState(() {
      if (_sel.contains(o.value)) {
        _sel.remove(o.value);
      } else if (o.value == widget.exclusive) {
        _sel
          ..clear()
          ..add(o.value);
      } else {
        _sel
          ..remove(widget.exclusive)
          ..add(o.value);
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final q = _q.text.trim().toLowerCase();
    final shown = widget.opts.where((o) => q.isEmpty || '${o.label} ${o.sub}'.toLowerCase().contains(q)).toList();
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: .8,
      builder: (c, sc) => Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
            child: Row(
              children: [
                Expanded(
                  child: Text(widget.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                ),
                if (widget.multi)
                  FilledButton(
                    key: const ValueKey('pick-done'),
                    onPressed: () => Navigator.pop(context, _sel),
                    child: const Text('Done'),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: TextField(
              key: const ValueKey('pick-q'),
              controller: _q,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search_rounded), hintText: 'Search'),
              onChanged: (_) => setState(() {}),
            ),
          ),
          Expanded(
            child: ListView(
              controller: sc,
              children: [
                for (final o in shown)
                  ListTile(
                    key: ValueKey('pick-${o.value}'),
                    title: Text(
                      o.label,
                      style: TextStyle(color: o.warn ? orange : null, fontWeight: FontWeight.w600),
                    ),
                    subtitle: o.sub.isEmpty ? null : Text(o.sub),
                    trailing: _sel.contains(o.value) ? const Icon(Icons.check_circle_rounded, color: blue) : null,
                    onTap: () => _tap(o),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
