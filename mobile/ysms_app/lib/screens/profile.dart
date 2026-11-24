// Profile: the role's tools, account settings (fingerprint login, appearance) and log out.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api.dart';
import '../bio.dart';
import '../config.dart';
import '../main.dart' show themeMode, themeFromName;
import '../ui.dart';
import 'actions.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key, required this.onLogout});
  final VoidCallback onLogout;
  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _bioAvail = false, _bioOn = false, _bioBusy = false;

  Map<String, dynamic> get me => Api.i.me ?? {};

  @override
  void initState() {
    super.initState();
    _bio();
  }

  Future<void> _bio() async {
    final a = await Bio.available();
    final k = await Bio.key();
    if (mounted) {
      setState(() {
        _bioAvail = a;
        _bioOn = k != null && k.isNotEmpty;
      });
    }
  }

  Future<void> _toggleBio(bool on) async {
    setState(() => _bioBusy = true);
    try {
      if (on) {
        await Bio.enroll();
        if (mounted) toast(context, 'Fingerprint login is on for this phone', ok: true);
      } else {
        await Bio.disable();
        if (mounted) toast(context, 'Fingerprint login is off');
      }
    } on ApiError catch (e) {
      if (mounted && e.status != -1) toast(context, e.message, bad: true);
    }
    await _bio();
    if (mounted) setState(() => _bioBusy = false);
  }

  Future<void> _theme() async {
    final cur = await Prefs.read('theme') ?? 'system';
    if (!mounted) return;
    final v = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (ctx) => SafeArea(
        child: RadioGroup<String>(
          groupValue: cur,
          onChanged: (x) => Navigator.pop(ctx, x),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(20, 0, 20, 8),
                child: Align(
                  alignment: Alignment.centerLeft,
                  child: Text('Appearance', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
                ),
              ),
              for (final o in const [('system', 'System default'), ('light', 'Light'), ('dark', 'Dark')])
                RadioListTile<String>(value: o.$1, title: Text(o.$2)),
              const SizedBox(height: 8),
            ],
          ),
        ),
      ),
    );
    if (v == null) return;
    await Prefs.write('theme', v);
    themeMode.value = themeFromName(v);
    setState(() {});
  }

  Future<void> _logout() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Log out?'),
        content: const Text('You will need your username and password to sign in again.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: red),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Log out'),
          ),
        ],
      ),
    );
    if (yes != true) return;
    await Api.i.logout();
    HapticFeedback.mediumImpact();
    widget.onLogout();
  }

  @override
  Widget build(BuildContext context) {
    final role = s(me['role']);
    final all = allActions(me);
    final unread = (me['unread'] as num?)?.toInt() ?? 0;
    const workKeys = ['inbox', 'chat', 'recovery', 'word', 'surveyreports', 'srnative', 'livesr', 'formats', 'cancelled', 'permission', 'lineups'];
    const adminKeys = [
      'assign',
      'surveyor',
      'addclient',
      'addadmin',
      'admin',
      'activity',
      'sacontrols',
      'duplicates',
      'templates',
      'rtemplates',
    ];
    final work = workKeys.expand((k) => all.where((a) => a.key == k)).toList();
    final admin = adminKeys.expand((k) => all.where((a) => a.key == k)).toList();
    final top = MediaQuery.of(context).padding.top;
    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: SystemUiOverlayStyle.light,
      child: Scaffold(
        body: ListView(
          padding: EdgeInsets.zero,
          children: [
            Container(
              padding: EdgeInsets.fromLTRB(20, top + 28, 20, 28),
              decoration: const BoxDecoration(
                gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [navy2, navy]),
                borderRadius: BorderRadius.vertical(bottom: Radius.circular(28)),
              ),
              child: Column(
                children: [
                  Avatar(me: me, size: 84, light: true),
                  const SizedBox(height: 12),
                  Text(
                    s(me['name']),
                    textAlign: TextAlign.center,
                    style: const TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    role,
                    style: const TextStyle(color: Color(0xFF9FC3FF), fontWeight: FontWeight.w600),
                  ),
                ],
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const SectionTitle('Work'),
                  _menu([
                    for (final a in work)
                      _item(
                        a.icon,
                        a.color,
                        a.label,
                        () => runAction(context, a).then((_) => mounted ? setState(() {}) : null),
                        trailing: a.key == 'inbox' && unread > 0 ? Badge(label: Text(unread > 99 ? '99+' : '$unread')) : null,
                      ),
                  ]),
                  if (admin.isNotEmpty) ...[
                    SectionTitle(role == 'Client' ? 'Client' : 'Admin'),
                    _menu([for (final a in admin) _item(a.icon, a.color, a.label, () => runAction(context, a))]),
                  ],
                  const SectionTitle('Account'),
                  _menu([
                    _item(Icons.badge_outlined, blue, 'My profile', () => runAction(context, all.firstWhere((a) => a.key == 'profile'))),
                    if (_bioAvail)
                      SwitchListTile(
                        secondary: const IconBadge(Icons.fingerprint_rounded, green),
                        title: const Text('Fingerprint login', style: TextStyle(fontWeight: FontWeight.w600)),
                        subtitle: const Text('Sign in on this phone with your fingerprint'),
                        value: _bioOn,
                        onChanged: _bioBusy ? null : _toggleBio,
                      ),
                    _item(
                      Icons.dark_mode_outlined,
                      purple,
                      'Appearance',
                      _theme,
                      trailing: Text(switch (themeMode.value) {
                        ThemeMode.light => 'Light',
                        ThemeMode.dark => 'Dark',
                        _ => 'System',
                      }, style: TextStyle(color: mutedText(context))),
                    ),
                    _item(Icons.support_agent_rounded, green, 'Support', () => openWeb(context, 'coming_soon.php?feature=Support')),
                    ListTile(
                      leading: const IconBadge(Icons.logout_rounded, red),
                      title: const Text(
                        'Log out',
                        style: TextStyle(color: red, fontWeight: FontWeight.w700),
                      ),
                      onTap: _logout,
                    ),
                  ]),
                  const SizedBox(height: 18),
                  Text(
                    'YMR YSMS ${Cfg.version}${Cfg.isLive ? '' : ' · staging'}',
                    textAlign: TextAlign.center,
                    style: TextStyle(color: mutedText(context), fontSize: 12),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _menu(List<Widget> items) => Box(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Column(
      children: [
        for (var i = 0; i < items.length; i++) ...[if (i > 0) Divider(height: 1, indent: 68, color: lineColor(context)), items[i]],
      ],
    ),
  );

  Widget _item(IconData ic, Color c, String label, VoidCallback onTap, {Widget? trailing}) => ListTile(
    leading: IconBadge(ic, c),
    title: Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
    trailing: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        ?trailing,
        const SizedBox(width: 4),
        Icon(Icons.chevron_right_rounded, color: mutedText(context)),
      ],
    ),
    onTap: onTap,
  );
}
