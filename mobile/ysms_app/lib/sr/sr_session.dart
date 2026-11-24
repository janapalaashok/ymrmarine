// One survey report being edited: loading (from the server, else the copy on this phone), saving by
// itself a moment after each change, reports started without internet (made on the server as soon as
// there is a connection), changes saved meanwhile elsewhere (never overwritten without asking), and the
// server's checks, results, files and lookups. As the website's editor (assets/js/survey-report.js).
import 'dart:async';
import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'sr_api.dart';
import 'sr_model.dart';
import 'sr_store.dart';

/// Asked when this phone has unsaved changes and the report was saved meanwhile elsewhere:
/// true = keep the changes made on this phone.
typedef SrConflict = Future<bool> Function(String message);

/// The final report made by itself after a PDF was made on the phone offline → its file id.
typedef SrFinal = void Function(Object? pdfId);

class SrSession extends ChangeNotifier {
  SrSession(this.id, {this.onConflict, this.onFinal});

  /// The report's secret link (token), or `draft-<x>` for a report started without internet.
  String id;
  final SrConflict? onConflict;
  SrFinal? onFinal;
  bool _finalBusy = false;

  SrReport? report;
  String error = '';
  bool loading = true, dirty = false, saving = false, fromCache = false, verifying = false, closed = false;

  /// "Saved" / "Saving…" / "Changes on this phone" / "Save failed".
  String saveState = '';
  Map<String, dynamic>? server; // the server's results (a=verify / generate)
  Map<String, String> serverErrors = {};
  int _version = 0;
  Timer? _timer, _retry, _live;
  final Set<String> defaultsDone = {};

  bool get isDraft => id.startsWith('draft-');
  String get _local => 'local_$id';
  String get _cache => 'get_$id';
  int get version => _version;

  static String newDraftId() => 'draft-${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}';

  // ---------- open ----------
  Future<void> load() async {
    loading = true;
    error = '';
    notifyListeners();
    Map<String, dynamic>? res;
    if (isDraft) {
      final c = await SrStore.i.read(_cache);
      if (c is! Map) {
        _fail('This report is not on this phone any more (it may have been sent already).');
        return;
      }
      res = Map<String, dynamic>.from(c);
      fromCache = true;
    } else {
      final r = await SrApi.i.get('get', {'id': id});
      if (r['ok'] == true) {
        res = r..remove('_status');
        await SrStore.i.write(_cache, res);
        fromCache = false;
      } else if (r['_status'] == 0) {
        final c = await SrStore.i.read(_cache);
        if (c is! Map) {
          _fail('You are offline and this report has not been opened on this phone before. Open it once while online.');
          return;
        }
        res = Map<String, dynamic>.from(c);
        fromCache = true;
      } else {
        _fail('${r['error'] ?? 'Report not found.'}');
        return;
      }
    }
    await _start(res);
  }

  void _fail(String msg) {
    loading = false;
    error = msg;
    notifyListeners();
  }

  Future<void> _start(Map<String, dynamic> res) async {
    final rep = SrReport(res);
    _version = rep.version;
    final local = await SrStore.i.read(_local);
    if (local is Map && local['dirty'] == true && local['data'] is Map) {
      final mine = rep.normalise(Map<String, dynamic>.from(jsonDecode(jsonEncode(local['data'])) as Map));
      if (local['base'] == _version || fromCache) {
        rep.data = mine;
        dirty = true;
      } else if (_canon(SrReport.payloadOf(mine)) == _canon(rep.payload())) {
        await SrStore.i.write(_local, null); // already saved
      } else {
        final keep =
            await (onConflict?.call(
                  'This phone has changes that were not saved yet, and the report was saved meanwhile '
                  '(${'${rep.report['updated_at'] ?? ''}'.padRight(16).substring(0, 16).trim()}). Keep the changes made on this phone?',
                ) ??
                Future.value(false));
        if (keep) {
          rep.data = mine;
          dirty = true;
          await SrStore.i.write(_local, {'data': mine, 'base': _version, 'dirty': true, 'at': _now()});
        } else {
          await SrStore.i.write(_local, null);
        }
      }
    }
    final step = await SrStore.i.read('step_$id');
    if (step is String && srSteps.any((s) => s.$1 == step)) rep.data['step'] = step;
    report = rep;
    loading = false;
    saveState = dirty ? (fromCache ? 'Changes on this phone' : 'Saving…') : 'Saved';
    await SrStore.i.remember(id, kind: rep.kind, kindLabel: rep.kindLabel, vessel: _titleOf(rep));
    notifyListeners();
    if (dirty) unawaited(saveNow());
    if (isDraft) unawaited(saveNow());
    if (!fromCache && !isDraft) unawaited(finalAfterOffline());
    _live?.cancel();
    _live = Timer.periodic(const Duration(seconds: 8), (_) => _livePoll());
  }

  static String _titleOf(SrReport r) {
    final v = r.val('vessel_name');
    return v.isNotEmpty ? v.toUpperCase() : r.title;
  }

  static int _now() => DateTime.now().millisecondsSinceEpoch;

  static String _canon(Object? v) {
    if (v is List) return '[${v.map(_canon).join(',')}]';
    if (v is Map) {
      final k = v.keys.map((e) => '$e').where((e) => !e.startsWith('_')).toList()..sort();
      return '{${k.map((e) => '${jsonEncode(e)}:${_canon(v[e])}').join(',')}}';
    }
    return jsonEncode(v == null ? '' : '$v');
  }

  /// The step this person is on (kept on this phone only, as the website does).
  Future<void> go(String step) async {
    report?.data['step'] = step;
    await SrStore.i.write('step_$id', step);
    notifyListeners();
    if (step == 'calc' || step == 'generate') unawaited(verify());
  }

  // ---------- saving ----------
  /// After every change: kept on this phone at once, saved on the server a moment later.
  void changed({bool quiet = false}) {
    final r = report;
    if (r == null || !r.canEdit) return;
    dirty = true;
    server = null;
    unawaited(SrStore.i.write(_local, {'data': r.data, 'base': _version, 'dirty': true, 'at': _now()}));
    saveState = 'Saving…';
    notifyListeners();
    _timer?.cancel();
    _timer = Timer(Duration(milliseconds: quiet ? 300 : 1000), saveNow);
  }

  Future<bool> saveNow() async {
    if (closed) return false;
    final r = report;
    if (r == null) return false;
    if (isDraft) return draftSync();
    if (!dirty || saving || !r.canEdit) return true;
    saving = true;
    final sent = jsonEncode(r.payload());
    final res = await SrApi.i.post('save', {'id': id, 'version': '$_version', 'data': sent});
    saving = false;
    if (res['ok'] == true) {
      _version = (res['version'] as num?)?.toInt() ?? _version;
      serverErrors = res['errors'] is Map ? (res['errors'] as Map).map((k, v) => MapEntry('$k', '$v')) : {};
      if (jsonEncode(r.payload()) == sent) {
        dirty = false;
        await SrStore.i.write(_local, null);
        saveState = 'Saved';
      } else {
        await SrStore.i.write(_local, {'data': r.data, 'base': _version, 'dirty': true, 'at': _now()});
        unawaited(saveNow());
      }
      final c = await SrStore.i.read(_cache);
      if (c is Map) {
        final cc = Map<String, dynamic>.from(c);
        cc['report'] = {
          ...Map<String, dynamic>.from(cc['report'] as Map),
          'version': _version,
          'data': jsonDecode(sent),
        };
        await SrStore.i.write(_cache, cc);
      }
      _retry?.cancel();
      notifyListeners();
      return true;
    }
    if (res['_status'] == 409 && res['server'] is Map) {
      saveState = 'Save failed — changed elsewhere';
      notifyListeners();
      final s = Map<String, dynamic>.from(res['server'] as Map);
      final keep =
          await (onConflict?.call(
                '${res['error'] ?? 'This report was saved meanwhile.'} Keep your changes (they replace the other version)?',
              ) ??
              Future.value(true));
      _version = (s['version'] as num?)?.toInt() ?? _version;
      if (keep) return saveNow();
      r.data = r.normalise(Map<String, dynamic>.from(s['data'] as Map));
      dirty = false;
      await SrStore.i.write(_local, null);
      saveState = 'Saved';
      notifyListeners();
      return false;
    }
    if (res['_status'] == 423) {
      saveState = 'Locked by the Admin';
      error = '${res['error'] ?? ''}';
      notifyListeners();
      return false;
    }
    saveState = res['_status'] == 0 ? 'Changes on this phone' : 'Save failed';
    if (res['_status'] != 0) error = '${res['error'] ?? 'Save failed.'}';
    _retryLater();
    notifyListeners();
    return false;
  }

  void _retryLater() {
    _retry?.cancel();
    _retry = Timer(const Duration(seconds: 15), () {
      if (dirty || isDraft) saveNow();
    });
  }

  /// A report started without internet: made on the server once (its link is kept with the draft, so
  /// a second try only saves), everything typed is saved into it, and this session moves to its link.
  Future<bool> draftSync() async {
    final r = report;
    if (r == null || saving || !isDraft) return false;
    saving = true;
    saveState = 'Sending the report…';
    notifyListeners();
    try {
      final d = await SrStore.i.read('drafttoken_$id');
      var token = d is String ? d : '';
      if (token.isEmpty) {
        final j = await SrApi.i.post('new', {'kind': r.kind});
        if (j['ok'] != true || '${j['token'] ?? ''}'.isEmpty) throw _SyncError(j);
        token = '${j['token']}';
        await SrStore.i.write('drafttoken_$id', token);
      }
      final g = await SrApi.i.get('get', {'id': token});
      if (g['ok'] != true) throw _SyncError(g);
      final ver = ((g['report'] as Map?)?['version'] as num?)?.toInt() ?? 0;
      final res = await SrApi.i.post('save', {'id': token, 'version': '$ver', 'data': jsonEncode(r.payload())});
      if (res['ok'] != true) throw _SyncError(res);
      final step = '${r.data['step']}';
      final old = id;
      await SrStore.i.write('step_$token', step);
      final off = await SrStore.i.read('offpdf_$old');
      if (off != null) await SrStore.i.write('offpdf_$token', off);
      for (final k in ['drafttoken_$old', 'local_$old', 'get_$old', 'step_$old', 'offpdf_$old']) {
        await SrStore.i.write(k, null);
      }
      await SrStore.i.rename(old, token);
      id = token;
      saving = false;
      dirty = false;
      await load(); // the report as the server has it now
      return true;
    } on _SyncError catch (e) {
      saving = false;
      saveState = 'Changes on this phone';
      if (e.res['_status'] != 0) error = '${e.res['error'] ?? 'Not sent yet — kept on this phone.'}';
      _retryLater();
      notifyListeners();
      return false;
    }
  }

  /// Wait for the save in flight and save what is left.
  Future<void> flush() async {
    _timer?.cancel();
    while (saving) {
      await Future<void>.delayed(const Duration(milliseconds: 150));
    }
    if (dirty || isDraft) await saveNow();
    while (saving) {
      await Future<void>.delayed(const Duration(milliseconds: 150));
    }
  }

  /// What someone else saves shows up here within seconds — only while nothing waits to be saved here.
  Future<void> _livePoll() async {
    if (closed || isDraft || dirty || saving || report == null || fromCache) return;
    final v = await SrApi.i.get('version', {'id': id});
    if (v['ok'] != true || ((v['version'] as num?)?.toInt() ?? 0) <= _version) return;
    final res = await SrApi.i.get('get', {'id': id});
    if (closed || res['ok'] != true || dirty || saving) return;
    final step = report!.data['step'];
    res.remove('_status');
    await SrStore.i.write(_cache, res);
    final rep = SrReport(res)..data['step'] = step;
    report = rep;
    _version = rep.version;
    server = null;
    notifyListeners();
  }

  // ---------- the server's checks, files, lookups ----------
  Future<void> verify() async {
    final r = report;
    if (r == null || isDraft || r.chosen().isEmpty) return;
    verifying = true;
    notifyListeners();
    await flush();
    final res = await SrApi.i.get('verify', {'id': id});
    verifying = false;
    if (res['ok'] == true && res['results'] is Map && !dirty) server = Map<String, dynamic>.from(res['results'] as Map);
    if (!closed) notifyListeners();
  }

  /// Makes the report (PDF) from the saved values → the answer (`new_files.pdf`, `files`, or `issues` / `error`).
  Future<Map<String, dynamic>> generate() async {
    await flush();
    if (isDraft) {
      return {'ok': false, 'error': 'The report is sent to the server first — connect to the internet and try again.'};
    }
    final res = await SrApi.i.post('generate', {'id': id, 'pdf': '1'});
    if (res['version'] is num) _version = (res['version'] as num).toInt();
    if (res['ok'] == true) {
      server = res['results'] is Map ? Map<String, dynamic>.from(res['results'] as Map) : server;
      final rep = report!;
      rep.res['files'] = res['files'] ?? rep.res['files'];
      final c = await SrStore.i.read(_cache);
      if (c is Map) await SrStore.i.write(_cache, {...Map<String, dynamic>.from(c), 'files': rep.res['files']});
      notifyListeners();
    }
    return res;
  }

  /// A PDF was made on this phone offline: the server's final report is made when the connection is back.
  Future<void> markOfflinePdf() => SrStore.i.write('offpdf_$id', {'at': DateTime.now().millisecondsSinceEpoch});

  /// Back online after a PDF made offline: save, then make the final PDF (and the Excel kept in the system).
  Future<void> finalAfterOffline() async {
    if (_finalBusy || isDraft || closed) return;
    final f = await SrStore.i.read('offpdf_$id');
    final r = report;
    if (f == null || r == null || !r.canEdit) return;
    _finalBusy = true;
    try {
      await flush();
      if (dirty || isDraft || r.issues().isNotEmpty) return;
      final res = await generate();
      if (res['ok'] != true) return;
      await SrStore.i.write('offpdf_$id', null);
      onFinal?.call((res['new_files'] as Map?)?['pdf']);
    } finally {
      _finalBusy = false;
    }
  }

  /// The vessel particulars of an IMO (the server's lookup).
  Future<Map<String, dynamic>> lookup(String imo) async {
    if (isDraft) return {'ok': false, 'error': 'Lookup needs the internet.'};
    return SrApi.i.post('lookup', {'id': id, 'imo': imo});
  }

  /// Tank rows read from an Excel workbook (nothing stored on the server).
  Future<Map<String, dynamic>> importTanks(String name, Stream<List<int>> Function() open, int size) async {
    if (isDraft) return {'ok': false, 'error': 'Importing needs the internet.'};
    return SrApi.i.post('import_tanks', {'id': id}, file: MultipartFile.fromStream(open, size, filename: name));
  }

  /// A report file (PDF) of this report.
  String fileUrl(Object fileId, {bool inline = false}) =>
      '${SrApi.path}?a=file&id=${Uri.encodeQueryComponent('$fileId')}&t=${Uri.encodeQueryComponent(id)}${inline ? '&inline=1' : ''}';

  @override
  void dispose() {
    closed = true;
    _timer?.cancel();
    _retry?.cancel();
    _live?.cancel();
    super.dispose();
  }
}

class _SyncError implements Exception {
  _SyncError(this.res);
  final Map<String, dynamic> res;
}

/// Starting a report: on the server when online, else from the report type's form kept on this phone.
class SrStart {
  /// The report types available (with their empty form, kept for offline use) → kind → label.
  static Future<Map<String, String>> kinds() async {
    const all = {
      'rob': '221B / ROB Survey', 'offhire': 'Offhire', 'onhire': 'Onhire', //
      'offhire3': '3 Party Offhire', 'onhire3': '3 Party Onhire', 'offhire4': '4 Party Offhire',
    };
    final out = <String, String>{};
    var online = false;
    await Future.wait([
      for (final k in all.keys)
        () async {
          final r = await SrApi.i.get('starter', {'kind': k});
          if (r['_status'] != 0) online = true;
          if (r['ok'] == true) {
            r.remove('_status');
            await SrStore.i.write('starter_$k', r);
            out[k] = all[k]!;
          } else if (r['_status'] == 404) {
            await SrStore.i.write('starter_$k', null);
          }
        }(),
    ]);
    if (!online) {
      for (final k in all.keys) {
        if (await SrStore.i.read('starter_$k') is Map) out[k] = all[k]!;
      }
    }
    return {
      for (final k in all.keys)
        if (out.containsKey(k)) k: out[k]!,
    };
  }

  /// A new report → its id (the token, or a draft id when offline). Throws a message when it cannot.
  static Future<String> create(String kind) async {
    final j = await SrApi.i.post('new', {'kind': kind});
    if (j['ok'] == true && '${j['token'] ?? ''}'.isNotEmpty) return '${j['token']}';
    if (j['_status'] != 0) throw '${j['error'] ?? 'The report could not be started.'}';
    final s = await SrStore.i.read('starter_$kind');
    if (s is! Map) throw 'You are offline and this report type has not been opened on this phone before.';
    final id = SrSession.newDraftId();
    await SrStore.i.write('get_$id', s);
    return id;
  }
}
