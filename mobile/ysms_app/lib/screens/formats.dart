// Formats: the approved survey formats (app_api.php?a=formats — the same files and order as
// formats_download.php), each opened on the phone after download. New ones of the last 7 days first.
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

class FormatsScreen extends StatefulWidget {
  const FormatsScreen({super.key});
  @override
  State<FormatsScreen> createState() => _FormatsScreenState();
}

class _FormatsScreenState extends State<FormatsScreen> {
  List<Map<String, dynamic>>? _items;
  Object? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final d = await Api.i.get('formats');
      final list = ((d['items'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
      if (mounted) {
        setState(() {
          _items = list;
          _error = null;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = e);
    }
  }

  void _open(Map<String, dynamic> f) {
    final path = s(f['path']);
    final ext = path.contains('.') ? path.substring(path.lastIndexOf('.')) : '';
    downloadAndOpen(context, path, name: '${s(f['name'])}$ext');
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    Widget body;
    if (_error != null && items == null) {
      body = ListView(
        children: [ErrorState(error: _error!, onRetry: _load)],
      );
    } else if (items == null) {
      body = const CardSkeletons();
    } else if (items.isEmpty) {
      body = ListView(
        children: const [EmptyState(icon: Icons.folder_outlined, color: green, text: 'No formats uploaded yet')],
      );
    } else {
      body = ListView.separated(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        itemCount: items.length + 1,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          if (i == 0) {
            return Padding(
              padding: const EdgeInsets.fromLTRB(4, 0, 4, 2),
              child: Text(
                '${items.length} approved format${items.length == 1 ? '' : 's'} · tap one to open it',
                style: TextStyle(color: mutedText(context), fontWeight: FontWeight.w600),
              ),
            );
          }
          final f = items[i - 1];
          return Box(
            key: ValueKey('format-${s(f['path'])}'),
            onTap: () => _open(f),
            padding: const EdgeInsets.all(14),
            child: Row(
              children: [
                const IconBadge(Icons.table_chart_outlined, green),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(s(f['name']), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14.5)),
                      if (s(f['updated']).isNotEmpty) ...[
                        const SizedBox(height: 3),
                        Text('Updated ${s(f['updated'])}', style: TextStyle(color: mutedText(context), fontSize: 12.5)),
                      ],
                    ],
                  ),
                ),
                if (f['recent'] == true) ...[const Tag('New', green), const SizedBox(width: 8)],
                Icon(Icons.download_rounded, color: mutedText(context)),
              ],
            ),
          );
        },
      );
    }
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(title: const Text('Formats')),
      body: RefreshIndicator(onRefresh: _load, child: body),
    );
  }
}
