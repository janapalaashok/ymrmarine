// The survey report API of the website (ajax/survey_report_public.php) with the app's sign-in cookies:
// the report is named by its secret link (token). Every answer is {ok, …}; a failed connection is
// {ok: false, _status: 0} (the report then stays on this phone).
import 'dart:convert';

import 'package:dio/dio.dart';

import '../api.dart';

class SrApi {
  SrApi._();
  static final i = SrApi._();
  static const path = 'ajax/survey_report_public.php';

  String _csrf = '';

  Map<String, dynamic> _answer(Response<dynamic> r) {
    final code = r.statusCode ?? 0;
    Object? d = r.data;
    if (d is String) {
      try {
        d = jsonDecode(d);
      } catch (_) {
        d = null;
      }
    }
    if (d is! Map) {
      return {
        'ok': false,
        '_status': code,
        '_json': false,
        'error': 'Unexpected answer from the server ($code) — your changes are kept on this phone.',
      };
    }
    return {...Map<String, dynamic>.from(d), '_status': code};
  }

  static Map<String, dynamic> offline() => {'ok': false, '_status': 0, 'error': 'No connection.'};

  Future<Map<String, dynamic>> get(String a, [Map<String, dynamic>? p]) async {
    try {
      final r = await Api.i.dio.get(path, queryParameters: {'a': a, ...?p});
      return _answer(r);
    } on DioException {
      return offline();
    }
  }

  Future<bool> freshToken() async {
    final r = await get('token');
    if (r['ok'] == true && '${r['csrf'] ?? ''}'.isNotEmpty) {
      _csrf = '${r['csrf']}';
      return true;
    }
    return false;
  }

  /// POST an action; the security token is fetched first and once more if the server renewed it.
  Future<Map<String, dynamic>> post(
    String a,
    Map<String, dynamic> fields, {
    MultipartFile? file,
    bool retried = false,
  }) async {
    if (_csrf.isEmpty && !await freshToken()) return offline();
    try {
      final body = FormData.fromMap({'a': a, 'csrf_token': _csrf, ...fields, 'file': ?file});
      final r = await Api.i.dio.post(
        path,
        data: body,
        options: Options(headers: {'X-CSRF-Token': _csrf}),
      );
      final res = _answer(r);
      // not JSON with 403: the security token changed (signed in again) — fetch it and send once more
      if (res['_status'] == 403 && res['_json'] == false && !retried && await freshToken()) {
        return await post(a, fields, file: file, retried: true);
      }
      return res;
    } on DioException {
      return offline();
    }
  }

  /// Test helper: forget the token.
  void reset() => _csrf = '';
}
