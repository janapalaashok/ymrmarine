// Add client, Add admin and Add / Edit surveyor: the website's own forms (add_client.php,
// add_admin.php, add_surveyor.php — their checks, duplicate usernames, emails) with their message shown.
// The required fields and the password match are checked here first, as the web app does.
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../config.dart';
import '../ui.dart';

/// One field of a form: text, password, email, tel, date (YYYY-MM-DD), select or checkbox.
class FieldDef {
  const FieldDef(
    this.name,
    this.label, {
    this.type = 'text',
    this.required = false,
    this.options = const [],
    this.hint = '',
    this.readonly = false,
  });
  final String name, label, type, hint;
  final bool required, readonly;
  final List<String> options;

  /// A field as config/admin_modules.php describes it (app_api.php?a=admin_modules).
  factory FieldDef.fromMap(Map<String, dynamic> m) => FieldDef(
    s(m['name']),
    s(m['label']),
    type: s(m['type']).isEmpty ? 'text' : s(m['type']),
    required: m['required'] == true,
    options: ((m['options'] as List?) ?? const []).map((e) => '$e').toList(),
    hint: s(m['hint']),
    readonly: m['readonly'] == true,
  );
}

/// The fields of a form, each with its own controller (or value for selects and checkboxes).
class FormFields extends StatefulWidget {
  const FormFields({super.key, required this.defs, required this.values});
  final List<FieldDef> defs;

  /// Values in and out: the form writes every change here.
  final Map<String, String> values;
  @override
  State<FormFields> createState() => _FormFieldsState();
}

class _FormFieldsState extends State<FormFields> {
  final _c = <String, TextEditingController>{};

  @override
  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextInputType _kb(String t) => switch (t) {
    'email' => TextInputType.emailAddress,
    'tel' => TextInputType.phone,
    'date' => TextInputType.datetime,
    _ => TextInputType.text,
  };

  @override
  Widget build(BuildContext context) {
    final v = widget.values;
    return Column(
      children: [
        for (final f in widget.defs)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: switch (f.type) {
              'select' => DropdownButtonFormField<String>(
                key: ValueKey('f-${f.name}'),
                initialValue: f.options.contains(v[f.name]) ? v[f.name] : f.options.first,
                decoration: InputDecoration(labelText: '${f.label}${f.required ? ' *' : ''}'),
                items: [for (final o in f.options) DropdownMenuItem(value: o, child: Text(o))],
                onChanged: (x) => v[f.name] = x ?? '',
              ),
              'checkbox' => CheckboxListTile(
                key: ValueKey('f-${f.name}'),
                contentPadding: EdgeInsets.zero,
                controlAffinity: ListTileControlAffinity.leading,
                value: v[f.name] == '1',
                title: Text(f.label),
                onChanged: (x) => setState(() => v[f.name] = x == true ? '1' : ''),
              ),
              _ => TextField(
                key: ValueKey('f-${f.name}'),
                controller: _c.putIfAbsent(f.name, () => TextEditingController(text: v[f.name] ?? '')),
                keyboardType: f.type == 'textarea' ? TextInputType.multiline : _kb(f.type),
                minLines: f.type == 'textarea' ? 3 : 1,
                maxLines: f.type == 'textarea' ? 6 : 1,
                readOnly: f.readonly,
                obscureText: f.type == 'password',
                autocorrect: f.name != 'username',
                decoration: InputDecoration(
                  labelText: '${f.label}${f.required ? ' *' : ''}',
                  helperText: f.hint.isNotEmpty ? f.hint : (f.type == 'date' ? 'YYYY-MM-DD' : null),
                  helperMaxLines: 3,
                ),
                onChanged: (x) => v[f.name] = f.type == 'password' ? x : x.trim(),
              ),
            },
          ),
      ],
    );
  }
}

/// The first required field left empty, as a message; or the passwords not matching.
String missing(List<FieldDef> defs, Map<String, String> v, {bool passwords = true}) {
  for (final f in defs) {
    if (f.required && !f.readonly && (v[f.name] ?? '').trim().isEmpty) return '${f.label} is required.';
  }
  if (passwords && v.containsKey('password') && v['password'] != (v['confirm_password'] ?? '')) {
    return 'Passwords do not match.';
  }
  return '';
}

/// Add client / Add admin: one form posted to its page.
class CreateUserScreen extends StatefulWidget {
  const CreateUserScreen({
    super.key,
    required this.title,
    required this.page,
    required this.defs,
    required this.button,
  });
  final String title, page, button;
  final List<FieldDef> defs;

  static CreateUserScreen client() => const CreateUserScreen(
    title: 'Add client',
    page: 'add_client.php',
    button: 'Create client',
    defs: [
      FieldDef('company_name', 'Company name', required: true),
      FieldDef('contact_person', 'Contact person', required: true),
      FieldDef('username', 'Username', required: true),
      FieldDef('mobile', 'Mobile', type: 'tel', required: true),
      FieldDef('email', 'Email', type: 'email', required: true),
      FieldDef('password', 'Password', type: 'password', required: true),
      FieldDef('confirm_password', 'Confirm password', type: 'password', required: true),
    ],
  );

  static CreateUserScreen admin() => const CreateUserScreen(
    title: 'Add admin',
    page: 'add_admin.php',
    button: 'Create login',
    defs: [
      FieldDef('full_name', 'Full name', required: true),
      FieldDef('username', 'Username', required: true),
      FieldDef('mobile', 'Mobile', type: 'tel'),
      FieldDef('email', 'Email', type: 'email'),
      FieldDef('password', 'Password', type: 'password', required: true),
      FieldDef('confirm_password', 'Confirm password', type: 'password', required: true),
      FieldDef('is_super_admin', 'Make this a Super Admin login', type: 'checkbox'),
    ],
  );

  @override
  State<CreateUserScreen> createState() => _CreateUserScreenState();
}

class _CreateUserScreenState extends State<CreateUserScreen> {
  final _v = <String, String>{};
  String _err = '';
  bool _busy = false;

  Future<void> _save() async {
    final m = missing(widget.defs, _v);
    if (m.isNotEmpty) {
      setState(() => _err = m);
      return;
    }
    setState(() {
      _busy = true;
      _err = '';
    });
    try {
      final r = await Api.i.postPage(widget.page, (csrf) => {..._v, 'csrf_token': csrf});
      if (!mounted) return;
      if (!r.ok) {
        setState(() => _err = r.message.isEmpty ? 'Could not save.' : r.message);
        return;
      }
      toast(context, r.message.isEmpty ? 'Saved' : r.message, ok: true);
      Navigator.of(context).maybePop();
    } on ApiError catch (e) {
      if (mounted) setState(() => _err = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => _FormPage(
    title: widget.title,
    button: widget.button,
    busy: _busy,
    error: _err,
    onSave: _save,
    children: [
      Box(
        child: FormFields(defs: widget.defs, values: _v),
      ),
    ],
  );
}

/// Add surveyor (id == null) or edit one (app_api.php?a=surveyor), with the photo; add_surveyor.php.
class SurveyorFormScreen extends StatefulWidget {
  const SurveyorFormScreen({super.key, this.id});
  final int? id;
  @override
  State<SurveyorFormScreen> createState() => _SurveyorFormScreenState();
}

class _SurveyorFormScreenState extends State<SurveyorFormScreen> {
  static const _defs = [
    FieldDef('first_name', 'First name', required: true),
    FieldDef('last_name', 'Last name', required: true),
    FieldDef('username', 'Username', required: true),
    FieldDef('phone', 'Mobile', type: 'tel'),
    FieldDef('email', 'Email', type: 'email'),
    FieldDef('dob', 'Date of birth', type: 'date'),
    FieldDef('date_of_joining', 'Date of joining', type: 'date'),
    FieldDef('status', 'Status', type: 'select', required: true, options: ['Active', 'Inactive']),
  ];
  static const _pw = [
    FieldDef('password', 'Password', type: 'password', required: true),
    FieldDef('confirm_password', 'Confirm password', type: 'password', required: true),
  ];
  Map<String, String>? _v;
  String _avatar = '', _err = '';
  Object? _loadErr;
  PlatformFile? _photo;
  bool _busy = false;

  bool get _edit => widget.id != null;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    if (!_edit) {
      setState(() => _v = {'status': 'Active'});
      return;
    }
    try {
      final u = await Api.i.get('surveyor', {'id': widget.id});
      if (!mounted) return;
      setState(() {
        _v = {for (final f in _defs) f.name: s(u[f.name])};
        _avatar = s(u['avatar']);
        _loadErr = null;
      });
    } catch (e) {
      if (mounted) setState(() => _loadErr = e);
    }
  }

  Future<void> _pick() async {
    PlatformFile? f;
    try {
      f = await FilePicker.pickFile(type: FileType.custom, allowedExtensions: const ['jpg', 'jpeg', 'png', 'webp']);
    } catch (_) {
      try {
        f = await FilePicker.pickFile(type: FileType.image);
      } catch (_) {
        return;
      }
    }
    if (f != null && mounted) setState(() => _photo = f);
  }

  Future<void> _save() async {
    final v = _v!;
    final m = missing([..._defs, if (!_edit) ..._pw], v, passwords: !_edit);
    if (m.isNotEmpty) {
      setState(() => _err = m);
      return;
    }
    setState(() {
      _busy = true;
      _err = '';
    });
    try {
      final p = _photo;
      final size = p == null ? 0 : await p.xFile.length();
      final r = await Api.i.postPage(
        'add_surveyor.php${_edit ? '?id=${widget.id}' : ''}',
        (csrf) => FormData.fromMap({
          ...v,
          'save_surveyor': '1',
          'csrf_token': csrf,
          if (p != null) 'profile_photo': MultipartFile.fromStream(() => p.xFile.openRead(), size, filename: p.name),
        }),
      );
      if (!mounted) return;
      if (!r.ok) {
        setState(() => _err = r.message.isEmpty ? 'Could not save.' : r.message);
        return;
      }
      toast(context, r.message.isEmpty ? 'Saved' : r.message, ok: true);
      Navigator.of(context).maybePop();
    } on ApiError catch (e) {
      if (mounted) setState(() => _err = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final v = _v;
    final title = _edit ? 'Edit surveyor' : 'Add surveyor';
    if (v == null) {
      return Scaffold(
        backgroundColor: pageColor(context),
        appBar: AppBar(title: Text(title)),
        body: _loadErr != null
            ? ListView(
                children: [ErrorState(error: _loadErr!, onRetry: _load)],
              )
            : const CardSkeletons(count: 2),
      );
    }
    final name = '${v['first_name'] ?? ''} ${v['last_name'] ?? ''}'.trim();
    return _FormPage(
      title: title,
      button: _edit ? 'Save changes' : 'Create surveyor',
      busy: _busy,
      error: _err,
      onSave: _save,
      children: [
        Center(
          child: Stack(
            children: [
              CircleAvatar(
                radius: 42,
                backgroundColor: blueSoft,
                foregroundImage: _photo == null && _avatar.isNotEmpty
                    ? NetworkImage(Cfg.url(_avatar), headers: Api.i.imageHeaders)
                    : null,
                child: _photo != null
                    ? const Icon(Icons.check_rounded, color: green, size: 34)
                    : Text(
                        name.isEmpty
                            ? '+'
                            : name.split(' ').map((w) => w.isEmpty ? '' : w[0]).take(2).join().toUpperCase(),
                        style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: blue),
                      ),
              ),
              Positioned(
                right: 0,
                bottom: 0,
                child: IconButton.filled(
                  key: const ValueKey('sv-photo'),
                  tooltip: 'Choose photo',
                  onPressed: _pick,
                  icon: const Icon(Icons.photo_camera_outlined),
                ),
              ),
            ],
          ),
        ),
        if (_photo != null)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Center(
              child: Text(_photo!.name, style: TextStyle(fontSize: 12, color: mutedText(context))),
            ),
          ),
        const SizedBox(height: 12),
        Box(
          child: FormFields(defs: _defs, values: v),
        ),
        if (!_edit) ...[
          const SectionTitle('Login password'),
          Box(
            child: FormFields(defs: _pw, values: v),
          ),
        ],
      ],
    );
  }
}

/// A form page: the fields, the page's error, and one save button at the bottom.
class _FormPage extends StatelessWidget {
  const _FormPage({
    required this.title,
    required this.button,
    required this.busy,
    required this.error,
    required this.onSave,
    required this.children,
  });
  final String title, button, error;
  final bool busy;
  final VoidCallback onSave;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: pageColor(context),
    appBar: AppBar(title: Text(title)),
    body: ListView(
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
      children: [
        ...children,
        if (error.isNotEmpty)
          Container(
            key: const ValueKey('form-error'),
            margin: const EdgeInsets.only(top: 12),
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: red.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
            child: Row(
              children: [
                const Icon(Icons.error_outline_rounded, color: red),
                const SizedBox(width: 10),
                Expanded(child: Text(error)),
              ],
            ),
          ),
      ],
    ),
    bottomNavigationBar: SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
        child: FilledButton(
          key: const ValueKey('form-save'),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
          onPressed: busy ? null : onSave,
          child: busy
              ? const SizedBox(
                  width: 22,
                  height: 22,
                  child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                )
              : Text(button),
        ),
      ),
    ),
  );
}
