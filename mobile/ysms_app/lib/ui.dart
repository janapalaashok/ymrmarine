// Shared look: colours, tags, vessel cards and pictures, loading / empty states, helpers.
import 'dart:async';
import 'dart:convert' show utf8;
import 'dart:io' show File;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'api.dart';
import 'config.dart';
import 'screens/web.dart';

const navy = Color(0xFF0B1E46);
const navy2 = Color(0xFF163F86);
const blue = Color(0xFF1D5FE0);
const sky = Color(0xFF00A3DF);
const blueSoft = Color(0xFFDBE6FF);
const pageBg = Color(0xFFF5F7FC);
const green = Color(0xFF12B76A);
const orange = Color(0xFFF79009);
const red = Color(0xFFF04438);
const purple = Color(0xFF7A5AF8);
const muted = Color(0xFF667085);

bool isDark(BuildContext c) => Theme.of(c).brightness == Brightness.dark;
Color cardBg(BuildContext c) => isDark(c) ? const Color(0xFF162036) : Colors.white;
Color pageColor(BuildContext c) => isDark(c) ? const Color(0xFF0B1220) : pageBg;
Color mutedText(BuildContext c) => isDark(c) ? const Color(0xFF98A2B3) : muted;
Color lineColor(BuildContext c) => isDark(c) ? const Color(0xFF26324A) : const Color(0xFFE4E7EC);

ThemeData appTheme(Brightness b) {
  final dark = b == Brightness.dark;
  final scheme = ColorScheme.fromSeed(
    seedColor: blue,
    brightness: b,
    primary: dark ? const Color(0xFF7FA6FF) : blue,
    surface: dark ? const Color(0xFF0B1220) : pageBg,
  );
  return ThemeData(
    useMaterial3: true,
    colorScheme: scheme,
    scaffoldBackgroundColor: scheme.surface,
    appBarTheme: AppBarTheme(
      backgroundColor: scheme.surface,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      titleTextStyle: TextStyle(
        fontSize: 19,
        fontWeight: FontWeight.w800,
        color: dark ? Colors.white : const Color(0xFF101828),
        letterSpacing: -.2,
      ),
    ),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: dark ? const Color(0xFF111A2E) : Colors.white,
      indicatorColor: dark ? const Color(0xFF223A6E) : blueSoft,
      labelTextStyle: WidgetStateProperty.resolveWith(
        (s) => TextStyle(fontSize: 12, fontWeight: s.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500),
      ),
      height: 68,
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: dark ? const Color(0xFF162036) : Colors.white,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: dark ? const Color(0xFF2B3954) : const Color(0xFFD0D5DD), width: 1.5),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: BorderSide(color: scheme.primary, width: 2),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ),
  );
}

/// Indian digit grouping with 3 decimals, as the website shows MT.
String fmtMt(dynamic v, [int dec = 3]) {
  final n = v is num ? v.toDouble() : double.tryParse('$v') ?? 0;
  final s = n.abs().toStringAsFixed(dec);
  final p = s.split('.');
  var i = p[0];
  if (i.length > 3) {
    final last = i.substring(i.length - 3);
    var rest = i.substring(0, i.length - 3);
    final g = <String>[];
    while (rest.length > 2) {
      g.insert(0, rest.substring(rest.length - 2));
      rest = rest.substring(0, rest.length - 2);
    }
    if (rest.isNotEmpty) g.insert(0, rest);
    i = '${g.join(',')},$last';
  }
  return '${n < 0 ? '-' : ''}$i${dec > 0 ? '.${p[1]}' : ''}';
}

String s(dynamic v) => v == null ? '' : '$v';

void toast(BuildContext context, String msg, {bool ok = false, bool bad = false}) {
  final m = ScaffoldMessenger.maybeOf(context);
  if (m == null) return;
  m.hideCurrentSnackBar();
  m.showSnackBar(
    SnackBar(
      content: Row(
        children: [
          if (ok) const Icon(Icons.check_circle_rounded, color: Color(0xFF6CE9A6), size: 20),
          if (bad) const Icon(Icons.error_rounded, color: Color(0xFFFDA29B), size: 20),
          if (ok || bad) const SizedBox(width: 10),
          Expanded(child: Text(msg)),
        ],
      ),
      duration: Duration(milliseconds: bad ? 4000 : 2600),
    ),
  );
  if (bad) HapticFeedback.heavyImpact();
}

/// A website page inside this app, e.g. 'report_generator.php?id=12' — or its Flutter screen when it
/// has one ([nativePageFor]).
Future<void> openWeb(BuildContext context, String path, {String? title}) async {
  final w = nativePageFor?.call(path);
  await Navigator.of(context).push(MaterialPageRoute(builder: (_) => w ?? WebScreen(path: path, title: title)));
}

/// Flutter screens for website pages (set in main.dart), e.g. 'generate_permission_copy.php'; null: the
/// page itself opens inside the app.
Widget? Function(String page)? nativePageFor;

/// Flutter screens for routes of the web app (set in main.dart), e.g. '/formats'; null: no Flutter screen yet.
Widget? Function(String route)? nativeScreenFor;

/// The screen for a route of the web app, e.g. '/chat', '/v/12': its Flutter screen when there is one,
/// else the web app's own screen (assets/app/app.js) inside the app.
Future<void> openAppRoute(BuildContext context, String route, {String? title}) async {
  final w = nativeScreenFor?.call(route);
  if (w != null) {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => w));
    return;
  }
  if (!context.mounted) return;
  await openWeb(context, 'app.php#$route', title: title);
}

Future<void> openExternal(String url) async {
  final u = Uri.parse(url);
  await launchUrl(u, mode: LaunchMode.externalApplication);
}

/// Download a file of the server with the sign-in (documents, Excel exports) and open it. With [form],
/// the file is the answer to that form posted to [path] (e.g. a generated Word file); when the page
/// answers with HTML instead, its own message is shown.
Future<void> downloadAndOpen(BuildContext context, String path, {String? name, Map<String, String>? form}) async {
  if (kIsWeb && form == null) {
    await launchUrl(Uri.parse(Cfg.url(path)), webOnlyWindowName: '_blank');
    return;
  }
  final messenger = ScaffoldMessenger.maybeOf(context);
  messenger?.hideCurrentSnackBar();
  messenger?.showSnackBar(
    const SnackBar(
      content: Row(
        children: [
          SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white)),
          SizedBox(width: 12),
          Text('Downloading…'),
        ],
      ),
      duration: Duration(minutes: 5),
    ),
  );
  try {
    final r = form == null
        ? await Api.i.dio.get<List<int>>(path, options: Options(responseType: ResponseType.bytes))
        : await Api.i.dio.post<List<int>>(
            path,
            data: {...form, 'csrf_token': Api.i.csrf},
            options: Options(responseType: ResponseType.bytes, contentType: Headers.formUrlEncodedContentType),
          );
    final code = r.statusCode ?? 0;
    final type = r.headers.value('content-type') ?? '';
    if (form != null && type.contains('text/html') && code < 400 && r.data != null) {
      final msg = pageResult(utf8.decode(r.data!, allowMalformed: true)).message;
      throw ApiError(code, msg.isNotEmpty ? msg : 'Could not generate the file.');
    }
    if (code == 401 || code == 403 || code >= 400 || r.data == null) {
      throw ApiError(code, code == 404 ? 'This file was not found on the server.' : 'Could not download this file.');
    }
    if (type.contains('text/html')) throw ApiError(code, 'Could not download this file (please sign in again).');
    var fname = name ?? '';
    final cd = r.headers.value('content-disposition') ?? '';
    final m = RegExp(r'''filename\*?=(?:UTF-8'')?"?([^";]+)"?''', caseSensitive: false).firstMatch(cd);
    if (m != null) fname = Uri.decodeComponent(m.group(1)!.trim());
    if (fname.isEmpty) fname = 'file';
    fname = fname.replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
    final dir = await getTemporaryDirectory();
    final f = File('${dir.path}/ysms_${DateTime.now().millisecondsSinceEpoch}_$fname');
    await f.writeAsBytes(r.data!, flush: true);
    messenger?.hideCurrentSnackBar();
    final res = await OpenFilex.open(f.path);
    if (res.type != ResultType.done && context.mounted) {
      toast(
        context,
        res.type == ResultType.noAppToOpen ? 'No app on this phone opens this kind of file.' : 'Could not open the file.',
        bad: true,
      );
    }
  } on ApiError catch (e) {
    messenger?.hideCurrentSnackBar();
    if (context.mounted) toast(context, e.message, bad: true);
  } catch (_) {
    messenger?.hideCurrentSnackBar();
    if (context.mounted) toast(context, 'Could not download this file.', bad: true);
  }
}

class Tag extends StatelessWidget {
  const Tag(this.text, this.color, {super.key});
  final String text;
  final Color color;
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: isDark(context) ? .22 : .12),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        text,
        maxLines: 1,
        overflow: TextOverflow.ellipsis,
        style: TextStyle(
          fontSize: 11.5,
          fontWeight: FontWeight.w700,
          color: isDark(context) ? Color.lerp(color, Colors.white, .35) : _deep(color),
        ),
      ),
    );
  }
}

Color _deep(Color c) => Color.lerp(c, Colors.black, .28)!;

Color statusColor(String st) => switch (st) {
  'Pending Vessel' => blue,
  'Pending Report' => orange,
  'Completed' => green,
  'Cancelled' => red,
  _ => blue,
};

/// Card container.
class Box extends StatelessWidget {
  const Box({super.key, required this.child, this.padding = const EdgeInsets.all(16), this.margin, this.onTap, this.color});
  final Widget child;
  final EdgeInsets padding;
  final EdgeInsets? margin;
  final VoidCallback? onTap;
  final Color? color;
  @override
  Widget build(BuildContext context) {
    final r = BorderRadius.circular(18);
    return Container(
      margin: margin,
      decoration: BoxDecoration(
        color: color ?? cardBg(context),
        borderRadius: r,
        border: Border.all(color: lineColor(context)),
        boxShadow: isDark(context) ? null : const [BoxShadow(color: Color(0x0A101828), blurRadius: 12, offset: Offset(0, 4))],
      ),
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          borderRadius: r,
          onTap: onTap,
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.text, {super.key, this.trailing});
  final String text;
  final Widget? trailing;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(4, 20, 4, 10),
    child: Row(
      children: [
        Expanded(
          child: Text(text, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, letterSpacing: -.2)),
        ),
        ?trailing,
      ],
    ),
  );
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.text, this.color = blue, this.action});
  final IconData icon;
  final String text;
  final Color color;
  final Widget? action;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 48, horizontal: 24),
    child: Column(
      children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(20)),
          child: Icon(icon, color: color, size: 30),
        ),
        const SizedBox(height: 14),
        Text(
          text,
          textAlign: TextAlign.center,
          style: TextStyle(fontWeight: FontWeight.w700, color: mutedText(context)),
        ),
        if (action != null) ...[const SizedBox(height: 14), action!],
      ],
    ),
  );
}

/// Error with a Retry button.
class ErrorState extends StatelessWidget {
  const ErrorState({super.key, required this.error, required this.onRetry});
  final Object error;
  final VoidCallback onRetry;
  @override
  Widget build(BuildContext context) {
    final e = error;
    final offline = e is ApiError && e.offline;
    return EmptyState(
      icon: offline ? Icons.wifi_off_rounded : Icons.error_outline_rounded,
      color: red,
      text: e is ApiError
          ? (e.status == 404 ? 'This is not available to you.' : (offline ? e.message : 'Could not load. ${e.message}'))
          : 'Could not load.',
      action: OutlinedButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh_rounded), label: const Text('Try again')),
    );
  }
}

class Skeleton extends StatefulWidget {
  const Skeleton({super.key, this.height = 14, this.width, this.radius = 8});
  final double height;
  final double? width;
  final double radius;
  @override
  State<Skeleton> createState() => _SkeletonState();
}

class _SkeletonState extends State<Skeleton> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100))..repeat(reverse: true);
  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final a = isDark(context) ? const Color(0xFF1E2A42) : const Color(0xFFEAEEF5);
    final b = isDark(context) ? const Color(0xFF26344F) : const Color(0xFFF4F6FA);
    return AnimatedBuilder(
      animation: _c,
      builder: (_, _) => Container(
        height: widget.height,
        width: widget.width,
        decoration: BoxDecoration(color: Color.lerp(a, b, _c.value), borderRadius: BorderRadius.circular(widget.radius)),
      ),
    );
  }
}

class CardSkeletons extends StatelessWidget {
  const CardSkeletons({super.key, this.count = 4});
  final int count;
  @override
  Widget build(BuildContext context) => Column(
    children: List.generate(
      count,
      (_) => const Box(
        margin: EdgeInsets.only(bottom: 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Skeleton(height: 56, width: 56, radius: 14),
                SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [Skeleton(height: 15, width: 170), SizedBox(height: 8), Skeleton(height: 12, width: 110)],
                  ),
                ),
              ],
            ),
            SizedBox(height: 14),
            Skeleton(height: 12, width: 200),
          ],
        ),
      ),
    ),
  );
}

/// Vessel pictures found by the AI from the vessel name (ajax/vessel_image.php), the same as the website.
/// The list / detail data says which are found (img), not looked up yet (pending / unknown) or missing.
class Pics {
  Pics._();
  static final found = ValueNotifier<Map<String, String>>({});
  static final _state = <String, String>{};
  static bool _busy = false;
  static int _tries = 0;

  static void take(Iterable<Map<String, dynamic>> items) {
    var changed = false;
    final f = Map<String, String>.from(found.value);
    for (final it in items) {
      final k = s(it['img_k']);
      if (k.isEmpty) continue;
      final st = s(it['img_st']);
      if (!_state.containsKey(k) || st == 'found') _state[k] = st;
      if (it['img'] != null && f[k] != s(it['img'])) {
        f[k] = s(it['img']);
        changed = true;
      }
    }
    if (changed) found.value = f;
    _tries = 0;
    lookup();
  }

  /// Ask the server to look up pictures not looked up yet (a few rounds, as the website does).
  static Future<void> lookup() async {
    if (_busy || _tries >= 6 || Api.i.me == null) return;
    final keys = _state.entries.where((e) => e.value == 'pending' || e.value == 'unknown').map((e) => e.key).take(40).toList();
    if (keys.isEmpty) return;
    _busy = true;
    _tries++;
    try {
      final r = await Api.i.post(
        'ajax/vessel_image.php',
        (csrf) =>
            FormData()
              ..fields.addAll([const MapEntry('a', 'lookup'), MapEntry('csrf_token', csrf), for (final k in keys) MapEntry('names[]', k)]),
        csrfHeader: true,
      );
      final j = Api.i.json(r);
      _busy = false;
      if (j == null || j['ok'] != true) return;
      final states = j['states'];
      final f = Map<String, String>.from(found.value);
      if (states is Map) {
        states.forEach((k, v) {
          if (v is! Map) return;
          _state['$k'] = s(v['status']);
          if (v['status'] == 'found' && v['img'] != null) f['$k'] = s(v['img']);
        });
      }
      found.value = f;
      if (j['more'] == true) {
        Timer(Duration(milliseconds: j['busy'] == true ? 8000 : 1500), lookup);
      }
    } catch (_) {
      _busy = false;
    }
  }
}

/// The vessel's picture, or the drawing until there is one.
class VesselPic extends StatelessWidget {
  const VesselPic({super.key, required this.v, this.hero = false, this.fit = BoxFit.cover});
  final Map<String, dynamic> v;
  final bool hero;
  final BoxFit fit;
  @override
  Widget build(BuildContext context) {
    final fallback = Image.asset(hero ? 'assets/images/vessel-hero.jpg' : 'assets/images/vessel-thumb.jpg', fit: fit);
    return ValueListenableBuilder<Map<String, String>>(
      valueListenable: Pics.found,
      builder: (_, found, _) {
        final path = found[s(v['img_k'])] ?? (v['img'] == null ? null : s(v['img']));
        if (path == null || path.isEmpty) return fallback;
        return Image.network(
          Cfg.url(path),
          headers: Api.i.imageHeaders,
          fit: fit,
          gaplessPlayback: true,
          frameBuilder: (_, child, frame, sync) => sync || frame != null ? child : fallback,
          errorBuilder: (_, _, _) => fallback,
        );
      },
    );
  }
}

/// One vessel in a list (the same facts as the website's cards).
class VesselCard extends StatelessWidget {
  const VesselCard({super.key, required this.v, required this.onTap});
  final Map<String, dynamic> v;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) {
    final over = v['overdue'] is Map ? v['overdue'] as Map : null;
    final st = s(v['status']);
    final when = st == 'Completed'
        ? (s(v['report_on']).isNotEmpty ? s(v['report_on']) : s(v['completed_on']))
        : st == 'Pending Report'
        ? s(v['completed_on'])
        : s(v['appointment']);
    final whenLabel = st == 'Completed' ? 'Report' : (st == 'Pending Report' ? 'Surveyed' : 'Appointment');
    final edge = over == null ? null : (over['kind'] == 'report' ? red : orange);
    return Box(
      margin: const EdgeInsets.only(bottom: 12),
      padding: EdgeInsets.zero,
      onTap: onTap,
      child: Container(
        decoration: edge == null
            ? null
            : BoxDecoration(
                border: Border(left: BorderSide(color: edge, width: 4)),
                borderRadius: BorderRadius.circular(18),
              ),
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(14),
                  child: SizedBox(width: 56, height: 56, child: VesselPic(v: v)),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        s(v['vessel']),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, letterSpacing: -.2),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        s(v['client']),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(fontSize: 13, color: mutedText(context)),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Flexible(
                  flex: 0,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 120),
                    child: over != null
                        ? Tag(over['kind'] == 'report' ? 'Report overdue' : 'Overdue', over['kind'] == 'report' ? red : orange)
                        : Tag(s(v['type']).isNotEmpty ? s(v['type']) : st, st == 'Completed' ? green : blue),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 14,
              runSpacing: 6,
              children: [
                _meta(context, Icons.place_outlined, s(v['port']).isEmpty ? '—' : s(v['port'])),
                if (s(v['surveyors']).isNotEmpty) _meta(context, Icons.person_outline_rounded, s(v['surveyors'])),
                if (when.isNotEmpty) _meta(context, Icons.event_outlined, '$whenLabel $when'),
              ],
            ),
            if (s(v['update']).isNotEmpty) ...[
              const SizedBox(height: 10),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                decoration: BoxDecoration(
                  color: isDark(context) ? const Color(0xFF1B2740) : const Color(0xFFF2F6FF),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text.rich(
                  TextSpan(
                    children: [
                      TextSpan(
                        text: 'Latest update · ',
                        style: TextStyle(color: mutedText(context), fontWeight: FontWeight.w600),
                      ),
                      TextSpan(text: s(v['update'])),
                    ],
                  ),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 12.5),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _meta(BuildContext c, IconData i, String t) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(i, size: 15, color: mutedText(c)),
      const SizedBox(width: 4),
      ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 220),
        child: Text(
          t,
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(fontSize: 12.5, color: mutedText(c)),
        ),
      ),
    ],
  );
}

/// Round avatar: the user's picture or initials.
class Avatar extends StatelessWidget {
  const Avatar({super.key, required this.me, this.size = 44, this.light = false});
  final Map<String, dynamic> me;
  final double size;
  final bool light;
  @override
  Widget build(BuildContext context) {
    final av = s(me['avatar']);
    return Container(
      width: size,
      height: size,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        color: light ? Colors.white.withValues(alpha: .16) : blueSoft,
        border: Border.all(color: light ? Colors.white.withValues(alpha: .35) : Colors.white, width: 2),
      ),
      alignment: Alignment.center,
      child: av.isNotEmpty
          ? Image.network(
              Cfg.url(av),
              headers: Api.i.imageHeaders,
              fit: BoxFit.cover,
              width: size,
              height: size,
              errorBuilder: (_, _, _) => _ini(),
            )
          : _ini(),
    );
  }

  Widget _ini() => Text(
    s(me['initials']),
    style: TextStyle(fontWeight: FontWeight.w800, fontSize: size * .36, color: light ? Colors.white : blue),
  );
}

/// Coloured round icon used in menus and tiles.
class IconBadge extends StatelessWidget {
  const IconBadge(this.icon, this.color, {super.key, this.size = 40});
  final IconData icon;
  final Color color;
  final double size;
  @override
  Widget build(BuildContext context) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(
      color: color.withValues(alpha: isDark(context) ? .22 : .12),
      borderRadius: BorderRadius.circular(size * .3),
    ),
    child: Icon(icon, color: isDark(context) ? Color.lerp(color, Colors.white, .3) : color, size: size * .52),
  );
}
