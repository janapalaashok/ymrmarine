// Manage templates (Admin): the Photo Report template and the Word report templates
// (app_api.php?a=templates). Upload, rename, delete — all through manage_templates.php's own forms and
// checks; the page's message is shown. Deleting asks twice.
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

class TemplatesScreen extends StatefulWidget {
  const TemplatesScreen({super.key});
  @override
  State<TemplatesScreen> createState() => _TemplatesScreenState();
}

class _TemplatesScreenState extends State<TemplatesScreen> {
  Map<String, dynamic>? _d;
  Object? _error;
  bool _busy = false, _photoSure = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('templates');
      if (mounted) {
        setState(() {
          _d = d;
          _error = null;
          _photoSure = false;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  /// Sends one of manage_templates.php's forms; true when the page says it worked.
  Future<bool> _send(Map<String, String> data, {PlatformFile? file, String? field}) async {
    setState(() => _busy = true);
    try {
      final size = file == null ? 0 : await file.xFile.length();
      final r = await Api.i.postPage(
        'manage_templates.php',
        (csrf) => FormData.fromMap({
          ...data,
          'csrf_token': csrf,
          if (file != null && field != null)
            field: MultipartFile.fromStream(() => file.xFile.openRead(), size, filename: file.name),
        }),
      );
      if (!mounted) return r.ok;
      toast(context, r.message.isNotEmpty ? r.message : (r.ok ? 'Saved' : 'Could not save.'), ok: r.ok, bad: !r.ok);
      if (r.ok) await _load();
      return r.ok;
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
      return false;
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<PlatformFile?> _pickDocx() async {
    try {
      return await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['docx']);
    } catch (_) {
      try {
        return await FilePicker.pickFile();
      } catch (_) {
        return null;
      }
    }
  }

  Future<void> _photoUpload() async {
    final f = await _pickDocx();
    if (f == null) return;
    if (!f.name.toLowerCase().endsWith('.docx')) {
      if (mounted) toast(context, 'Please choose a .docx file.', bad: true);
      return;
    }
    await _send({'upload_photo_report_template': '1'}, file: f, field: 'photo_template_file');
  }

  Future<void> _photoDelete() async {
    if (!_photoSure) {
      setState(() => _photoSure = true);
      return;
    }
    await _send({'delete_photo_report_template': '1'});
  }

  /// Upload a new Word template (t == null) or rename / delete one.
  Future<void> _sheet([Map<String, dynamic>? t]) => showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    builder: (c) => _TemplateSheet(t: t, send: _send, pick: _pickDocx),
  );

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final items = ((d?['items'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final photo = d?['photo'] is Map ? Map<String, dynamic>.from(d!['photo'] as Map) : null;
    Widget body;
    if (_error != null && d == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (d == null) {
      body = const CardSkeletons(count: 3);
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 96),
        children: [
          const SectionTitle('Photo Report template'),
          Box(
            padding: const EdgeInsets.all(14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const IconBadge(Icons.description_outlined, blue),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            photo == null ? 'Not uploaded' : s(photo['name']),
                            style: const TextStyle(fontWeight: FontWeight.w800),
                          ),
                          Text(
                            photo == null ? 'Used by the Photo Report Generator' : 'Updated ${s(photo['when'])}',
                            style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                Wrap(
                  alignment: WrapAlignment.end,
                  children: [
                    TextButton(
                      key: const ValueKey('tpl-photo-up'),
                      onPressed: _busy ? null : _photoUpload,
                      child: Text(photo == null ? 'Upload' : 'Replace'),
                    ),
                    if (photo != null)
                      TextButton(
                        key: const ValueKey('tpl-photo-del'),
                        style: TextButton.styleFrom(foregroundColor: red),
                        onPressed: _busy ? null : _photoDelete,
                        child: Text(_photoSure ? 'Tap to confirm' : 'Delete'),
                      ),
                  ],
                ),
              ],
            ),
          ),
          const SectionTitle('Word templates'),
          if (items.isEmpty)
            const EmptyState(icon: Icons.description_outlined, text: 'No Word templates yet')
          else
            for (final t in items)
              Box(
                key: ValueKey('tpl-${t['id']}'),
                margin: const EdgeInsets.only(bottom: 10),
                padding: const EdgeInsets.all(14),
                onTap: _busy ? null : () => _sheet(t),
                child: Row(
                  children: [
                    const IconBadge(Icons.article_outlined, blue),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(s(t['name']), style: const TextStyle(fontWeight: FontWeight.w800)),
                          Text(
                            [
                              s(t['type']).isEmpty ? 'Any survey type' : s(t['type']),
                              s(t['when']),
                            ].where((x) => x.isNotEmpty).join(' · '),
                            style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                          ),
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_right_rounded),
                  ],
                ),
              ),
        ],
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Manage templates'),
            Text('Word report templates', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
      floatingActionButton: FloatingActionButton(
        key: const ValueKey('tpl-add'),
        tooltip: 'Upload template',
        onPressed: _busy ? null : () => _sheet(),
        child: const Icon(Icons.upload_rounded),
      ),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}

/// The sheet of one Word template: its name and survey type (and, for a new one, the .docx file).
/// Owns its text fields, so they live exactly as long as the sheet.
class _TemplateSheet extends StatefulWidget {
  const _TemplateSheet({required this.t, required this.send, required this.pick});
  final Map<String, dynamic>? t;
  final Future<bool> Function(Map<String, String> data, {PlatformFile? file, String? field}) send;
  final Future<PlatformFile?> Function() pick;
  @override
  State<_TemplateSheet> createState() => _TemplateSheetState();
}

class _TemplateSheetState extends State<_TemplateSheet> {
  late final _name = TextEditingController(text: widget.t == null ? '' : s(widget.t!['name']));
  late final _type = TextEditingController(text: widget.t == null ? '' : s(widget.t!['type']));
  PlatformFile? _file;
  bool _sure = false, _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _type.dispose();
    super.dispose();
  }

  Future<void> _go(Future<bool> Function() f) async {
    setState(() => _busy = true);
    final ok = await f();
    if (!mounted) return;
    if (ok) {
      Navigator.pop(context);
    } else {
      setState(() => _busy = false);
    }
  }

  void _save() {
    final t = widget.t;
    if (_name.text.trim().isEmpty) {
      toast(context, 'Please enter a template name.', bad: true);
      return;
    }
    if (t == null) {
      final f = _file;
      if (f == null || !f.name.toLowerCase().endsWith('.docx')) {
        toast(context, 'Please choose a .docx file.', bad: true);
        return;
      }
      _go(
        () => widget.send(
          {'upload_template': '1', 'template_name': _name.text.trim(), 'survey_type': _type.text.trim()},
          file: f,
          field: 'template_file',
        ),
      );
    } else {
      _go(
        () => widget.send({
          'rename_template': '1',
          'template_id': '${t['id']}',
          'new_template_name': _name.text.trim(),
          'new_survey_type': _type.text.trim(),
        }),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final t = widget.t;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              t == null ? 'Upload Word template' : s(t['name']),
              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
            ),
            const SizedBox(height: 14),
            TextField(
              key: const ValueKey('tpl-name'),
              controller: _name,
              decoration: const InputDecoration(labelText: 'Template name'),
            ),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('tpl-type'),
              controller: _type,
              decoration: const InputDecoration(labelText: 'Survey type (optional)'),
            ),
            if (t == null) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(
                key: const ValueKey('tpl-pick'),
                onPressed: () async {
                  final f = await widget.pick();
                  if (f != null && mounted) setState(() => _file = f);
                },
                icon: const Icon(Icons.upload_file_rounded),
                label: Text(_file?.name ?? 'Choose .docx file'),
              ),
            ],
            const SizedBox(height: 16),
            FilledButton(
              key: const ValueKey('tpl-save'),
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
              onPressed: _busy ? null : _save,
              child: Text(t == null ? 'Upload' : 'Save'),
            ),
            if (t != null) ...[
              const SizedBox(height: 8),
              OutlinedButton(
                key: const ValueKey('tpl-delete'),
                style: OutlinedButton.styleFrom(foregroundColor: red, minimumSize: const Size.fromHeight(48)),
                onPressed: _busy
                    ? null
                    : () {
                        if (!_sure) {
                          setState(() => _sure = true);
                          return;
                        }
                        _go(() => widget.send({'delete_template': '1', 'template_id': '${t['id']}'}));
                      },
                child: Text(_sure ? 'Tap again to delete' : 'Delete'),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
