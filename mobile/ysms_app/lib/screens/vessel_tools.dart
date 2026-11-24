// The vessel screen's own tools, as the web app's vessel screen has them: email the agent
// (ajax/send_agent_update.php), edit recovery and add / delete remarks (the detail page's own forms, so
// its checks apply), replace a document (ajax/document_replace.php). Each returns true when it saved, so
// the screen reloads. Who may do what is decided by those pages; the buttons only follow the flags the
// server sent (can_edit, can_add, can_delete, can_replace).
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

Widget _busyLabel(bool busy, String label) => busy
    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
    : Text(label);

Widget _sheet(BuildContext c, String title, List<Widget> children, {String? sub}) => Padding(
  padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(c).viewInsets.bottom),
  child: SingleChildScrollView(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(title, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
        if (sub != null)
          Padding(
            padding: const EdgeInsets.only(top: 4),
            child: Text(sub, style: TextStyle(color: mutedText(c))),
          ),
        const SizedBox(height: 12),
        ...children,
      ],
    ),
  ),
);

Future<bool> _open(BuildContext context, Widget Function(BuildContext) b) async =>
    await showModalBottomSheet<bool>(context: context, isScrollControlled: true, showDragHandle: true, builder: b) ==
    true;

/// Send the survey update to the agent: the saved emails, or ask for one.
Future<bool> agentMailSheet(BuildContext context, int id) async {
  final Map<String, dynamic> d;
  try {
    d = await Api.i.getJson('ajax/send_agent_update.php', {'action': 'lookup', 'survey_id': id});
  } on ApiError catch (e) {
    if (context.mounted) toast(context, e.message, bad: true);
    return false;
  }
  if (!context.mounted) return false;
  return _open(context, (_) => _AgentSheet(id: id, d: d));
}

class _AgentSheet extends StatefulWidget {
  const _AgentSheet({required this.id, required this.d});
  final int id;
  final Map<String, dynamic> d;
  @override
  State<_AgentSheet> createState() => _AgentSheetState();
}

class _AgentSheetState extends State<_AgentSheet> {
  final _a = TextEditingController(), _b = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _a.dispose();
    _b.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final has = widget.d['has_emails'] == true;
    if (!has && _a.text.trim().isEmpty) {
      toast(context, 'Enter the agent email.', bad: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final r = await Api.i.postJson('ajax/send_agent_update.php', {
        'action': 'send',
        'survey_id': '${widget.id}',
        if (!has) 'email_primary': _a.text.trim(),
        if (!has) 'email_optional': _b.text.trim(),
      });
      if (!mounted) return;
      toast(context, s(r['message']).isEmpty ? 'Email sent.' : s(r['message']), ok: true);
      Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, e.message, bad: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final d = widget.d, has = d['has_emails'] == true;
    final emails = ((d['emails'] as List?) ?? const []).join(', ');
    return _sheet(
      context,
      'Send update to agent',
      sub: '${s(d['agent_name'])} · ${has ? emails : 'no email saved yet'}',
      [
        if (!has) ...[
          TextField(
            key: const ValueKey('ag-1'),
            controller: _a,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(labelText: 'Agent email'),
          ),
          const SizedBox(height: 10),
          TextField(
            key: const ValueKey('ag-2'),
            controller: _b,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(labelText: 'Another email (optional)'),
          ),
          const SizedBox(height: 14),
        ],
        FilledButton(
          key: const ValueKey('ag-send'),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
          onPressed: _busy ? null : _send,
          child: _busyLabel(_busy, 'Send email'),
        ),
      ],
    );
  }
}

/// Edit recovery (MT) with the detail page's update_recovery form. Total = VLSFO + LSMGO + HSFO when
/// any of the three is filled; with all three empty the total is typed (older vessels have only a total).
Future<bool> recoverySheet(BuildContext context, String page, Map rec) =>
    _open(context, (_) => _RecoverySheet(page: page, rec: rec));

class _RecoverySheet extends StatefulWidget {
  const _RecoverySheet({required this.page, required this.rec});
  final String page;
  final Map rec;
  @override
  State<_RecoverySheet> createState() => _RecoverySheetState();
}

class _RecoverySheetState extends State<_RecoverySheet> {
  static const _f = [
    ('recovery_amount', 'Total', 'total'),
    ('vlsfo_recovery', 'VLSFO', 'vlsfo'),
    ('lsmgo_recovery', 'LSMGO', 'lsmgo'),
    ('hsfo_recovery', 'HSFO', 'hsfo'),
  ];
  late final _c = {for (final f in _f) f.$1: TextEditingController(text: s(widget.rec[f.$3]))};
  bool _busy = false;

  bool get _auto => _f.skip(1).any((f) => _c[f.$1]!.text.trim().isNotEmpty);

  @override
  void initState() {
    super.initState();
    _sum();
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  void _sum() {
    if (!_auto) return;
    final t = _f.skip(1).fold<double>(0, (a, f) => a + (double.tryParse(_c[f.$1]!.text.trim()) ?? 0));
    _c['recovery_amount']!.text = t.toStringAsFixed(3);
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      final r = await Api.i.postPage(
        widget.page,
        (csrf) => {'update_recovery': '1', for (final f in _f) f.$1: _c[f.$1]!.text.trim(), 'csrf_token': csrf},
      );
      if (!r.ok) throw ApiError(400, r.message.isEmpty ? 'Could not save.' : r.message);
      if (!mounted) return;
      toast(context, r.message.isEmpty ? 'Recovery saved' : r.message, ok: true);
      Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, e.message, bad: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final auto = _auto;
    return _sheet(context, 'Edit recovery (MT)', [
      Wrap(
        spacing: 10,
        runSpacing: 10,
        children: [
          for (final f in _f)
            SizedBox(
              width: 150,
              child: TextField(
                key: ValueKey('rec-${f.$3}'),
                controller: _c[f.$1],
                readOnly: f.$3 == 'total' && auto,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(labelText: f.$2, filled: f.$3 == 'total' && auto),
                onChanged: (_) => setState(_sum),
              ),
            ),
        ],
      ),
      if (auto)
        Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Text('Total = VLSFO + LSMGO + HSFO', style: TextStyle(fontSize: 12, color: mutedText(context))),
        ),
      const SizedBox(height: 14),
      FilledButton(
        key: const ValueKey('rec-save'),
        style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
        onPressed: _busy ? null : _save,
        child: _busyLabel(_busy, 'Save recovery'),
      ),
    ]);
  }
}

/// Replace one uploaded document with a new file (ajax/document_replace.php decides who may).
Future<bool> replaceDocument(BuildContext context, dynamic docId, {Future<PlatformFile?> Function()? pick}) async {
  PlatformFile? f;
  try {
    f = await (pick ?? FilePicker.pickFile)();
  } catch (_) {
    return false;
  }
  if (f == null || !context.mounted) return false;
  final file = f;
  toast(context, 'Replacing…');
  try {
    final size = await file.xFile.length();
    final r = await Api.i.post(
      'ajax/document_replace.php',
      (csrf) => FormData.fromMap({
        'document_id': '$docId',
        'csrf_token': csrf,
        'new_file': MultipartFile.fromStream(() => file.xFile.openRead(), size, filename: file.name),
      }),
      csrfHeader: true,
    );
    final d = Api.i.json(r);
    if (d == null || d['success'] != true) {
      throw ApiError(r.statusCode ?? 500, s(d?['message']).isEmpty ? 'Could not replace the file' : s(d?['message']));
    }
    if (context.mounted) toast(context, s(d['message']).isEmpty ? 'Document replaced' : s(d['message']), ok: true);
    return true;
  } on ApiError catch (e) {
    if (context.mounted) toast(context, e.message, bad: true);
    return false;
  }
}

/// Add a remark (text and / or files) with the detail page's add_remark form.
Future<bool> addRemarkSheet(BuildContext context, String page) => _open(context, (_) => _RemarkSheet(page: page));

class _RemarkSheet extends StatefulWidget {
  const _RemarkSheet({required this.page});
  final String page;
  @override
  State<_RemarkSheet> createState() => _RemarkSheetState();
}

class _RemarkSheetState extends State<_RemarkSheet> {
  final _t = TextEditingController();
  final _files = <PlatformFile>[];
  bool _busy = false;

  @override
  void dispose() {
    _t.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    try {
      final r = await FilePicker.pickFiles();
      if (mounted) setState(() => _files.addAll(r));
    } catch (_) {}
  }

  Future<void> _save() async {
    final text = _t.text.trim();
    if (text.isEmpty && _files.isEmpty) {
      toast(context, 'Write a remark or attach a file.', bad: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final parts = <MapEntry<String, MultipartFile>>[];
      for (final f in _files) {
        final size = await f.xFile.length();
        parts.add(
          MapEntry('remark_attachments[]', MultipartFile.fromStream(() => f.xFile.openRead(), size, filename: f.name)),
        );
      }
      final r = await Api.i.postPage(
        widget.page,
        (csrf) => FormData.fromMap({'add_remark': '1', 'remarks': text, 'csrf_token': csrf})..files.addAll(parts),
      );
      if (!r.ok) throw ApiError(400, r.message.isEmpty ? 'Could not save.' : r.message);
      if (!mounted) return;
      toast(context, r.message.isEmpty ? 'Remark saved' : r.message, ok: true);
      Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, e.message, bad: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) => _sheet(context, 'Add remark', [
    TextField(
      key: const ValueKey('rm-text'),
      controller: _t,
      autofocus: true,
      minLines: 2,
      maxLines: 6,
      decoration: const InputDecoration(hintText: 'Add a remark'),
    ),
    const SizedBox(height: 8),
    for (final f in _files)
      Chip(
        label: Text(f.name, overflow: TextOverflow.ellipsis),
        onDeleted: () => setState(() => _files.remove(f)),
      ),
    Align(
      alignment: Alignment.centerLeft,
      child: TextButton.icon(
        key: const ValueKey('rm-attach'),
        onPressed: _busy ? null : _pick,
        icon: const Icon(Icons.attach_file_rounded),
        label: const Text('Attach files'),
      ),
    ),
    const SizedBox(height: 8),
    FilledButton(
      key: const ValueKey('rm-save'),
      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
      onPressed: _busy ? null : _save,
      child: _busyLabel(_busy, 'Save remark'),
    ),
  ]);
}

/// Delete a remark with the detail page's form; true when the page says it worked.
Future<bool> deleteRemark(BuildContext context, String page, dynamic id) async {
  try {
    final r = await Api.i.postPage(page, (csrf) => {'delete_remark_id': '$id', 'csrf_token': csrf});
    if (context.mounted) {
      toast(
        context,
        r.message.isEmpty ? (r.ok ? 'Remark deleted' : 'Could not delete.') : r.message,
        ok: r.ok,
        bad: !r.ok,
      );
    }
    return r.ok;
  } on ApiError catch (e) {
    if (context.mounted) toast(context, e.message, bad: true);
    return false;
  }
}
