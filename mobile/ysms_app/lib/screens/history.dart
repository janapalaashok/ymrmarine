// Previous surveys of a vessel (app_api.php?a=history: same name or IMO, any client — as
// vessel_detail.php shows them): status, place, surveyors, report number, recovery and documents.
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key, required this.id});
  final int id;
  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  Map<String, dynamic>? _d;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('history', {'id': widget.id});
      if (mounted) {
        setState(() {
          _d = d;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Widget _rec(String label, String v) => Expanded(
    child: Column(
      children: [
        Text(
          label,
          style: TextStyle(fontSize: 11, color: mutedText(context), fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 2),
        Text(
          v,
          textAlign: TextAlign.center,
          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12.5),
        ),
      ],
    ),
  );

  Widget _meta(IconData i, String t) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(i, size: 14, color: mutedText(context)),
      const SizedBox(width: 4),
      Flexible(
        child: Text(t, style: TextStyle(fontSize: 12.5, color: mutedText(context))),
      ),
    ],
  );

  Widget _item(Map<String, dynamic> h) {
    final r = Map<String, dynamic>.from((h['recovery'] as Map?) ?? const {});
    final docs = ((h['docs'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final st = s(h['status']);
    final place = [s(h['client']), s(h['port'])].where((x) => x.isNotEmpty).join(' · ');
    return Box(
      key: ValueKey('hist-${h['id']}'),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(s(h['vessel']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
                    if (place.isNotEmpty) ...[
                      const SizedBox(height: 2),
                      Text(place, style: TextStyle(color: mutedText(context), fontSize: 12.5)),
                    ],
                  ],
                ),
              ),
              Tag(st, st == 'Completed' ? green : orange),
            ],
          ),
          const SizedBox(height: 8),
          Wrap(
            spacing: 12,
            runSpacing: 4,
            children: [
              if (s(h['completed_on']).isNotEmpty) _meta(Icons.event_outlined, 'Surveyed ${s(h['completed_on'])}'),
              if (s(h['surveyors']).isNotEmpty) _meta(Icons.person_outline_rounded, s(h['surveyors'])),
              if (s(h['report_no']).isNotEmpty) _meta(Icons.description_outlined, s(h['report_no'])),
            ],
          ),
          const SizedBox(height: 10),
          Container(
            padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 4),
            decoration: BoxDecoration(color: purple.withValues(alpha: .07), borderRadius: BorderRadius.circular(12)),
            child: Row(
              children: [
                _rec('Total', s(r['total'])),
                _rec('VLSFO', s(r['vlsfo'])),
                _rec('LSMGO', s(r['lsmgo'])),
                _rec('HSFO', s(r['hsfo'])),
              ],
            ),
          ),
          if (s(h['type']).isNotEmpty) ...[
            const SizedBox(height: 6),
            Text(s(h['type']), style: TextStyle(fontSize: 12, color: mutedText(context))),
          ],
          const SizedBox(height: 6),
          if (docs.isEmpty)
            Text('No documents on this survey.', style: TextStyle(fontSize: 12, color: mutedText(context)))
          else
            for (final d in docs)
              ListTile(
                key: ValueKey('hist-doc-${d['id']}'),
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: const IconBadge(Icons.insert_drive_file_outlined, blue, size: 34),
                title: Text(s(d['name']), maxLines: 1, overflow: TextOverflow.ellipsis),
                subtitle: Text([s(d['type']), s(d['when'])].where((x) => x.isNotEmpty).join(' · ')),
                trailing: const Icon(Icons.download_rounded),
                onTap: () => downloadAndOpen(context, 'download.php?upload_id=${d['id']}', name: s(d['name'])),
              ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final d = _d;
    final items = ((d?['items'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    Widget body;
    if (_error != null && d == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (d == null) {
      body = const CardSkeletons(count: 3);
    } else if (items.isEmpty) {
      body = ListView(
        children: const [
          EmptyState(icon: Icons.history_rounded, color: purple, text: 'No earlier surveys of this vessel'),
        ],
      );
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
            child: Text(
              'Earlier surveys of this vessel (same name or IMO, any client), for reference.',
              style: TextStyle(color: mutedText(context), fontWeight: FontWeight.w600),
            ),
          ),
          for (final h in items) ...[_item(h), const SizedBox(height: 10)],
        ],
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Previous surveys'),
            if (s(d?['vessel']).isNotEmpty)
              Text(s(d?['vessel']), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}
