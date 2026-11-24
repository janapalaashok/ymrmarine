// Website pages made in Flutter: Surveyor ID / business cards (admin_surveyor_cards.php), Daily emails
// (admin_daily_emails.php), Permission copy (generate_permission_copy.php) and the Coming soon page.
// The data comes from app_api.php (read-only); every change posts to the page's own form, so its
// checks (Admin only, file types, valid times …) apply and its message is shown.
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

Widget _loadBody(Object? error, Object? data, VoidCallback retry, Widget Function() ok) {
  if (error != null && data == null) {
    return ListView(children: [ErrorState(error: error, onRetry: retry)]);
  }
  if (data == null) return const CardSkeletons(count: 3);
  return ok();
}

PreferredSizeWidget _bar(String title, [String? sub]) => AppBar(
  title: Column(
    crossAxisAlignment: CrossAxisAlignment.start,
    children: [
      Text(title),
      if (sub != null) Text(sub, style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
    ],
  ),
);

Map<String, dynamic> _map(dynamic v) => v is Map ? Map<String, dynamic>.from(v) : <String, dynamic>{};
List<Map<String, dynamic>> _maps(dynamic l) => ((l as List?) ?? const []).map(_map).toList();

/// Posts one of the page's forms and shows its message; true when the page says it worked.
Future<bool> _post(BuildContext context, String page, Object Function(String csrf) body) async {
  try {
    final r = await Api.i.postPage(page, body);
    if (context.mounted) {
      toast(context, r.message.isNotEmpty ? r.message : (r.ok ? 'Saved' : 'Could not save.'), ok: r.ok, bad: !r.ok);
    }
    return r.ok;
  } on ApiError catch (e) {
    if (context.mounted) toast(context, e.message, bad: true);
    return false;
  }
}

// ───────────────────────── Surveyor ID / business cards ─────────────────────────

class SurveyorCardsScreen extends StatefulWidget {
  const SurveyorCardsScreen({super.key, this.pick});
  final Future<PlatformFile?> Function()? pick; // tests
  @override
  State<SurveyorCardsScreen> createState() => _SurveyorCardsScreenState();
}

class _SurveyorCardsScreenState extends State<SurveyorCardsScreen> {
  static const _page = 'admin_surveyor_cards.php';
  List<Map<String, dynamic>>? _items;
  Object? _error;
  String _busy = '', _sure = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('surveyor_cards');
      if (mounted) {
        setState(() {
          _items = _maps(d['items']);
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<PlatformFile?> _pickFile() async {
    if (widget.pick != null) return widget.pick!();
    try {
      return await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['pdf', 'jpg', 'jpeg', 'png']);
    } catch (_) {
      try {
        return await FilePicker.pickFile();
      } catch (_) {
        return null;
      }
    }
  }

  Future<void> _upload(int uid, String type) async {
    final f = await _pickFile();
    if (f == null || !mounted) return;
    final ext = f.name.contains('.') ? f.name.split('.').last.toLowerCase() : '';
    if (!const ['pdf', 'jpg', 'jpeg', 'png'].contains(ext)) {
      toast(context, 'Only PDF, JPG or PNG files are allowed.', bad: true);
      return;
    }
    setState(() => _busy = '$type-$uid');
    final size = await f.xFile.length();
    if (!mounted) return;
    final ok = await _post(
      context,
      _page,
      (csrf) => FormData.fromMap({
        'upload_card': '1',
        'user_id': '$uid',
        'card_type': type,
        'csrf_token': csrf,
        'card_file': MultipartFile.fromStream(() => f.xFile.openRead(), size, filename: f.name),
      }),
    );
    if (mounted) setState(() => _busy = '');
    if (ok) _load();
  }

  Future<void> _remove(int uid, String type) async {
    final k = '$type-$uid';
    if (_sure != k) {
      setState(() => _sure = k);
      return;
    }
    setState(() {
      _sure = '';
      _busy = k;
    });
    final ok = await _post(
      context,
      _page,
      (csrf) => {'remove_card': '1', 'user_id': '$uid', 'card_type': type, 'csrf_token': csrf},
    );
    if (mounted) setState(() => _busy = '');
    if (ok) _load();
  }

  Widget _row(Map<String, dynamic> u, String type, String label) {
    final path = s(u[type]);
    final uid = (u['id'] as num).toInt();
    final k = '$type-$uid';
    final busy = _busy == k;
    return Container(
      padding: const EdgeInsets.only(top: 8),
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: lineColor(context))),
      ),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
                Text(
                  path.isEmpty ? 'Not uploaded yet' : 'Uploaded',
                  style: TextStyle(fontSize: 12, color: path.isEmpty ? mutedText(context) : green),
                ),
              ],
            ),
          ),
          if (busy)
            const Padding(
              padding: EdgeInsets.all(12),
              child: SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)),
            ),
          if (!busy && path.isNotEmpty) ...[
            IconButton(
              key: ValueKey('card-dl-$k'),
              tooltip: 'Download',
              onPressed: () => downloadAndOpen(context, path, name: path.split('/').last),
              icon: const Icon(Icons.download_rounded, color: blue),
            ),
            TextButton(
              key: ValueKey('card-rm-$k'),
              style: TextButton.styleFrom(foregroundColor: red),
              onPressed: () => _remove(uid, type),
              child: Text(_sure == k ? 'Tap to confirm' : 'Remove'),
            ),
          ],
          if (!busy)
            IconButton(
              key: ValueKey('card-up-$k'),
              tooltip: path.isEmpty ? 'Upload' : 'Replace',
              onPressed: _busy.isEmpty ? () => _upload(uid, type) : null,
              icon: const Icon(Icons.upload_rounded, color: purple),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: pageColor(context),
    appBar: _bar('ID / Business Cards', 'Surveyor cards'),
    body: RefreshIndicator(
      onRefresh: _load,
      child: _loadBody(_error, _items, _load, () {
        final items = _items!;
        if (items.isEmpty) {
          return ListView(
            children: const [EmptyState(icon: Icons.badge_outlined, color: purple, text: 'No surveyors found.')],
          );
        }
        return ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            for (final u in items)
              Box(
                key: ValueKey('sc-${u['id']}'),
                margin: const EdgeInsets.only(bottom: 12),
                padding: const EdgeInsets.fromLTRB(14, 12, 6, 8),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(s(u['name']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                    Text('@${s(u['username'])}', style: TextStyle(fontSize: 12, color: mutedText(context))),
                    const SizedBox(height: 6),
                    _row(u, 'business_card', 'Business Card'),
                    _row(u, 'id_card', 'ID Card'),
                  ],
                ),
              ),
          ],
        );
      }),
    ),
  );
}

// ───────────────────────── Daily emails ─────────────────────────

class DailyEmailsScreen extends StatefulWidget {
  const DailyEmailsScreen({super.key});
  @override
  State<DailyEmailsScreen> createState() => _DailyEmailsScreenState();
}

class _DailyEmailsScreenState extends State<DailyEmailsScreen> {
  static const _page = 'admin_daily_emails.php';
  Map<String, dynamic>? _d;
  Object? _error;
  bool _busy = false;
  String _sureNow = '';
  final _on = <String, bool>{}, _empty = <String, bool>{}, _time = <String, String>{};
  final _from = TextEditingController();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _from.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('daily_emails');
      if (!mounted) return;
      setState(() {
        _d = d;
        _error = null;
        for (final j in _maps(d['jobs'])) {
          final k = s(j['job']);
          _on[k] = j['enabled'] == true;
          _empty[k] = j['send_empty'] == true;
          _time[k] = s(j['time']);
          if (k == 'surveyor_pending') _from.text = s(j['from']);
        }
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final jobs = _maps(_d?['jobs']).map((j) => s(j['job'])).toList();
    final ok = await _post(
      context,
      _page,
      (csrf) => {
        'action': 'save',
        'csrf_token': csrf,
        'surveyor_pending_from': _from.text.trim(),
        for (final k in jobs) ...{
          '${k}_time': _time[k] ?? '',
          if (_on[k] == true) '${k}_enabled': '1',
          if (_empty[k] == true) '${k}_send_empty': '1',
        },
      },
    );
    if (mounted) setState(() => _busy = false);
    if (ok) _load();
  }

  Future<void> _run(String action, String job, String label) async {
    if (action == 'send_now' && _sureNow != job) {
      setState(() => _sureNow = job);
      toast(context, 'Tap Send now again to email the real recipients of $label (in addition to the scheduled email).');
      return;
    }
    setState(() {
      _busy = true;
      _sureNow = '';
    });
    final ok = await _post(context, _page, (csrf) => {'action': action, 'job': job, 'csrf_token': csrf});
    if (mounted) setState(() => _busy = false);
    if (ok) _load();
  }

  Future<void> _pickTime(String job) async {
    final p = (_time[job] ?? '17:00').split(':');
    final t = await showTimePicker(
      context: context,
      initialTime: TimeOfDay(hour: int.tryParse(p.first) ?? 17, minute: p.length > 1 ? int.tryParse(p[1]) ?? 0 : 0),
      builder: (c, w) => MediaQuery(data: MediaQuery.of(c).copyWith(alwaysUse24HourFormat: true), child: w!),
    );
    if (t != null && mounted) {
      setState(() => _time[job] = '${t.hour.toString().padLeft(2, '0')}:${t.minute.toString().padLeft(2, '0')}');
    }
  }

  Color _statusColor(String st) => switch (st) {
    'sent' => green,
    'failed' => red,
    'running' || 'pending' => blue,
    _ => muted,
  };

  Widget _note(String text, Color c) => Container(
    margin: const EdgeInsets.only(bottom: 10),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: c.withValues(alpha: .08),
      border: Border.all(color: c.withValues(alpha: .35)),
      borderRadius: BorderRadius.circular(14),
    ),
    child: Text(text, style: const TextStyle(fontSize: 13, height: 1.4)),
  );

  Widget _job(Map<String, dynamic> j) {
    final k = s(j['job']);
    final counts = _map(_d?['counts']);
    final weekly = j['weekly'] == true;
    final extra = switch (k) {
      'surveyor_pending' =>
        '${_d?['surveyors_with_email']} of ${_d?['surveyors']} active surveyors have a profile email.',
      'all_pending' => 'Now: ${counts['all_pending']} vessels.',
      'appointments' => 'Today so far: ${counts['appointments']} vessels.',
      'all_reports' => 'Now: ${counts['all_reports']} reports.',
      _ => '',
    };
    return Box(
      key: ValueKey('de-$k'),
      margin: const EdgeInsets.only(bottom: 12),
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(s(j['label']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
              ),
              Tag(_on[k] == true ? 'ON' : 'OFF', _on[k] == true ? green : muted),
            ],
          ),
          if (extra.isNotEmpty) Text(extra, style: TextStyle(fontSize: 12.5, color: mutedText(context))),
          SwitchListTile(
            key: ValueKey('de-on-$k'),
            contentPadding: EdgeInsets.zero,
            title: Text(weekly ? 'Send every Friday' : 'Send every day'),
            value: _on[k] == true,
            onChanged: (v) => setState(() => _on[k] = v),
          ),
          ListTile(
            key: ValueKey('de-time-$k'),
            contentPadding: EdgeInsets.zero,
            leading: const Icon(Icons.schedule_rounded),
            title: const Text('Time (IST)'),
            trailing: Text(_time[k] ?? '', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
            onTap: () => _pickTime(k),
          ),
          if (k == 'surveyor_pending')
            TextField(
              key: const ValueKey('de-from'),
              controller: _from,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'From'),
            ),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Text(s(j['route']), style: TextStyle(fontSize: 12.5, color: mutedText(context))),
          ),
          CheckboxListTile(
            key: ValueKey('de-empty-$k'),
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _empty[k] == true,
            title: Text(switch (k) {
              'surveyor_pending' => 'Also email surveyors who have no assigned vessels',
              'surveyor_reports' => 'Also email surveyors who have no pending reports',
              'all_pending' => 'Send a "no pending vessels" email when the list is empty',
              'appointments' => 'Send a "no appointments" email when there are none that day',
              _ => 'Send a "no pending reports" email when the list is empty',
            }, style: const TextStyle(fontSize: 13.5)),
            onChanged: (v) => setState(() => _empty[k] = v == true),
          ),
        ],
      ),
    );
  }

  Widget _today(Map<String, dynamic> j) {
    final k = s(j['job']);
    final t = j['today'] is Map ? _map(j['today']) : null;
    return Padding(
      key: ValueKey('de-today-$k'),
      padding: const EdgeInsets.only(top: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(s(j['label']), style: const TextStyle(fontWeight: FontWeight.w700)),
          if (t == null)
            Text(
              j['due_today'] == true ? 'Not sent yet (due at ${s(j['time'])} IST)' : 'Not due today — Fridays only',
              style: TextStyle(fontSize: 12.5, color: mutedText(context)),
            )
          else ...[
            const SizedBox(height: 4),
            Wrap(
              spacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                Tag(s(t['status']), _statusColor(s(t['status']))),
                Text(
                  'attempt ${t['attempts']} · ${s(t['via'])}',
                  style: TextStyle(fontSize: 12, color: mutedText(context)),
                ),
              ],
            ),
            if (s(t['detail']).isNotEmpty) Text(s(t['detail']), style: const TextStyle(fontSize: 12.5)),
            for (final d in _maps(t['deliveries']))
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(
                  '${s(d['name'])} <${s(d['to'])}> · ${d['vessels']} vessels · ${s(d['status'])}'
                  '${s(d['sent_at']).isEmpty ? '' : ' at ${s(d['sent_at'])}'}${s(d['error']).isEmpty ? '' : ' · ${s(d['error'])}'}',
                  style: TextStyle(fontSize: 12, color: s(d['status']) == 'failed' ? red : mutedText(context)),
                ),
              ),
            if (s(t['status']) == 'failed')
              TextButton.icon(
                key: ValueKey('de-retry-$k'),
                onPressed: _busy ? null : () => _run('retry', k, s(j['label'])),
                icon: const Icon(Icons.refresh_rounded),
                label: const Text('Retry failed'),
              ),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: pageColor(context),
    appBar: _bar('Daily emails', 'Times are India time (IST)'),
    body: RefreshIndicator(
      onRefresh: _load,
      child: _loadBody(_error, _d, _load, () {
        final d = _d!;
        final jobs = _maps(d['jobs']);
        final runs = _maps(d['runs']);
        return ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
          children: [
            if (d['mail_on'] == false)
              _note(
                'Outgoing email is switched off on this environment — runs will be logged as failed and nothing is sent.',
                orange,
              ),
            if (d['scheduler'] == true)
              _note(
                'Scheduler connected. Last scheduler call — ${jobs.map((j) => '${s(j['label'])}: ${s(j['last_scheduler_call']).isEmpty ? '—' : s(j['last_scheduler_call'])}').join(' · ')}',
                green,
              )
            else
              _note(
                'Cloud Scheduler is not connected yet. Until it is, the emails only go out when someone has YSMS open after the set time.',
                orange,
              ),
            for (final j in jobs) _job(j),
            FilledButton.icon(
              key: const ValueKey('de-save'),
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(50)),
              onPressed: _busy ? null : _save,
              icon: const Icon(Icons.save_outlined),
              label: const Text('Save settings'),
            ),
            SectionTitle("Today's scheduled emails (${s(d['today'])})"),
            Box(
              padding: const EdgeInsets.fromLTRB(14, 2, 14, 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [for (final j in jobs) _today(j)],
              ),
            ),
            const SectionTitle('Test / send now'),
            Box(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    '"Test to me" sends one sample only to your own profile email. "Send now" sends to the real recipients straight away as an extra email (the scheduled one still goes at its time).',
                    style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                  ),
                  for (final j in jobs) ...[
                    const SizedBox(height: 10),
                    Text(s(j['label']), style: const TextStyle(fontWeight: FontWeight.w700)),
                    Wrap(
                      spacing: 8,
                      children: [
                        OutlinedButton.icon(
                          key: ValueKey('de-test-${j['job']}'),
                          onPressed: _busy ? null : () => _run('send_test', s(j['job']), s(j['label'])),
                          icon: const Icon(Icons.science_outlined, size: 18),
                          label: const Text('Test to me'),
                        ),
                        OutlinedButton.icon(
                          key: ValueKey('de-now-${j['job']}'),
                          style: OutlinedButton.styleFrom(foregroundColor: _sureNow == s(j['job']) ? red : null),
                          onPressed: _busy ? null : () => _run('send_now', s(j['job']), s(j['label'])),
                          icon: const Icon(Icons.send_rounded, size: 18),
                          label: Text(_sureNow == s(j['job']) ? 'Tap to confirm' : 'Send now'),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
            const SectionTitle('Recent runs'),
            if (runs.isEmpty)
              const EmptyState(icon: Icons.mail_outline_rounded, text: 'Nothing sent yet.')
            else
              for (final r in runs)
                Box(
                  margin: const EdgeInsets.only(bottom: 8),
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: Text(s(r['label']), style: const TextStyle(fontWeight: FontWeight.w700)),
                          ),
                          Tag(s(r['status']), _statusColor(s(r['status']))),
                        ],
                      ),
                      Text(
                        '${s(r['when'])} · ${s(r['how'])}',
                        style: TextStyle(fontSize: 12, color: mutedText(context)),
                      ),
                      if (s(r['detail']).isNotEmpty) Text(s(r['detail']), style: const TextStyle(fontSize: 12.5)),
                    ],
                  ),
                ),
          ],
        );
      }),
    ),
  );
}

// ───────────────────────── Permission copy ─────────────────────────

class PermissionCopyScreen extends StatefulWidget {
  const PermissionCopyScreen({super.key});
  @override
  State<PermissionCopyScreen> createState() => _PermissionCopyScreenState();
}

class _PermissionCopyScreenState extends State<PermissionCopyScreen> {
  static const _page = 'generate_permission_copy.php';
  static const _fields = [
    ('permission_port', 'Port', 'e.g. Visakhapatnam'),
    ('recipient_designation', 'To — Designation', 'e.g. The Superintendent of the customs'),
    ('recipient_company', 'To — Company', 'e.g. Adani Gangavaram Port Limited'),
    ('recipient_location', 'To — Location', 'e.g. Gangavaram, Visakhapatnam.'),
    ('vessel_name', 'Vessel name', 'Enter vessel name'),
    ('berth_no', 'Berth No', 'e.g. B-6'),
  ];
  Map<String, dynamic>? _d;
  Object? _error;
  bool _busy = false;
  String _date = '', _type = '';
  final _c = {for (final f in _fields) f.$1: TextEditingController()};
  final _rows = <({String name, TextEditingController age, TextEditingController aadhar})>[];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    for (final r in _rows) {
      r.age.dispose();
      r.aadhar.dispose();
    }
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('permission_copy');
      if (!mounted) return;
      setState(() {
        _d = d;
        _error = null;
        if (_date.isEmpty) _date = s(d['today']);
        if (_rows.isEmpty) _addRow();
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _addRow() => _rows.add((name: '', age: TextEditingController(), aadhar: TextEditingController()));

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final t = await showDatePicker(
      context: context,
      initialDate: DateTime.tryParse(_date) ?? now,
      firstDate: DateTime(now.year - 5),
      lastDate: DateTime(now.year + 5),
    );
    if (t != null && mounted) {
      setState(() => _date = '${t.year}-${t.month.toString().padLeft(2, '0')}-${t.day.toString().padLeft(2, '0')}');
    }
  }

  Future<void> _generate() async {
    final missing = [
      if (_date.isEmpty) 'Date',
      for (final f in _fields)
        if (_c[f.$1]!.text.trim().isEmpty) f.$2,
      if (_type.isEmpty) 'Survey type',
    ];
    if (missing.isNotEmpty) {
      toast(context, 'Please fill: ${missing.join(', ')}', bad: true);
      return;
    }
    setState(() => _busy = true);
    final rows = _rows.where((r) => r.name.isNotEmpty).toList();
    await downloadAndOpen(
      context,
      _page,
      name: 'Permission Copy - ${_c['vessel_name']!.text.trim()}.docx',
      form: {
        'generate_copy': '1',
        'permission_date': _date,
        'survey_type': _type,
        for (final f in _fields) f.$1: _c[f.$1]!.text.trim(),
        for (var i = 0; i < rows.length; i++) ...{
          'surveyor_name[$i]': rows[i].name,
          'surveyor_age[$i]': rows[i].age.text.trim(),
          'surveyor_aadhar[$i]': rows[i].aadhar.text.trim(),
        },
      },
    );
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _uploadTemplate() async {
    PlatformFile? f;
    try {
      f = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['docx']);
    } catch (_) {
      try {
        f = await FilePicker.pickFile();
      } catch (_) {
        return;
      }
    }
    if (f == null || !mounted) return;
    final file = f;
    if (!file.name.toLowerCase().endsWith('.docx')) {
      toast(context, 'Only Word (.docx) files are allowed for the template.', bad: true);
      return;
    }
    setState(() => _busy = true);
    final size = await file.xFile.length();
    try {
      final r = await Api.i.postPage(
        _page,
        (csrf) => FormData.fromMap({
          'upload_template': '1',
          'csrf_token': csrf,
          'template_file': MultipartFile.fromStream(() => file.xFile.openRead(), size, filename: file.name),
        }),
      );
      // The page shows no success message; the new template name tells it worked.
      final before = s(_d?['template']);
      await _load();
      if (!mounted) return;
      final ok = s(_d?['template']).isNotEmpty && s(_d?['template']) != before;
      toast(
        context,
        ok ? 'Template uploaded' : (r.message.isNotEmpty ? r.message : 'The template could not be uploaded.'),
        ok: ok,
        bad: !ok,
      );
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: pageColor(context),
    appBar: _bar('Permission copy', 'Word document from the template'),
    body: RefreshIndicator(
      onRefresh: _load,
      child: _loadBody(_error, _d, _load, () {
        final d = _d!;
        final tpl = s(d['template']);
        final types = ((d['survey_types'] as List?) ?? const []).map((e) => '$e').toList();
        final surveyors = ((d['surveyors'] as List?) ?? const []).map((e) => '$e').toList();
        final max = (d['max_surveyors'] as num?)?.toInt() ?? 4;
        return ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
          children: [
            if (d['can_upload'] == true)
              Box(
                margin: const EdgeInsets.only(bottom: 12),
                padding: const EdgeInsets.all(14),
                child: Row(
                  children: [
                    Icon(
                      tpl.isEmpty ? Icons.warning_amber_rounded : Icons.check_circle_rounded,
                      color: tpl.isEmpty ? orange : green,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        tpl.isEmpty ? 'No template uploaded yet.' : 'Template uploaded: $tpl',
                        style: const TextStyle(fontSize: 13),
                      ),
                    ),
                    TextButton(
                      key: const ValueKey('pc-upload'),
                      onPressed: _busy ? null : _uploadTemplate,
                      child: Text(tpl.isEmpty ? 'Upload' : 'Replace'),
                    ),
                  ],
                ),
              )
            else if (tpl.isEmpty)
              const Padding(
                padding: EdgeInsets.only(bottom: 12),
                child: Text(
                  'No Word template has been uploaded yet. Please ask Admin to upload one first.',
                  style: TextStyle(color: orange),
                ),
              ),
            Box(
              padding: const EdgeInsets.all(14),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  ListTile(
                    key: const ValueKey('pc-date'),
                    contentPadding: EdgeInsets.zero,
                    leading: const Icon(Icons.event_outlined),
                    title: const Text('Date'),
                    trailing: Text(_date, style: const TextStyle(fontWeight: FontWeight.w700)),
                    onTap: _pickDate,
                  ),
                  for (final f in _fields) ...[
                    TextField(
                      key: ValueKey('pc-${f.$1}'),
                      controller: _c[f.$1],
                      decoration: InputDecoration(labelText: '${f.$2} *', hintText: f.$3),
                    ),
                    const SizedBox(height: 10),
                  ],
                  DropdownButtonFormField<String>(
                    key: const ValueKey('pc-type'),
                    initialValue: _type.isEmpty ? null : _type,
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'Survey type *'),
                    items: [for (final t in types) DropdownMenuItem(value: t, child: Text(t))],
                    onChanged: (v) => setState(() => _type = v ?? ''),
                  ),
                  const SizedBox(height: 16),
                  Text('Surveyors (up to $max, optional)', style: const TextStyle(fontWeight: FontWeight.w800)),
                  for (var i = 0; i < _rows.length; i++)
                    Padding(
                      padding: const EdgeInsets.only(top: 10),
                      child: Column(
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: DropdownButtonFormField<String>(
                                  key: ValueKey('pc-sv-$i'),
                                  initialValue: _rows[i].name.isEmpty ? null : _rows[i].name,
                                  isExpanded: true,
                                  decoration: InputDecoration(labelText: 'Surveyor ${i + 1}'),
                                  items: [for (final n in surveyors) DropdownMenuItem(value: n, child: Text(n))],
                                  onChanged: (v) => setState(
                                    () => _rows[i] = (name: v ?? '', age: _rows[i].age, aadhar: _rows[i].aadhar),
                                  ),
                                ),
                              ),
                              IconButton(
                                tooltip: 'Remove',
                                onPressed: () => setState(() {
                                  final r = _rows.removeAt(i);
                                  r.age.dispose();
                                  r.aadhar.dispose();
                                }),
                                icon: const Icon(Icons.close_rounded, color: red),
                              ),
                            ],
                          ),
                          const SizedBox(height: 6),
                          Row(
                            children: [
                              SizedBox(
                                width: 90,
                                child: TextField(
                                  key: ValueKey('pc-age-$i'),
                                  controller: _rows[i].age,
                                  keyboardType: TextInputType.number,
                                  decoration: const InputDecoration(labelText: 'Age'),
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: TextField(
                                  key: ValueKey('pc-aadhar-$i'),
                                  controller: _rows[i].aadhar,
                                  keyboardType: TextInputType.number,
                                  decoration: const InputDecoration(labelText: 'Aadhar No.'),
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  if (_rows.length < max)
                    Align(
                      alignment: Alignment.centerLeft,
                      child: TextButton.icon(
                        key: const ValueKey('pc-add'),
                        onPressed: () => setState(_addRow),
                        icon: const Icon(Icons.add_rounded),
                        label: const Text('Add surveyor'),
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              key: const ValueKey('pc-generate'),
              style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
              onPressed: _busy ? null : _generate,
              icon: const Icon(Icons.download_rounded),
              label: const Text('Generate & Download'),
            ),
          ],
        );
      }),
    ),
  );
}

// ───────────────────────── Coming soon ─────────────────────────

class ComingSoonScreen extends StatelessWidget {
  const ComingSoonScreen({super.key, required this.feature});
  final String feature;

  /// The feature name of a 'coming_soon.php?feature=…' link, as the page shows it.
  static String featureOf(String page) {
    final f = (Uri.tryParse(page)?.queryParameters['feature'] ?? '')
        .replaceAll(RegExp(r'[^\p{L}\p{N}\s\-+&/().]', unicode: true), '')
        .trim();
    return f.isEmpty ? 'This feature' : f;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: pageColor(context),
    appBar: AppBar(title: Text(feature)),
    body: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Box(
          padding: const EdgeInsets.fromLTRB(24, 32, 24, 28),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const IconBadge(Icons.schedule_rounded, blue),
              const SizedBox(height: 16),
              const Text('Coming Soon', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text.rich(
                TextSpan(
                  children: [
                    TextSpan(
                      text: feature,
                      style: const TextStyle(fontWeight: FontWeight.w800),
                    ),
                    const TextSpan(text: ' is being prepared and will be available here soon.'),
                  ],
                ),
                textAlign: TextAlign.center,
                style: TextStyle(color: mutedText(context)),
              ),
              const SizedBox(height: 20),
              FilledButton.icon(
                key: const ValueKey('cs-home'),
                onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
                icon: const Icon(Icons.home_rounded),
                label: const Text('Return Home'),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}
