import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';

import 'crash_log.dart';

enum DraftKind {
  video('Montage vidéo'),
  photo('Retouche'),
  collage('Collage');

  const DraftKind(this.label);
  final String label;
}

class Draft {
  Draft({required this.id, required this.kind, required this.updated, this.thumb, this.exported = false});
  final String id;
  final DraftKind kind;
  final DateTime updated;
  final String? thumb;

  /// Already saved as a creation at least once (cleaned up after a month without edits).
  final bool exported;

  Draft copyWith({bool? exported}) => Draft(id: id, kind: kind, updated: updated, thumb: thumb, exported: exported ?? this.exported);

  Map<String, Object?> toJson() => {'id': id, 'kind': kind.name, 'updated': updated.millisecondsSinceEpoch, 'thumb': thumb, 'exported': exported};

  static Draft fromJson(Map<String, dynamic> j) => Draft(
        id: j['id'] as String,
        kind: DraftKind.values.firstWhere((k) => k.name == j['kind'], orElse: () => DraftKind.photo),
        updated: DateTime.fromMillisecondsSinceEpoch((j['updated'] as num).toInt()),
        thumb: j['thumb'] as String?,
        exported: j['exported'] == true,
      );
}

/// Work in progress, saved automatically so nothing is lost when the app closes.
/// Each draft has its own folder holding `doc.json`, a thumbnail and its media files.
class DraftsStore extends ChangeNotifier {
  final List<Draft> _items = [];
  Directory? _root;

  List<Draft> get items => List.unmodifiable(_items);

  Future<Directory> _folder() async {
    if (_root != null) return _root!;
    final docs = await getApplicationDocumentsDirectory();
    final d = Directory('${docs.path}/drafts');
    if (!await d.exists()) await d.create(recursive: true);
    return _root = d;
  }

  Future<Directory> folderFor(String id) async {
    final d = Directory('${(await _folder()).path}/$id');
    if (!await d.exists()) await d.create(recursive: true);
    return d;
  }

  File _index(Directory root) => File('${root.path}/index.json');

  Future<void> load() async {
    try {
      final root = await _folder();
      final f = _index(root);
      if (!await f.exists()) return;
      final list = jsonDecode(await f.readAsString()) as List;
      _items
        ..clear()
        ..addAll(list
            .map((e) => Draft.fromJson(e as Map<String, dynamic>))
            .where((d) => File('${root.path}/${d.id}/doc.json').existsSync()));
      _items.sort((a, b) => b.updated.compareTo(a.updated));
      final stale = DateTime.now().subtract(const Duration(days: 30));
      for (final d in _items.where((d) => d.exported && d.updated.isBefore(stale)).toList()) {
        await remove(d.id);
      }
      notifyListeners();
    } catch (e, s) {
      CrashLog.record(e, s, context: 'drafts.load');
    }
  }

  Future<void> markExported(String id) async {
    final i = _items.indexWhere((d) => d.id == id);
    if (i < 0) return;
    _items[i] = _items[i].copyWith(exported: true);
    await _persist();
  }

  /// Disk space used by all drafts.
  Future<int> totalBytes() async {
    var n = 0;
    try {
      await for (final e in (await _folder()).list(recursive: true)) {
        if (e is File) n += await e.length();
      }
    } catch (_) {}
    return n;
  }

  Future<void> _persist() async {
    final root = await _folder();
    final f = _index(root);
    final tmp = File('${f.path}.tmp');
    await tmp.writeAsString(jsonEncode(_items.map((d) => d.toJson()).toList()), flush: true);
    await tmp.rename(f.path);
  }

  static String newId() => 'd${DateTime.now().microsecondsSinceEpoch}';

  /// Copies a media file into the draft folder and returns its new path. With [move],
  /// temporary files (picker copies in the cache) are moved instead, which saves space;
  /// files kept by the app itself are always copied. Files already inside the folder are
  /// returned unchanged.
  Future<String> adopt(String id, String path, {bool move = false}) async {
    final dir = await folderFor(id);
    if (path.startsWith(dir.path)) return path;
    final name = '${DateTime.now().microsecondsSinceEpoch}_${path.split('/').last}';
    final target = File('${dir.path}/$name');
    final src = File(path);
    final kept = [(await getApplicationDocumentsDirectory()).path, (await getApplicationSupportDirectory()).path];
    final movable = move && !kept.any(path.startsWith);
    try {
      if (movable) {
        await src.rename(target.path);
      } else {
        await src.copy(target.path);
      }
    } on FileSystemException {
      await src.copy(target.path);
    }
    return target.path;
  }

  Future<void> save(String id, DraftKind kind, Map<String, Object?> doc, {Uint8List? thumb}) async {
    final dir = await folderFor(id);
    final tmp = File('${dir.path}/doc.json.tmp');
    await tmp.writeAsString(jsonEncode(doc), flush: true);
    await tmp.rename('${dir.path}/doc.json');
    String? thumbPath = _items.where((d) => d.id == id).firstOrNull?.thumb;
    if (thumb != null) {
      // A new name each time, so image caches never show the previous thumbnail.
      final old = thumbPath;
      thumbPath = '${dir.path}/thumb_${DateTime.now().millisecondsSinceEpoch}.jpg';
      await File(thumbPath).writeAsBytes(thumb, flush: true);
      if (old != null && old != thumbPath) {
        try {
          await File(old).delete();
        } catch (_) {}
      }
    }
    final wasExported = _items.where((d) => d.id == id).firstOrNull?.exported ?? false;
    _items.removeWhere((d) => d.id == id);
    _items.insert(0, Draft(id: id, kind: kind, updated: DateTime.now(), thumb: thumbPath, exported: wasExported));
    await _persist();
    notifyListeners();
  }

  Future<Map<String, dynamic>?> read(String id) async {
    try {
      final f = File('${(await _folder()).path}/$id/doc.json');
      if (!await f.exists()) return null;
      return jsonDecode(await f.readAsString()) as Map<String, dynamic>;
    } catch (e, s) {
      CrashLog.record(e, s, context: 'drafts.read');
      return null;
    }
  }

  Future<void> remove(String id) async {
    _items.removeWhere((d) => d.id == id);
    await _persist();
    notifyListeners();
    try {
      final d = Directory('${(await _folder()).path}/$id');
      if (await d.exists()) await d.delete(recursive: true);
    } catch (_) {}
  }
}

final drafts = DraftsStore();

/// Debounced autosave used by the editors.
class Autosaver {
  Autosaver(this._save, {this.delay = const Duration(milliseconds: 1500)});
  final Future<void> Function() _save;
  final Duration delay;
  Timer? _timer;
  bool _saving = false;
  bool _again = false;

  void schedule() {
    _timer?.cancel();
    _timer = Timer(delay, flush);
  }

  Future<void> flush() async {
    _timer?.cancel();
    if (_saving) {
      _again = true;
      return;
    }
    _saving = true;
    try {
      await _save();
    } catch (e, s) {
      CrashLog.record(e, s, context: 'autosave');
    } finally {
      _saving = false;
      if (_again) {
        _again = false;
        await flush();
      }
    }
  }

  void dispose() => _timer?.cancel();
}

String timeAgo(DateTime t) {
  final d = DateTime.now().difference(t);
  if (d.inMinutes < 1) return 'à l’instant';
  if (d.inMinutes < 60) return 'il y a ${d.inMinutes} min';
  if (d.inHours < 24) return 'il y a ${d.inHours} h';
  if (d.inDays == 1) return 'hier';
  return 'il y a ${d.inDays} jours';
}
