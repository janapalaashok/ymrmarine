// The survey report against a real YSMS server (not run by default):
//   flutter test test/sr_live_test.dart --dart-define=YSMS_SERVER=http://127.0.0.1:8095/YSMS/ --dart-define=SR_LIVE=true
// Starts a report, fills it as a surveyor would, saves, checks on the server, makes the PDF, and a
// report started "offline" is sent later. Use a test server only — it creates reports.
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:ysms_app/api.dart';
import 'package:ysms_app/sr/sr_api.dart';
import 'package:ysms_app/sr/sr_model.dart';
import 'package:ysms_app/sr/sr_session.dart';
import 'package:ysms_app/sr/sr_store.dart';

const live = bool.fromEnvironment('SR_LIVE');

void fill(SrReport r) {
  final f = {
    'imo': '9074729', 'vessel_name': 'MV APP TEST', 'flag': 'PANAMA', 'port_of_registry': 'PANAMA', 'grt': '30000', //
    'nrt': '17000',
    'holds': '5',
    'builder': 'HYUNDAI',
    'keel_month': 'MARCH',
    'keel_year': '2010',
    'operator': 'OPS LTD',
    'callsign': 'ABCD1', 'loa': '190.000', 'breadth': '32.260', 'depth': '18.000', 'summer_draft': '12.500',
    'summer_dwt': '55000', 'class': 'NK', 'owners': 'BLUE OWNERS', 'charterers': 'RED CHARTERERS', 'port_name': 'VIZAG',
    'place': 'OUTER ANCHORAGE',
    'draft_aft': '8.000',
    'draft_fwd': '7.500',
    'master': 'CAPT A',
    'chief_engineer': 'CE B',
    'surveyor_name': 'SURVEYOR C', 'fwe_date': '2026-11-15', 'fwe_time': '08:00', 'started_date': '2026-11-15',
    'started_time': '09:00', 'completed_date': '2026-11-15', 'completed_time': '10:30',
  };
  f.forEach((k, v) {
    if (r.field(k) != null) r.setVal(k, v);
  });
  r.chosenRaw
    ..clear()
    ..add('vlsfo');
  r.setVal('brob_fwe_vlsfo', '120.000');
  r.setVal('brob_started_vlsfo', '119.500');
  r.tanks['vlsfo'] = [
    {
      ...SrReport.emptyRow(),
      'name': '1P',
      'ref_height': '12.000',
      'sounding': '2.345',
      'su': 's',
      'volume': '100.000',
      'density': '0.9500',
      'temp': '30.0',
      'done': '1',
    },
    {
      ...SrReport.emptyRow(),
      'name': '1S',
      'ref_height': '12.000',
      'mode': 'ullage',
      'su': 'u',
      'ullage': '10.000',
      'sounding': '2.000',
      'volume': '25.500',
      'density': '0.9500',
      'temp': '28.5',
      'done': '1',
    },
  ];
}

void main() {
  group('live server', skip: live ? false : 'needs SR_LIVE=true and YSMS_SERVER (a test server)', () {
    setUp(() {
      SrStore.memoryOnly = true;
      SrStore.i.clearMemory();
      SrApi.i.reset();
      Api.i = Api(cookies: MemoryCookies());
    });

    test('a report: start, fill, save, verify, PDF', () async {
      final kinds = await SrStart.kinds();
      expect(kinds, contains('offhire'));
      final id = await SrStart.create('offhire');
      expect(id, matches(RegExp(r'^[0-9a-f]{64}$')));
      final s = SrSession(id);
      await s.load();
      expect(s.error, '');
      final r = s.report!;
      fill(r);
      expect(r.issues(), isEmpty, reason: r.issues().map((x) => x.msg).join('\n'));
      s.changed();
      await s.flush();
      expect(s.dirty, isFalse);
      expect(s.saveState, 'Saved');
      expect(s.version, greaterThan(0));
      // the server has exactly what was typed
      final g = await SrApi.i.get('get', {'id': id});
      expect(((g['report'] as Map)['data'] as Map)['fields']['vessel_name'], 'MV APP TEST');
      expect((((g['report'] as Map)['data'] as Map)['tanks']['vlsfo'] as List).length, 2);
      await s.verify();
      final local = r.gradeTotals('vlsfo').mt;
      final srv = ((s.server!['grades'] as Map)['vlsfo'] as Map)['completed'] as num;
      expect(srv.toDouble(), closeTo(local, 1e-9), reason: 'the app and the server calculate the same MT');
      final res = await s.generate();
      // ignore: avoid_print
      print(
        'generate: ok=${res['ok']} pdf=${(res['new_files'] as Map?)?['pdf']} pdf_error=${res['pdf_error']} error=${res['error']}',
      );
      expect(res['ok'], isTrue);
      final pdf = (res['new_files'] as Map?)?['pdf'];
      if (pdf != null) {
        final f = await Api.i.dio.get<List<int>>(s.fileUrl(pdf), options: Options(responseType: ResponseType.bytes));
        expect(f.headers.value('content-type'), contains('pdf'));
        expect(String.fromCharCodes(f.data!.take(4)), '%PDF');
      }
      s.dispose();
    });

    test('a report started offline is made on the server later', () async {
      await SrStart.kinds(); // keeps the empty forms on this phone
      final draft = SrSession.newDraftId();
      await SrStore.i.write('get_$draft', await SrStore.i.read('starter_offhire'));
      Object? finalPdf = 'not called';
      final s = SrSession(draft, onFinal: (id) => finalPdf = id);
      await s.load();
      fill(s.report!);
      await s.markOfflinePdf(); // a PDF was made on the phone without internet
      s.changed();
      await s.flush();
      expect(s.isDraft, isFalse);
      expect(s.id, matches(RegExp(r'^[0-9a-f]{64}$')));
      expect(s.report!.val('vessel_name'), 'MV APP TEST');
      expect((await SrStore.i.index()).first['id'], s.id);
      // back online: the server's final PDF is made by itself
      for (var i = 0; i < 100 && finalPdf == 'not called'; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 100));
      }
      expect(finalPdf, isA<num>());
      expect(await SrStore.i.read('offpdf_${s.id}'), isNull);
      s.dispose();
    });

    test('a change saved elsewhere meanwhile is asked about', () async {
      final id = await SrStart.create('offhire');
      var asked = '';
      final s = SrSession(
        id,
        onConflict: (m) async {
          asked = m;
          return true;
        },
      );
      await s.load();
      // another device saves first
      final other = await SrApi.i.post('save', {
        'id': id,
        'version': '${s.version}',
        'data': '{"fields":{"vessel_name":"OTHER"},"tanks":{},"grades":[],"step":"vessel","extra":{}}',
      });
      expect(other['ok'], isTrue);
      s.report!.setVal('vessel_name', 'MINE');
      s.changed();
      await s.flush();
      expect(asked, contains('saved meanwhile'));
      final g = await SrApi.i.get('get', {'id': id});
      expect(((g['report'] as Map)['data'] as Map)['fields']['vessel_name'], 'MINE');
      s.dispose();
    });
  });
}
