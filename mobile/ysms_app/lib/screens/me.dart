// My profile: the details, the password and the photo (app_api.php?a=profile), saved with
// profile.php's own forms (its checks; Surveyors edit first / last name and date of birth,
// everyone else full name, email and mobile). The page's own message is shown.
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../config.dart';
import '../ui.dart';

class MyProfileScreen extends StatefulWidget {
  const MyProfileScreen({super.key});
  @override
  State<MyProfileScreen> createState() => _MyProfileScreenState();
}

class _MyProfileScreenState extends State<MyProfileScreen> {
  Map<String, dynamic>? _p;
  Object? _error;
  final _c = <String, TextEditingController>{};
  final _pw = {
    for (final k in const ['current_password', 'new_password', 'confirm_password']) k: TextEditingController(),
  };
  bool _savingDetails = false, _savingPw = false, _savingPhoto = false;
  int _photoV = 0;

  bool get _surveyor => _p?['surveyor_form'] == true;
  List<(String, String, TextInputType)> get _fields => _surveyor
      ? const [
          ('first_name', 'First name', TextInputType.name),
          ('last_name', 'Last name', TextInputType.name),
          ('dob', 'Date of birth', TextInputType.datetime),
        ]
      : const [
          ('full_name', 'Full name', TextInputType.name),
          ('email', 'Email', TextInputType.emailAddress),
          ('phone', 'Mobile number', TextInputType.phone),
        ];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    for (final c in [..._c.values, ..._pw.values]) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _ctl(String k) => _c.putIfAbsent(k, TextEditingController.new);

  Future<void> _load() async {
    try {
      final p = await Api.i.get('profile');
      if (!mounted) return;
      setState(() {
        _p = p;
        _error = null;
        for (final f in _fields) {
          _ctl(f.$1).text = s(p[f.$1]);
        }
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _done(PageResult r, String okText, String badText, {bool reload = false}) async {
    if (!mounted) return;
    toast(context, r.message.isNotEmpty ? r.message : (r.ok ? okText : badText), ok: r.ok, bad: !r.ok);
    if (r.ok && reload) {
      try {
        await Api.i.loadMe();
      } catch (_) {}
      _photoV++;
      await _load();
    }
  }

  Future<void> _saveDetails() async {
    final data = {for (final f in _fields) f.$1: _ctl(f.$1).text.trim()};
    if (_surveyor && (data['first_name']!.isEmpty || data['last_name']!.isEmpty)) {
      toast(context, 'First name and last name are required.', bad: true);
      return;
    }
    if (!_surveyor && data['full_name']!.isEmpty) {
      toast(context, 'Full name is required.', bad: true);
      return;
    }
    setState(() => _savingDetails = true);
    try {
      final r = await Api.i.postPage('profile.php', (csrf) => {'update_profile': '1', ...data, 'csrf_token': csrf});
      await _done(r, 'Saved', 'Could not save', reload: true);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _savingDetails = false);
    }
  }

  Future<void> _savePassword() async {
    final d = {for (final e in _pw.entries) e.key: e.value.text};
    if (d.values.any((v) => v.isEmpty)) {
      toast(context, 'All password fields are required.', bad: true);
      return;
    }
    if (d['new_password']!.length < 6) {
      toast(context, 'The new password needs at least 6 characters.', bad: true);
      return;
    }
    if (d['new_password'] != d['confirm_password']) {
      toast(context, 'New password and confirm password do not match.', bad: true);
      return;
    }
    setState(() => _savingPw = true);
    try {
      final r = await Api.i.postPage('profile.php', (csrf) => {'change_password': '1', ...d, 'csrf_token': csrf});
      await _done(r, 'Password updated', 'Could not update the password');
      if (r.ok) {
        for (final c in _pw.values) {
          c.clear();
        }
      }
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _savingPw = false);
    }
  }

  Future<void> _photo() async {
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
    if (f == null) return;
    final p = _p ?? const {};
    final file = f;
    final size = await file.xFile.length();
    if (!mounted) return;
    setState(() => _savingPhoto = true);
    try {
      // profile.php's own photo form: Surveyors send the photo alone; others re-send their details.
      final r = await Api.i.postPage(
        'profile.php',
        (csrf) => FormData.fromMap({
          'update_profile': '1',
          'csrf_token': csrf,
          if (!_surveyor) ...{'full_name': s(p['full_name']), 'email': s(p['email']), 'phone': s(p['phone'])},
          'profile_image': MultipartFile.fromStream(() => file.xFile.openRead(), size, filename: file.name),
        }),
      );
      await _done(r, 'Photo updated', 'Could not update the photo', reload: true);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _savingPhoto = false);
    }
  }

  Widget _field(String key, String label, TextInputType type, TextEditingController c, {bool secret = false}) =>
      Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextField(
          key: ValueKey('me-$key'),
          controller: c,
          keyboardType: type,
          obscureText: secret,
          decoration: InputDecoration(labelText: label),
        ),
      );

  Widget _button(String key, String label, bool busy, VoidCallback go, {bool outline = false}) {
    final child = busy
        ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.2))
        : Text(label);
    final style = ButtonStyle(minimumSize: WidgetStateProperty.all(const Size.fromHeight(48)));
    return outline
        ? OutlinedButton(key: ValueKey(key), style: style, onPressed: busy ? null : go, child: child)
        : FilledButton(key: ValueKey(key), style: style, onPressed: busy ? null : go, child: child);
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    Widget body;
    if (_error != null && p == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (p == null) {
      body = const CardSkeletons(count: 2);
    } else {
      final avatar = s(p['avatar']);
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: [
          Center(
            child: Stack(
              children: [
                CircleAvatar(
                  key: ValueKey('me-avatar-$_photoV'),
                  radius: 46,
                  backgroundColor: blueSoft,
                  foregroundImage: avatar.isEmpty
                      ? null
                      : NetworkImage('${Cfg.url(avatar)}?v=$_photoV', headers: Api.i.imageHeaders),
                  child: Text(
                    s(Api.i.me?['initials']),
                    style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: blue),
                  ),
                ),
                Positioned(
                  right: 0,
                  bottom: 0,
                  child: IconButton.filled(
                    key: const ValueKey('me-photo'),
                    tooltip: 'Change photo',
                    onPressed: _savingPhoto ? null : _photo,
                    icon: _savingPhoto
                        ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Icon(Icons.photo_camera_outlined),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 10),
          Center(
            child: Text(s(p['full_name']), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          ),
          Center(
            child: Text('@${s(p['username'])}', style: TextStyle(color: mutedText(context))),
          ),
          const SectionTitle('Details'),
          Box(
            child: Column(
              children: [
                for (final f in _fields) _field(f.$1, f.$2, f.$3, _ctl(f.$1)),
                if (_surveyor)
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Text(
                      'Date of birth as YYYY-MM-DD',
                      style: TextStyle(fontSize: 12, color: mutedText(context)),
                    ),
                  ),
                const SizedBox(height: 8),
                _button('me-save', 'Save details', _savingDetails, _saveDetails),
              ],
            ),
          ),
          const SectionTitle('Change password'),
          Box(
            child: Column(
              children: [
                _field(
                  'current_password',
                  'Current password',
                  TextInputType.visiblePassword,
                  _pw['current_password']!,
                  secret: true,
                ),
                _field(
                  'new_password',
                  'New password',
                  TextInputType.visiblePassword,
                  _pw['new_password']!,
                  secret: true,
                ),
                _field(
                  'confirm_password',
                  'Confirm new password',
                  TextInputType.visiblePassword,
                  _pw['confirm_password']!,
                  secret: true,
                ),
                _button('me-pass', 'Update password', _savingPw, _savePassword, outline: true),
              ],
            ),
          ),
        ],
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(title: const Text('My profile')),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}
