// What the survey report keeps on this phone (as the website keeps it in the browser): the reports
// opened here (their link), each report as last loaded, changes not saved yet, reports started without
// internet (drafts) and the empty form of each report type (to start one offline). JSON files in the
// app's own folder; in tests, in memory.
import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

class SrStore {
  SrStore._();
  static final i = SrStore._();

  /// Tests: kept in memory only.
  static bool memoryOnly = false;
  final _mem = <String, String>{};
  Directory? _dir;

  Future<File> _file(String key) async {
    _dir ??= Directory('${(await getApplicationSupportDirectory()).path}/survey_reports');
    if (!await _dir!.exists()) await _dir!.create(recursive: true);
    return File('${_dir!.path}/${key.replaceAll(RegExp(r'[^A-Za-z0-9_.-]'), '_')}.json');
  }

  Future<Object?> read(String key) async {
    try {
      final String? t;
      if (memoryOnly) {
        t = _mem[key];
      } else {
        final f = await _file(key);
        t = await f.exists() ? await f.readAsString() : null;
      }
      return t == null ? null : jsonDecode(t);
    } catch (_) {
      return null;
    }
  }

  Future<void> write(String key, Object? value) async {
    try {
      if (memoryOnly) {
        value == null ? _mem.remove(key) : _mem[key] = jsonEncode(value);
        return;
      }
      final f = await _file(key);
      if (value == null) {
        if (await f.exists()) await f.delete();
        return;
      }
      // written whole, then renamed: a report is never left half-written
      final tmp = File('${f.path}.tmp');
      await tmp.writeAsString(jsonEncode(value), flush: true);
      await tmp.rename(f.path);
    } catch (_) {}
  }

  void clearMemory() => _mem.clear();

  // ---------- the reports opened on this phone ----------
  Future<List<Map<String, dynamic>>> index() async {
    final v = await read('index');
    return v is List ? v.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList() : [];
  }

  /// Adds or updates a report in the list (most recent first).
  Future<void> remember(String id, {required String kind, required String kindLabel, required String vessel}) async {
    final l = await index();
    l.removeWhere((e) => e['id'] == id);
    l.insert(0, {
      'id': id,
      'kind': kind,
      'kind_label': kindLabel,
      'vessel': vessel,
      'at': DateTime.now().millisecondsSinceEpoch,
    });
    await write('index', l.take(50).toList());
  }

  Future<void> forget(String id) async {
    final l = await index();
    l.removeWhere((e) => e['id'] == id);
    await write('index', l);
  }

  /// A draft became a report on the server: its place in the list now names the report.
  Future<void> rename(String from, String to) async {
    final l = await index();
    for (final e in l) {
      if (e['id'] == from) e['id'] = to;
    }
    await write('index', l);
  }
}
