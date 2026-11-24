// YMR YSMS — the Flutter app (Android and iOS). Two apps are built from it: staging and live (config.dart).
//
// Screens made in Flutter: login, home, the vessel lists (Pending Vessels, Pending Reports, Completed,
// Cancelled), search, vessel details, upload, profile, team chat, inbox, recovery, formats, history,
// templates, users, admin tools, assign / edit vessel (nativeScreen below). The report generators (Word,
// photo, invoice, survey reports) open the web app's own screen inside the app (screens/web.dart).
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'api.dart';
import 'bio.dart';
import 'config.dart';
import 'screens/activity.dart';
import 'screens/assign.dart';
import 'screens/chat.dart';
import 'screens/admin.dart';
import 'screens/duplicates.dart';
import 'screens/forgot.dart';
import 'screens/formats.dart';
import 'screens/history.dart';
import 'screens/home.dart';
import 'screens/inbox.dart';
import 'screens/lists.dart';
import 'screens/login.dart';
import 'screens/me.dart';
import 'screens/pages.dart';
import 'screens/profile.dart';
import 'screens/recovery.dart';
import 'screens/templates.dart';
import 'screens/upload.dart';
import 'screens/users.dart';
import 'screens/vessel.dart';
import 'screens/web.dart';
import 'sr/sr_editor.dart';
import 'sr/sr_home.dart';
import 'ui.dart';

final navKey = GlobalKey<NavigatorState>();
final themeMode = ValueNotifier<ThemeMode>(ThemeMode.system);

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  Api.i = Api();
  WebScreen.nativeRoute = nativeRoute;
  nativeScreenFor = nativeScreen;
  nativePageFor = nativePage;
  Prefs.read('theme').then((t) => themeMode.value = themeFromName(t));
  runApp(const YsmsApp());
}

ThemeMode themeFromName(String? t) => switch (t) {
  'light' => ThemeMode.light,
  'dark' => ThemeMode.dark,
  _ => ThemeMode.system,
};

/// The Flutter screen of a web app route ('/v/12', '/formats' …), null when it has none yet.
Widget? nativeScreen(String route) {
  final m = RegExp(r'^/(v|up|h|sv|e|c)/(\d+)$').firstMatch(route);
  if (m != null) {
    final id = int.parse(m.group(2)!);
    return switch (m.group(1)) {
      'v' => VesselScreen(id: id),
      'up' => UploadScreen(id: id),
      'sv' => SurveyorFormScreen(id: id),
      'e' => AssignScreen(editId: id),
      'c' => RoomScreen(id: id),
      _ => HistoryScreen(id: id),
    };
  }
  final mm = RegExp(r'^/m/([a-z_]+)$').firstMatch(route);
  if (mm != null) return ModuleScreen(moduleKey: mm.group(1)!);
  return switch (route) {
    '/formats' => const FormatsScreen(),
    '/duplicates' => const DuplicatesScreen(),
    '/activity' => const ActivityScreen(),
    '/recovery' => const RecoveryScreen(),
    '/inbox' => const InboxScreen(),
    '/me' => const MyProfileScreen(),
    '/forgot' => const ForgotScreen(),
    '/templates' => const TemplatesScreen(),
    '/addclient' => CreateUserScreen.client(),
    '/addadmin' => CreateUserScreen.admin(),
    '/surveyor' => const SurveyorFormScreen(),
    '/admin' => const AdminScreen(),
    '/assign' => const AssignScreen(),
    '/sacontrols' => const DataCleanupScreen(),
    '/chat' => const ChatScreen(),
    _ => null,
  };
}

/// The Flutter screen of a website page ('generate_permission_copy.php' …), null when it opens as a page.
Widget? nativePage(String page) {
  final name = page.split('?').first.split('#').first;
  return switch (name) {
    'admin_surveyor_cards.php' => const SurveyorCardsScreen(),
    'admin_daily_emails.php' => const DailyEmailsScreen(),
    'generate_permission_copy.php' => const PermissionCopyScreen(),
    'coming_soon.php' => ComingSoonScreen(feature: ComingSoonScreen.featureOf(page)),
    // the survey report in Flutter: staging app only (the live app keeps the website's page)
    'survey_report_public.php' when !Cfg.isLive => _srPage(page),
    _ => null,
  };
}

Widget _srPage(String page) {
  final t = RegExp(r'[?&]t=([0-9a-f]{64})').firstMatch(page)?.group(1);
  return t != null ? SrEditorScreen(id: t) : const SrHomeScreen();
}

/// Web app routes that have a Flutter screen (a link inside a web screen).
bool nativeRoute(BuildContext context, String route) {
  final w = nativeScreen(route);
  if (w == null) return false;
  Navigator.of(context).push(MaterialPageRoute(builder: (_) => w));
  return true;
}

class YsmsApp extends StatelessWidget {
  const YsmsApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<ThemeMode>(
      valueListenable: themeMode,
      builder: (_, mode, _) => MaterialApp(
        title: 'YMR YSMS',
        debugShowCheckedModeBanner: false,
        navigatorKey: navKey,
        theme: appTheme(Brightness.light),
        darkTheme: appTheme(Brightness.dark),
        themeMode: mode,
        home: const Root(),
      ),
    );
  }
}

/// Start: signed in already (the "stay signed in" cookie) → the app; else the login screen.
class Root extends StatefulWidget {
  const Root({super.key});
  @override
  State<Root> createState() => _RootState();
}

class _RootState extends State<Root> {
  bool _booting = true;
  bool _signedIn = false;
  String _msg = '';

  @override
  void initState() {
    super.initState();
    Api.i.sessionLost.addListener(_lost);
    _boot();
  }

  @override
  void dispose() {
    Api.i.sessionLost.removeListener(_lost);
    super.dispose();
  }

  Future<void> _boot() async {
    try {
      await Api.i.loadMe();
      _signedIn = true;
      Shell.tab.value = 0;
    } on ApiError catch (e) {
      _signedIn = false;
      if (e.offline) _msg = e.message;
    } catch (_) {
      _signedIn = false;
    }
    if (mounted) setState(() => _booting = false);
  }

  void _lost() {
    navKey.currentState?.popUntil((r) => r.isFirst);
    if (mounted) {
      setState(() {
        _signedIn = false;
        _msg = 'You were signed out. Please sign in again.';
      });
    }
  }

  void _in() {
    Shell.tab.value = 0;
    setState(() {
      _signedIn = true;
      _msg = '';
    });
  }

  void _out() => setState(() {
    _signedIn = false;
    _msg = '';
  });

  @override
  Widget build(BuildContext context) {
    if (_booting) return const _Splash();
    final child = _signedIn
        ? Shell(key: const ValueKey('shell'), onLogout: _out)
        : Theme(
            key: const ValueKey('login'),
            data: appTheme(Brightness.light),
            child: LoginScreen(onSignedIn: _in, message: _msg),
          );
    return AnimatedSwitcher(duration: const Duration(milliseconds: 300), child: child);
  }
}

class _Splash extends StatelessWidget {
  const _Splash();
  @override
  Widget build(BuildContext context) => AnnotatedRegion<SystemUiOverlayStyle>(
    value: SystemUiOverlayStyle.light,
    child: Scaffold(
      backgroundColor: navy,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 76,
              height: 76,
              padding: const EdgeInsets.all(13),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24)),
              child: Image.asset('assets/images/logo.png'),
            ),
            const SizedBox(height: 18),
            const Text(
              'YMR MARINE',
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 18, letterSpacing: 1.6),
            ),
            const SizedBox(height: 4),
            const Text('Survey Management System', style: TextStyle(color: Color(0xFF9FC3FF), fontSize: 12.5)),
            const SizedBox(height: 28),
            const SizedBox(width: 26, height: 26, child: CircularProgressIndicator(strokeWidth: 2.6, color: sky)),
          ],
        ),
      ),
    ),
  );
}

/// The signed-in app: five tabs.
class Shell extends StatefulWidget {
  const Shell({super.key, required this.onLogout});
  final VoidCallback onLogout;

  static final tab = ValueNotifier<int>(0);

  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> {
  final _seen = <int>{0};

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<int>(
      valueListenable: Shell.tab,
      builder: (_, i, _) {
        _seen.add(i);
        Widget page(int n, Widget Function() b) => _seen.contains(n) ? b() : const SizedBox.shrink();
        return PopScope(
          canPop: i == 0,
          onPopInvokedWithResult: (didPop, _) {
            if (!didPop) Shell.tab.value = 0;
          },
          child: Scaffold(
            body: IndexedStack(
              index: i,
              children: [
                const HomeScreen(),
                page(1, () => const ListScreen(kind: ListKind.vessels)),
                page(2, () => const ListScreen(kind: ListKind.reports)),
                page(3, () => const ListScreen(kind: ListKind.completed)),
                page(4, () => ProfileScreen(onLogout: widget.onLogout)),
              ],
            ),
            bottomNavigationBar: NavigationBar(
              selectedIndex: i,
              onDestinationSelected: (n) {
                HapticFeedback.selectionClick();
                Shell.tab.value = n;
              },
              destinations: const [
                NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home_rounded), label: 'Home'),
                NavigationDestination(
                  icon: Icon(Icons.directions_boat_outlined),
                  selectedIcon: Icon(Icons.directions_boat_rounded),
                  label: 'Vessels',
                ),
                NavigationDestination(
                  icon: Icon(Icons.description_outlined),
                  selectedIcon: Icon(Icons.description_rounded),
                  label: 'Reports',
                ),
                NavigationDestination(icon: Icon(Icons.task_alt_outlined), selectedIcon: Icon(Icons.task_alt_rounded), label: 'Completed'),
                NavigationDestination(icon: Icon(Icons.person_outline_rounded), selectedIcon: Icon(Icons.person_rounded), label: 'Profile'),
              ],
            ),
          ),
        );
      },
    );
  }
}
