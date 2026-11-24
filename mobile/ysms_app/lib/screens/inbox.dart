// Inbox (Admin, Surveyor): the notifications (app_api.php?a=inbox), All / Unread, 30 at a time.
// A tap marks it read the website's way (inbox.php?open=) and opens its vessel or page;
// "Mark all read" posts inbox.php's own form (action=mark_all_read).
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';
import 'vessel.dart';

/// The notification types of the website (as the web app's icons).
IconData _icon(String type) => switch (type) {
  'assign' => Icons.directions_boat_outlined,
  'edit' => Icons.edit_outlined,
  'cancel' => Icons.block_rounded,
  'delete' => Icons.close_rounded,
  'format' => Icons.folder_outlined,
  'surveyor' || 'card' => Icons.person_outline_rounded,
  'status' => Icons.chat_bubble_outline_rounded,
  'upload' => Icons.upload_file_outlined,
  'report' || 'expense' => Icons.description_outlined,
  _ => Icons.notifications_none_rounded,
};

class InboxScreen extends StatefulWidget {
  const InboxScreen({super.key});
  @override
  State<InboxScreen> createState() => _InboxScreenState();
}

class _InboxScreenState extends State<InboxScreen> {
  final _items = <Map<String, dynamic>>[];
  final _scroll = ScrollController();
  bool _unreadOnly = false, _more = false, _loading = false, _busy = false;
  int _page = 1, _seq = 0, _unread = -1;
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
      final d = await Api.i.get('inbox', {'page': _page, if (_unreadOnly) 'unread': 1});
      if (my != _seq || !mounted) return;
      final rows = ((d['items'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      setState(() {
        if (reset) _items.clear();
        _items.addAll(rows);
        _unread = (d['unread'] as num?)?.toInt() ?? 0;
        Api.i.me?['unread'] = _unread; // the Home badge
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

  Future<void> _markAll() async {
    setState(() => _busy = true);
    try {
      final r = await Api.i.post('inbox.php', (csrf) => {'action': 'mark_all_read', 'csrf_token': csrf});
      final code = r.statusCode ?? 0;
      if (code >= 400) {
        throw ApiError(code, code == 403 ? 'Your session expired. Please sign in again.' : 'Could not mark them read');
      }
      if (mounted) toast(context, 'All notifications marked as read', ok: true);
      await _load(true);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _open(Map<String, dynamic> n) async {
    // inbox.php?open= marks it read (as tapping it on the website)
    Api.i.dio.get('inbox.php', queryParameters: {'open': n['id']}).ignore();
    if (n['read'] != true) {
      setState(() {
        n['read'] = true;
        if (_unread > 0) _unread--;
        Api.i.me?['unread'] = _unread;
      });
    }
    final sid = (n['survey_id'] as num?)?.toInt() ?? 0;
    if (sid > 0) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => VesselScreen(id: sid)));
      return;
    }
    final link = s(n['link']);
    if (link.isNotEmpty && mounted) await openWeb(context, link);
  }

  Widget _row(Map<String, dynamic> n) {
    final read = n['read'] == true;
    return Box(
      key: ValueKey('notif-${n['id']}'),
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(12),
      color: read ? null : purple.withValues(alpha: isDark(context) ? .16 : .06),
      onTap: () => _open(n),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          IconBadge(_icon(s(n['type'])), read ? blue : purple),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(s(n['title']), style: TextStyle(fontWeight: read ? FontWeight.w600 : FontWeight.w800)),
                if (s(n['message']).isNotEmpty) ...[
                  const SizedBox(height: 2),
                  Text(s(n['message']), style: const TextStyle(fontSize: 13)),
                ],
                const SizedBox(height: 4),
                Text(
                  [s(n['from']), s(n['ago'])].where((x) => x.isNotEmpty).join(' · '),
                  style: TextStyle(fontSize: 12, color: mutedText(context)),
                ),
              ],
            ),
          ),
          if (!read)
            Container(
              key: ValueKey('notif-dot-${n['id']}'),
              margin: const EdgeInsets.only(left: 8, top: 4),
              width: 9,
              height: 9,
              decoration: const BoxDecoration(color: purple, shape: BoxShape.circle),
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
            const Text('Inbox'),
            if (_unread >= 0)
              Text(
                _unread > 0 ? '$_unread unread' : 'All caught up',
                style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500),
              ),
          ],
        ),
        actions: [
          TextButton(
            key: const ValueKey('inbox-allread'),
            onPressed: _busy || _unread == 0 ? null : _markAll,
            child: const Text('Mark all read'),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(true),
        child: ListView(
          controller: _scroll,
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
          children: [
            Row(
              children: [
                for (final f in const [(false, 'All'), (true, 'Unread')])
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      key: ValueKey('inbox-chip-${f.$2}'),
                      label: Text(f.$2),
                      selected: _unreadOnly == f.$1,
                      onSelected: (_) {
                        setState(() => _unreadOnly = f.$1);
                        _load(true);
                      },
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 12),
            if (_error != null && _items.isEmpty)
              ErrorState(error: _error!, onRetry: () => _load(true))
            else if (_items.isEmpty && _loading)
              const CardSkeletons(count: 4)
            else if (_items.isEmpty)
              EmptyState(
                icon: Icons.inbox_outlined,
                text: _unreadOnly ? 'No unread notifications' : 'No notifications yet',
              )
            else
              for (final n in _items) _row(n),
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
