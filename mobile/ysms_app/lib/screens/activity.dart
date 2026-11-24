// Login activity (Admin / Super Admin): the summary, every sign-in with search, status and date filters,
// 30 at a time, and one user's history in a sheet. The website's own pages and checks:
// ajax/login_activity_summary.php, ajax/login_activity_list.php, ajax/login_activity_user_history.php.
import 'dart:async';

import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

/// "2026-11-14 09:05:00" → "14 Nov, 09:05".
String fmtWhen(dynamic v) {
  final t = s(v);
  final d = DateTime.tryParse(t.replaceFirst(' ', 'T'));
  if (d == null) return t;
  const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  String two(int x) => x.toString().padLeft(2, '0');
  return '${two(d.day)} ${m[d.month - 1]}, ${two(d.hour)}:${two(d.minute)}';
}

class ActivityScreen extends StatefulWidget {
  const ActivityScreen({super.key});
  @override
  State<ActivityScreen> createState() => _ActivityScreenState();
}

class _ActivityScreenState extends State<ActivityScreen> {
  final _rows = <Map<String, dynamic>>[];
  final _q = TextEditingController();
  final _scroll = ScrollController();
  Map<String, dynamic>? _sum;
  Timer? _t;
  String _status = 'All', _range = '';
  int _page = 1, _total = -1, _seq = 0;
  bool _more = false, _loading = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      if (_more && !_loading && _scroll.position.pixels > _scroll.position.maxScrollExtent - 300) _load(false);
    });
    Api.i
        .getJson('ajax/login_activity_summary.php')
        .then((d) {
          if (mounted) setState(() => _sum = Map<String, dynamic>.from((d['summary'] as Map?) ?? const {}));
        })
        .catchError((_) {});
    _load(true);
  }

  @override
  void dispose() {
    _t?.cancel();
    _q.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load(bool reset) async {
    if (reset) {
      _page = 1;
      _more = false;
    }
    final my = ++_seq;
    setState(() => _loading = true);
    try {
      final d = await Api.i.getJson('ajax/login_activity_list.php', {
        'page': _page,
        'per_page': 30,
        'search': _q.text.trim(),
        'status': _status,
        if (_range.isNotEmpty) 'date_range': _range,
      });
      if (my != _seq || !mounted) return;
      final rows = ((d['rows'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      setState(() {
        if (reset) _rows.clear();
        _rows.addAll(rows);
        _total = (d['total'] as num?)?.toInt() ?? _rows.length;
        _more = _page * 30 < _total;
        if (_more) _page++;
        _error = null;
      });
    } catch (e) {
      if (my == _seq && mounted) setState(() => _error = e);
    } finally {
      if (my == _seq && mounted) setState(() => _loading = false);
    }
  }

  Future<void> _user(int id) async {
    try {
      final d = await Api.i.getJson('ajax/login_activity_user_history.php', {'user_id': id});
      if (!mounted) return;
      final u = Map<String, dynamic>.from((d['user'] as Map?) ?? const {});
      final h = ((d['history'] as List?) ?? const []).take(50).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (c) => DraggableScrollableSheet(
          expand: false,
          initialChildSize: .7,
          builder: (c, sc) => ListView(
            controller: sc,
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            children: [
              Text(s(u['user_name']), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              Text(
                [s(u['role']), s(u['username']), s(u['user_email'])].where((x) => x.isNotEmpty).join(' · '),
                style: TextStyle(color: mutedText(c)),
              ),
              const SizedBox(height: 12),
              Row(
                children: [
                  _stat('Successful', s(u['total_success'] ?? 0), green),
                  _stat('Failed', s(u['total_failed'] ?? 0), red),
                  _stat('Latest', fmtWhen(u['latest_login']), blue),
                ],
              ),
              const SizedBox(height: 8),
              for (final x in h)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  dense: true,
                  title: Text(
                    '${s(x['login_status']) == 'SUCCESS' ? 'Signed in' : 'Failed'} · ${fmtWhen(x['login_time'])}',
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: s(x['login_status']) == 'SUCCESS' ? green : red,
                    ),
                  ),
                  subtitle: Text(
                    [
                      s(x['device_type']),
                      s(x['browser']),
                      s(x['ip_address']),
                      s(x['session_duration_label']),
                    ].where((y) => y.isNotEmpty).join(' · '),
                  ),
                ),
            ],
          ),
        ),
      );
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
  }

  Widget _stat(String l, String v, Color c) => Expanded(
    child: Container(
      margin: const EdgeInsets.only(right: 8),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(color: c.withValues(alpha: .1), borderRadius: BorderRadius.circular(12)),
      child: Column(
        children: [
          Text(
            v,
            textAlign: TextAlign.center,
            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
          ),
          Text(l, style: TextStyle(fontSize: 11.5, color: mutedText(context))),
        ],
      ),
    ),
  );

  Widget _summary() {
    final d = _sum;
    if (d == null) return const SizedBox.shrink();
    final cells = [
      ('Users', d['total_users'], Icons.people_outline_rounded, blue),
      ('Logged in today', d['logged_in_today'], Icons.check_circle_outline_rounded, green),
      ('Active sessions', d['active_sessions'], Icons.schedule_rounded, sky),
      ('Failed today', d['failed_today'], Icons.error_outline_rounded, red),
    ];
    return GridView.count(
      crossAxisCount: 2,
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      mainAxisSpacing: 10,
      crossAxisSpacing: 10,
      childAspectRatio: 2.3,
      children: [
        for (final c in cells)
          Box(
            padding: const EdgeInsets.all(12),
            child: Row(
              children: [
                IconBadge(c.$3, c.$4, size: 36),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(s(c.$2 ?? 0), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                      Text(
                        c.$1,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontSize: 11.5, color: mutedText(context)),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }

  Widget _chip(String label, bool on, VoidCallback tap) => Padding(
    padding: const EdgeInsets.only(right: 8),
    child: ChoiceChip(key: ValueKey('act-chip-$label'), label: Text(label), selected: on, onSelected: (_) => tap()),
  );

  Widget _row(Map<String, dynamic> r) {
    final ok = s(r['login_status']) == 'SUCCESS';
    final where = [
      fmtWhen(r['login_time']),
      s(r['ip_address']),
      if (s(r['country']).isNotEmpty && s(r['country']) != 'Unknown') s(r['country']),
      if (!ok) s(r['failure_reason']).isEmpty ? 'Failed' : s(r['failure_reason']),
    ].where((x) => x.isNotEmpty).join(' · ');
    final uid = (r['user_id'] as num?)?.toInt() ?? 0;
    return Box(
      key: ValueKey('act-${r['id']}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      onTap: uid > 0 ? () => _user(uid) : null,
      child: Row(
        children: [
          IconBadge(ok ? Icons.login_rounded : Icons.block_rounded, ok ? green : red),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  s(r['user_name']).isEmpty ? s(r['username']) : s(r['user_name']),
                  style: const TextStyle(fontWeight: FontWeight.w800),
                ),
                Text(
                  [s(r['role']), s(r['device_type']), s(r['browser'])].where((x) => x.isNotEmpty).join(' · '),
                  style: TextStyle(fontSize: 12, color: mutedText(context)),
                ),
                Text(where, style: TextStyle(fontSize: 12, color: ok ? mutedText(context) : red)),
              ],
            ),
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
            const Text('Login activity'),
            if (_total >= 0)
              Text('$_total logins', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(true),
        child: ListView(
          controller: _scroll,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            _summary(),
            const SizedBox(height: 12),
            TextField(
              key: const ValueKey('act-search'),
              controller: _q,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(
                prefixIcon: Icon(Icons.search_rounded),
                hintText: 'Search name, username, email',
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
                  for (final st in const ['All', 'Successful', 'Failed'])
                    _chip(st, _status == st, () {
                      setState(() => _status = st);
                      _load(true);
                    }),
                  for (final dr in const [('today', 'Today'), ('last7', '7 days')])
                    _chip(dr.$2, _range == dr.$1, () {
                      setState(() => _range = _range == dr.$1 ? '' : dr.$1);
                      _load(true);
                    }),
                ],
              ),
            ),
            const SizedBox(height: 12),
            if (_error != null && _rows.isEmpty)
              ErrorState(error: _error!, onRetry: () => _load(true))
            else if (_rows.isEmpty && _loading)
              const CardSkeletons(count: 4)
            else if (_rows.isEmpty)
              const EmptyState(icon: Icons.search_off_rounded, text: 'Nothing matches')
            else
              for (final r in _rows) _row(r),
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
