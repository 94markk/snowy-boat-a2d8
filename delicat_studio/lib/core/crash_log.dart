import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// Local crash journal. Nothing leaves the phone unless the user shares a report.
abstract final class CrashLog {
  static File? _file;
  static const _maxBytes = 200 * 1024;
  static const appVersion = '1.2.0';

  static Future<void> init() async {
    try {
      final dir = await getApplicationSupportDirectory();
      _file = File('${dir.path}/crash_log.txt');
    } catch (_) {
      _file = null;
    }
  }

  /// Records an error. Never throws.
  static void record(Object error, StackTrace? stack, {String? context}) {
    final f = _file;
    if (f == null) return;
    try {
      final lines = (stack?.toString() ?? '').split('\n').where((l) => l.trim().isNotEmpty).take(25).join('\n');
      final entry = StringBuffer()
        ..writeln('--- ${DateTime.now().toIso8601String()}  v$appVersion')
        ..writeln(context == null ? '$error' : '$context: $error')
        ..writeln(lines);
      f.writeAsStringSync(entry.toString(), mode: FileMode.append, flush: true);
      if (f.lengthSync() > _maxBytes) {
        final text = f.readAsStringSync();
        f.writeAsStringSync(text.substring(text.length - _maxBytes ~/ 2));
      }
    } catch (_) {
      // Logging must never crash the app.
    }
  }

  static Future<String> read() async {
    final f = _file;
    if (f == null || !await f.exists()) return '';
    return f.readAsString();
  }

  static Future<int> count() async {
    final text = await read();
    return RegExp(r'^--- ', multiLine: true).allMatches(text).length;
  }

  static Future<void> clear() async {
    final f = _file;
    if (f != null && await f.exists()) await f.delete();
  }
}
