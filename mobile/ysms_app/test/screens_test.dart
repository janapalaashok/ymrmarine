// Widget tests of the screens moved from the web app to Flutter (fake server, no network).
import 'dart:convert';
import 'dart:typed_data';

import 'package:cross_file/cross_file.dart';
import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/api.dart';
import 'package:ysms_app/bio.dart';
import 'package:ysms_app/main.dart' show nativePage, nativeScreen;
import 'package:ysms_app/screens/activity.dart';
import 'package:ysms_app/screens/admin.dart';
import 'package:ysms_app/screens/assign.dart';
import 'package:ysms_app/screens/chat.dart';
import 'package:ysms_app/screens/duplicates.dart';
import 'package:ysms_app/screens/forgot.dart';
import 'package:ysms_app/screens/formats.dart';
import 'package:ysms_app/screens/history.dart';
import 'package:ysms_app/screens/inbox.dart';
import 'package:ysms_app/screens/me.dart';
import 'package:ysms_app/screens/pages.dart';
import 'package:ysms_app/screens/recovery.dart';
import 'package:ysms_app/screens/templates.dart';
import 'package:ysms_app/screens/users.dart';
import 'package:ysms_app/screens/vessel.dart';
import 'package:ysms_app/screens/vessel_tools.dart';
import 'package:ysms_app/sr/sr_api.dart';
import 'package:ysms_app/sr/sr_editor.dart';
import 'package:ysms_app/sr/sr_home.dart';
import 'package:ysms_app/sr/sr_store.dart';
import 'package:ysms_app/ui.dart';

import 'sr_model_test.dart' show starter;

/// app_api.php?a=… → JSON; other pages → [pages] (POSTs are recorded with their fields).
class Fake implements HttpClientAdapter {
  final api = <String, Object>{};
  final pages = <String, String>{};
  final chat = <String, Object>{};
  final sr = <String, Object>{};
  bool offline = false;
  final calls = <String>[];
  final posts = <Map<String, dynamic>>[];

  ResponseBody _json(Object o, [int code = 200]) => ResponseBody.fromString(
    jsonEncode(o),
    code,
    headers: {
      Headers.contentTypeHeader: ['application/json'],
    },
  );

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? body, Future<void>? cancel) async {
    if (offline) throw DioException(requestOptions: o, type: DioExceptionType.connectionError);
    final path = o.uri.path.split('/').last;
    final a = o.uri.queryParameters['a'] ?? '';
    calls.add('${o.method} $path${a.isEmpty ? '' : '?a=$a'}');
    if (o.method == 'POST') {
      final d = o.data;
      posts.add({
        'path': path,
        if (d is Map) ...d.map((k, v) => MapEntry('$k', v)),
        if (d is FormData) ...{for (final f in d.fields) f.key: f.value},
      });
    }
    if (path == 'survey_report_public.php' && o.uri.path.contains('ajax/')) {
      final act = a.isNotEmpty ? a : s(posts.isEmpty ? null : posts.last['a']);
      calls.add('sr $act');
      final r = sr[act];
      return r == null ? _json({'ok': false, 'error': 'Not found.'}, 404) : _json(r);
    }
    if (path == 'team_chat.php') {
      final act = o.uri.queryParameters['action'] ?? s(posts.isEmpty ? null : posts.last['action']);
      calls.add('chat $act');
      final r = chat[act];
      return r == null ? _json({'ok': false, 'error': 'Not found.'}, 404) : _json(r);
    }
    if (path == 'app_api.php') {
      final r = api[a];
      if (r == null) return _json({'error': 'not_found'}, 404);
      return _json(r);
    }
    final p = pages[path];
    if (p != null) {
      return ResponseBody.fromString(
        p,
        200,
        headers: {
          Headers.contentTypeHeader: [p.startsWith('{') ? 'application/json' : 'text/html'],
        },
      );
    }
    return ResponseBody.fromString('', 404);
  }

  @override
  void close({bool force = false}) {}
}

/// A picked file in memory (tests only).
final class _MemFile extends PlatformFile {
  _MemFile(this.name, this.bytes);
  @override
  final String name;
  final Uint8List bytes;
  @override
  Uri get uri => Uri.parse('memory:$name');
  @override
  XFile get xFile => XFile.fromData(bytes, name: name, length: bytes.length);
  @override
  int? lengthSync() => bytes.length;
  @override
  Future<int?> length() async => bytes.length;
  @override
  Future<Uint8List> readAsBytes() async => bytes;
  @override
  Stream<Uint8List> readAsByteStream() => Stream.value(bytes);
}

Future<void> settle(WidgetTester t) async {
  for (var i = 0; i < 20; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

Widget app(Widget w) => MaterialApp(theme: appTheme(Brightness.light), home: w);

void main() {
  late Fake server;
  setUp(() {
    Prefs.memoryOnly = true;
    server = Fake();
    Api.i = Api(cookies: MemoryCookies(), adapter: server);
    Api.i.me = {
      'id': 1,
      'name': 'Ravi Kumar',
      'initials': 'RK',
      'role': 'Admin',
      'csrf': 'tok',
      'can': <String, dynamic>{},
    };
  });

  testWidgets('Formats: the list, newest first marked New', (t) async {
    server.api['formats'] = {
      'items': [
        {
          'name': 'YMR OFFHIRE FORMAT',
          'path': 'formats/1_YMR-OFFHIRE-FORMAT.xlsx',
          'updated': '14 Nov 2026, 10:00 AM',
          'recent': true,
        },
        {
          'name': 'YMR ROB FORMAT',
          'path': 'formats/2_YMR-ROB-FORMAT.xlsx',
          'updated': '01 Oct 2026, 09:00 AM',
          'recent': false,
        },
      ],
    };
    await t.pumpWidget(app(const FormatsScreen()));
    await settle(t);
    expect(find.text('YMR OFFHIRE FORMAT'), findsOneWidget);
    expect(find.text('YMR ROB FORMAT'), findsOneWidget);
    expect(find.text('New'), findsOneWidget);
    expect(find.text('2 approved formats · tap one to open it'), findsOneWidget);
    expect(server.calls, contains('GET app_api.php?a=formats'));
  });

  testWidgets('Formats: none uploaded', (t) async {
    server.api['formats'] = {'items': []};
    await t.pumpWidget(app(const FormatsScreen()));
    await settle(t);
    expect(find.text('No formats uploaded yet'), findsOneWidget);
  });

  testWidgets('Previous surveys: each earlier survey with recovery and documents', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1200));
    server.api['history'] = {
      'vessel': 'MV OCEAN STAR',
      'items': [
        {
          'id': 7,
          'vessel': 'MV OCEAN STAR',
          'status': 'Completed',
          'report_no': 'YMR/BB/123',
          'type': 'Bunker Survey + Offhire',
          'client': 'Oceanus',
          'port': 'Kakinada',
          'surveyors': 'John Doe',
          'completed_on': '10 Oct 2026',
          'report_on': '12 Oct 2026',
          'recovery': {'total': '12.500', 'vlsfo': '10.000', 'lsmgo': '2.500', 'hsfo': '—'},
          'docs': [
            {'id': 55, 'name': 'Final Report.pdf', 'type': 'Final Report', 'when': '12 Oct 2026'},
          ],
        },
      ],
    };
    await t.pumpWidget(app(const HistoryScreen(id: 3)));
    await settle(t);
    expect(find.text('MV OCEAN STAR'), findsWidgets);
    expect(find.text('Oceanus · Kakinada'), findsOneWidget);
    expect(find.text('Surveyed 10 Oct 2026'), findsOneWidget);
    expect(find.text('12.500'), findsOneWidget);
    expect(find.text('Final Report.pdf'), findsOneWidget);
    expect(find.text('Completed'), findsOneWidget);
    expect(server.calls, contains('GET app_api.php?a=history'));
  });

  testWidgets('Previous surveys: none', (t) async {
    server.api['history'] = {'vessel': 'MV X', 'items': []};
    await t.pumpWidget(app(const HistoryScreen(id: 3)));
    await settle(t);
    expect(find.text('No earlier surveys of this vessel'), findsOneWidget);
  });

  testWidgets('Possible duplicates: clusters, filter, delete asks twice', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['duplicates'] = {
      'clusters': [
        {
          'kind': 'duplicate',
          'month': 'Oct 2026',
          'extra_mt': 12.5,
          'extra_ships': 1,
          'records': [
            {
              'id': 11,
              'vessel': 'MV TWIN',
              'report_no': 'R-1',
              'status': 'Completed',
              'type': 'Bunker',
              'surveyor': 'John',
              'completed_on': '01 Oct 2026',
              'recovery': '12.500',
              'files': 2,
              'source': 'Upload',
              'list': 'completed_surveys',
            },
            {
              'id': 12,
              'vessel': 'MV TWIN',
              'report_no': 'R-2',
              'status': 'Completed',
              'type': 'Bunker',
              'surveyor': 'John',
              'completed_on': '05 Oct 2026',
              'recovery': '12.500',
              'files': 1,
              'source': 'Import',
              'list': 'completed_surveys',
            },
          ],
        },
        {
          'kind': 'check',
          'month': 'Sep 2026',
          'extra_mt': 0,
          'extra_ships': 0,
          'records': [
            {
              'id': 21,
              'vessel': 'MV CHECK ME',
              'report_no': '',
              'status': 'Pending Report',
              'type': 'Draft',
              'surveyor': '',
              'completed_on': '',
              'recovery': '—',
              'files': 0,
              'source': 'App',
              'list': 'pending_reports',
            },
          ],
        },
      ],
    };
    server.pages['admin_surveys.php'] = '{"success":true,"message":"Deleted"}';
    await t.pumpWidget(app(const DuplicatesScreen()));
    await settle(t);
    expect(find.text('MV TWIN'), findsOneWidget);
    expect(find.text('MV CHECK ME'), findsOneWidget);
    expect(find.text('12.500 MT counted 2 times'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('dup-chip-check')));
    await settle(t);
    expect(find.text('MV TWIN'), findsNothing);
    expect(find.text('MV CHECK ME'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('dup-chip-')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('dup-del-12')));
    await settle(t);
    expect(find.text('Tap to confirm'), findsOneWidget);
    expect(server.posts, isEmpty);
    await t.tap(find.byKey(const ValueKey('dup-del-12')));
    await settle(t);
    expect(server.posts.single['path'], 'admin_surveys.php');
    expect(server.posts.single['action'], 'delete');
    expect(server.posts.single['id'], '12');
    expect(server.posts.single['type'], 'completed_surveys');
  });

  testWidgets('Login activity: summary, rows, filter', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.pages['login_activity_summary.php'] = jsonEncode({
      'success': true,
      'summary': {'total_users': 9, 'logged_in_today': 3, 'active_sessions': 2, 'failed_today': 1},
    });
    server.pages['login_activity_list.php'] = jsonEncode({
      'success': true,
      'total': 2,
      'page': 1,
      'per_page': 30,
      'rows': [
        {
          'id': 1,
          'user_id': 4,
          'user_name': 'Ravi Kumar',
          'username': 'ravi',
          'role': 'Admin',
          'device_type': 'Mobile',
          'browser': 'Chrome',
          'login_time': '2026-11-14 09:05:00',
          'ip_address': '1.2.3.4',
          'country': 'India',
          'login_status': 'SUCCESS',
        },
        {
          'id': 2,
          'user_id': 0,
          'user_name': '',
          'username': 'ghost',
          'role': '',
          'device_type': 'Desktop',
          'browser': 'Edge',
          'login_time': '2026-11-14 10:00:00',
          'ip_address': '5.6.7.8',
          'country': 'Unknown',
          'login_status': 'FAILED',
          'failure_reason': 'Wrong password',
        },
      ],
    });
    await t.pumpWidget(app(const ActivityScreen()));
    await settle(t);
    expect(find.text('Ravi Kumar'), findsOneWidget);
    expect(find.text('ghost'), findsOneWidget);
    expect(find.text('14 Nov, 10:00 · 5.6.7.8 · Wrong password'), findsOneWidget);
    expect(find.text('2 logins'), findsOneWidget);
    expect(find.text('Logged in today'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('act-chip-Failed')));
    await settle(t);
    expect(server.calls.where((c) => c == 'GET login_activity_list.php').length, 2);
  });

  testWidgets('Recovery: totals, list, month filter, paging', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['recovery'] = {
      'count': 2,
      'vlsfo': 10.5,
      'lsmgo': 2.25,
      'hsfo': 0,
      'total': 12.75,
      'months': [
        {'value': '2026-11', 'label': 'Nov 2026'},
      ],
      'more': false,
      'items': [
        {
          'id': 5,
          'vessel': 'MV OCEAN STAR',
          'client': 'Oceanus',
          'port': 'Kakinada',
          'type': 'Bunker Survey',
          'completed': '10 Nov 2026',
          'total': 12.75,
          'na': false,
          'total_text': '12.750',
        },
        {
          'id': 6,
          'vessel': 'MV DRAFT ONLY',
          'client': 'Oceanus',
          'port': 'Vizag',
          'type': 'Draft Survey',
          'completed': '',
          'total': null,
          'na': true,
          'total_text': 'N/A',
        },
      ],
    };
    await t.pumpWidget(app(const RecoveryScreen()));
    await settle(t);
    expect(find.text('MV OCEAN STAR'), findsOneWidget);
    expect(find.text('N/A'), findsOneWidget);
    expect(find.text('Nov 2026'), findsOneWidget);
    expect(find.byKey(const ValueKey('rec-total')), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('rec-month-2026-11')));
    await settle(t);
    expect(server.calls.where((c) => c == 'GET app_api.php?a=recovery').length, 2);
  });

  testWidgets('Inbox: unread marked, tap marks read, Mark all read posts the form', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1200));
    server.api['inbox'] = {
      'total': 2,
      'unread': 1,
      'more': false,
      'items': [
        {
          'id': 1,
          'type': 'assign',
          'title': 'New vessel assigned',
          'message': 'MV OCEAN STAR at Kakinada',
          'read': false,
          'ago': '5 min ago',
          'from': 'Admin',
          'link': 'about-us.php',
          'survey_id': 0,
        },
        {
          'id': 2,
          'type': 'upload',
          'title': 'Report uploaded',
          'message': 'MV TWIN',
          'read': true,
          'ago': '1 day ago',
          'from': '',
          'link': '',
          'survey_id': 0,
        },
      ],
    };
    server.pages['inbox.php'] = '<html>ok</html>';
    await t.pumpWidget(app(const InboxScreen()));
    await settle(t);
    expect(find.text('New vessel assigned'), findsOneWidget);
    expect(find.text('1 unread'), findsOneWidget);
    expect(find.byKey(const ValueKey('notif-dot-1')), findsOneWidget);
    expect(find.byKey(const ValueKey('notif-dot-2')), findsNothing);
    await t.tap(find.byKey(const ValueKey('inbox-allread')));
    await settle(t);
    expect(server.posts.last['path'], 'inbox.php');
    expect(server.posts.last['action'], 'mark_all_read');
    expect(server.posts.last['csrf_token'], 'tok');
    await t.tap(find.byKey(const ValueKey('inbox-chip-Unread')));
    await settle(t);
    expect(server.calls.where((c) => c == 'GET app_api.php?a=inbox').length, greaterThanOrEqualTo(3));
  });

  testWidgets('My profile: details saved with profile.php, its message shown; password checks', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['profile'] = {
      'username': 'ravi',
      'full_name': 'Ravi Kumar',
      'first_name': 'Ravi',
      'last_name': 'Kumar',
      'dob': '',
      'email': 'ravi@example.com',
      'phone': '99999',
      'avatar': '',
      'surveyor_form': false,
    };
    server.api['me'] = Api.i.me!;
    server.pages['profile.php'] = '<html><div class="alert alert-success mx-3"><i class="fa-solid fa-circle-check"></i> Changes saved successfully!</div></html>';
    await t.pumpWidget(app(const MyProfileScreen()));
    await settle(t);
    expect(find.text('@ravi'), findsOneWidget);
    expect(find.byKey(const ValueKey('me-full_name')), findsOneWidget);
    expect(find.byKey(const ValueKey('me-first_name')), findsNothing);
    await t.enterText(find.byKey(const ValueKey('me-phone')), '12345');
    await t.tap(find.byKey(const ValueKey('me-save')));
    await settle(t);
    final p = server.posts.last;
    expect(p['path'], 'profile.php');
    expect(p['update_profile'], '1');
    expect(p['phone'], '12345');
    expect(p['full_name'], 'Ravi Kumar');
    expect(p['csrf_token'], 'tok');
    expect(find.text('Changes saved successfully!'), findsOneWidget);
    // password: checked before sending
    final n = server.posts.length;
    await t.enterText(find.byKey(const ValueKey('me-current_password')), 'old');
    await t.enterText(find.byKey(const ValueKey('me-new_password')), 'abc');
    await t.enterText(find.byKey(const ValueKey('me-confirm_password')), 'abc');
    await t.tap(find.byKey(const ValueKey('me-pass')));
    await settle(t);
    expect(server.posts.length, n);
    expect(find.text('The new password needs at least 6 characters.'), findsOneWidget);
  });

  testWidgets('My profile: a Surveyor edits first / last name and date of birth', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['profile'] = {
      'username': 'sv',
      'full_name': 'John Doe',
      'first_name': 'John',
      'last_name': 'Doe',
      'dob': '1990-01-02',
      'email': '',
      'phone': '',
      'avatar': '',
      'surveyor_form': true,
    };
    await t.pumpWidget(app(const MyProfileScreen()));
    await settle(t);
    expect(find.byKey(const ValueKey('me-first_name')), findsOneWidget);
    expect(find.byKey(const ValueKey('me-dob')), findsOneWidget);
    expect(find.byKey(const ValueKey('me-email')), findsNothing);
  });

  test('page messages: alert, flash toast, error', () {
    expect(pageResult('<div class="alert alert-danger x"><i></i> Wrong &amp; bad</div>').ok, isFalse);
    expect(pageResult('<div class="alert alert-danger x"><i></i> Wrong &amp; bad</div>').message, 'Wrong & bad');
    final toastOk = pageResult(
      '<div id="nativeAppToast" class="ysms-toast ysms-toast-success"><i></i><span class="ysms-toast-text">Saved</span></div>',
    );
    expect(toastOk.ok, isTrue);
    expect(toastOk.message, 'Saved');
    expect(pageResult('<html>nothing</html>').ok, isFalse);
  });

  testWidgets('Forgot password: checks the email, posts forgot_password.php, shows its message', (t) async {
    server.pages['forgot_password.php'] = '<html><div class="alert alert-success py-2 mb-3">If this email is registered, a reset link has been sent.</div></html>';
    await t.pumpWidget(app(const ForgotScreen()));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('fp-email')), 'nope');
    await t.tap(find.byKey(const ValueKey('fp-send')));
    await settle(t);
    expect(find.text('Please enter a valid email address.'), findsOneWidget);
    expect(server.posts, isEmpty);
    await t.enterText(find.byKey(const ValueKey('fp-email')), 'ravi@example.com');
    await t.tap(find.byKey(const ValueKey('fp-send')));
    await settle(t);
    expect(server.posts.single['path'], 'forgot_password.php');
    expect(server.posts.single['email'], 'ravi@example.com');
    expect(find.text('If this email is registered, a reset link has been sent.'), findsOneWidget);
  });

  testWidgets('Templates: list, rename via manage_templates.php, delete asks twice', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1200));
    server.api['templates'] = {
      'items': [
        {'id': 3, 'name': 'Bunker Survey Report', 'type': 'Bunker Survey', 'file': 'a.docx', 'when': '01 Nov 2026'},
      ],
      'photo': {'name': 'YMR_Photo_Report_Template.docx', 'when': '02 Nov 2026, 10:00 AM'},
    };
    server.pages['manage_templates.php'] = '<html><div class="alert alert-success">Template updated.</div></html>';
    await t.pumpWidget(app(const TemplatesScreen()));
    await settle(t);
    expect(find.text('Bunker Survey Report'), findsOneWidget);
    expect(find.text('YMR_Photo_Report_Template.docx'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('tpl-3')));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('tpl-name')), 'Bunker Report v2');
    await t.tap(find.byKey(const ValueKey('tpl-save')));
    await settle(t);
    expect(server.posts.last['path'], 'manage_templates.php');
    expect(server.posts.last['rename_template'], '1');
    expect(server.posts.last['template_id'], '3');
    expect(server.posts.last['new_template_name'], 'Bunker Report v2');
    expect(find.text('Template updated.'), findsOneWidget);
    // the photo template: delete asks twice
    final n = server.posts.length;
    await t.tap(find.byKey(const ValueKey('tpl-photo-del')));
    await settle(t);
    expect(server.posts.length, n);
    expect(find.text('Tap to confirm'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('tpl-photo-del')));
    await settle(t);
    expect(server.posts.last['delete_photo_report_template'], '1');
  });

  testWidgets('Add client: required fields and passwords checked, then add_client.php', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.pages['add_client.php'] = '<html><div class="alert alert-danger">Username already exists.</div></html>';
    await t.pumpWidget(app(CreateUserScreen.client()));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('form-save')));
    await settle(t);
    expect(find.text('Company name is required.'), findsOneWidget);
    expect(server.posts, isEmpty);
    for (final e in {
      'company_name': 'Oceanus',
      'contact_person': 'Mr A',
      'username': 'oceanus',
      'mobile': '999',
      'email': 'a@b.co',
      'password': 'secret1',
      'confirm_password': 'secret2',
    }.entries) {
      await t.enterText(find.byKey(ValueKey('f-${e.key}')), e.value);
    }
    await t.tap(find.byKey(const ValueKey('form-save')));
    await settle(t);
    expect(find.text('Passwords do not match.'), findsOneWidget);
    await t.enterText(find.byKey(const ValueKey('f-confirm_password')), 'secret1');
    await t.tap(find.byKey(const ValueKey('form-save')));
    await settle(t);
    expect(server.posts.single['path'], 'add_client.php');
    expect(server.posts.single['company_name'], 'Oceanus');
    expect(server.posts.single['csrf_token'], 'tok');
    expect(find.text('Username already exists.'), findsOneWidget);
  });

  testWidgets('Add admin: Super Admin checkbox sent', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.pages['add_admin.php'] = '<html><div class="alert alert-success">Admin created.</div></html>';
    await t.pumpWidget(
      app(
        Scaffold(
          body: Builder(
            builder: (c) => TextButton(
              onPressed: () => Navigator.of(c).push(MaterialPageRoute(builder: (_) => CreateUserScreen.admin())),
              child: const Text('go'),
            ),
          ),
        ),
      ),
    );
    await t.tap(find.text('go'));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('f-full_name')), 'New Admin');
    await t.enterText(find.byKey(const ValueKey('f-username')), 'nadmin');
    await t.enterText(find.byKey(const ValueKey('f-password')), 'secret1');
    await t.enterText(find.byKey(const ValueKey('f-confirm_password')), 'secret1');
    await t.tap(find.byKey(const ValueKey('f-is_super_admin')));
    await t.tap(find.byKey(const ValueKey('form-save')));
    await settle(t);
    expect(server.posts.single['path'], 'add_admin.php');
    expect(server.posts.single['is_super_admin'], '1');
    expect(find.text('go'), findsOneWidget); // back after saving
  });

  testWidgets('Edit surveyor: loads, saves to add_surveyor.php?id=', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1400));
    server.api['surveyor'] = {
      'id': 9,
      'username': 'john',
      'first_name': 'John',
      'last_name': 'Doe',
      'dob': '1990-01-02',
      'date_of_joining': '2024-05-01',
      'phone': '111',
      'email': 'j@x.co',
      'status': 'Active',
      'avatar': '',
    };
    server.pages['add_surveyor.php'] = '<html><div class="alert alert-success">Surveyor updated.</div></html>';
    await t.pumpWidget(app(const SurveyorFormScreen(id: 9)));
    await settle(t);
    expect(find.text('Edit surveyor'), findsOneWidget);
    expect(find.byKey(const ValueKey('f-password')), findsNothing);
    await t.enterText(find.byKey(const ValueKey('f-phone')), '222');
    await t.tap(find.byKey(const ValueKey('form-save')));
    await settle(t);
    expect(server.posts.single['path'], 'add_surveyor.php');
    expect(server.posts.single['save_surveyor'], '1');
    expect(server.posts.single['phone'], '222');
    expect(server.posts.single['username'], 'john');
    expect(server.calls, contains('POST add_surveyor.php'));
  });

  final modules = {
    'modules': [
      {
        'key': 'ports',
        'label': 'Ports',
        'singular': 'Port',
        'name_field': 'port_name',
        'fields': [
          {'name': 'port_name', 'label': 'Port name', 'type': 'text', 'required': true},
          {'name': 'country', 'label': 'Country', 'type': 'text'},
          {
            'name': 'status',
            'label': 'Status',
            'type': 'select',
            'required': true,
            'options': ['Active', 'Inactive'],
          },
        ],
      },
    ],
  };

  testWidgets('Admin controls: master data tiles and the tools', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1600));
    server.api['admin_modules'] = modules;
    await t.pumpWidget(app(const AdminScreen()));
    await settle(t);
    expect(find.text('Admin controls'), findsOneWidget);
    expect(find.byKey(const ValueKey('mod-ports')), findsOneWidget);
    expect(find.text('Add surveyor'), findsOneWidget);
    expect(find.text('Possible duplicates'), findsOneWidget);
    expect(find.text('Add admin'), findsNothing);
  });

  testWidgets('Master data: list, edit saved to admin_master.php, delete asks twice', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1600));
    server.api['admin_modules'] = modules;
    server.pages['admin_master.php'] = jsonEncode({
      'success': true,
      'message': 'Saved',
      'rows': [
        {'id': 4, 'port_name': 'Kakinada', 'country': 'India', 'status': 'Active'},
      ],
    });
    await t.pumpWidget(app(const ModuleScreen(moduleKey: 'ports')));
    await settle(t);
    expect(find.text('Kakinada'), findsOneWidget);
    expect(find.text('India · Active'), findsOneWidget);
    expect(find.text('1 entry'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('row-4')));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('f-country')), 'IN');
    await t.tap(find.byKey(const ValueKey('sheet-save')));
    await settle(t);
    final p = server.posts.last;
    expect(p['path'], 'admin_master.php');
    expect(p['action'], 'edit');
    expect(p['id'], '4');
    expect(p['module'], 'ports');
    expect(p['country'], 'IN');
    expect(p['port_name'], 'Kakinada');
    await t.tap(find.byKey(const ValueKey('row-4')));
    await settle(t);
    final n = server.posts.length;
    await t.tap(find.byKey(const ValueKey('sheet-delete')));
    await settle(t);
    expect(server.posts.length, n);
    expect(find.text('Tap again to delete'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('sheet-delete')));
    await settle(t);
    expect(server.posts.last['action'], 'delete');
  });

  testWidgets('Data cleanup: the word must be typed exactly', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1600));
    server.api['sa_controls'] = {
      'counts': {'pending_vessel': 3, 'pending_report': 0, 'cancelled': 1, 'completed': 0},
      'groups': [],
    };
    server.pages['super_admin_controls.php'] =
        '<html><div class="alert alert-success">Deleted 1 record(s).</div></html>';
    await t.pumpWidget(app(const DataCleanupScreen()));
    await settle(t);
    expect(find.text('No duplicate client companies found.'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('wipe-cancelled')));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('typed')), 'delete');
    await settle(t);
    expect(t.widget<FilledButton>(find.byKey(const ValueKey('typed-yes'))).onPressed, isNull);
    await t.enterText(find.byKey(const ValueKey('typed')), 'DELETE');
    await settle(t);
    await t.tap(find.byKey(const ValueKey('typed-yes')));
    await settle(t);
    expect(server.posts.single['path'], 'super_admin_controls.php');
    expect(server.posts.single['delete_target'], 'cancelled');
    expect(server.posts.single['confirm_text'], 'DELETE');
  });

  Map<String, Object> assignForm({Map<String, Object>? edit}) => {
    'role': 'Admin',
    'edit': ?edit,
    'clients': [
      {'id': 1, 'name': 'Oceanus Maritime'},
      {'id': 2, 'name': 'Blue Sea'},
    ],
    'surveyors': [
      {'id': 7, 'name': 'John Doe'},
      {'id': 8, 'name': 'Ravi'},
    ],
    'ports': [
      {'id': 3, 'name': 'Kakinada', 'country': 'India'},
    ],
    'types': [
      {'id': 4, 'name': 'Bunker Survey'},
      {'id': 5, 'name': 'Offhire'},
    ],
  };

  testWidgets('Edit vessel: prefilled, saved to assign_vessel.php?edit_id=', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1600));
    server.api['assign_form'] = assignForm(
      edit: {
        'id': 12,
        'vessel': 'MV OCEAN STAR',
        'report_no': 'R-1',
        'client_id': 1,
        'agent': 'AG',
        'port_id': 3,
        'type_ids': [4, 5],
        'surveyor_ids': [7],
        'appointment': '2026-11-10',
        'remarks': 'note',
      },
    );
    server.pages['assign_vessel.php'] = '<html><div class="alert alert-success">Vessel updated.</div></html>';
    await t.pumpWidget(app(const AssignScreen(editId: 12)));
    await settle(t);
    expect(find.text('Edit vessel'), findsOneWidget);
    expect(find.text('Kakinada · India'), findsOneWidget);
    expect(find.text('Bunker Survey, Offhire'), findsOneWidget);
    await t.enterText(find.byKey(const ValueKey('as-agent')), 'NEW AGENT');
    await t.tap(find.byKey(const ValueKey('as-save')));
    await settle(t);
    final p = server.posts.single;
    expect(p['path'], 'assign_vessel.php');
    expect(p['edit_id'], '12');
    expect(p['agent_name'], 'NEW AGENT');
    expect(p['survey_type_ids'], '4,5');
    expect(p['surveyor_ids'], '7');
    expect(p['appointment_date'], '2026-11-10');
    expect(p['client_id'], '1');
    expect(server.calls, isNot(contains('GET check_pending_vessel_duplicate.php')));
  });

  testWidgets('Assign vessel: checks, pickers (Outsourcing alone), report number, duplicate question', (t) async {
    await t.binding.setSurfaceSize(const Size(400, 1600));
    server.api['assign_form'] = assignForm();
    server.pages['preview_report_number.php'] = jsonEncode({'success': true, 'report_number': 'YMR/OM/101'});
    await t.pumpWidget(app(const AssignScreen()));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('as-save')));
    await settle(t);
    expect(find.text('Vessel name is required.'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('as-client')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('pick-2')));
    await settle(t);
    expect(find.text('Blue Sea'), findsOneWidget);
    expect(find.text('YMR/OM/101'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('as-surveyors')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('pick-7')));
    await t.tap(find.byKey(const ValueKey('pick-outsourcing')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('pick-done')));
    await settle(t);
    expect(find.text('Outsourcing'), findsOneWidget);
    expect(find.text('John Doe'), findsNothing);
  });

  testWidgets('Team chat: groups with unread, new group posts its members', (t) async {
    server.chat['bootstrap'] = {
      'ok': true,
      'me': {'id': 1, 'name': 'Admin', 'can_manage': true, 'is_member': true},
      'groups': [
        {'id': 4, 'name': 'Vizag team', 'member_count': 3, 'is_member': true, 'unread': 2},
        {'id': 5, 'name': 'Office', 'member_count': 1, 'is_member': false, 'unread': 0},
      ],
    };
    server.chat['staff'] = {
      'ok': true,
      'staff': [
        {'user_id': 7, 'name': 'Ravi', 'role': 'Surveyor'},
      ],
    };
    server.chat['create_group'] = {
      'ok': true,
      'group': {'id': 9},
    };
    await t.pumpWidget(app(const ChatScreen()));
    await settle(t);
    expect(find.text('Vizag team'), findsOneWidget);
    expect(find.byKey(const ValueKey('unread-4')), findsOneWidget);
    expect(find.byKey(const ValueKey('unread-5')), findsNothing);
    expect(find.textContaining('you are not in this group'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('chat-new')));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('ng-name')), 'Night shift');
    await t.tap(find.byKey(const ValueKey('ng-7')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('ng-create')));
    await settle(t);
    final p = server.posts.firstWhere((x) => x['action'] == 'create_group');
    expect(p['name'], 'Night shift');
    expect(p['member_ids[]'], '7');
  });

  testWidgets('Team chat room: messages by day, mine vs theirs, send posts the text', (t) async {
    final now = DateTime.now().millisecondsSinceEpoch / 1000;
    server.chat['bootstrap'] = {
      'ok': true,
      'me': {'id': 1, 'name': 'Admin', 'can_manage': false, 'is_member': true},
      'group': {'id': 4, 'name': 'Vizag team'},
      'members': [{}, {}],
      'settings': {
        'allowed_ext': ['pdf'],
        'max_upload_bytes': 1000,
      },
    };
    server.chat['messages'] = {
      'ok': true,
      'has_more': true,
      'messages': [
        {'id': 10, 'sender_id': 7, 'sender_name': 'Ravi', 'text': 'Reached the vessel', 'ts': now},
        {'id': 11, 'sender_id': 1, 'sender_name': 'Admin', 'text': 'Good', 'ts': now},
      ],
    };
    server.chat['poll'] = {'ok': true, 'messages': [], 'member_count': 2};
    server.chat['mark_read'] = {'ok': true};
    server.chat['send'] = {
      'ok': true,
      'message': {'id': 12, 'sender_id': 1, 'sender_name': 'Admin', 'text': 'On my way', 'ts': now},
    };
    await t.pumpWidget(app(const RoomScreen(id: 4)));
    await settle(t);
    expect(find.text('Vizag team'), findsOneWidget);
    expect(find.text('2 members'), findsOneWidget);
    expect(find.text('Today'), findsOneWidget);
    expect(find.text('Reached the vessel'), findsOneWidget);
    expect(find.text('Ravi'), findsOneWidget);
    expect(find.byKey(const ValueKey('older')), findsOneWidget);
    expect(server.calls, contains('chat mark_read'));
    await t.enterText(find.byKey(const ValueKey('composer')), 'On my way');
    await t.tap(find.byKey(const ValueKey('send')));
    await settle(t);
    final p = server.posts.firstWhere((x) => x['action'] == 'send');
    expect(p['text'], 'On my way');
    expect(p['group_id'], '4');
    expect(find.text('On my way'), findsOneWidget);
    expect(find.text('Sending…'), findsNothing);
    await t.pumpWidget(const SizedBox());
  });

  testWidgets('Team chat room: not a member shows the note and no composer', (t) async {
    server.chat['bootstrap'] = {
      'ok': true,
      'me': {'id': 1, 'name': 'X', 'can_manage': false, 'is_member': false},
      'group': {'id': 5, 'name': 'Office'},
      'members': [],
    };
    await t.pumpWidget(app(const RoomScreen(id: 5)));
    await settle(t);
    expect(find.textContaining('not a member'), findsOneWidget);
    expect(find.byKey(const ValueKey('composer')), findsNothing);
    expect(server.calls, isNot(contains('chat messages')));
  });

  testWidgets('Vessel tools: recovery total summed and saved, remark delete asks twice, agent email', (t) async {
    t.view.physicalSize = const Size(800, 3000);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    server.api['survey'] = {
      'id': 12,
      'vessel': 'MV Blue',
      'status': 'Completed',
      'web': 'completed_detail.php?id=12',
      'docs': [
        {'id': 3, 'name': 'Report.pdf', 'type': 'Final report', 'when': '01 Nov 2026'},
      ],
      'can_replace': true,
      'recovery': {'can_edit': true, 'total': '1.000', 'vlsfo': '', 'lsmgo': '', 'hsfo': ''},
      'agent_email': {'sent': ''},
      'remarks_list': {
        'can_add': true,
        'can_delete': true,
        'items': [
          {'id': 44, 'text': 'Checked', 'by': 'Ravi', 'when': '02 Nov 2026', 'files': []},
        ],
      },
    };
    server.pages['completed_detail.php'] = '<div class="alert alert-success">Saved.</div>';
    server.pages['send_agent_update.php'] = jsonEncode({
      'success': true,
      'agent_name': 'ABC Shipping',
      'has_emails': true,
      'emails': ['ops@abc.test'],
      'message': 'Email sent.',
    });
    await t.pumpWidget(app(const VesselScreen(id: 12)));
    await settle(t);
    expect(find.text('All tools of this vessel'), findsNothing);
    expect(find.byKey(const ValueKey('replace-3')), findsOneWidget);

    await t.tap(find.byKey(const ValueKey('rec-edit')));
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('rec-vlsfo')), '1.5');
    await t.enterText(find.byKey(const ValueKey('rec-lsmgo')), '0.25');
    await settle(t);
    expect(find.text('1.750'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('rec-save')));
    await settle(t);
    final rp = server.posts.firstWhere((x) => x['update_recovery'] == '1');
    expect(rp['path'], 'completed_detail.php');
    expect(rp['recovery_amount'], '1.750');
    expect(rp['vlsfo_recovery'], '1.5');
    expect(rp['hsfo_recovery'], '');

    await t.tap(find.byKey(const ValueKey('rm-del-44')));
    await settle(t);
    expect(server.posts.where((x) => x.containsKey('delete_remark_id')), isEmpty);
    expect(find.text('Tap to confirm'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('rm-del-44')));
    await settle(t);
    expect(server.posts.lastWhere((x) => x.containsKey('delete_remark_id'))['delete_remark_id'], '44');

    await t.tap(find.byKey(const ValueKey('agent-mail')));
    await settle(t);
    expect(find.textContaining('ops@abc.test'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('ag-send')));
    await settle(t);
    final ap = server.posts.lastWhere((x) => x['path'] == 'send_agent_update.php');
    expect(ap['action'], 'send');
    expect(ap['survey_id'], '12');
    expect(ap.containsKey('email_primary'), isFalse);
  });

  testWidgets('Replace document posts the file to document_replace.php', (t) async {
    server.pages['document_replace.php'] = jsonEncode({'success': true, 'message': 'Document replaced successfully.'});
    late BuildContext ctx;
    await t.pumpWidget(
      app(
        Scaffold(
          body: Builder(
            builder: (c) {
              ctx = c;
              return const SizedBox();
            },
          ),
        ),
      ),
    );
    final ok = await t.runAsync(
      () => replaceDocument(ctx, 3, pick: () async => _MemFile('new.pdf', Uint8List.fromList([1, 2, 3]))),
    );
    await settle(t);
    expect(ok, isTrue);
    final p = server.posts.single;
    expect(p['path'], 'document_replace.php');
    expect(p['document_id'], '3');
    expect(find.text('Document replaced successfully.'), findsOneWidget);
  });

  testWidgets('Surveyor cards: list, upload and remove (asks twice) post admin_surveyor_cards.php', (t) async {
    server.api['surveyor_cards'] = {
      'items': [
        {'id': 2, 'name': 'John Doe', 'username': 'surveyor', 'business_card': 'uploads/cards/b.pdf', 'id_card': ''},
      ],
    };
    server.pages['admin_surveyor_cards.php'] = '<div class="alert alert-success">File removed successfully.</div>';
    await t.pumpWidget(app(SurveyorCardsScreen(pick: () async => _MemFile('card.png', Uint8List.fromList([1, 2])))));
    await settle(t);
    expect(find.text('John Doe'), findsOneWidget);
    expect(find.text('Uploaded'), findsOneWidget);
    expect(find.text('Not uploaded yet'), findsOneWidget);
    expect(find.byKey(const ValueKey('card-rm-id_card-2')), findsNothing);
    await t.tap(find.byKey(const ValueKey('card-rm-business_card-2')));
    await settle(t);
    expect(server.posts, isEmpty);
    await t.tap(find.byKey(const ValueKey('card-rm-business_card-2')));
    await settle(t);
    expect(server.posts.single['remove_card'], '1');
    expect(server.posts.single['card_type'], 'business_card');
    expect(server.posts.single['user_id'], '2');
    expect(find.text('File removed successfully.'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('card-up-id_card-2')));
    await settle(t);
    final up = server.posts.last;
    expect(up['upload_card'], '1');
    expect(up['card_type'], 'id_card');
  });

  testWidgets('Daily emails: settings saved to admin_daily_emails.php, Send now asks twice', (t) async {
    t.view.physicalSize = const Size(800, 4000);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    Map<String, Object?> job(String k, String label, {bool on = true, bool weekly = false}) => {
      'job': k,
      'label': label,
      'enabled': on,
      'time': '17:00',
      'send_empty': false,
      'from': k == 'surveyor_pending' ? 'ops@ymr.test' : '',
      'weekly': weekly,
      'due_today': !weekly,
      'last_scheduler_call': '',
      'route': 'Fixed route',
      'today': null,
    };
    server.api['daily_emails'] = {
      'jobs': [
        job('surveyor_pending', 'Pending Vessels by Surveyor'),
        job('all_reports', 'Pending Reports — full list', on: false, weekly: true),
      ],
      'runs': [
        {
          'when': '14 Nov 2026 17:00',
          'label': 'Pending Vessels by Surveyor',
          'how': 'Scheduled',
          'status': 'sent',
          'attempts': 1,
          'detail': '2 sent',
        },
      ],
      'today': '15 Nov 2026',
      'mail_on': true,
      'scheduler': false,
      'surveyors_with_email': 1,
      'surveyors': 2,
      'counts': {'appointments': 0, 'all_pending': 3, 'all_reports': 4},
    };
    server.pages['admin_daily_emails.php'] = '<div class="alert alert-success">Daily email settings saved.</div>';
    await t.pumpWidget(app(const DailyEmailsScreen()));
    await settle(t);
    expect(find.text('Send every Friday'), findsOneWidget);
    expect(find.textContaining('Not due today'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('de-on-all_reports')));
    await t.tap(find.byKey(const ValueKey('de-empty-surveyor_pending')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('de-save')));
    await settle(t);
    final p = server.posts.single;
    expect(p['action'], 'save');
    expect(p['surveyor_pending_from'], 'ops@ymr.test');
    expect(p['surveyor_pending_enabled'], '1');
    expect(p['surveyor_pending_send_empty'], '1');
    expect(p['all_reports_enabled'], '1');
    expect(p.containsKey('all_reports_send_empty'), isFalse);
    expect(p['all_reports_time'], '17:00');
    await t.ensureVisible(find.byKey(const ValueKey('de-now-all_reports')));
    await settle(t);
    await t.tap(find.byKey(const ValueKey('de-now-all_reports')));
    await settle(t);
    expect(server.posts.length, 1);
    expect(find.text('Tap to confirm'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('de-now-all_reports')));
    await settle(t);
    expect(server.posts.last['action'], 'send_now');
    expect(server.posts.last['job'], 'all_reports');
    await t.scrollUntilVisible(find.text('2 sent'), 300, scrollable: find.byType(Scrollable).first);
    expect(find.text('2 sent'), findsOneWidget);
  });

  testWidgets('Permission copy: required fields checked, then the form posted with the surveyors', (t) async {
    t.view.physicalSize = const Size(800, 3000);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    server.api['permission_copy'] = {
      'template': 'permission_copy_template_1.docx',
      'can_upload': true,
      'max_surveyors': 4,
      'survey_types': ['Bunker Survey', 'Draft Survey'],
      'surveyors': ['John Doe', 'Ramesh Kumar'],
      'today': '2026-11-15',
    };
    server.pages['generate_permission_copy.php'] =
        '<div class="alert alert-danger">Could not generate the Word document: test</div>';
    await t.pumpWidget(app(const PermissionCopyScreen()));
    await settle(t);
    expect(find.textContaining('permission_copy_template_1.docx'), findsOneWidget);
    expect(find.text('2026-11-15'), findsOneWidget);
    await t.tap(find.byKey(const ValueKey('pc-generate')));
    await settle(t);
    expect(find.textContaining('Please fill: Port'), findsOneWidget);
    expect(server.posts, isEmpty);
    const vals = {
      'permission_port': 'Vizag',
      'recipient_designation': 'The Superintendent',
      'recipient_company': 'AGPL',
      'recipient_location': 'Gangavaram',
      'vessel_name': 'MV Blue',
      'berth_no': 'B-6',
    };
    for (final e in vals.entries) {
      await t.enterText(find.byKey(ValueKey('pc-${e.key}')), e.value);
    }
    await t.tap(find.byKey(const ValueKey('pc-type')));
    await settle(t);
    await t.tap(find.text('Draft Survey').last);
    await settle(t);
    await t.tap(find.byKey(const ValueKey('pc-sv-0')));
    await settle(t);
    await t.tap(find.text('Ramesh Kumar').last);
    await settle(t);
    await t.enterText(find.byKey(const ValueKey('pc-age-0')), '34');
    await t.tap(find.byKey(const ValueKey('pc-generate')));
    await settle(t);
    final p = server.posts.single;
    expect(p['path'], 'generate_permission_copy.php');
    expect(p['generate_copy'], '1');
    expect(p['permission_date'], '2026-11-15');
    expect(p['survey_type'], 'Draft Survey');
    expect(p['vessel_name'], 'MV Blue');
    expect(p['surveyor_name[0]'], 'Ramesh Kumar');
    expect(p['surveyor_age[0]'], '34');
    expect(p['csrf_token'], 'tok');
    expect(find.text('Could not generate the Word document: test'), findsOneWidget);
  });

  testWidgets('Coming soon: the feature name, Return Home', (t) async {
    await t.pumpWidget(
      app(ComingSoonScreen(feature: ComingSoonScreen.featureOf('coming_soon.php?feature=Vessel+Lineups'))),
    );
    await settle(t);
    expect(find.text('Vessel Lineups'), findsWidgets);
    expect(find.text('Coming Soon'), findsOneWidget);
    expect(ComingSoonScreen.featureOf('coming_soon.php?feature=<b>x</b>'), 'bx/b');
    expect(ComingSoonScreen.featureOf('coming_soon.php'), 'This feature');
  });

  test('website pages with a Flutter screen', () {
    expect(nativePage('admin_surveyor_cards.php'), isA<SurveyorCardsScreen>());
    expect(nativePage('admin_daily_emails.php'), isA<DailyEmailsScreen>());
    expect(nativePage('generate_permission_copy.php'), isA<PermissionCopyScreen>());
    expect(nativePage('coming_soon.php?feature=Support'), isA<ComingSoonScreen>());
    expect(nativePage('report_generator.php?id=3'), isNull);
  });

  group('Survey report', () {
    setUp(() {
      SrStore.memoryOnly = true;
      SrStore.i.clearMemory();
      SrApi.i.reset();
      server.sr['token'] = {'ok': true, 'csrf': 'srtok'};
    });

    Map<String, dynamic> got(String token) {
      final s = starter();
      (s['report'] as Map)['id'] = token;
      (s['report'] as Map)['version'] = 1;
      return s;
    }

    testWidgets('home: report types, a new report opens the editor', (t) async {
      await t.binding.setSurfaceSize(const Size(400, 2000));
      server.sr['starter'] = starter();
      server.sr['new'] = {'ok': true, 'token': 'a' * 64};
      server.sr['get'] = got('a' * 64);
      server.sr['version'] = {'ok': true, 'version': 1};
      await t.pumpWidget(app(const SrHomeScreen()));
      await settle(t);
      expect(find.byKey(const ValueKey('srh-new-offhire')), findsOneWidget);
      expect(find.byKey(const ValueKey('srh-new-onhire3')), findsOneWidget);
      await t.tap(find.byKey(const ValueKey('srh-new-offhire')));
      await settle(t);
      expect(server.posts.where((p) => p['a'] == 'new').single['kind'], 'offhire');
      expect(server.posts.where((p) => p['a'] == 'new').single['csrf_token'], 'srtok');
      expect(find.byKey(const ValueKey('sr-step-title')), findsOneWidget);
      expect(find.text('Vessel Details'), findsWidgets);
      await t.pageBack();
      await settle(t);
      expect(find.byKey(ValueKey('srh-mine-${'a' * 64}')), findsOneWidget);
      await t.pumpWidget(const SizedBox());
      await settle(t);
    });

    testWidgets('home offline: a report starts on this phone (not sent yet)', (t) async {
      await SrStore.i.write('starter_offhire', starter());
      server.offline = true;
      await t.pumpWidget(app(const SrHomeScreen()));
      await settle(t);
      expect(find.byKey(const ValueKey('srh-new-offhire')), findsOneWidget);
      expect(find.byKey(const ValueKey('srh-new-rob')), findsNothing);
    });

    testWidgets('editor: Next checks the step, tanks calculate as typed, S / U, save', (t) async {
      await t.binding.setSurfaceSize(const Size(400, 2600));
      final token = 'b' * 64;
      server.sr['get'] = got(token);
      server.sr['save'] = {'ok': true, 'version': 2, 'errors': {}};
      server.sr['version'] = {'ok': true, 'version': 1};
      server.sr['verify'] = {
        'ok': true,
        'results': {
          'grades': {
            'vlsfo': {'completed': 93.865},
          },
        },
      };
      await t.pumpWidget(app(SrEditorScreen(id: token)));
      await settle(t);
      await t.tap(find.byKey(const ValueKey('sr-next')));
      await settle(t);
      expect(find.text('Please complete this step'), findsOneWidget);
      expect(find.textContaining('IMO Number is missing.'), findsOneWidget);
      await t.tap(find.text('OK'));
      await settle(t);
      await t.enterText(find.byKey(const ValueKey('sr-f-imo')), '9074728');
      await settle(t);
      expect(find.text('IMO check digit does not match — please re-check the number.'), findsOneWidget);
      await t.enterText(find.byKey(const ValueKey('sr-f-imo')), '9074729');
      await t.tap(find.byKey(const ValueKey('sr-manual')));
      await settle(t);
      await t.enterText(find.byKey(const ValueKey('sr-f-vessel_name')), 'mv blue');
      await settle(t);
      await t.tap(find.byKey(const ValueKey('sr-next')));
      await settle(t);
      expect(find.text('Survey Details'), findsWidgets);
      final saved = server.posts.where((p) => p['a'] == 'save').toList();
      expect(saved, isNotEmpty);
      expect(jsonDecode('${saved.last['data']}')['fields']['vessel_name'], 'mv blue');
      expect(saved.first['version'], '1');
      // Tank Details from the step bar is closed until Survey Details is complete
      await t.scrollUntilVisible(
        find.byKey(const ValueKey('sr-step-tanks')),
        120,
        scrollable: find.descendant(of: find.byKey(const ValueKey('sr-steps')), matching: find.byType(Scrollable)),
      );
      await t.tap(find.byKey(const ValueKey('sr-step-tanks')));
      await settle(t);
      expect(find.text('Survey Details'), findsWidgets);
      // the step list: back to step 1
      await t.tap(find.byKey(const ValueKey('sr-back')));
      await settle(t);
      expect(find.byKey(const ValueKey('sr-f-imo')), findsOneWidget);
      await t.pumpWidget(const SizedBox());
      await settle(t);
    });

    testWidgets('tanks: the usual tanks, MT as typed, U works out the sounding', (t) async {
      await t.binding.setSurfaceSize(const Size(400, 3000));
      final token = 'c' * 64;
      final g = got(token);
      (((g['report'] as Map)['data']) as Map)['step'] = 'tanks';
      server.sr['get'] = g;
      server.sr['save'] = {'ok': true, 'version': 2, 'errors': {}};
      server.sr['version'] = {'ok': true, 'version': 1};
      await t.pumpWidget(app(SrEditorScreen(id: token)));
      await settle(t);
      expect(find.text('VLSFO Tanks (11)'), findsOneWidget);
      // the first tank still to complete is open
      await t.enterText(find.byKey(const ValueKey('sr-t-vlsfo-0-volume')), '100');
      await t.enterText(find.byKey(const ValueKey('sr-t-vlsfo-0-density')), '0.95');
      await t.enterText(find.byKey(const ValueKey('sr-t-vlsfo-0-temp')), '30');
      await settle(t);
      expect(find.text('93.865'), findsWidgets); // the website's result for these readings
      await t.enterText(find.byKey(const ValueKey('sr-t-vlsfo-0-ref_height')), '12');
      await t.enterText(find.byKey(const ValueKey('sr-t-vlsfo-0-sounding')), '2.5');
      await settle(t);
      await t.tap(find.byKey(const ValueKey('sr-su-vlsfo-0-u')));
      await settle(t);
      expect(find.byKey(const ValueKey('sr-t-vlsfo-0-ullage')), findsOneWidget);
      await t.enterText(find.byKey(const ValueKey('sr-t-vlsfo-0-ullage')), '10.000');
      await settle(t);
      expect(find.text('Sounding 2.000'), findsOneWidget);
      await t.tap(find.byKey(const ValueKey('sr-save-tank-vlsfo-0')));
      await settle(t);
      expect(find.text('Saved'), findsWidgets);
      final last = jsonDecode('${server.posts.where((p) => p['a'] == 'save').last['data']}');
      final row = (last['tanks']['vlsfo'] as List).first as Map;
      expect(row['mode'], 'ullage');
      expect(row['su'], 'u');
      expect(row['sounding'], '2.000');
      expect(row['temp'], '30.0');
      expect(row['density'], '0.9500');
      expect(row['done'], '1');
      // move the second tank up: the report keeps this order
      await t.tap(find.byKey(const ValueKey('sr-up-vlsfo-1')));
      await settle(t);
      final moved = jsonDecode('${server.posts.where((p) => p['a'] == 'save').last['data']}');
      expect(((moved['tanks']['vlsfo'] as List).first as Map)['name'], '1S');
      await t.pumpWidget(const SizedBox());
      await settle(t);
    });
  });

  test('routes with a Flutter screen', () {
    expect(nativeScreen('/assign'), isA<AssignScreen>());
    expect(nativeScreen('/e/12'), isA<AssignScreen>());
    expect(nativeScreen('/admin'), isA<AdminScreen>());
    expect(nativeScreen('/m/ports'), isA<ModuleScreen>());
    expect(nativeScreen('/sacontrols'), isA<DataCleanupScreen>());
    expect(nativeScreen('/addclient'), isA<CreateUserScreen>());
    expect(nativeScreen('/addadmin'), isA<CreateUserScreen>());
    expect(nativeScreen('/surveyor'), isA<SurveyorFormScreen>());
    expect(nativeScreen('/sv/9'), isA<SurveyorFormScreen>());
    expect(nativeScreen('/templates'), isA<TemplatesScreen>());
    expect(nativeScreen('/forgot'), isA<ForgotScreen>());
    expect(nativeScreen('/me'), isA<MyProfileScreen>());
    expect(nativeScreen('/inbox'), isA<InboxScreen>());
    expect(nativeScreen('/recovery'), isA<RecoveryScreen>());
    expect(nativeScreen('/duplicates'), isA<DuplicatesScreen>());
    expect(nativeScreen('/activity'), isA<ActivityScreen>());
    expect(nativeScreen('/h/12'), isA<HistoryScreen>());
    expect(nativeScreen('/formats'), isA<FormatsScreen>());
    expect(nativeScreen('/chat'), isA<ChatScreen>());
    expect(nativeScreen('/c/4'), isA<RoomScreen>());
  });
}
