// Admin controls (Admin) / Manage (Super Admin): the master data (ports, agents, clients, survey types,
// surveyors — app_api.php?a=admin_modules, ajax/admin_master.php: Admin every module, Super Admin only
// Clients), people, tools and the website's other tools; and Data cleanup (Super Admin,
// super_admin_controls.php: merge duplicate client companies, delete all — the word typed exactly).
import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'users.dart';

Future<void> _push(BuildContext context, String route) => openAppRoute(context, route);

class AdminScreen extends StatefulWidget {
  const AdminScreen({super.key});
  @override
  State<AdminScreen> createState() => _AdminScreenState();
}

class _AdminScreenState extends State<AdminScreen> {
  List<Map<String, dynamic>>? _mods;

  static const _iconOf = {
    'ports': (Icons.place_outlined, blue),
    'agents': (Icons.groups_outlined, orange),
    'clients': (Icons.business_outlined, green),
    'survey_types': (Icons.description_outlined, purple),
    'surveyors': (Icons.person_outline_rounded, sky),
  };

  @override
  void initState() {
    super.initState();
    Api.i
        .get('admin_modules')
        .then((d) {
          if (mounted) {
            setState(
              () => _mods = ((d['modules'] as List?) ?? const [])
                  .map((e) => Map<String, dynamic>.from(e as Map))
                  .toList(),
            );
          }
        })
        .catchError((_) {
          if (mounted) setState(() => _mods = const []);
        });
  }

  Widget _item(String label, IconData i, Color c, VoidCallback go, {bool download = false}) => ListTile(
    key: ValueKey('admin-$label'),
    leading: IconBadge(i, c, size: 36),
    title: Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
    trailing: Icon(download ? Icons.download_rounded : Icons.chevron_right_rounded),
    onTap: go,
  );

  Widget _menu(List<Widget> items) => Box(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Column(children: items),
  );

  @override
  Widget build(BuildContext context) {
    final me = Api.i.me ?? const {};
    final admin = s(me['role']) == 'Admin';
    final can = (me['can'] as Map?) ?? const {};
    final now = DateTime.now();
    final mods = _mods;
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(title: Text(admin ? 'Admin controls' : 'Manage')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
        children: [
          const SectionTitle('Master data'),
          if (mods == null)
            const CardSkeletons(count: 1)
          else
            GridView.count(
              crossAxisCount: 2,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 10,
              crossAxisSpacing: 10,
              childAspectRatio: 2.4,
              children: [
                for (final m in mods)
                  Box(
                    key: ValueKey('mod-${m['key']}'),
                    padding: const EdgeInsets.all(12),
                    onTap: () => _push(context, '/m/${m['key']}'),
                    child: Row(
                      children: [
                        IconBadge(
                          _iconOf[s(m['key'])]?.$1 ?? Icons.folder_outlined,
                          _iconOf[s(m['key'])]?.$2 ?? blue,
                          size: 36,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(s(m['label']), style: const TextStyle(fontWeight: FontWeight.w800)),
                        ),
                      ],
                    ),
                  ),
              ],
            ),
          const SectionTitle('People'),
          _menu([
            if (admin) _item('Add surveyor', Icons.person_add_alt_outlined, orange, () => _push(context, '/surveyor')),
            _item('Add client', Icons.group_add_outlined, green, () => _push(context, '/addclient')),
            if (!admin)
              _item('Add admin', Icons.admin_panel_settings_outlined, purple, () => _push(context, '/addadmin')),
          ]),
          const SectionTitle('Tools'),
          _menu([
            _item('Login activity', Icons.security_rounded, red, () => _push(context, '/activity')),
            if (admin) ...[
              _item('Manage templates', Icons.article_outlined, sky, () => _push(context, '/templates')),
              _item('Possible duplicates', Icons.copy_all_outlined, orange, () => _push(context, '/duplicates')),
              _item('Formats', Icons.folder_outlined, green, () => _push(context, '/formats')),
            ],
            if (s(me['role']) == 'Super Admin')
              _item('Data cleanup', Icons.cleaning_services_outlined, red, () => _push(context, '/sacontrols')),
          ]),
          const SectionTitle('More tools'),
          _menu([
            if (admin) ...[
              if (can['survey_reports'] == true)
                _item('Survey reports', Icons.fact_check_outlined, green, () => openWeb(context, 'survey_reports.php')),
              if (can['live_survey'] == true)
                _item(
                  'Live survey reports',
                  Icons.sensors_rounded,
                  red,
                  () => openWeb(context, 'live_survey_reports.php'),
                ),
              if (can['report_templates'] == true)
                _item(
                  'Report templates',
                  Icons.library_books_outlined,
                  green,
                  () => openWeb(context, 'admin_report_templates.php'),
                ),
              _item(
                'Invoice airtable',
                Icons.table_chart_outlined,
                blue,
                () => openWeb(context, 'invoices_airtable.php'),
              ),
              _item('Daily emails', Icons.mail_outline_rounded, blue, () => openWeb(context, 'admin_daily_emails.php')),
              _item('Surveyor cards', Icons.badge_outlined, purple, () => openWeb(context, 'admin_surveyor_cards.php')),
              _item('Import ports', Icons.place_outlined, sky, () => openWeb(context, 'ports_import.php')),
            ],
            _item(
              'Import completed vessels',
              Icons.upload_rounded,
              green,
              () => openWeb(context, 'completed_import.php'),
            ),
            _item(
              'Month summary (Excel)',
              Icons.table_view_outlined,
              blue,
              () => downloadAndOpen(context, 'export_month_summary.php?y=${now.year}&m=${now.month}'),
              download: true,
            ),
          ]),
        ],
      ),
    );
  }
}

/// One master-data module: search, list, add / edit / delete (ajax/admin_master.php); Surveyors open
/// their own form; Super Admin also sets a client company's login (ajax/super_admin_client_login.php).
class ModuleScreen extends StatefulWidget {
  const ModuleScreen({super.key, required this.moduleKey});
  final String moduleKey;
  @override
  State<ModuleScreen> createState() => _ModuleScreenState();
}

class _ModuleScreenState extends State<ModuleScreen> {
  Map<String, dynamic>? _mod;
  List<Map<String, dynamic>>? _rows;
  Map<String, dynamic> _logins = {};
  Object? _error;
  final _q = TextEditingController();
  Timer? _t;

  String get _key => widget.moduleKey;
  bool get _saClients => _key == 'clients' && s(Api.i.me?['role']) == 'Super Admin';
  List<FieldDef> get _fields => ((_mod?['fields'] as List?) ?? const [])
      .map((e) => FieldDef.fromMap(Map<String, dynamic>.from(e as Map)))
      .toList();

  @override
  void initState() {
    super.initState();
    Api.i
        .get('admin_modules')
        .then((d) {
          final m = ((d['modules'] as List?) ?? const [])
              .map((e) => Map<String, dynamic>.from(e as Map))
              .where((m) => m['key'] == _key);
          if (!mounted) return;
          if (m.isEmpty) {
            setState(() => _error = ApiError(404, 'Not available'));
            return;
          }
          setState(() => _mod = m.first);
          _load();
        })
        .catchError((Object e) {
          if (mounted) setState(() => _error = e);
        });
  }

  @override
  void dispose() {
    _t?.cancel();
    _q.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.getJson('ajax/admin_master.php', {'module': _key, 'action': 'list', 'q': _q.text.trim()});
      if (_saClients) {
        try {
          final l = await Api.i.getJson('ajax/super_admin_client_login.php', {'action': 'list_logins'});
          _logins = Map<String, dynamic>.from((l['by_client'] as Map?) ?? const {});
        } catch (_) {}
      }
      if (!mounted) return;
      setState(() {
        _rows = ((d['rows'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  String _sub(Map<String, dynamic> r) => _fields
      .where((f) => f.name != s(_mod?['name_field']) && f.type != 'password' && s(r[f.name]).isNotEmpty)
      .map((f) => s(r[f.name]))
      .join(' · ');

  Future<void> _open([Map<String, dynamic>? row]) async {
    if (_key == 'surveyors') {
      await openAppRoute(context, row == null ? '/surveyor' : '/sv/${row['id']}');
      _load();
      return;
    }
    final changed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _EditSheet(
        title: '${row == null ? 'Add' : 'Edit'} ${s(_mod?['singular']).toLowerCase()}',
        defs: _fields.where((f) => row != null || !f.readonly).toList(),
        values: {for (final f in _fields) f.name: s(row?[f.name])},
        saveLabel: row == null ? 'Add ${s(_mod?['singular']).toLowerCase()}' : 'Save changes',
        save: (v) => Api.i.postJson('ajax/admin_master.php', {
          ...v,
          'module': _key,
          'action': row == null ? 'add' : 'edit',
          if (row != null) 'id': '${row['id']}',
        }),
        delete: row == null
            ? null
            : () => Api.i.postJson('ajax/admin_master.php', {'module': _key, 'action': 'delete', 'id': '${row['id']}'}),
        extra: row != null && _saClients
            ? (c) => TextButton.icon(
                key: const ValueKey('mod-login'),
                onPressed: () {
                  Navigator.pop(c);
                  _clientLogin(row);
                },
                icon: const Icon(Icons.key_rounded),
                label: Text(_logins['${row['id']}'] != null ? 'Client login' : 'Create client login'),
              )
            : null,
      ),
    );
    if (changed == true) _load();
  }

  Future<void> _clientLogin(Map<String, dynamic> row) async {
    final lg = Map<String, dynamic>.from((_logins['${row['id']}'] as Map?) ?? const {});
    final has = s(lg['user_id']).isNotEmpty;
    final changed = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _EditSheet(
        title: 'Client login · ${s(row['company_name'])}',
        defs: [
          const FieldDef('username', 'Username', required: true),
          const FieldDef('full_name', 'Contact person', required: true),
          const FieldDef('email', 'Email', type: 'email'),
          const FieldDef('phone', 'Mobile', type: 'tel'),
          FieldDef(
            'password',
            has ? 'New password' : 'Password',
            type: 'password',
            required: !has,
            hint: has ? 'Leave blank to keep the current password.' : '',
          ),
        ],
        values: {
          for (final k in const ['username', 'full_name', 'email', 'phone']) k: s(lg[k]),
        },
        saveLabel: has ? 'Save login' : 'Create login',
        save: (v) =>
            Api.i.postJson('ajax/super_admin_client_login.php', {...v, 'action': 'save', 'client_id': '${row['id']}'}),
      ),
    );
    if (changed == true) _load();
  }

  @override
  Widget build(BuildContext context) {
    final rows = _rows;
    final nameField = s(_mod?['name_field']);
    Widget list;
    if (_error != null && rows == null) {
      list = ErrorState(error: _error!, onRetry: _load);
    } else if (rows == null) {
      list = const CardSkeletons(count: 4);
    } else if (rows.isEmpty) {
      list = EmptyState(
        icon: Icons.folder_outlined,
        text: _q.text.trim().isEmpty ? 'Nothing here yet' : 'Nothing matches',
      );
    } else {
      list = Column(
        children: [
          for (final r in rows)
            Box(
              key: ValueKey('row-${r['id']}'),
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(14),
              onTap: () => _open(r),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(s(r[nameField]), style: const TextStyle(fontWeight: FontWeight.w800)),
                        if (_sub(r).isNotEmpty)
                          Text(_sub(r), style: TextStyle(fontSize: 12.5, color: mutedText(context))),
                        if (_saClients)
                          Text(
                            _logins['${r['id']}'] != null
                                ? 'Login: ${s((_logins['${r['id']}'] as Map)['username'])}'
                                : 'No login yet',
                            style: TextStyle(fontSize: 12, color: _logins['${r['id']}'] != null ? green : orange),
                          ),
                      ],
                    ),
                  ),
                  if (s(r['status']).isNotEmpty) Tag(s(r['status']), s(r['status']) == 'Active' ? green : red),
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
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_mod == null ? '…' : s(_mod!['label'])),
            if (rows != null)
              Text(
                '${rows.length} ${rows.length == 1 ? 'entry' : 'entries'}',
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500),
              ),
          ],
        ),
      ),
      floatingActionButton: _mod == null
          ? null
          : FloatingActionButton(
              key: const ValueKey('mod-add'),
              tooltip: 'Add',
              onPressed: () => _open(),
              child: const Icon(Icons.add_rounded),
            ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 96),
          children: [
            TextField(
              key: const ValueKey('mod-search'),
              controller: _q,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search_rounded), hintText: 'Search'),
              onChanged: (_) {
                _t?.cancel();
                _t = Timer(const Duration(milliseconds: 350), _load);
              },
            ),
            const SizedBox(height: 12),
            list,
          ],
        ),
      ),
    );
  }
}

/// A sheet with a form: save (and delete, asked twice); pops with true when something was saved.
class _EditSheet extends StatefulWidget {
  const _EditSheet({
    required this.title,
    required this.defs,
    required this.values,
    required this.saveLabel,
    required this.save,
    this.delete,
    this.extra,
  });
  final String title, saveLabel;
  final List<FieldDef> defs;
  final Map<String, String> values;
  final Future<Map<String, dynamic>> Function(Map<String, String> v) save;
  final Future<Map<String, dynamic>> Function()? delete;
  final Widget Function(BuildContext c)? extra;
  @override
  State<_EditSheet> createState() => _EditSheetState();
}

class _EditSheetState extends State<_EditSheet> {
  String _err = '';
  bool _busy = false, _sure = false;

  Future<void> _run(Future<Map<String, dynamic>> Function() f) async {
    setState(() {
      _busy = true;
      _err = '';
    });
    try {
      final r = await f();
      if (!mounted) return;
      toast(context, s(r['message']).isEmpty ? 'Saved' : s(r['message']), ok: true);
      Navigator.pop(context, true);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _err = e.message;
          _sure = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
    child: SingleChildScrollView(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(widget.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 14),
          FormFields(defs: widget.defs, values: widget.values),
          if (_err.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Text(
                _err,
                key: const ValueKey('sheet-error'),
                style: const TextStyle(color: red, fontWeight: FontWeight.w600),
              ),
            ),
          FilledButton(
            key: const ValueKey('sheet-save'),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
            onPressed: _busy
                ? null
                : () {
                    final m = missing(widget.defs, widget.values, passwords: false);
                    if (m.isNotEmpty) {
                      setState(() => _err = m);
                      return;
                    }
                    _run(() => widget.save(widget.values));
                  },
            child: Text(widget.saveLabel),
          ),
          if (widget.extra != null) widget.extra!(context),
          if (widget.delete != null) ...[
            const SizedBox(height: 8),
            OutlinedButton(
              key: const ValueKey('sheet-delete'),
              style: OutlinedButton.styleFrom(foregroundColor: red, minimumSize: const Size.fromHeight(48)),
              onPressed: _busy
                  ? null
                  : () {
                      if (!_sure) {
                        setState(() => _sure = true);
                        return;
                      }
                      _run(widget.delete!);
                    },
              child: Text(_sure ? 'Tap again to delete' : 'Delete'),
            ),
          ],
        ],
      ),
    ),
  );
}

/// Data cleanup (Super Admin): merge duplicate client companies, delete all of a list. Both post to
/// super_admin_controls.php, which checks the role and the word typed (MERGE / DELETE) again.
class DataCleanupScreen extends StatefulWidget {
  const DataCleanupScreen({super.key});
  @override
  State<DataCleanupScreen> createState() => _DataCleanupScreenState();
}

class _DataCleanupScreenState extends State<DataCleanupScreen> {
  static const _label = {
    'pending_vessel': 'Pending Vessels',
    'pending_report': 'Pending Reports',
    'cancelled': 'Cancelled Vessels',
    'completed': 'Completed Vessels',
  };
  Map<String, dynamic>? _d;
  Object? _error;
  final _keep = <int, int>{}; // group → client id kept

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('sa_controls');
      if (mounted) {
        setState(() {
          _d = d;
          _error = null;
          _keep.clear();
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  List<Map<String, dynamic>> get _groups =>
      ((_d?['groups'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

  /// The confirmation sheet: the word typed exactly, as on the website.
  Future<void> _typed(
    String title,
    String text,
    String word,
    String button,
    Future<PageResult> Function(String typed) send,
  ) async {
    final done = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _TypedSheet(title: title, text: text, word: word, button: button, send: send),
    );
    if (done != null && mounted) {
      toast(context, done, ok: true);
      _load();
    }
  }

  void _merge(int gi) {
    final g = _groups[gi];
    final rows = ((g['rows'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final keep = _keep[gi] ?? _defaultKeep(rows);
    _typed(
      'Merge ${s(rows.first['company'])}?',
      'Keeps client #$keep and removes the other ${rows.length - 1} row(s).',
      'MERGE',
      'Merge',
      (txt) async {
        final r = await Api.i.postPage(
          'super_admin_controls.php',
          (csrf) => {
            'merge_action': '1',
            'group_norm_name': s(g['norm']),
            'keep_id': '$keep',
            'confirm_text': txt,
            'csrf_token': csrf,
          },
        );
        if (r.message.isNotEmpty) return r;
        // the page shows its merge message inside the group, which is gone after a merge: check the data again
        final n = await Api.i.get('sa_controls');
        final still = ((n['groups'] as List?) ?? const []).any((x) => (x as Map)['norm'] == g['norm']);
        return still
            ? const PageResult(false, 'Could not merge these records. Please refresh and try again.')
            : PageResult(
                true,
                'Merged into client #$keep. Its vessels/surveys and login (if any) are now on this one row.',
              );
      },
    );
  }

  int _defaultKeep(List<Map<String, dynamic>> rows) =>
      (rows.reduce((a, b) => ((b['surveys'] as num?) ?? 0) > ((a['surveys'] as num?) ?? 0) ? b : a)['id'] as num)
          .toInt();

  @override
  Widget build(BuildContext context) {
    final d = _d;
    Widget body;
    if (_error != null && d == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (d == null) {
      body = const CardSkeletons(count: 3);
    } else {
      final counts = Map<String, dynamic>.from((d['counts'] as Map?) ?? const {});
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          Box(
            color: red.withValues(alpha: .07),
            padding: const EdgeInsets.all(12),
            child: const Text(
              'Temporary tool — for clearing test data before go-live only. Delete actions permanently remove every record in that category, with no way to undo.',
              style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
            ),
          ),
          const SectionTitle('Duplicate client companies'),
          if (_groups.isEmpty)
            const Box(child: Text('No duplicate client companies found.'))
          else
            for (var gi = 0; gi < _groups.length; gi++)
              Builder(
                builder: (_) {
                  final g = _groups[gi];
                  final rows = ((g['rows'] as List?) ?? const [])
                      .map((e) => Map<String, dynamic>.from(e as Map))
                      .toList();
                  final keep = _keep[gi] ?? _defaultKeep(rows);
                  return Box(
                    key: ValueKey('group-$gi'),
                    margin: const EdgeInsets.only(bottom: 10),
                    padding: const EdgeInsets.all(12),
                    child: RadioGroup<int>(
                      groupValue: keep,
                      onChanged: (v) => setState(() => _keep[gi] = v ?? keep),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Text(
                                  s(rows.first['company']),
                                  style: const TextStyle(fontWeight: FontWeight.w800),
                                ),
                              ),
                              Tag('${rows.length} rows', orange),
                            ],
                          ),
                          for (final r in rows)
                            RadioListTile<int>(
                              key: ValueKey('keep-${r['id']}'),
                              contentPadding: EdgeInsets.zero,
                              value: (r['id'] as num).toInt(),
                              title: Text(
                                'Client #${r['id']}${s(r['contact']).isEmpty ? '' : ' — ${s(r['contact'])}'}',
                              ),
                              subtitle: Text(
                                '${r['surveys']} vessel(s)/survey(s) · ${s(r['login']).isEmpty ? 'no login' : 'login: ${s(r['login'])}'}',
                              ),
                            ),
                          FilledButton.icon(
                            key: ValueKey('merge-$gi'),
                            onPressed: () => _merge(gi),
                            icon: const Icon(Icons.merge_rounded),
                            label: const Text('Merge selected'),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
          const SectionTitle('Danger zone'),
          for (final k in _label.keys)
            Box(
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Delete all ${_label[k]}', style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text(
                          '${counts[k] ?? 0} record(s) now · with their uploaded files. Cannot be undone.',
                          style: TextStyle(fontSize: 12, color: mutedText(context)),
                        ),
                      ],
                    ),
                  ),
                  FilledButton(
                    key: ValueKey('wipe-$k'),
                    style: FilledButton.styleFrom(backgroundColor: red),
                    onPressed: ((counts[k] as num?) ?? 0) == 0
                        ? null
                        : () => _typed(
                            'Delete all ${_label[k]}?',
                            'Permanently deletes ${counts[k]} ${_label[k]!.toLowerCase()} record(s) and their uploaded files. This cannot be undone.',
                            'DELETE',
                            'Delete all',
                            (txt) => Api.i.postPage(
                              'super_admin_controls.php',
                              (csrf) => {'delete_target': k, 'confirm_text': txt, 'csrf_token': csrf},
                            ),
                          ),
                    child: const Text('Delete all'),
                  ),
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
            Text('Super Admin controls'),
            Text('Data cleanup', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}

class _TypedSheet extends StatefulWidget {
  const _TypedSheet({
    required this.title,
    required this.text,
    required this.word,
    required this.button,
    required this.send,
  });
  final String title, text, word, button;
  final Future<PageResult> Function(String typed) send;
  @override
  State<_TypedSheet> createState() => _TypedSheetState();
}

class _TypedSheetState extends State<_TypedSheet> {
  final _c = TextEditingController();
  bool _busy = false;
  String _err = '';

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  Future<void> _go() async {
    setState(() {
      _busy = true;
      _err = '';
    });
    try {
      final r = await widget.send(_c.text.trim());
      if (!mounted) return;
      if (!r.ok) {
        setState(() {
          _busy = false;
          _err = r.message.isEmpty ? 'Could not complete this action.' : r.message;
        });
        return;
      }
      Navigator.pop(context, r.message.isEmpty ? 'Done' : r.message);
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          _busy = false;
          _err = e.message;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
    padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
    child: Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(widget.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        Text(widget.text),
        const SizedBox(height: 14),
        TextField(
          key: const ValueKey('typed'),
          controller: _c,
          autocorrect: false,
          textCapitalization: TextCapitalization.characters,
          decoration: InputDecoration(labelText: 'Type ${widget.word} to confirm', hintText: widget.word),
          onChanged: (_) => setState(() {}),
        ),
        if (_err.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _err,
              style: const TextStyle(color: red, fontWeight: FontWeight.w600),
            ),
          ),
        const SizedBox(height: 14),
        FilledButton(
          key: const ValueKey('typed-yes'),
          style: FilledButton.styleFrom(backgroundColor: red, minimumSize: const Size.fromHeight(48)),
          onPressed: _busy || _c.text.trim() != widget.word ? null : _go,
          child: Text(_busy ? 'Working…' : widget.button),
        ),
      ],
    ),
  );
}
