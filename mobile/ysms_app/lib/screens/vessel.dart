// Vessel details (app_api.php?a=survey): the facts of vessel_detail / report_detail / completed_detail.
// Latest update is saved through vessel_detail.php's own form; uploading, editing (assign) and the
// tools (agent email, recovery, replace a document, remarks: vessel_tools.dart) are Flutter screens; the
// report generators open the website's own pages.
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api.dart';
import '../ui.dart';
import 'upload.dart';
import 'vessel_tools.dart';

class VesselScreen extends StatefulWidget {
  const VesselScreen({super.key, required this.id, this.first});
  final int id;
  final Map<String, dynamic>? first; // the list card, shown while the details load
  @override
  State<VesselScreen> createState() => _VesselScreenState();
}

class _VesselScreenState extends State<VesselScreen> {
  Map<String, dynamic>? _v;
  Object? _error;
  final _scroll = ScrollController();
  bool _collapsed = false;

  Map<String, dynamic> get me => Api.i.me ?? {};

  @override
  void initState() {
    super.initState();
    _scroll.addListener(() {
      final c = _scroll.hasClients && _scroll.offset > 170;
      if (c != _collapsed) setState(() => _collapsed = c);
    });
    _load();
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final v = await Api.i.get('survey', {'id': widget.id});
      Pics.take([v]);
      if (mounted) {
        setState(() {
          _v = v;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  Object? _sureRemark;

  /// The detail page of this vessel (vessel_detail / report_detail / completed_detail.php?id=), whose
  /// forms the tools post to.
  String get _page => s(_v?['web']).isEmpty ? 'vessel_detail.php?id=${widget.id}' : s(_v!['web']);

  Future<void> _tool(Future<bool> f) async {
    if (await f) _load();
  }

  Future<void> _web(String route) async {
    await openAppRoute(context, route);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final v = _v ?? widget.first;
    final role = s(me['role']);
    final can = (me['can'] as Map?) ?? {};
    final st = s(v?['status']);
    final menu = <(IconData, String, VoidCallback)>[
      if (role == 'Admin') (Icons.edit_outlined, 'Edit vessel', () => _web('/e/${widget.id}')),
      if (st == 'Pending Report' && can['report_generator'] == true)
        (Icons.description_outlined, 'Word report generator', () => openWeb(context, 'report_generator.php?id=${widget.id}')),
      if (st == 'Pending Report' && (role == 'Admin' || role == 'Surveyor'))
        (Icons.photo_camera_outlined, 'Photo report generator', () => openWeb(context, 'photo_report_generator.php?id=${widget.id}')),
      if (role == 'Admin' && (st == 'Pending Report' || st == 'Completed'))
        (Icons.receipt_long_outlined, 'Invoice generator', () => openWeb(context, 'invoice_generator.php?id=${widget.id}')),
      if (st == 'Completed' && role == 'Surveyor')
        (Icons.payments_outlined, 'Generate expenses', () => openWeb(context, 'coming_soon.php?feature=Generate+Expenses')),
    ];
    final full = _v;
    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        child: CustomScrollView(
          controller: _scroll,
          slivers: [
            SliverAppBar(
              pinned: true,
              expandedHeight: 250,
              backgroundColor: navy,
              foregroundColor: Colors.white,
              systemOverlayStyle: SystemUiOverlayStyle.light,
              title: AnimatedOpacity(
                opacity: _collapsed ? 1 : 0,
                duration: const Duration(milliseconds: 180),
                child: Text(
                  s(v?['vessel']),
                  style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800),
                ),
              ),
              actions: [
                if (menu.isNotEmpty)
                  PopupMenuButton<int>(
                    tooltip: 'More actions',
                    icon: const Icon(Icons.more_vert_rounded),
                    onSelected: (i) => menu[i].$3(),
                    itemBuilder: (_) => [
                      for (var i = 0; i < menu.length; i++)
                        PopupMenuItem(
                          value: i,
                          child: ListTile(leading: Icon(menu[i].$1), title: Text(menu[i].$2), contentPadding: EdgeInsets.zero),
                        ),
                    ],
                  ),
              ],
              flexibleSpace: FlexibleSpaceBar(
                background: Stack(
                  fit: StackFit.expand,
                  children: [
                    if (v != null) VesselPic(v: v, hero: true) else Image.asset('assets/images/vessel-hero.jpg', fit: BoxFit.cover),
                    const DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [Color(0x99000000), Color(0x00000000), Color(0xB30B1E46)],
                          stops: [0, .45, 1],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            SliverToBoxAdapter(
              child: _error != null && full == null
                  ? ErrorState(error: _error!, onRetry: _load)
                  : Padding(
                      padding: const EdgeInsets.fromLTRB(16, 18, 16, 120),
                      child: v == null ? const CardSkeletons(count: 2) : _body(context, v, full),
                    ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: full != null && full['can_upload'] == true
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(
                    minimumSize: const Size.fromHeight(54),
                    backgroundColor: isDark(context) ? blue : navy,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  ),
                  onPressed: () async {
                    final done = await Navigator.of(context).push<bool>(
                      MaterialPageRoute(
                        builder: (_) => UploadScreen(id: widget.id, first: full),
                      ),
                    );
                    if (done == true) _load();
                  },
                  icon: const Icon(Icons.upload_rounded),
                  label: Text(
                    (full['upload'] as Map?)?['kind'] == 'report' ? 'Upload final report' : 'Upload report',
                    style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
            )
          : null,
    );
  }

  Widget _body(BuildContext context, Map<String, dynamic> v, Map<String, dynamic>? full) {
    final st = s(v['status']);
    final over = v['overdue'] as Map?;
    final rows = <(String, String)>[
      ('Appointment', s(v['appointment'])),
      ('Survey type', s(v['type'])),
      ('Surveyor', s(v['surveyors'])),
      ('Agent', s(v['agent'])),
      ('Report no.', s(v['report_no'])),
      ('IMO', s(v['imo'])),
      ('Assigned on', s(v['assigned_on'])),
      ('Survey completed', s(v['completed_on'])),
      ('Report completed', s(v['report_on'])),
    ].where((r) => r.$2.isNotEmpty).toList();
    final share = full?['share'] as Map?;
    final rec = full?['recovery'] as Map?;
    final docs = ((full?['docs'] as List?) ?? []).cast<Map>();
    final remarks = full?['remarks_list'] as Map?;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Expanded(
              child: Text(
                s(v['vessel']),
                style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: -.4, height: 1.15),
              ),
            ),
            const SizedBox(width: 8),
            Padding(padding: const EdgeInsets.only(top: 4), child: Tag(st, statusColor(st))),
          ],
        ),
        const SizedBox(height: 4),
        Text(
          [s(v['client']), s(v['port'])].where((x) => x.isNotEmpty).join(' · '),
          style: TextStyle(color: mutedText(context), fontSize: 14),
        ),
        if (share != null) ...[
          const SizedBox(height: 14),
          Row(
            children: [
              Expanded(
                child: FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: const Color(0xFF25D366), minimumSize: const Size.fromHeight(46)),
                  onPressed: () => openExternal(s(share['whatsapp'])),
                  icon: const Icon(Icons.chat_rounded, size: 20),
                  label: const Text('WhatsApp'),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: OutlinedButton.icon(
                  style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(46)),
                  onPressed: () => openExternal(
                    Uri(
                      scheme: 'mailto',
                      query: 'subject=${Uri.encodeComponent(s(share['subject']))}&body=${Uri.encodeComponent(s(share['body']))}',
                    ).toString(),
                  ),
                  icon: const Icon(Icons.mail_outline_rounded, size: 20),
                  label: const Text('Email'),
                ),
              ),
            ],
          ),
        ],
        if (over != null) ...[
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: (over['kind'] == 'survey' ? orange : red).withValues(alpha: .1),
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: (over['kind'] == 'survey' ? orange : red).withValues(alpha: .35)),
            ),
            child: Row(
              children: [
                Icon(Icons.warning_amber_rounded, color: over['kind'] == 'survey' ? orange : red),
                const SizedBox(width: 10),
                Expanded(
                  child: Text.rich(
                    TextSpan(
                      children: [
                        TextSpan(
                          text: over['kind'] == 'survey' ? 'Survey pending. ' : 'Report overdue. ',
                          style: const TextStyle(fontWeight: FontWeight.w800),
                        ),
                        TextSpan(
                          text: '${over['days']} days since the ${over['kind'] == 'survey' ? 'appointment date' : 'survey was completed'}.',
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
        const SizedBox(height: 14),
        Box(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                children: [
                  const Expanded(
                    child: Text('Details', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                  ),
                  if (s(me['role']) == 'Admin' && full != null)
                    TextButton.icon(
                      onPressed: () => _web('/e/${widget.id}'),
                      icon: const Icon(Icons.edit_outlined, size: 18),
                      label: const Text('Edit'),
                    ),
                ],
              ),
              for (final r in rows) _kv(context, r.$1, r.$2),
              if (s(v['remarks']).isNotEmpty) _kv(context, 'Admin remarks', s(v['remarks']), labelColor: orange),
              if (full?['agent_email'] is Map)
                _kv(
                  context,
                  'Agent update',
                  s((full!['agent_email'] as Map)['sent']).isEmpty ? 'Not sent yet' : 'Sent ${(full['agent_email'] as Map)['sent']}',
                  action: TextButton(
                    key: const ValueKey('agent-mail'),
                    onPressed: () => _tool(agentMailSheet(context, widget.id)),
                    child: Text(s((full['agent_email'] as Map)['sent']).isEmpty ? 'Send email' : 'Send again'),
                  ),
                ),
              if (full == null) const Padding(padding: EdgeInsets.symmetric(vertical: 10), child: Skeleton(height: 60)),
            ],
          ),
        ),
        if (rec != null) ...[
          const SizedBox(height: 12),
          Box(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Expanded(
                      child: Text('Recovery (MT)', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                    ),
                    if (rec['can_edit'] == true)
                      TextButton(
                        key: const ValueKey('rec-edit'),
                        onPressed: () => _tool(recoverySheet(context, _page, rec)),
                        child: const Text('Edit'),
                      ),
                  ],
                ),
                const SizedBox(height: 8),
                if (rec['na'] == true)
                  Text('Not applicable for this survey type.', style: TextStyle(color: mutedText(context)))
                else
                  Row(
                    children: [
                      for (final k in [('Total', 'total'), ('VLSFO', 'vlsfo'), ('LSMGO', 'lsmgo'), ('HSFO', 'hsfo')])
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                k.$1,
                                style: TextStyle(fontSize: 11.5, color: mutedText(context), fontWeight: FontWeight.w700),
                              ),
                              const SizedBox(height: 2),
                              FittedBox(
                                fit: BoxFit.scaleDown,
                                alignment: Alignment.centerLeft,
                                child: Text(
                                  s(rec[k.$2]).isEmpty ? '—' : s(rec[k.$2]),
                                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: k.$2 == 'total' ? blue : null),
                                ),
                              ),
                            ],
                          ),
                        ),
                    ],
                  ),
              ],
            ),
          ),
        ],
        if (full != null) ...[
          const SizedBox(height: 12),
          Box(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Expanded(
                      child: Text('Latest update', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                    ),
                    if (full['can_update'] == true)
                      TextButton(onPressed: () => _updateSheet(full), child: Text(s(full['update']).isEmpty ? 'Add' : 'Edit')),
                  ],
                ),
                const SizedBox(height: 4),
                if (s(full['update']).isEmpty)
                  Text('No updates recorded yet.', style: TextStyle(color: mutedText(context)))
                else ...[
                  Text(s(full['update']), style: const TextStyle(fontSize: 14.5)),
                  const SizedBox(height: 4),
                  Text(
                    s(full['update_when']) + (s(full['update_by']).isNotEmpty ? ' · by ${s(full['update_by'])}' : ''),
                    style: TextStyle(fontSize: 12, color: mutedText(context)),
                  ),
                ],
              ],
            ),
          ),
          const SizedBox(height: 12),
          Box(
            padding: const EdgeInsets.fromLTRB(16, 14, 8, 8),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Expanded(
                      child: Text('Documents', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
                    ),
                    Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: Text(
                        '${docs.length}',
                        style: TextStyle(color: mutedText(context), fontWeight: FontWeight.w700),
                      ),
                    ),
                  ],
                ),
                if (docs.isEmpty)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: Text('No documents uploaded yet.', style: TextStyle(color: mutedText(context))),
                  ),
                for (final d in docs)
                  ListTile(
                    contentPadding: const EdgeInsets.only(right: 4),
                    leading: FileBadge(name: s(d['name'])),
                    title: Text(
                      s(d['name']),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                    ),
                    subtitle: Text([s(d['type']), s(d['when'])].where((x) => x.isNotEmpty).join(' · ')),
                    trailing: full['can_replace'] == true
                        ? IconButton(
                            key: ValueKey('replace-${d['id']}'),
                            tooltip: 'Replace ${s(d['name'])}',
                            onPressed: () => _tool(replaceDocument(context, d['id'])),
                            icon: const Icon(Icons.sync_rounded),
                          )
                        : const Icon(Icons.download_rounded),
                    onTap: () => downloadAndOpen(context, 'download.php?upload_id=${d['id']}', name: s(d['name'])),
                  ),
              ],
            ),
          ),
          if (remarks != null) ...[const SizedBox(height: 12), _remarks(context, remarks)],
          if (full['can_history'] == true) ...[
            const SizedBox(height: 12),
            Box(
              onTap: () => _web('/h/${widget.id}'),
              child: Row(
                children: [
                  const IconBadge(Icons.history_rounded, purple),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Previous surveys of this vessel', style: TextStyle(fontWeight: FontWeight.w700)),
                        Text('Documents and recovery', style: TextStyle(fontSize: 12.5, color: mutedText(context))),
                      ],
                    ),
                  ),
                  const Icon(Icons.chevron_right_rounded),
                ],
              ),
            ),
          ],
        ],
      ],
    );
  }

  Widget _remarks(BuildContext context, Map r) {
    final items = ((r['items'] as List?) ?? []).cast<Map>();
    return Box(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              const Expanded(
                child: Text('Remarks', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
              ),
              if (r['can_add'] == true)
                TextButton(
                  key: const ValueKey('rm-add'),
                  onPressed: () => _tool(addRemarkSheet(context, _page)),
                  child: const Text('Add remark'),
                ),
            ],
          ),
          if (items.isEmpty) Text('No remarks yet.', style: TextStyle(color: mutedText(context))),
          for (final m in items)
            Padding(
              padding: const EdgeInsets.only(top: 10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(s(m['by']), style: const TextStyle(fontWeight: FontWeight.w700)),
                            Text(s(m['when']), style: TextStyle(fontSize: 12, color: mutedText(context))),
                          ],
                        ),
                      ),
                      if (r['can_delete'] == true)
                        TextButton(
                          key: ValueKey('rm-del-${m['id']}'),
                          style: TextButton.styleFrom(foregroundColor: red),
                          onPressed: () async {
                            if (_sureRemark != m['id']) {
                              setState(() => _sureRemark = m['id']);
                              return;
                            }
                            setState(() => _sureRemark = null);
                            await _tool(deleteRemark(context, _page, m['id']));
                          },
                          child: Text(_sureRemark == m['id'] ? 'Tap to confirm' : 'Delete'),
                        ),
                    ],
                  ),
                  if (s(m['text']).isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(s(m['text']))),
                  for (final f in ((m['files'] as List?) ?? []).cast<Map>())
                    TextButton.icon(
                      style: TextButton.styleFrom(padding: EdgeInsets.zero, alignment: Alignment.centerLeft),
                      onPressed: () => downloadAndOpen(context, 'download.php?remark_attachment_id=${f['id']}', name: s(f['name'])),
                      icon: const Icon(Icons.attach_file_rounded, size: 18),
                      label: Text(s(f['name']), overflow: TextOverflow.ellipsis),
                    ),
                ],
              ),
            ),
        ],
      ),
    );
  }

  Widget _kv(BuildContext context, String k, String val, {Color? labelColor, Widget? action}) => Container(
    padding: const EdgeInsets.symmetric(vertical: 10),
    decoration: BoxDecoration(
      border: Border(top: BorderSide(color: lineColor(context))),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          width: 128,
          child: Text(
            k,
            style: TextStyle(
              color: labelColor ?? mutedText(context),
              fontSize: 13.5,
              fontWeight: labelColor != null ? FontWeight.w700 : null,
            ),
          ),
        ),
        Expanded(
          child: Text(val, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
        ),
        ?action,
      ],
    ),
  );

  Future<void> _updateSheet(Map<String, dynamic> v) async {
    final ta = TextEditingController(text: s(v['update']));
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) {
        var busy = false;
        return StatefulBuilder(
          builder: (ctx, set) {
            return Padding(
              padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.of(ctx).viewInsets.bottom),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text('Latest update', style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 12),
                  TextField(
                    controller: ta,
                    autofocus: true,
                    maxLength: 1000,
                    minLines: 3,
                    maxLines: 6,
                    decoration: const InputDecoration(hintText: 'e.g. ETA 22nd 16:00 hours'),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                    onPressed: busy
                        ? null
                        : () async {
                            final text = ta.text.trim();
                            if (text.isEmpty) return;
                            set(() => busy = true);
                            // The same form vessel_detail.php shows, so its checks and notifications apply.
                            try {
                              final r = await Api.i.post(
                                'vessel_detail.php?id=${widget.id}',
                                (csrf) => {'csrf_token': csrf, 'latest_status': text, 'update_latest_status': '1'},
                              );
                              final ok = (r.statusCode ?? 500) < 400 && Api.i.text(r).contains('Latest update registered successfully');
                              if (!ok) throw ApiError(r.statusCode ?? 500, 'save');
                              if (ctx.mounted) Navigator.pop(ctx, true);
                            } catch (e) {
                              set(() => busy = false);
                              if (ctx.mounted) toast(ctx, e is ApiError && e.offline ? e.message : 'Could not save the update', bad: true);
                            }
                          },
                    child: busy
                        ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                        : const Text('Save update'),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
    ta.dispose();
    if (saved == true && mounted) {
      HapticFeedback.mediumImpact();
      toast(context, 'Latest update saved', ok: true);
      _load();
    }
  }
}

/// PDF / XLS / DOC badge for a file name.
class FileBadge extends StatelessWidget {
  const FileBadge({super.key, required this.name});
  final String name;
  @override
  Widget build(BuildContext context) {
    final n = name.toLowerCase();
    final ext = n.contains('.') ? n.split('.').last : 'file';
    final (String t, Color c) = ext == 'pdf'
        ? ('PDF', red)
        : RegExp(r'^(xlsx?|xlsm|csv)$').hasMatch(ext)
        ? ('XLS', green)
        : RegExp(r'^docx?$').hasMatch(ext)
        ? ('DOC', blue)
        : ((ext.length > 4 ? ext.substring(0, 4) : ext).toUpperCase(), muted);
    return Container(
      width: 42,
      height: 42,
      alignment: Alignment.center,
      decoration: BoxDecoration(color: c.withValues(alpha: .12), borderRadius: BorderRadius.circular(12)),
      child: Text(
        t,
        style: TextStyle(color: c, fontWeight: FontWeight.w800, fontSize: 11.5),
      ),
    );
  }
}
