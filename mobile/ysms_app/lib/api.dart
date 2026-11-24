// Talks to the existing YSMS server: app_api.php (read-only JSON, the same rules as the website) and the
// existing pages for every change (login.php, vessel_detail.php, ajax/upload_handler.php …), so the
// server's own checks, permissions and notifications apply unchanged.
//
// Cookies (the PHP session and the "stay signed in" cookie) live in the WebView's cookie store, shared
// with the web screens the app still opens (WebScreen), so one sign-in covers both.
import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_inappwebview/flutter_inappwebview.dart' as iw;

import 'config.dart';

class ApiError implements Exception {
  ApiError(this.status, this.message);
  final int status; // 0 = no connection
  final String message;
  bool get offline => status == 0;
  bool get login => status == 401;
  @override
  String toString() => message;
}

/// Where the cookies are kept.
abstract class CookieStore {
  Future<String> header();
  Future<void> save(List<String> setCookie);
  Future<void> clear();
}

/// Android / iOS: the WebView's cookie store (also used by the web screens).
class WebViewCookies implements CookieStore {
  final _cm = iw.CookieManager.instance();
  iw.WebUri get _u => iw.WebUri(Cfg.base);

  @override
  Future<String> header() async {
    final list = await _cm.getCookies(url: _u);
    return list.map((c) => '${c.name}=${c.value}').join('; ');
  }

  @override
  Future<void> save(List<String> setCookie) async {
    for (final raw in setCookie) {
      final c = parseSetCookie(raw);
      if (c == null) continue;
      if (c.deleted) {
        await _cm.deleteCookie(url: _u, name: c.name, path: c.path);
      } else {
        await _cm.setCookie(
          url: _u,
          name: c.name,
          value: c.value,
          path: c.path,
          expiresDate: c.expires?.millisecondsSinceEpoch,
          isSecure: c.secure ? true : null,
          isHttpOnly: c.httpOnly ? true : null,
          sameSite: iw.HTTPCookieSameSitePolicy.LAX,
        );
      }
    }
  }

  @override
  Future<void> clear() async {
    await _cm.deleteCookies(url: _u);
  }
}

/// Web preview and tests: the browser (same site) or nothing keeps the cookies; this keeps them in memory.
class MemoryCookies implements CookieStore {
  final Map<String, String> jar = {};
  @override
  Future<String> header() async => jar.entries.map((e) => '${e.key}=${e.value}').join('; ');
  @override
  Future<void> save(List<String> setCookie) async {
    for (final raw in setCookie) {
      final c = parseSetCookie(raw);
      if (c == null) continue;
      if (c.deleted) {
        jar.remove(c.name);
      } else {
        jar[c.name] = c.value;
      }
    }
  }

  @override
  Future<void> clear() async => jar.clear();
}

class ParsedCookie {
  ParsedCookie(this.name, this.value, this.path, this.expires, this.secure, this.httpOnly, this.deleted);
  final String name, value, path;
  final DateTime? expires;
  final bool secure, httpOnly, deleted;
}

ParsedCookie? parseSetCookie(String raw) {
  final parts = raw.split(';');
  final first = parts.first;
  final eq = first.indexOf('=');
  if (eq <= 0) return null;
  final name = first.substring(0, eq).trim();
  final value = first.substring(eq + 1).trim();
  var path = '/';
  DateTime? expires;
  int? maxAge;
  var secure = false, httpOnly = false;
  for (final p in parts.skip(1)) {
    final i = p.indexOf('=');
    final k = (i < 0 ? p : p.substring(0, i)).trim().toLowerCase();
    final v = i < 0 ? '' : p.substring(i + 1).trim();
    if (k == 'path' && v.isNotEmpty) path = v;
    if (k == 'expires') expires = _httpDate(v);
    if (k == 'max-age') maxAge = int.tryParse(v);
    if (k == 'secure') secure = true;
    if (k == 'httponly') httpOnly = true;
  }
  if (maxAge != null) expires = DateTime.now().add(Duration(seconds: maxAge));
  final deleted = value.isEmpty || value == 'deleted' || (expires != null && expires.isBefore(DateTime.now()));
  return ParsedCookie(name, value, path, expires, secure, httpOnly, deleted);
}

DateTime? _httpDate(String v) {
  // "Thu, 13-Nov-2026 10:00:00 GMT" or "Thu, 13 Nov 2026 10:00:00 GMT"
  const months = {
    'jan': 1,
    'feb': 2,
    'mar': 3,
    'apr': 4,
    'may': 5,
    'jun': 6,
    'jul': 7,
    'aug': 8,
    'sep': 9,
    'oct': 10,
    'nov': 11,
    'dec': 12,
  };
  final m = RegExp(r'(\d{1,2})[ -]([A-Za-z]{3})[ -](\d{2,4}) (\d{2}):(\d{2}):(\d{2})').firstMatch(v);
  if (m == null) return null;
  var y = int.parse(m.group(3)!);
  if (y < 100) y += 2000;
  final mo = months[m.group(2)!.toLowerCase()];
  if (mo == null) return null;
  return DateTime.utc(y, mo, int.parse(m.group(1)!), int.parse(m.group(4)!), int.parse(m.group(5)!), int.parse(m.group(6)!));
}

class Api {
  Api({CookieStore? cookies, HttpClientAdapter? adapter})
    : cookies = cookies ?? (kIsWeb ? MemoryCookies() : WebViewCookies()),
      dio = Dio(
        BaseOptions(
          baseUrl: Cfg.base,
          connectTimeout: const Duration(seconds: 20),
          receiveTimeout: const Duration(seconds: 90),
          sendTimeout: const Duration(minutes: 10),
          followRedirects: false,
          validateStatus: (_) => true,
          headers: kIsWeb ? {} : {'User-Agent': Cfg.userAgent},
        ),
      ) {
    if (adapter != null) dio.httpClientAdapter = adapter;
    if (kIsWeb) dio.options.extra['withCredentials'] = true;
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (o, h) async {
          if (!kIsWeb) {
            try {
              final c = await this.cookies.header();
              cookieHeader = c;
              if (c.isNotEmpty) o.headers['Cookie'] = c;
            } catch (_) {}
          }
          h.next(o);
        },
        onResponse: (r, h) async {
          final sc = r.headers.map['set-cookie'];
          if (sc != null && sc.isNotEmpty) {
            try {
              await this.cookies.save(sc);
              cookieHeader = await this.cookies.header();
            } catch (_) {}
          }
          h.next(r);
        },
      ),
    );
  }

  static late Api i;

  final Dio dio;
  final CookieStore cookies;

  /// The last Cookie header sent (for pictures loaded with Image.network).
  String cookieHeader = '';

  /// The signed-in user (app_api.php?a=me), null when signed out.
  Map<String, dynamic>? me;
  String get csrf => (me?['csrf'] ?? '') as String;

  /// Goes up each time the server says the sign-in ended (the app then shows the login screen).
  final sessionLost = ValueNotifier<int>(0);

  Map<String, String> get imageHeaders => kIsWeb ? const {} : {'Cookie': cookieHeader, 'User-Agent': Cfg.userAgent};

  Future<Response<dynamic>> _send(Future<Response<dynamic>> Function() f) async {
    try {
      return await f();
    } on DioException catch (e) {
      if (e.type == DioExceptionType.cancel) rethrow;
      throw ApiError(0, 'No connection. Check the internet and try again.');
    }
  }

  dynamic _json(Response<dynamic> r) {
    final d = r.data;
    if (d is Map || d is List) return d;
    if (d is String && d.isNotEmpty) {
      try {
        return jsonDecode(d);
      } catch (_) {}
    }
    return null;
  }

  /// GET app_api.php?a=(action).
  Future<Map<String, dynamic>> get(String action, [Map<String, dynamic>? params]) async {
    final r = await _send(() => dio.get('app_api.php', queryParameters: {'a': action, ...?params}));
    final d = _json(r);
    if (r.statusCode == 401) {
      _lost();
      throw ApiError(401, 'Please sign in again.');
    }
    if (d is! Map) throw ApiError(r.statusCode ?? 500, 'The server did not answer properly.');
    final m = Map<String, dynamic>.from(d);
    if ((r.statusCode ?? 500) >= 400) throw ApiError(r.statusCode!, '${m['error'] ?? 'error'}');
    return m;
  }

  /// GET a JSON page of the website (e.g. 'ajax/login_activity_list.php'): its own checks apply.
  /// {success: false, message} and HTTP errors become an [ApiError] with the server's message.
  Future<Map<String, dynamic>> getJson(String path, [Map<String, dynamic>? params]) async {
    final r = await _send(() => dio.get(path, queryParameters: params));
    final d = _json(r);
    if (r.statusCode == 401) {
      _lost();
      throw ApiError(401, 'Please sign in again.');
    }
    if (d is! Map) throw ApiError(r.statusCode ?? 500, 'The server did not answer properly.');
    final m = Map<String, dynamic>.from(d);
    if ((r.statusCode ?? 500) >= 400 || m['success'] == false) {
      throw ApiError(r.statusCode ?? 500, '${m['message'] ?? m['error'] ?? 'Not available.'}');
    }
    return m;
  }

  void markLost() => _lost();

  void _lost() {
    if (me != null) {
      me = null;
      sessionLost.value++;
    }
  }

  Future<Map<String, dynamic>> loadMe() async {
    final m = await get('me');
    me = m;
    return m;
  }

  /// Sign in with login.php (the website's own login, its checks and login activity). Then app_api says
  /// whether it worked (a wrong password leaves the user signed out).
  Future<void> login(String username, String password, bool remember) async {
    await _send(
      () => dio.post(
        'login.php',
        data: {'username': username, 'password': password, 'remember': remember ? '1' : ''},
        options: Options(contentType: Headers.formUrlEncodedContentType),
      ),
    );
    try {
      await loadMe();
    } on ApiError catch (e) {
      if (e.offline) rethrow;
      throw ApiError(401, 'Wrong username or password.');
    }
  }

  /// Fingerprint login: the key this phone keeps (ajax/app_bio_login.php action=login).
  Future<void> bioLogin(String key) async {
    final r = await _send(
      () => dio.post(
        'ajax/app_bio_login.php',
        data: {'action': 'login', 'key': key},
        options: Options(contentType: Headers.formUrlEncodedContentType),
      ),
    );
    final d = _json(r);
    if (r.statusCode != 200 || d is! Map || d['success'] != true) {
      throw ApiError(
        r.statusCode == 401 ? 401 : (r.statusCode ?? 500),
        (d is Map ? d['message'] : null)?.toString() ?? 'Fingerprint login did not work. Sign in with your password.',
      );
    }
    await loadMe();
  }

  Future<void> logout() async {
    try {
      await dio.get('logout.php');
    } catch (_) {}
    me = null;
    try {
      await cookies.clear();
    } catch (_) {}
  }

  /// POST to an existing page. [body] gets the current form (CSRF) token; when the server answers 403
  /// (the session was renewed with a new token), the token is refreshed and the post sent once more.
  Future<Response<dynamic>> post(
    String path,
    Object Function(String csrf) body, {
    bool csrfHeader = false,
    Map<String, String>? headers,
    ProgressCallback? onSendProgress,
    bool form = true,
  }) async {
    Future<Response<dynamic>> once() {
      final b = body(csrf);
      return _send(
        () => dio.post(
          path,
          data: b,
          onSendProgress: onSendProgress,
          options: Options(
            contentType: b is FormData ? null : (form ? Headers.formUrlEncodedContentType : null),
            headers: {if (csrfHeader) 'X-CSRF-Token': csrf, ...?headers},
          ),
        ),
      );
    }

    var r = await once();
    if (r.statusCode == 403 && me != null) {
      final old = csrf;
      try {
        await loadMe();
      } catch (_) {
        return r;
      }
      if (csrf.isNotEmpty && csrf != old) r = await once();
    }
    return r;
  }

  /// POST a form of the website and read its own message: the inline alert, or the flash toast after a
  /// save-and-redirect (the redirect is followed once). As the web app's postPage().
  Future<PageResult> postPage(String path, Object Function(String csrf) body) async {
    var r = await post(path, body);
    final code = r.statusCode ?? 0;
    if (code == 403) throw ApiError(403, 'Your session expired. Please sign in again.');
    if (code == 301 || code == 302 || code == 303) {
      final loc = r.headers.value('location') ?? '';
      if (loc.isEmpty) return const PageResult(true, '');
      r = await _send(() => dio.get(loc.startsWith('http') ? loc : loc.replaceFirst(RegExp(r'^/'), '')));
    }
    if ((r.statusCode ?? 500) >= 500) throw ApiError(r.statusCode ?? 500, 'The server could not save this. Please try again.');
    return pageResult(text(r));
  }

  /// POST a form to a JSON page of the website ({success, message}); not success → [ApiError] with its message.
  Future<Map<String, dynamic>> postJson(String path, Map<String, String> fields) async {
    final r = await post(path, (csrf) => {...fields, 'csrf_token': csrf}, csrfHeader: true);
    final d = json(r);
    if (r.statusCode == 403 && d == null) throw ApiError(403, 'Your session expired. Please sign in again.');
    if (d == null || d['success'] != true) {
      throw ApiError(r.statusCode ?? 500, s0(d?['message']).isEmpty ? 'Could not save.' : s0(d?['message']));
    }
    return d;
  }

  Map<String, dynamic>? json(Response<dynamic> r) {
    final d = _json(r);
    return d is Map ? Map<String, dynamic>.from(d) : null;
  }

  String text(Response<dynamic> r) => r.data is String ? r.data as String : (r.data == null ? '' : '${r.data}');
}

/// A website page's own answer to a form: ok (green alert / success toast) or not, with its text.
class PageResult {
  const PageResult(this.ok, this.message);
  final bool ok;
  final String message;
}

String _plain(String h) => h
    .replaceAll(RegExp(r'<[^>]+>'), ' ')
    .replaceAll('&times;', '')
    .replaceAll('&amp;', '&')
    .replaceAll('&quot;', '"')
    .replaceAll('&#039;', "'")
    .replaceAll('&#39;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replaceAll(RegExp(r'\s+'), ' ')
    .trim();

/// The message a page shows after a form: .alert-success / .alert-danger (also the vessel-page and
/// remarks variants) or the flash toast (.ysms-toast-success / -error).
PageResult pageResult(String html) {
  RegExpMatch? box(String cls) => RegExp(
    '<(div|span)[^>]*class="[^"]*\\b(?:$cls)\\b[^"]*"[^>]*>([\\s\\S]*?)</\\1>',
    caseSensitive: false,
  ).firstMatch(html);
  final toast = RegExp(
    r'class="[^"]*ysms-toast-(success|error|warning)[^"]*"[\s\S]*?class="ysms-toast-text">([\s\S]*?)</span>',
  ).firstMatch(html);
  final bad = box('alert-danger|vdd-alert-danger|remarks-alert');
  if (bad != null) return PageResult(false, _plain(bad.group(2)!));
  final ok = box('alert-success|vdd-alert-success');
  if (ok != null) return PageResult(true, _plain(ok.group(2)!));
  if (toast != null) return PageResult(toast.group(1) == 'success', _plain(toast.group(2)!));
  return const PageResult(false, '');
}

String s0(dynamic v) => v == null ? '' : '$v';
