// Team chat: the groups (with unread counts; Admins create groups) and a group's messages — new ones
// every 3 seconds, earlier ones on request, text and a file (images shown), members (Admins add /
// remove). All through ajax/team_chat.php and its own checks (staff only, members only, file types and
// size from its settings).
import 'dart:async';
import 'dart:math';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../config.dart';
import '../ui.dart';
import 'upload.dart' show mb;

const _tc = 'ajax/team_chat.php';

Future<Map<String, dynamic>> _get(String action, [Map<String, dynamic>? p]) async {
  final Response<dynamic> r;
  try {
    r = await Api.i.dio.get(_tc, queryParameters: {'action': action, ...?p});
  } on DioException {
    throw ApiError(0, 'No connection. Check the internet and try again.');
  }
  return _result(r);
}

Future<Map<String, dynamic>> _post(String action, Object Function(String csrf) body) async {
  final r = await Api.i.post(_tc, body, csrfHeader: true);
  return _result(r);
}

Map<String, dynamic> _result(Response<dynamic> r) {
  if (r.statusCode == 401) {
    Api.i.markLost();
    throw ApiError(401, 'Please sign in again.');
  }
  final d = Api.i.json(r);
  if (d == null) throw ApiError(r.statusCode ?? 500, 'Chat is not available right now.');
  if (d['ok'] != true) throw ApiError(r.statusCode ?? 500, s(d['error']).isEmpty ? 'error' : s(d['error']));
  return d;
}

String initialsOf(String name) {
  final w = name.trim().split(RegExp(r'\s+')).where((x) => x.isNotEmpty).take(2);
  final i = w.map((x) => x[0].toUpperCase()).join();
  return i.isEmpty ? '?' : i;
}

List<Map<String, dynamic>> _maps(dynamic l) =>
    ((l as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();

class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key});
  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  List<Map<String, dynamic>>? _groups;
  bool _canManage = false;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await _get('bootstrap');
      if (!mounted) return;
      setState(() {
        _canManage = (d['me'] as Map?)?['can_manage'] == true;
        _groups = _maps(d['groups']);
        _error = null;
      });
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Future<void> _open(int id) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => RoomScreen(id: id)));
    _load();
  }

  Future<void> _newGroup() async {
    try {
      final d = await _get('staff');
      if (!mounted) return;
      final id = await showModalBottomSheet<int>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => _NewGroupSheet(staff: _maps(d['staff'])),
      );
      if (id != null && mounted) {
        toast(context, 'Group created', ok: true);
        _open(id);
      }
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = _groups;
    Widget body;
    if (_error != null && g == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (g == null) {
      body = const CardSkeletons(count: 3);
    } else if (g.isEmpty) {
      body = ListView(
        children: const [EmptyState(icon: Icons.forum_outlined, color: purple, text: 'No groups yet')],
      );
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          for (final x in g)
            Box(
              key: ValueKey('group-${x['id']}'),
              margin: const EdgeInsets.only(bottom: 10),
              padding: const EdgeInsets.all(12),
              onTap: () => _open((x['id'] as num).toInt()),
              child: Row(
                children: [
                  CircleAvatar(
                    backgroundColor: purple.withValues(alpha: .15),
                    child: Text(
                      initialsOf(s(x['name'])),
                      style: const TextStyle(color: purple, fontWeight: FontWeight.w800),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(s(x['name']), style: const TextStyle(fontWeight: FontWeight.w800)),
                        Text(
                          '${x['member_count']} member${x['member_count'] == 1 ? '' : 's'}${x['is_member'] == false ? ' · you are not in this group' : ''}',
                          style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                        ),
                      ],
                    ),
                  ),
                  if (((x['unread'] as num?) ?? 0) > 0)
                    Container(
                      key: ValueKey('unread-${x['id']}'),
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(color: purple, borderRadius: BorderRadius.circular(20)),
                      child: Text(
                        ((x['unread'] as num).toInt() > 99) ? '99+' : '${x['unread']}',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 12),
                      ),
                    ),
                  const Icon(Icons.chevron_right_rounded),
                ],
              ),
            ),
        ],
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: const Text('Team chat'),
        actions: [
          if (_canManage)
            IconButton(
              key: const ValueKey('chat-new'),
              tooltip: 'New group',
              onPressed: _newGroup,
              icon: const Icon(Icons.group_add_outlined),
            ),
        ],
      ),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}

class _NewGroupSheet extends StatefulWidget {
  const _NewGroupSheet({required this.staff});
  final List<Map<String, dynamic>> staff;
  @override
  State<_NewGroupSheet> createState() => _NewGroupSheetState();
}

class _NewGroupSheetState extends State<_NewGroupSheet> {
  final _name = TextEditingController();
  final _ids = <String>{};
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  Future<void> _create() async {
    setState(() => _busy = true);
    try {
      final r = await _post(
        'create_group',
        (csrf) =>
            FormData.fromMap({'action': 'create_group', 'name': _name.text.trim(), 'csrf_token': csrf})
              ..fields.addAll(_ids.map((i) => MapEntry('member_ids[]', i))),
      );
      if (mounted) Navigator.pop(context, ((r['group'] as Map)['id'] as num).toInt());
    } on ApiError catch (e) {
      if (mounted) {
        setState(() => _busy = false);
        toast(context, e.message, bad: true);
      }
    }
  }

  @override
  Widget build(BuildContext context) => DraggableScrollableSheet(
    expand: false,
    initialChildSize: .85,
    builder: (c, sc) => ListView(
      controller: sc,
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
      children: [
        const Text('New group', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        TextField(
          key: const ValueKey('ng-name'),
          controller: _name,
          maxLength: 60,
          decoration: const InputDecoration(labelText: 'Group name', hintText: 'e.g. Vizag surveyors'),
        ),
        const Text('Members', style: TextStyle(fontWeight: FontWeight.w800)),
        for (final u in widget.staff)
          CheckboxListTile(
            key: ValueKey('ng-${u['user_id']}'),
            contentPadding: EdgeInsets.zero,
            value: _ids.contains('${u['user_id']}'),
            title: Text(s(u['name'])),
            subtitle: Text(s(u['role'])),
            onChanged: (v) => setState(() => v == true ? _ids.add('${u['user_id']}') : _ids.remove('${u['user_id']}')),
          ),
        const SizedBox(height: 12),
        FilledButton(
          key: const ValueKey('ng-create'),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(48)),
          onPressed: _busy ? null : _create,
          child: const Text('Create group'),
        ),
      ],
    ),
  );
}

class RoomScreen extends StatefulWidget {
  const RoomScreen({super.key, required this.id});
  final int id;
  @override
  State<RoomScreen> createState() => _RoomScreenState();
}

class _RoomScreenState extends State<RoomScreen> {
  final _msgs = <Map<String, dynamic>>[];
  final _avatars = <String, String>{};
  final _scroll = ScrollController();
  final _text = TextEditingController();
  Map<String, dynamic>? _me, _group, _settings;
  int _members = 0, _lastId = 0, _firstId = 0;
  bool _hasMore = false, _loadingOlder = false, _loaded = false;
  Object? _error;
  Timer? _poll;
  PlatformFile? _file;
  int _fileSize = 0;

  int get _gid => (_group?['id'] as num?)?.toInt() ?? widget.id;
  int get _myId => (_me?['id'] as num?)?.toInt() ?? -1;

  @override
  void initState() {
    super.initState();
    _start();
  }

  @override
  void dispose() {
    _poll?.cancel();
    _scroll.dispose();
    _text.dispose();
    super.dispose();
  }

  void _addAvatars(Map<String, dynamic> r) {
    final a = r['avatars'];
    if (a is Map) a.forEach((k, v) => _avatars['$k'] = '$v');
  }

  Future<void> _start() async {
    try {
      final d = await _get('bootstrap', {'group_id': widget.id});
      _me = Map<String, dynamic>.from((d['me'] as Map?) ?? const {});
      _group = Map<String, dynamic>.from((d['group'] as Map?) ?? const {});
      _settings = Map<String, dynamic>.from((d['settings'] as Map?) ?? const {});
      _members = _maps(d['members']).length;
      if (_me?['is_member'] != true) {
        if (mounted) setState(() => _loaded = true);
        return;
      }
      final r = await _get('messages', {'group_id': _gid});
      _addAvatars(r);
      final m = _maps(r['messages']);
      if (!mounted) return;
      setState(() {
        _msgs
          ..clear()
          ..addAll(m);
        if (m.isNotEmpty) {
          _firstId = (m.first['id'] as num).toInt();
          _lastId = (m.last['id'] as num).toInt();
        }
        _hasMore = r['has_more'] == true;
        _loaded = true;
        _error = null;
      });
      _toBottom();
      _markRead();
      _schedule();
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e;
          _loaded = true;
        });
      }
    }
  }

  void _schedule() {
    _poll?.cancel();
    _poll = Timer(const Duration(seconds: 3), _tick);
  }

  Future<void> _tick() async {
    if (!mounted) return;
    try {
      final r = await _get('poll', {'group_id': _gid, 'after_id': _lastId});
      _addAvatars(r);
      final m = _maps(r['messages']);
      final have = _msgs.map((x) => x['id']).toSet();
      final fresh = m.where((x) => !have.contains(x['id'])).toList();
      if (!mounted) return;
      final stick = !_scroll.hasClients || _scroll.position.maxScrollExtent - _scroll.position.pixels < 120;
      setState(() {
        _msgs.addAll(fresh);
        if (m.isNotEmpty) _lastId = max(_lastId, (m.last['id'] as num).toInt());
        _members = (r['member_count'] as num?)?.toInt() ?? _members;
      });
      if (fresh.isNotEmpty) {
        if (stick) _toBottom();
        _markRead();
      }
    } catch (_) {}
    if (mounted) _schedule();
  }

  void _markRead() {
    if (_lastId == 0) return;
    _post(
      'mark_read',
      (csrf) => {'action': 'mark_read', 'group_id': '$_gid', 'message_id': '$_lastId', 'csrf_token': csrf},
    ).ignore();
  }

  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
    if (_scroll.hasClients) _scroll.jumpTo(_scroll.position.maxScrollExtent);
  });

  Future<void> _older() async {
    setState(() => _loadingOlder = true);
    try {
      final r = await _get('messages', {'group_id': _gid, 'before_id': _firstId});
      _addAvatars(r);
      final m = _maps(r['messages']);
      if (!mounted) return;
      setState(() {
        if (m.isNotEmpty) {
          _firstId = (m.first['id'] as num).toInt();
          _msgs.insertAll(0, m);
        }
        _hasMore = r['has_more'] == true;
      });
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    } finally {
      if (mounted) setState(() => _loadingOlder = false);
    }
  }

  Future<void> _attach() async {
    PlatformFile? f;
    try {
      f = await FilePicker.pickFile();
    } catch (_) {
      return;
    }
    if (f == null) return;
    final ext = f.name.contains('.') ? f.name.split('.').last.toLowerCase() : '';
    final allowed = ((_settings?['allowed_ext'] as List?) ?? const []).map((e) => '$e').toList();
    if (allowed.isNotEmpty && !allowed.contains(ext)) {
      if (mounted) toast(context, 'This file type cannot be shared in chat.', bad: true);
      return;
    }
    final size = await f.xFile.length();
    final maxB = (_settings?['max_upload_bytes'] as num?)?.toInt() ?? 0;
    if (maxB > 0 && size > maxB) {
      if (mounted) toast(context, 'File is too large. Maximum is ${(maxB / 1048576).round()} MB.', bad: true);
      return;
    }
    if (mounted) {
      setState(() {
        _file = f;
        _fileSize = size;
      });
    }
  }

  Future<void> _send() async {
    final text = _text.text.trim();
    final file = _file, size = _fileSize;
    if (text.isEmpty && file == null) return;
    final token =
        'app-${DateTime.now().millisecondsSinceEpoch.toRadixString(36)}-${Random().nextInt(1 << 30).toRadixString(36)}';
    final temp = <String, dynamic>{
      'id': token,
      'sender_id': _myId,
      'sender_name': s(_me?['name']),
      'text': text + (file == null ? '' : '${text.isEmpty ? '' : '\n'}Attachment: ${file.name}'),
      'ts': DateTime.now().millisecondsSinceEpoch / 1000,
      '_sending': true,
    };
    setState(() {
      _msgs.add(temp);
      _text.clear();
      _file = null;
    });
    _toBottom();
    try {
      final r = await _post(
        'send',
        (csrf) => FormData.fromMap({
          'action': 'send',
          'group_id': '$_gid',
          'text': text,
          'client_token': token,
          'csrf_token': csrf,
          if (file != null) 'file': MultipartFile.fromStream(() => file.xFile.openRead(), size, filename: file.name),
        }),
      );
      _addAvatars(r);
      final m = r['message'] is Map ? Map<String, dynamic>.from(r['message'] as Map) : null;
      if (!mounted) return;
      setState(() {
        final i = _msgs.indexOf(temp);
        if (m != null && !_msgs.any((x) => x['id'] == m['id'])) {
          if (i >= 0) _msgs[i] = m;
          _lastId = max(_lastId, (m['id'] as num).toInt());
        } else {
          _msgs.remove(temp);
        }
      });
    } on ApiError catch (e) {
      if (mounted) {
        setState(() {
          temp['_sending'] = false;
          temp['_failed'] = 'Not sent — ${e.message}';
        });
      }
    }
  }

  Future<void> _membersSheet() async {
    try {
      final manage = _me?['can_manage'] == true;
      final m = await _get('members', {'group_id': _gid});
      final c = manage ? await _get('candidates', {'group_id': _gid}) : const <String, dynamic>{};
      if (!mounted) return;
      final changed = await showModalBottomSheet<bool>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (sc) => DraggableScrollableSheet(
          expand: false,
          initialChildSize: .7,
          builder: (_, ctl) => ListView(
            controller: ctl,
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
            children: [
              const Text('Members', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              for (final x in _maps(m['members']))
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: CircleAvatar(child: Text(initialsOf(s(x['name'])))),
                  title: Text(s(x['name'])),
                  subtitle: Text(s(x['role'])),
                  trailing: manage
                      ? TextButton(
                          key: ValueKey('rm-${x['user_id']}'),
                          style: TextButton.styleFrom(foregroundColor: red),
                          onPressed: () => _member(sc, 'remove_member', x['user_id']),
                          child: const Text('Remove'),
                        )
                      : null,
                ),
              if (manage && _maps(c['candidates']).isNotEmpty) ...[
                const SizedBox(height: 10),
                const Text('Add people', style: TextStyle(fontWeight: FontWeight.w800)),
                for (final x in _maps(c['candidates']))
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: CircleAvatar(child: Text(initialsOf(s(x['name'])))),
                    title: Text(s(x['name'])),
                    subtitle: Text(s(x['role'])),
                    trailing: TextButton(
                      key: ValueKey('add-${x['user_id']}'),
                      onPressed: () => _member(sc, 'add_member', x['user_id']),
                      child: const Text('Add'),
                    ),
                  ),
              ],
            ],
          ),
        ),
      );
      if (changed == true) _start();
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
  }

  Future<void> _member(BuildContext sheet, String action, dynamic uid) async {
    try {
      await _post(action, (csrf) => {'action': action, 'group_id': '$_gid', 'user_id': '$uid', 'csrf_token': csrf});
      if (!mounted) return;
      toast(context, action == 'add_member' ? 'Member added' : 'Member removed', ok: true);
      if (sheet.mounted) Navigator.pop(sheet, true);
    } on ApiError catch (e) {
      if (mounted) toast(context, e.message, bad: true);
    }
  }

  String _day(num ts) {
    final d = DateTime.fromMillisecondsSinceEpoch((ts * 1000).round());
    final now = DateTime.now();
    final y = now.subtract(const Duration(days: 1));
    bool same(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;
    if (same(d, now)) return 'Today';
    if (same(d, y)) return 'Yesterday';
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${d.day.toString().padLeft(2, '0')} ${m[d.month - 1]} ${d.year}';
  }

  String _time(num ts) {
    final d = DateTime.fromMillisecondsSinceEpoch((ts * 1000).round());
    return '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  Widget _bubble(Map<String, dynamic> m) {
    final mine = m['sender_id'] == _myId;
    final av = _avatars['${m['sender_id']}'] ?? '';
    final atts = _maps(m['attachments']);
    final failed = s(m['_failed']);
    final bg = mine ? blue : cardBg(context);
    final fg = mine ? Colors.white : null;
    return Padding(
      key: ValueKey('msg-${m['id']}'),
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: mine ? MainAxisAlignment.end : MainAxisAlignment.start,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          if (!mine) ...[
            CircleAvatar(
              radius: 15,
              backgroundColor: purple.withValues(alpha: .15),
              foregroundImage: av.isEmpty ? null : NetworkImage(Cfg.url(av), headers: Api.i.imageHeaders),
              child: Text(initialsOf(s(m['sender_name'])), style: const TextStyle(fontSize: 11, color: purple)),
            ),
            const SizedBox(width: 6),
          ],
          Flexible(
            child: Container(
              constraints: const BoxConstraints(maxWidth: 300),
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 6),
              decoration: BoxDecoration(
                color: bg,
                borderRadius: BorderRadius.circular(16),
                border: mine ? null : Border.all(color: lineColor(context)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (!mine)
                    Text(
                      s(m['sender_name']),
                      style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12, color: purple),
                    ),
                  for (final a in atts)
                    a['is_image'] == true
                        ? GestureDetector(
                            onTap: () =>
                                downloadAndOpen(context, '$_tc?action=download&id=${a['id']}', name: s(a['name'])),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(10),
                                child: Image.network(
                                  Cfg.url('$_tc?action=download&id=${a['id']}&inline=1'),
                                  headers: Api.i.imageHeaders,
                                  height: 160,
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, _, _) => Text(s(a['name']), style: TextStyle(color: fg)),
                                ),
                              ),
                            ),
                          )
                        : InkWell(
                            onTap: () =>
                                downloadAndOpen(context, '$_tc?action=download&id=${a['id']}', name: s(a['name'])),
                            child: Padding(
                              padding: const EdgeInsets.symmetric(vertical: 4),
                              child: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  Icon(Icons.insert_drive_file_outlined, color: fg),
                                  const SizedBox(width: 6),
                                  Flexible(
                                    child: Text(
                                      '${s(a['name'])} · ${mb(((a['size'] as num?) ?? 0).toInt())}',
                                      style: TextStyle(color: fg, fontWeight: FontWeight.w600),
                                    ),
                                  ),
                                  Icon(Icons.download_rounded, color: fg, size: 18),
                                ],
                              ),
                            ),
                          ),
                  if (s(m['text']).isNotEmpty) Text(s(m['text']), style: TextStyle(color: fg)),
                  const SizedBox(height: 2),
                  Text(
                    failed.isNotEmpty ? failed : (m['_sending'] == true ? 'Sending…' : _time((m['ts'] as num?) ?? 0)),
                    style: TextStyle(
                      fontSize: 10.5,
                      color: failed.isNotEmpty ? red : (mine ? Colors.white70 : mutedText(context)),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final items = <Widget>[];
    var day = '';
    for (final m in _msgs) {
      final d = _day((m['ts'] as num?) ?? 0);
      if (d != day) {
        day = d;
        items.add(
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Center(child: Tag(d, muted)),
          ),
        );
      }
      items.add(_bubble(m));
    }
    final member = _me?['is_member'] == true;
    Widget body;
    if (!_loaded) {
      body = const CardSkeletons(count: 4);
    } else if (_error != null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _start)],
      );
    } else if (!member) {
      body = ListView(
        children: [
          EmptyState(
            icon: Icons.group_outlined,
            color: purple,
            text:
                'You are not a member of this group.${_me?['can_manage'] == true ? '\nAdd yourself from Members to read and write here.' : ''}',
          ),
        ],
      );
    } else {
      body = ListView(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 12),
        children: [
          if (_hasMore)
            Center(
              child: TextButton(
                key: const ValueKey('older'),
                onPressed: _loadingOlder ? null : _older,
                child: const Text('Load earlier messages'),
              ),
            ),
          if (_msgs.isEmpty)
            const EmptyState(
              icon: Icons.chat_bubble_outline_rounded,
              color: purple,
              text: 'No messages yet — say hello.',
            ),
          ...items,
        ],
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_group == null ? 'Chat' : s(_group!['name'])),
            if (_group != null)
              Text('$_members members', style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
        actions: [
          if (member || _me?['can_manage'] == true)
            IconButton(
              key: const ValueKey('members'),
              tooltip: 'Members',
              onPressed: _membersSheet,
              icon: const Icon(Icons.group_outlined),
            ),
        ],
      ),
      body: body,
      bottomNavigationBar: !member
          ? null
          : SafeArea(
              child: Padding(
                padding: EdgeInsets.fromLTRB(8, 6, 8, 8 + MediaQuery.of(context).viewInsets.bottom),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (_file != null)
                      Chip(
                        key: const ValueKey('att-chip'),
                        label: Text(_file!.name, overflow: TextOverflow.ellipsis),
                        onDeleted: () => setState(() => _file = null),
                      ),
                    Row(
                      children: [
                        IconButton(
                          key: const ValueKey('attach'),
                          tooltip: 'Attach a file',
                          onPressed: _attach,
                          icon: const Icon(Icons.add_rounded),
                        ),
                        Expanded(
                          child: TextField(
                            key: const ValueKey('composer'),
                            controller: _text,
                            minLines: 1,
                            maxLines: 5,
                            maxLength: 4000,
                            textCapitalization: TextCapitalization.sentences,
                            decoration: const InputDecoration(hintText: 'Message', counterText: ''),
                          ),
                        ),
                        IconButton.filled(
                          key: const ValueKey('send'),
                          tooltip: 'Send',
                          onPressed: _send,
                          icon: const Icon(Icons.send_rounded),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}
