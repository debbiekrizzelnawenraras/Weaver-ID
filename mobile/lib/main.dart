// Weaver-ID scanner: tap an NFC tag, ask the API if it is genuine.
//
// Talks to POST {server}/api/verify with {uid, device_id, counter, code} and shows one of
// three verdicts: authenticated, unregistered, suspicious. The counter is the
// tag's own read counter (NTAG21x) and the code is the tag code the tag desk
// wrote into the tag's memory; each is only sent when the tag has one.
//
// After a suspicious result the server says whether the person may report the
// tag (can_report). The report goes to POST {server}/api/reports and is reviewed
// by DTI-Mountain Province; it never changes the tag by itself.
//
// Real NFC reading works on an Android phone. On Windows/Edge you can still
// test the screen by typing a UID into the "Test with a UID" box.

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_nfc_kit/flutter_nfc_kit.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

void main() => runApp(const WeaverIdApp());

/// Sent with a report so DTI knows which app version it came from.
const appVersion = '1.0.0';

/// The tag code record the tag desk writes into a secured tag (pages 4..10):
/// an NDEF message with one external-type record "weaver.id:t" whose 8-byte
/// payload is the code. [hex] is the memory of pages 4..11 (32 bytes) as hex.
/// Returns the code as 16 upper-case hex characters, or null when the tag holds
/// no such record. Same layout as build_code_record in tools/reader_bridge.py.
String? parseTagCode(String hex) {
  final clean = hex.replaceAll(RegExp(r'[^0-9a-fA-F]'), '').toUpperCase();
  // 03 16 = NDEF TLV of 22 bytes, D4 0B 08 = external record, type length 11,
  // payload length 8, then the type "weaver.id:t".
  const head = '0316D40B08' '7765617665722E69643A74';
  if (clean.length < head.length + 16 || !clean.startsWith(head)) return null;
  return clean.substring(head.length, head.length + 16);
}

class WeaverIdApp extends StatelessWidget {
  const WeaverIdApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Weaver-ID',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF0A4D2E)),
        useMaterial3: true,
      ),
      home: const ScanPage(),
    );
  }
}

// ---------------------------------------------------------------- model

enum Verdict { authenticated, unregistered, suspicious, error }

class VerifyResult {
  VerifyResult({
    required this.verdict,
    required this.message,
    this.productTitle,
    this.productCategory,
    this.photoPath,
    this.associationName,
    this.associationMunicipality,
    this.canReport = false,
    this.retry = false,
  });

  final Verdict verdict;
  final String message;
  final String? productTitle;
  final String? productCategory;

  /// Address of the product photo as sent by the server (relative or absolute).
  final String? photoPath;
  final String? associationName;
  final String? associationMunicipality;

  /// True when the server allows a report for this tag from this phone.
  final bool canReport;

  /// True when the flag may come from a read that was cut short: tap again first.
  final bool retry;

  factory VerifyResult.error(String message) =>
      VerifyResult(verdict: Verdict.error, message: message);

  factory VerifyResult.fromJson(Map<String, dynamic> json) {
    final product = json['product'] as Map<String, dynamic>?;
    final association = json['association'] as Map<String, dynamic>?;

    Verdict verdict;
    switch (json['result']) {
      case 'authenticated':
        verdict = Verdict.authenticated;
        break;
      case 'unregistered':
        verdict = Verdict.unregistered;
        break;
      case 'suspicious':
        verdict = Verdict.suspicious;
        break;
      default:
        verdict = Verdict.error;
    }

    return VerifyResult(
      verdict: verdict,
      message: (json['message'] ?? '').toString(),
      productTitle: product?['title']?.toString(),
      productCategory: product?['category']?.toString(),
      photoPath: product?['photo_url']?.toString(),
      associationName: association?['name']?.toString(),
      associationMunicipality: association?['municipality']?.toString(),
      canReport: json['can_report'] == true,
      retry: json['retry'] == true,
    );
  }
}

/// What came back from sending a report.
class ReportOutcome {
  ReportOutcome({required this.ok, required this.message, this.reference});

  final bool ok;
  final String message;

  /// Reference number the person can quote to DTI-Mountain Province.
  final String? reference;
}

/// Today's date as the server expects it (yyyy-MM-dd).
String formatDate(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

// ------------------------------------------------------------------ api

/// Full address of a product photo. The server sends a path such as
/// /api/product-photos/<name>.jpg; it is joined to the server address saved in
/// the app. Returns null when there is no photo.
String? resolvePhotoUrl(String serverUrl, String? photoPath) {
  if (photoPath == null || photoPath.trim().isEmpty) return null;
  final path = photoPath.trim();
  if (path.startsWith('http://') || path.startsWith('https://')) return path;
  final base = serverUrl.trim().replaceAll(RegExp(r'/+$'), '');
  return path.startsWith('/') ? '$base$path' : '$base/$path';
}

class WeaverApi {
  WeaverApi({required this.baseUrl, required this.deviceId});

  final String baseUrl;
  final String deviceId;

  Future<VerifyResult> verify(String uid, {int? counter, String? code}) async {
    final base = baseUrl.trim().replaceAll(RegExp(r'/+$'), '');
    try {
      final response = await http
          .post(
            Uri.parse('$base/api/verify'),
            headers: const {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
            body: jsonEncode({
              'uid': uid,
              'device_id': deviceId,
              if (counter != null) 'counter': counter,
              if (code != null) 'code': code,
            }),
          )
          .timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body) as Map<String, dynamic>;
        return VerifyResult.fromJson(data);
      }
      if (response.statusCode == 422) {
        return VerifyResult.error(
            'The tag could not be read correctly. Try again.');
      }
      if (response.statusCode == 429) {
        return VerifyResult.error('Too many scans. Wait a minute and retry.');
      }
      return VerifyResult.error('Server error (${response.statusCode}).');
    } on TimeoutException {
      return VerifyResult.error(
          'The server did not answer. Check the server address and Wi-Fi.');
    } catch (_) {
      return VerifyResult.error(
          'Cannot reach the server. Check the address in Settings and that both devices are on the same network.');
    }
  }

  /// Sends a report about a tag the server flagged. Only [location] is required;
  /// leave [contact] empty to report anonymously.
  Future<ReportOutcome> report(
    String uid, {
    required String location,
    String? seenOn,
    String? note,
    String? contact,
  }) async {
    final base = baseUrl.trim().replaceAll(RegExp(r'/+$'), '');
    try {
      final response = await http
          .post(
            Uri.parse('$base/api/reports'),
            headers: const {
              'Accept': 'application/json',
              'Content-Type': 'application/json',
            },
            body: jsonEncode({
              'uid': uid,
              'device_id': deviceId,
              'app_version': appVersion,
              'location': location,
              if (seenOn != null && seenOn.isNotEmpty) 'seen_on': seenOn,
              if (note != null && note.isNotEmpty) 'note': note,
              if (contact != null && contact.isNotEmpty) 'contact': contact,
            }),
          )
          .timeout(const Duration(seconds: 10));

      Map<String, dynamic> data = {};
      try {
        data = jsonDecode(response.body) as Map<String, dynamic>;
      } catch (_) {}
      final message = (data['message'] ?? '').toString();

      if (response.statusCode == 200 || response.statusCode == 201) {
        return ReportOutcome(
          ok: true,
          message: message.isNotEmpty ? message : 'Your report was sent.',
          reference: data['reference']?.toString(),
        );
      }
      if (response.statusCode == 422) {
        return ReportOutcome(
            ok: false,
            message: message.isNotEmpty
                ? message
                : 'The report could not be accepted. Check the fields and try again.');
      }
      if (response.statusCode == 429) {
        return ReportOutcome(
            ok: false, message: 'Too many reports from this network. Try again later.');
      }
      return ReportOutcome(
          ok: false, message: 'Server error (${response.statusCode}).');
    } on TimeoutException {
      return ReportOutcome(
          ok: false,
          message: 'The server did not answer. Check the server address and Wi-Fi.');
    } catch (_) {
      return ReportOutcome(
          ok: false,
          message: 'Cannot reach the server. Check the address in Settings.');
    }
  }
}

// ----------------------------------------------------------------- page

class ScanPage extends StatefulWidget {
  const ScanPage({super.key});

  @override
  State<ScanPage> createState() => _ScanPageState();
}

class _ScanPageState extends State<ScanPage> {
  static const _serverKey = 'server_url';
  static const _deviceKey = 'device_id';

  String _serverUrl = 'http://localhost:8000';
  String _deviceId = 'unknown';
  bool _busy = false;
  String _status = 'Ready.';
  VerifyResult? _result;

  /// UID of the tag the shown result is about (needed to report it).
  String? _resultUid;
  final _uidController = TextEditingController();

  bool get _nfcPlatform =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.android ||
          defaultTargetPlatform == TargetPlatform.iOS);

  @override
  void initState() {
    super.initState();
    _loadSettings();
  }

  @override
  void dispose() {
    _uidController.dispose();
    super.dispose();
  }

  Future<void> _loadSettings() async {
    final prefs = await SharedPreferences.getInstance();
    var deviceId = prefs.getString(_deviceKey);
    if (deviceId == null) {
      final random = Random.secure();
      deviceId = 'app-${List.generate(8, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join()}';
      await prefs.setString(_deviceKey, deviceId);
    }
    if (!mounted) return;
    setState(() {
      _serverUrl = prefs.getString(_serverKey) ?? _serverUrl;
      _deviceId = deviceId!;
    });
  }

  static String _normalizeUid(String raw) =>
      raw.replaceAll(RegExp(r'[^0-9a-fA-F]'), '').toUpperCase();

  Future<void> _verify(String uid, {int? counter, String? code}) async {
    setState(() {
      _busy = true;
      _status = 'Checking $uid ...';
      _result = null;
      _resultUid = uid;
    });
    final api = WeaverApi(baseUrl: _serverUrl, deviceId: _deviceId);
    final result = await api.verify(uid, counter: counter, code: code);
    if (!mounted) return;
    setState(() {
      _busy = false;
      _status = 'Tag $uid';
      _result = result;
    });
  }

  /// Reads the tag's own tap counter (NTAG21x). Returns null for tags without
  /// a counter, so older tags keep working. A secured tag that answers without
  /// a counter is flagged by the server.
  Future<int?> _readCounter() async {
    try {
      // A memory read makes the tag count this tap, then READ_CNT returns the total.
      await FlutterNfcKit.transceive('3004');
      final reply = await FlutterNfcKit.transceive('3902');
      final hex = reply.toString().replaceAll(RegExp(r'[^0-9a-fA-F]'), '');
      if (hex.length != 6) return null;
      final bytes = [
        for (var i = 0; i < 6; i += 2) int.parse(hex.substring(i, i + 2), radix: 16),
      ];
      return bytes[0] | (bytes[1] << 8) | (bytes[2] << 16); // little-endian, 24 bit
    } catch (_) {
      return null;
    }
  }

  /// Reads the tag code record (pages 4..11). Returns null when the tag has
  /// none; the server decides whether that is a problem. Reading in the same
  /// session as the counter does not count a second tap.
  Future<String?> _readTagCode() async {
    try {
      final first = await FlutterNfcKit.transceive('3004');
      final second = await FlutterNfcKit.transceive('3008');
      return parseTagCode('$first$second');
    } catch (_) {
      return null;
    }
  }

  Future<void> _scanTag() async {
    if (!_nfcPlatform) {
      setState(() {
        _result = VerifyResult.error(
            'NFC scanning only works on an Android or iOS phone. Use the UID box below to test.');
      });
      return;
    }

    setState(() {
      _busy = true;
      _status = 'Hold the phone against the tag ...';
      _result = null;
    });

    try {
      final availability = await FlutterNfcKit.nfcAvailability;
      if (availability != NFCAvailability.available) {
        if (!mounted) return;
        setState(() {
          _busy = false;
          _result = VerifyResult.error(availability == NFCAvailability.disabled
              ? 'NFC is turned off. Turn it on in the phone settings.'
              : 'This phone does not support NFC.');
        });
        return;
      }

      final tag = await FlutterNfcKit.poll(
        timeout: const Duration(seconds: 20),
        iosAlertMessage: 'Hold your phone near the tag',
      );
      final uid = _normalizeUid(tag.id);
      // Must happen while the tag is still connected.
      final counter = await _readCounter();
      final code = await _readTagCode();
      await FlutterNfcKit.finish();

      if (uid.isEmpty) {
        if (!mounted) return;
        setState(() {
          _busy = false;
          _result = VerifyResult.error('The tag has no readable ID.');
        });
        return;
      }
      await _verify(uid, counter: counter, code: code);
    } catch (e) {
      try {
        await FlutterNfcKit.finish();
      } catch (_) {}
      if (!mounted) return;
      setState(() {
        _busy = false;
        _result = VerifyResult.error(
            'No tag was read (${e.toString().split('\n').first}). Try again.');
      });
    }
  }

  Future<void> _openReport() async {
    final uid = _resultUid;
    if (uid == null) return;
    await Navigator.of(context).push<void>(
      MaterialPageRoute(
        builder: (_) => ReportPage(
          api: WeaverApi(baseUrl: _serverUrl, deviceId: _deviceId),
          uid: uid,
        ),
      ),
    );
  }

  Future<void> _manualVerify() async {
    final uid = _normalizeUid(_uidController.text);
    if (uid.length < 8 || uid.length > 20) {
      setState(() {
        _result = VerifyResult.error(
            'Type 8 to 20 hex characters, for example 04E3CA79410289.');
      });
      return;
    }
    await _verify(uid);
  }

  Future<void> _openSettings() async {
    final controller = TextEditingController(text: _serverUrl);
    final saved = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Server address'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            TextField(
              controller: controller,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                hintText: 'http://192.168.1.5:8000',
              ),
            ),
            const SizedBox(height: 12),
            Text('Device ID: $_deviceId',
                style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, controller.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );
    controller.dispose();
    if (saved == null || saved.isEmpty) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_serverKey, saved);
    if (!mounted) return;
    setState(() => _serverUrl = saved);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Weaver-ID'),
        actions: [
          IconButton(
            tooltip: 'Server settings',
            icon: const Icon(Icons.settings),
            onPressed: _busy ? null : _openSettings,
          ),
        ],
      ),
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            Text(
              'Check a handwoven product',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 4),
            Text(
              'Tap the tag sewn into the textile to see if DTI-Mountain Province has registered it.',
              style: Theme.of(context).textTheme.bodyMedium,
            ),
            const SizedBox(height: 20),
            FilledButton.icon(
              onPressed: _busy ? null : _scanTag,
              icon: _busy
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.nfc),
              label: Padding(
                padding: const EdgeInsets.symmetric(vertical: 14),
                child: Text(_busy ? 'Working ...' : 'Scan a tag'),
              ),
            ),
            const SizedBox(height: 8),
            Text(_status,
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 16),
            if (_result != null)
              ResultCard(
                result: _result!,
                serverUrl: _serverUrl,
                onReport: _busy ? null : _openReport,
              ),
            const SizedBox(height: 24),
            const Divider(),
            const SizedBox(height: 8),
            Text('Test with a UID (no NFC needed)',
                style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _uidController,
                    enabled: !_busy,
                    textCapitalization: TextCapitalization.characters,
                    decoration: const InputDecoration(
                      hintText: '04E3CA79410289',
                      border: OutlineInputBorder(),
                      isDense: true,
                    ),
                    onSubmitted: (_) => _manualVerify(),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton(
                  onPressed: _busy ? null : _manualVerify,
                  child: const Text('Verify'),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text('Server: $_serverUrl',
                style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
      ),
    );
  }
}

// --------------------------------------------------------- result card

class ResultCard extends StatelessWidget {
  const ResultCard(
      {super.key, required this.result, this.serverUrl = '', this.onReport});

  final VerifyResult result;

  /// Opens the report screen. The button shows only when the server allows a report.
  final VoidCallback? onReport;

  /// Server address saved in the app; used to load the product photo.
  final String serverUrl;

  MaterialColor get _color {
    switch (result.verdict) {
      case Verdict.authenticated:
        return Colors.green;
      case Verdict.unregistered:
        return Colors.blueGrey;
      case Verdict.suspicious:
        return Colors.red;
      case Verdict.error:
        return Colors.orange;
    }
  }

  IconData get _icon {
    switch (result.verdict) {
      case Verdict.authenticated:
        return Icons.verified;
      case Verdict.unregistered:
        return Icons.help_outline;
      case Verdict.suspicious:
        return Icons.warning_amber_rounded;
      case Verdict.error:
        return Icons.cloud_off;
    }
  }

  String get _title {
    switch (result.verdict) {
      case Verdict.authenticated:
        return 'Genuine';
      case Verdict.unregistered:
        return 'Not registered';
      case Verdict.suspicious:
        return 'Suspicious';
      case Verdict.error:
        return 'Could not check';
    }
  }

  @override
  Widget build(BuildContext context) {
    final color = _color;
    return Card(
      color: color.shade50,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: color.shade300),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(_icon, color: color.shade700, size: 32),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    _title,
                    style: Theme.of(context)
                        .textTheme
                        .headlineSmall
                        ?.copyWith(color: color.shade800),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Text(result.message),
            if (result.verdict == Verdict.suspicious &&
                result.canReport &&
                onReport != null) ...[
              const SizedBox(height: 12),
              OutlinedButton.icon(
                onPressed: onReport,
                icon: const Icon(Icons.flag_outlined),
                label: const Text('Report this tag to DTI'),
              ),
            ],
            if (result.verdict == Verdict.authenticated) ...[
              const Divider(height: 24),
              if (resolvePhotoUrl(serverUrl, result.photoPath) != null) ...[
                ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: Image.network(
                    resolvePhotoUrl(serverUrl, result.photoPath)!,
                    height: 200,
                    width: double.infinity,
                    fit: BoxFit.cover,
                    loadingBuilder: (context, child, progress) => progress == null
                        ? child
                        : const SizedBox(
                            height: 200,
                            child: Center(child: CircularProgressIndicator()),
                          ),
                    errorBuilder: (context, error, stack) => const SizedBox(
                      height: 60,
                      child: Center(child: Text('Photo could not be loaded.')),
                    ),
                  ),
                ),
                const SizedBox(height: 6),
                const Text(
                  'Compare this photo with the product in your hands.',
                  style: TextStyle(color: Colors.black54, fontSize: 12),
                ),
                const SizedBox(height: 10),
              ],
              _row('Product', result.productTitle),
              _row('Category', result.productCategory),
              _row('Association', result.associationName),
              _row('Municipality', result.associationMunicipality),
            ],
          ],
        ),
      ),
    );
  }

  Widget _row(String label, String? value) {
    if (value == null || value.isEmpty) return const SizedBox.shrink();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 104,
            child: Text(label, style: const TextStyle(color: Colors.black54)),
          ),
          Expanded(
            child: Text(value,
                style: const TextStyle(fontWeight: FontWeight.w600)),
          ),
        ],
      ),
    );
  }
}

// --------------------------------------------------------- report page

/// Form shown after a suspicious result. Only the place is required; the date,
/// a note and contact details are optional, and leaving the contact empty
/// sends the report anonymously.
class ReportPage extends StatefulWidget {
  const ReportPage({super.key, required this.api, required this.uid});

  final WeaverApi api;
  final String uid;

  @override
  State<ReportPage> createState() => _ReportPageState();
}

class _ReportPageState extends State<ReportPage> {
  final _location = TextEditingController();
  final _note = TextEditingController();
  final _contact = TextEditingController();
  DateTime _seenOn = DateTime.now();
  bool _sending = false;
  String? _error;
  ReportOutcome? _done;

  @override
  void dispose() {
    _location.dispose();
    _note.dispose();
    _contact.dispose();
    super.dispose();
  }

  Future<void> _pickDate() async {
    final now = DateTime.now();
    final picked = await showDatePicker(
      context: context,
      initialDate: _seenOn,
      firstDate: now.subtract(const Duration(days: 365)),
      lastDate: now,
    );
    if (picked != null && mounted) setState(() => _seenOn = picked);
  }

  Future<void> _send() async {
    final place = _location.text.trim();
    if (place.length < 3) {
      setState(() => _error =
          'Tell us where you found it: a shop, stall or market and the town, or the online seller.');
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    final outcome = await widget.api.report(
      widget.uid,
      location: place,
      seenOn: formatDate(_seenOn),
      note: _note.text.trim(),
      contact: _contact.text.trim(),
    );
    if (!mounted) return;
    setState(() {
      _sending = false;
      if (outcome.ok) {
        _done = outcome;
      } else {
        _error = outcome.message;
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final done = _done;
    return Scaffold(
      appBar: AppBar(title: const Text('Report a tag')),
      body: SafeArea(
        child: done != null ? _thanks(context, done) : _form(context),
      ),
    );
  }

  Widget _thanks(BuildContext context, ReportOutcome done) {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const SizedBox(height: 24),
        const Icon(Icons.check_circle_outline, size: 64, color: Colors.green),
        const SizedBox(height: 12),
        Text(done.message,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleMedium),
        if (done.reference != null) ...[
          const SizedBox(height: 12),
          Text('Reference: ${done.reference}',
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
        const SizedBox(height: 8),
        const Text(
          'DTI-Mountain Province decides what happens next. Please do not confront the seller.',
          textAlign: TextAlign.center,
          style: TextStyle(color: Colors.black54),
        ),
        const SizedBox(height: 24),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Done'),
        ),
      ],
    );
  }

  Widget _form(BuildContext context) {
    final small = Theme.of(context).textTheme.bodySmall;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        Text('Tag ${widget.uid}',
            style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 4),
        const Text(
          'This tag was flagged as suspicious. That is not proof that the product is fake. DTI-Mountain Province reviews every report.',
        ),
        const SizedBox(height: 16),
        TextField(
          controller: _location,
          enabled: !_sending,
          maxLength: 150,
          textCapitalization: TextCapitalization.sentences,
          decoration: const InputDecoration(
            labelText: 'Where did you find it? (required)',
            hintText: 'Stall name and town, or the online seller or link',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 8),
        ListTile(
          contentPadding: EdgeInsets.zero,
          leading: const Icon(Icons.calendar_today),
          title: Text('Date seen: ${formatDate(_seenOn)}'),
          trailing: TextButton(
            onPressed: _sending ? null : _pickDate,
            child: const Text('Change'),
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _note,
          enabled: !_sending,
          maxLines: 3,
          maxLength: 1000,
          textCapitalization: TextCapitalization.sentences,
          decoration: const InputDecoration(
            labelText: 'What did you notice? (optional)',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 8),
        TextField(
          controller: _contact,
          enabled: !_sending,
          maxLength: 120,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(
            labelText: 'Your phone or email (optional)',
            helperText: 'Leave empty to report anonymously.',
            border: OutlineInputBorder(),
          ),
        ),
        const SizedBox(height: 8),
        Text(
          'Privacy: DTI-Mountain Province uses this report only to review the tag. The app also sends the tag ID, the scan time and this phone\'s app ID. Do not include photos or names of other people.',
          style: small,
        ),
        if (_error != null) ...[
          const SizedBox(height: 12),
          Text(_error!, style: const TextStyle(color: Colors.red)),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _sending ? null : _send,
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 12),
            child: Text(_sending ? 'Sending ...' : 'Send report'),
          ),
        ),
      ],
    );
  }
}
