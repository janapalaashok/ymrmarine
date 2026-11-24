// Survey Report: start a new report (the report types available — each one's empty form is kept on
// this phone, so a report can be started without internet), the reports opened on this phone (those
// started offline are marked and sent as soon as there is a connection) and opening a report by its
// link.
import 'package:flutter/material.dart';

import '../ui.dart';
import 'sr_editor.dart';
import 'sr_session.dart';
import 'sr_store.dart';

const _kindIcons = {
  'rob': Icons.inventory_2_outlined,
  'offhire': Icons.logout_rounded,
  'onhire': Icons.login_rounded,
  'offhire3': Icons.groups_outlined,
  'onhire3': Icons.groups_outlined,
  'offhire4': Icons.groups_2_outlined,
};

class SrHomeScreen extends StatefulWidget {
  const SrHomeScreen({super.key});
  @override
  State<SrHomeScreen> createState() => _SrHomeScreenState();
}

class _SrHomeScreenState extends State<SrHomeScreen> {
  Map<String, String>? _kinds;
  List<Map<String, dynamic>> _mine = [];
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final mine = await SrStore.i.index();
    if (mounted) setState(() => _mine = mine);
    final k = await SrStart.kinds();
    if (mounted) {
      setState(() {
        _kinds = k;
      });
    }
  }

  Future<void> _open(String id) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SrEditorScreen(id: id)));
    final mine = await SrStore.i.index();
    if (mounted) setState(() => _mine = mine);
  }

  Future<void> _new(String kind) async {
    setState(() => _busy = true);
    try {
      final id = await SrStart.create(kind);
      if (!mounted) return;
      if (id.startsWith('draft-')) {
        toast(context, 'Offline — the report is kept on this phone and sent when you are online.');
      }
      await _open(id);
    } catch (e) {
      if (mounted) toast(context, '$e', bad: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _byLink() async {
    final c = TextEditingController();
    final v = await showDialog<String>(
      context: context,
      builder: (d) => AlertDialog(
        title: const Text('Open a report'),
        content: TextField(
          key: const ValueKey('srh-link'),
          controller: c,
          autofocus: true,
          decoration: const InputDecoration(
            labelText: "The report's link",
            hintText: 'https://…/survey_report_public.php?t=…',
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(d), child: const Text('Cancel')),
          FilledButton(
            key: const ValueKey('srh-link-open'),
            onPressed: () => Navigator.pop(d, c.text),
            child: const Text('Open'),
          ),
        ],
      ),
    );
    c.dispose();
    if (v == null) return;
    final m = RegExp(r'(?:[?&]t=)?([0-9a-f]{64})').firstMatch(v.trim());
    if (m == null) {
      if (mounted) toast(context, 'This is not a survey report link.', bad: true);
      return;
    }
    await _open(m.group(1)!);
  }

  Future<void> _remove(Map<String, dynamic> e) async {
    final draft = '${e['id']}'.startsWith('draft-');
    final ok = await showDialog<bool>(
      context: context,
      builder: (c) => AlertDialog(
        title: Text(draft ? 'Delete this report?' : 'Remove from this phone?'),
        content: Text(
          draft
              ? 'It was started without internet and is not on the server yet — what was typed is lost.'
              : 'The report stays on the server; open it again with its link.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: red),
            onPressed: () => Navigator.pop(c, true),
            child: Text(draft ? 'Delete' : 'Remove'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final id = '${e['id']}';
    for (final k in ['get_$id', 'local_$id', 'step_$id', 'drafttoken_$id']) {
      await SrStore.i.write(k, null);
    }
    await SrStore.i.forget(id);
    final mine = await SrStore.i.index();
    if (mounted) setState(() => _mine = mine);
  }

  String _when(Object? at) {
    final d = DateTime.fromMillisecondsSinceEpoch((at as num?)?.toInt() ?? 0);
    const m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return '${d.day.toString().padLeft(2, '0')} ${m[d.month - 1]}, ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final k = _kinds;
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(
        title: const Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Survey Report'),
            Text('Bunker survey on site', style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500)),
          ],
        ),
        actions: [
          IconButton(
            key: const ValueKey('srh-open-link'),
            tooltip: 'Open a report link',
            onPressed: _byLink,
            icon: const Icon(Icons.link_rounded),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 32),
          children: [
            const SectionTitle('Start a new report'),
            if (k == null)
              const CardSkeletons(count: 2)
            else if (k.isEmpty)
              const EmptyState(
                icon: Icons.cloud_off_rounded,
                color: orange,
                text: 'Survey reports are not available right now (or open this once while online).',
              )
            else
              for (final e in k.entries)
                Box(
                  key: ValueKey('srh-new-${e.key}'),
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.all(14),
                  onTap: _busy ? null : () => _new(e.key),
                  child: Row(
                    children: [
                      IconBadge(_kindIcons[e.key] ?? Icons.description_outlined, blue),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(e.value, style: const TextStyle(fontWeight: FontWeight.w800)),
                            Text('New report', style: TextStyle(fontSize: 12.5, color: mutedText(context))),
                          ],
                        ),
                      ),
                      const Icon(Icons.chevron_right_rounded),
                    ],
                  ),
                ),
            if (_mine.isNotEmpty) ...[
              const SectionTitle('On this phone'),
              for (final e in _mine)
                Box(
                  key: ValueKey('srh-mine-${e['id']}'),
                  margin: const EdgeInsets.only(bottom: 10),
                  padding: const EdgeInsets.fromLTRB(14, 10, 4, 10),
                  onTap: () => _open('${e['id']}'),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('${e['vessel']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                            const SizedBox(height: 2),
                            Wrap(
                              spacing: 6,
                              crossAxisAlignment: WrapCrossAlignment.center,
                              children: [
                                Text(
                                  '${e['kind_label']} · ${_when(e['at'])}',
                                  style: TextStyle(fontSize: 12.5, color: mutedText(context)),
                                ),
                                if ('${e['id']}'.startsWith('draft-')) const Tag('Not sent yet', orange),
                              ],
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        key: ValueKey('srh-rm-${e['id']}'),
                        tooltip: 'Remove from this phone',
                        onPressed: () => _remove(e),
                        icon: const Icon(Icons.more_vert_rounded),
                      ),
                    ],
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}
