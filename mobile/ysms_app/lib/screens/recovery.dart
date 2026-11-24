// Recovery: Pending Report + Completed vessels with their recovery (app_api.php?a=recovery — the same
// rules as recovery.php): the totals (VLSFO / LSMGO / HSFO / vessels), search, month filter, 30 at a time,
// a tap opens the vessel, Download = export_recovery.php with the same filters.
import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'vessel.dart';

class RecoveryScreen extends StatefulWidget {
  const RecoveryScreen({super.key, this.surveyorId, this.surveyorName});

  /// One surveyor's recovery (Admin / Super Admin, from the home Surveyor report).
  final int? surveyorId;
  final String? surveyorName;
  @override
  State<RecoveryScreen> createState() => _RecoveryScreenState();
}

class _RecoveryScreenState extends State<RecoveryScreen> {
  final _items = <Map<String, dynamic>>[];
  final _q = TextEditingController();
  final _scroll = ScrollController();
  Map<String, dynamic>? _tot;
  List<Map<String, dynamic>> _months = const [];
  String _month = '';
  Timer? _t;
  int _page = 1, _seq = 0;
  bool _more = false, _loading = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_more && !_loading && _scroll.position.pixels > _scroll.position.maxScrollExtent - 300) _load(false);
    });
    _load(true);
  }

  @override
  void dispose() {
    _t?.cancel();
    _q.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Map<String, dynamic> get _filters => {
    if (_q.text.trim().isNotEmpty) 'q': _q.text.trim(),
    if (_month.isNotEmpty) 'month': _month,
    if (widget.surveyorId != null) 'surveyor': widget.surveyorId,
  };

  Future<void> _load(bool reset) async {
    if (reset) {
      _page = 1;
      _more = false;
    }
    final my = ++_seq;
    setState(() => _loading = true);
    try {
      final d = await Api.i.get('recovery', {'page': _page, ..._filters});
      if (my != _seq || !mounted) return;
      final rows = ((d['items'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      setState(() {
        if (reset) _items.clear();
        _items.addAll(rows);
        _tot = d;
        if (_months.isEmpty) {
          _months = ((d['months'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
        }
        _more = d['more'] == true;
        if (_more) _page++;
        _error = null;
      });
    } catch (e) {
      if (my == _seq && mounted) setState(() => _error = e);
    } finally {
      if (my == _seq && mounted) setState(() => _loading = false);
    }
  }

  void _download() {
    final qs = {'full': '1', ..._filters.map((k, v) => MapEntry(k, '$v'))};
    downloadAndOpen(
      context,
      Uri(path: 'export_recovery.php', queryParameters: qs).toString(),
      name: 'Recovery.xlsx',
    );
  }

  Widget _hero() {
    final d = _tot;
    Widget cell(String l, String v) => Expanded(
      child: Column(
        children: [
          Text(
            l,
            style: const TextStyle(color: Colors.white70, fontSize: 11.5, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 2),
          Text(
            v,
            style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 14),
          ),
        ],
      ),
    );
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        gradient: const LinearGradient(colors: [navy, navy2, blue]),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'Total recovery',
            style: TextStyle(color: Colors.white70, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 4),
          Text(
            d == null ? '–' : '${fmtMt(d['total'])} MT',
            key: const ValueKey('rec-total'),
            style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.w800),
          ),
          const SizedBox(height: 12),
          Row(
            children: [
              cell('VLSFO', d == null ? '–' : fmtMt(d['vlsfo'])),
              cell('LSMGO', d == null ? '–' : fmtMt(d['lsmgo'])),
              cell('HSFO', d == null ? '–' : fmtMt(d['hsfo'])),
              cell('Vessels', d == null ? '–' : s(d['count'])),
            ],
          ),
        ],
      ),
    );
  }

  Widget _card(Map<String, dynamic> r) {
    final na = r['na'] == true || r['total'] == null;
    return Box(
      key: ValueKey('rec-${r['id']}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(14),
      onTap: () =>
          Navigator.of(context).push(MaterialPageRoute(builder: (_) => VesselScreen(id: (r['id'] as num).toInt()))),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(s(r['vessel']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
                Text(
                  [s(r['client']), s(r['port'])].where((x) => x.isNotEmpty).join(' · '),
                  style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                ),
                Text(
                  [s(r['type']), s(r['completed'])].where((x) => x.isNotEmpty).join(' · '),
                  style: TextStyle(fontSize: 12, color: mutedText(context)),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                s(r['total_text']),
                style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: na ? mutedText(context) : blue),
              ),
              if (!na) Text('MT', style: TextStyle(fontSize: 11, color: mutedText(context))),
            ],
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Recovery'),
            Text(
              widget.surveyorName ?? 'Pending Report + Completed',
              style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500),
            ),
          ],
        ),
        actions: [
          IconButton(
            key: const ValueKey('rec-download'),
            tooltip: 'Download Excel',
            onPressed: _download,
            icon: const Icon(Icons.download_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(true),
        child: ListView(
          controller: _scroll,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            _hero(),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('rec-search'),
              controller: _q,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search_rounded),
                hintText: 'Search vessel, report no., port',
              ),
              onChanged: (_) {
                _t?.cancel();
                _t = Timer(const Duration(milliseconds: 350), () => _load(true));
              },
            ),
            const SizedBox(height: 10),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  for (final m in [
                    {'value': '', 'label': 'All months'},
                    ..._months,
                  ])
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: ChoiceChip(
                        key: ValueKey('rec-month-${m['value']}'),
                        label: Text(s(m['label'])),
                        selected: _month == s(m['value']),
                        onSelected: (_) {
                          setState(() => _month = s(m['value']));
                          _load(true);
                        },
                      ),
                    ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            if (_error != null && _items.isEmpty)
              ErrorState(error: _error!, onRetry: () => _load(true))
            else if (_items.isEmpty && _loading)
              const CardSkeletons(count: 4)
            else if (_items.isEmpty)
              const EmptyState(icon: Icons.local_gas_station_outlined, color: sky, text: 'Nothing matches')
            else
              for (final r in _items) _card(r),
            if (_more)
              const Padding(
                padding: EdgeInsets.all(16),
                child: Center(child: CircularProgressIndicator()),
              ),
          ],
        ),
      ),
    );
  }
}
