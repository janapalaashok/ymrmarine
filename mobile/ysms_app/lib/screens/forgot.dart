// Forgot password (from the login screen): forgot_password.php's own form — it emails a link to set a
// new password and answers with its message (shown here as it is).
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

import '../api.dart';
import '../ui.dart';

class ForgotScreen extends StatefulWidget {
  const ForgotScreen({super.key});
  @override
  State<ForgotScreen> createState() => _ForgotScreenState();
}

class _ForgotScreenState extends State<ForgotScreen> {
  final _email = TextEditingController();
  bool _busy = false;
  String _msg = '';
  bool _ok = false;

  @override
  void dispose() {
    _email.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    final email = _email.text.trim();
    if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(email)) {
      setState(() {
        _ok = false;
        _msg = 'Please enter a valid email address.';
      });
      return;
    }
    setState(() {
      _busy = true;
      _msg = '';
    });
    try {
      final r = await Api.i.dio.post(
        'forgot_password.php',
        data: {'email': email},
        options: Options(contentType: Headers.formUrlEncodedContentType),
      );
      final html = Api.i.text(r);
      final m = RegExp(r'class="alert alert-(success|danger|info)[^"]*"[^>]*>([\s\S]*?)</div>').firstMatch(html);
      setState(() {
        _ok = m?.group(1) == 'success';
        _msg = m == null
            ? 'Could not send the link. Try again.'
            : m.group(2)!.replaceAll(RegExp(r'<[^>]+>'), '').replaceAll('&#039;', "'").replaceAll('&amp;', '&').trim();
      });
    } on DioException {
      setState(() {
        _ok = false;
        _msg = 'Could not connect. Check the internet and try again.';
      });
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: pageColor(context),
      appBar: AppBar(title: const Text('Forgot password')),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const IconBadge(Icons.lock_reset_rounded, blue, size: 56),
          const SizedBox(height: 14),
          const Text('Forgot password?', style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text('We will email you a link to set a new one.', style: TextStyle(color: mutedText(context))),
          const SizedBox(height: 20),
          if (_msg.isNotEmpty)
            Container(
              key: const ValueKey('fp-msg'),
              margin: const EdgeInsets.only(bottom: 14),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: (_ok ? green : red).withValues(alpha: .1),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(
                children: [
                  Icon(
                    _ok ? Icons.check_circle_outline_rounded : Icons.error_outline_rounded,
                    color: _ok ? green : red,
                  ),
                  const SizedBox(width: 10),
                  Expanded(child: Text(_msg)),
                ],
              ),
            ),
          TextField(
            key: const ValueKey('fp-email'),
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            autofillHints: const [AutofillHints.email],
            decoration: const InputDecoration(labelText: 'Email', prefixIcon: Icon(Icons.mail_outline_rounded)),
            onSubmitted: (_) => _send(),
          ),
          const SizedBox(height: 16),
          FilledButton(
            key: const ValueKey('fp-send'),
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
            onPressed: _busy ? null : _send,
            child: _busy
                ? const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white),
                  )
                : const Text('Send reset link'),
          ),
        ],
      ),
    );
  }
}
