// Form boxes of the survey report: a text / number box, a date or time with its picker and "Now",
// a month list and the Survey Type list. Each box keeps its own controller in [SrBoxes], so typing is
// never interrupted when the report is redrawn, and a value changed elsewhere (a lookup, another
// device) shows as soon as the box is not being typed in.
import 'package:flutter/material.dart';

import '../ui.dart';
import 'sr_model.dart';

class SrBoxes {
  final _c = <String, TextEditingController>{};
  final _f = <String, FocusNode>{};

  TextEditingController ctl(String key, String value) {
    final c = _c.putIfAbsent(key, () => TextEditingController(text: value));
    final f = focus(key);
    if (!f.hasFocus && c.text != value) c.text = value;
    return c;
  }

  FocusNode focus(String key) => _f.putIfAbsent(key, FocusNode.new);

  void dispose() {
    for (final c in _c.values) {
      c.dispose();
    }
    for (final f in _f.values) {
      f.dispose();
    }
  }
}

/// The message under a box, or null.
typedef SrErr = String? Function(String key);

class SrFieldBox extends StatelessWidget {
  const SrFieldBox({
    super.key,
    required this.r,
    required this.k,
    required this.boxes,
    required this.onChanged,
    required this.err,
    this.label,
    this.req = false,
    this.enabled = true,
    this.highlight = false,
  });
  final SrReport r;
  final String k;
  final SrBoxes boxes;
  final void Function(String key, String value) onChanged;
  final SrErr err;
  final String? label;
  final bool req, enabled, highlight;

  @override
  Widget build(BuildContext context) {
    final f = r.field(k);
    if (f == null) return const SizedBox.shrink();
    final text = '${label ?? r.label(k)}${req ? ' *' : ''}';
    final e = err(k);
    final v = r.val(k);
    InputDecoration deco({Widget? suffix}) => InputDecoration(
      labelText: text,
      errorText: e,
      errorMaxLines: 3,
      suffixIcon: suffix,
      filled: highlight,
      fillColor: highlight ? orange.withValues(alpha: .10) : null,
      helperText: highlight ? 'Filled by Vessel lookup — please check' : null,
    );
    if (f.type == 'month') {
      return DropdownButtonFormField<String>(
        key: ValueKey('sr-f-$k'),
        initialValue: srMonths.contains(v) ? v : null,
        isExpanded: true,
        decoration: deco(),
        items: [for (final m in srMonths) DropdownMenuItem(value: m, child: Text(m))],
        onChanged: enabled ? (x) => onChanged(k, x ?? '') : null,
      );
    }
    if (k == 'survey_type') {
      final choices = Map<String, dynamic>.from((r.cert['choices'] as Map?) ?? const {});
      final cur = r.certType();
      return DropdownButtonFormField<String>(
        key: ValueKey('sr-f-$k'),
        initialValue: choices.containsKey(cur) ? cur : null,
        isExpanded: true,
        decoration: deco(),
        items: [for (final c in choices.entries) DropdownMenuItem(value: c.key, child: Text('${c.value}'))],
        onChanged: enabled ? (x) => onChanged(k, x ?? '') : null,
      );
    }
    final c = boxes.ctl('f:$k', v);
    if (f.type == 'date' || f.type == 'time') {
      final isDate = f.type == 'date';
      Future<void> pick() async {
        if (!enabled) return;
        if (isDate) {
          final now = DateTime.now();
          final d = await showDatePicker(
            context: context,
            initialDate: DateTime.tryParse(v) ?? now,
            firstDate: DateTime(now.year - 10),
            lastDate: DateTime(now.year + 2),
          );
          if (d != null) onChanged(k, _ymd(d));
        } else {
          final p = v.split(':');
          final t = await showTimePicker(
            context: context,
            initialTime: p.length == 2
                ? TimeOfDay(hour: int.tryParse(p[0]) ?? 0, minute: int.tryParse(p[1]) ?? 0)
                : TimeOfDay.now(),
            builder: (c, w) => MediaQuery(data: MediaQuery.of(c).copyWith(alwaysUse24HourFormat: true), child: w!),
          );
          if (t != null) onChanged(k, _hm(t.hour, t.minute));
        }
      }

      return TextField(
        key: ValueKey('sr-f-$k'),
        controller: c,
        readOnly: true,
        enabled: enabled,
        onTap: pick,
        decoration: deco(suffix: Icon(isDate ? Icons.event_outlined : Icons.schedule_rounded)),
      );
    }
    final numeric = RegExp(r'^(num|int|imo)').hasMatch(f.type);
    return TextField(
      key: ValueKey('sr-f-$k'),
      controller: c,
      focusNode: boxes.focus('f:$k'),
      enabled: enabled,
      maxLength: 200,
      keyboardType: numeric ? const TextInputType.numberWithOptions(decimal: true, signed: true) : TextInputType.text,
      textCapitalization: numeric ? TextCapitalization.none : TextCapitalization.characters,
      decoration: deco().copyWith(counterText: ''),
      onChanged: (x) => onChanged(k, x),
    );
  }
}

String _ymd(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
String _hm(int h, int m) => '${h.toString().padLeft(2, '0')}:${m.toString().padLeft(2, '0')}';

/// "Now": today's date and the time to the minute.
(String, String) srNow() {
  final d = DateTime.now();
  return (_ymd(d), _hm(d.hour, d.minute));
}

/// A card with a title (and a "2 / 4" or "Done" mark).
class SrCard extends StatelessWidget {
  const SrCard({super.key, required this.title, required this.icon, required this.children, this.trailing});
  final String title;
  final IconData icon;
  final List<Widget> children;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) => Box(
    margin: const EdgeInsets.only(bottom: 12),
    padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Icon(icon, size: 20, color: blue),
            const SizedBox(width: 8),
            Expanded(
              child: Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            ),
            ?trailing,
          ],
        ),
        const SizedBox(height: 10),
        ...children,
      ],
    ),
  );
}

/// The boxes of a card, two in a row on a wider screen.
class SrGrid extends StatelessWidget {
  const SrGrid({super.key, required this.children});
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (c, box) {
      final two = box.maxWidth >= 560;
      final w = two ? (box.maxWidth - 12) / 2 : box.maxWidth;
      return Wrap(
        spacing: 12,
        runSpacing: 12,
        children: [for (final x in children) SizedBox(width: w, child: x)],
      );
    },
  );
}

Widget srDoneMark(SrReport r, List<String> keys) {
  final n = keys.where((k) => r.val(k).isNotEmpty).length;
  return n == keys.length
      ? const Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.check_circle_rounded, color: green, size: 18),
            SizedBox(width: 4),
            Text(
              'Done',
              style: TextStyle(color: green, fontWeight: FontWeight.w700),
            ),
          ],
        )
      : Text(
          '$n / ${keys.length}',
          style: const TextStyle(color: muted, fontWeight: FontWeight.w700),
        );
}
