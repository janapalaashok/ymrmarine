// Possible duplicates (Admin): the same vessel + client counted twice (app_api.php?a=duplicates —
// includes/duplicate_finder.php). Duplicate: same recovery within 31 days; Check: within 15 days with
// a different recovery. Open goes to the vessel; Delete is the Admin Controls delete
// (ajax/admin_surveys.php, Admin only), asked twice.
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'vessel.dart';

class DuplicatesScreen extends StatefulWidget {
  const DuplicatesScreen({super.key});
  @override
  State<DuplicatesScreen> createState() => _DuplicatesScreenState();
}

class _DuplicatesScreenState extends State<DuplicatesScreen> {
  List<Map<String, dynamic>>? _clusters;
  Object? _error;
  String _kind = '';
  int? _sure; // the record whose Delete was tapped once
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('duplicates');
      final list = ((d['clusters'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      if (mounted) {
        setState(() {
          _clusters = list;
          _error = null;
          _sure = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _delete(Map<String, dynamic> r) async {
    final id = (r['id'] as num).toInt();
    if (_sure != id) {
      setState(() => _sure = id);
      return;
    }
    setState(() => _busy = true);
    try {
      final res = await Api.i.post(
        'ajax/admin_surveys.php',
        (csrf) => {'type': s(r['list']), 'action': 'delete', 'id': '$id', 'csrf_token': csrf},
        csrfHeader: true,
      );
      final j = Api.i.json(res);
      if (j == null || j['success'] != true) {
        throw ApiError(res.statusCode ?? 500, s(j?['message']).isEmpty ? 'Could not delete' : s(j?['message']));
      }
      if (mounted) toast(context, s(j['message']).isEmpty ? 'Deleted' : s(j['message']), ok: true);
      await _load();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _chip(String k, String label, Color c) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: ChoiceChip(
      key: ValueKey('dup-chip-$k'),
      label: Text(label),
      selected: _kind == k,
      selectedColor: c.withValues(alpha: .16),
      onSelected: (_) => setState(() => _kind = k),
    ),
  );

  Widget _cluster(Map<String, dynamic> c) {
    final recs = ((c['records'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final dup = s(c['kind']) == 'duplicate';
    final extra = c['extra_mt'];
    return Box(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  recs.isEmpty ? '' : s(recs.first['vessel']),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                ),
              ),
              Tag(dup ? 'Duplicate' : 'Check', dup ? red : orange),
            ],
          ),
          if (s(c['month']).isNotEmpty)
            Text(s(c['month']), style: TextStyle(color: mutedText(context), fontSize: 12.5)),
          if (extra != null && extra != 0)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                '${fmtMt(extra)} MT counted ${((c['extra_ships'] as num?) ?? 0).toInt() + 1} times',
                style: const TextStyle(color: red, fontSize: 12.5, fontWeight: FontWeight.w700),
              ),
            ),
          const SizedBox(height: 6),
          for (final r in recs)
            Container(
              key: ValueKey('dup-${r['id']}'),
              margin: const EdgeInsets.only(top: 8),
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                border: Border.all(color: lineColor(context)),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${s(r['report_no']).isEmpty ? '#${r['id']}' : s(r['report_no'])} · ${s(r['status'])}',
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        Text(
                          [s(r['completed_on']), s(r['type']), s(r['surveyor'])].where((x) => x.isNotEmpty).join(' · '),
                          style: TextStyle(fontSize: 12, color: mutedText(context)),
                        ),
                        Text(
                          '${s(r['recovery'])} MT · ${r['files']} file${r['files'] == 1 ? '' : 's'} · ${s(r['source'])}',
                          style: TextStyle(fontSize: 12, color: mutedText(context)),
                        ),
                      ],
                    ),
                  ),
                  TextButton(
                    onPressed: () =>
                        Navigator.of(context)
                            .push(MaterialPageRoute(builder: (_) => VesselScreen(id: (r['id'] as num).toInt()))),
                    child: const Text('Open'),
                  ),
                  TextButton(
                    key: ValueKey('dup-del-${r['id']}'),
                    style: TextButton.styleFrom(foregroundColor: red),
                    onPressed: _busy ? null : () => _delete(r),
                    child: Text(_sure == r['id'] ? 'Tap to confirm' : 'Delete'),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final all = _clusters;
    final shown = (all ?? const <Map<String, dynamic>>[]).where((c) => _kind.isEmpty || s(c['kind']) == _kind).toList();
    Widget body;
    if (_error != null && all == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (all == null) {
      body = const CardSkeletons(count: 3);
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          Box(
            color: orange.withValues(alpha: .08),
            padding: const EdgeInsets.all(12),
            child: const Text(
              'Duplicate: same recovery within 31 days. Check: within 15 days, recovery different — you decide.',
              style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600),
            ),
          ),
          const SizedBox(height: 10),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [_chip('', 'All', blue), _chip('duplicate', 'Duplicate', red), _chip('check', 'Check', orange)],
            ),
          ),
          const SizedBox(height: 10),
          if (shown.isEmpty)
            const EmptyState(icon: Icons.check_circle_outline_rounded, color: green, text: 'No possible duplicates')
          else
            for (final c in shown) ...[_cluster(c), const SizedBox(height: 10)],
        ],
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Possible duplicates'),
            Text('Same vessel + client counted twice?', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}
