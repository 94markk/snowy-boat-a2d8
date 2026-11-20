import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';

enum CreationKind {
  cutout('Détourage'),
  collage('Collage'),
  edit('Retouche');

  const CreationKind(this.label);
  final String label;
}

class Creation {
  Creation({
    required this.id,
    required this.file,
    required this.thumb,
    required this.kind,
    required this.width,
    required this.height,
    required this.bytes,
    required this.created,
  });

  final String id;
  final String file;
  final String thumb;
  final CreationKind kind;
  final int width;
  final int height;
  final int bytes;
  final DateTime created;

  String get fileName => file.split('/').last;

  Map<String, Object> toJson() => {
        'id': id,
        'file': file,
        'thumb': thumb,
        'kind': kind.name,
        'width': width,
        'height': height,
        'bytes': bytes,
        'created': created.millisecondsSinceEpoch,
      };

  static Creation fromJson(Map<String, dynamic> j) => Creation(
        id: j['id'] as String,
        file: j['file'] as String,
        thumb: j['thumb'] as String,
        kind: CreationKind.values.firstWhere((k) => k.name == j['kind'], orElse: () => CreationKind.edit),
        width: j['width'] as int,
        height: j['height'] as int,
        bytes: j['bytes'] as int,
        created: DateTime.fromMillisecondsSinceEpoch(j['created'] as int),
      );
}

/// Exported images kept in the app's documents folder, newest first.
class CreationsStore extends ChangeNotifier {
  final List<Creation> _items = [];
  Directory? _dir;

  List<Creation> get items => List.unmodifiable(_items);

  int get thisWeek {
    final since = DateTime.now().subtract(const Duration(days: 7));
    return _items.where((c) => c.created.isAfter(since)).length;
  }

  Future<Directory> _folder() async {
    if (_dir != null) return _dir!;
    final docs = await getApplicationDocumentsDirectory();
    final d = Directory('${docs.path}/creations');
    if (!await d.exists()) await d.create(recursive: true);
    return _dir = d;
  }

  File _index(Directory d) => File('${d.path}/index.json');

  Future<void> load() async {
    try {
      final d = await _folder();
      final f = _index(d);
      if (!await f.exists()) return;
      final list = jsonDecode(await f.readAsString()) as List;
      _items
        ..clear()
        ..addAll(list.map((e) => Creation.fromJson(e as Map<String, dynamic>)).where((c) => File(c.file).existsSync()));
      notifyListeners();
    } catch (_) {
      // A damaged index should never block the app from starting.
    }
  }

  Future<void> _persist() async {
    final d = await _folder();
    await _index(d).writeAsString(jsonEncode(_items.map((c) => c.toJson()).toList()));
  }

  static String fileNameFor(DateTime t, String ext) {
    String two(int v) => v.toString().padLeft(2, '0');
    return 'DS_${t.year}${two(t.month)}${two(t.day)}_${two(t.hour)}${two(t.minute)}${two(t.second)}.$ext';
  }

  Future<Creation> add({
    required Uint8List bytes,
    required Uint8List thumb,
    required String ext,
    required CreationKind kind,
    required int width,
    required int height,
  }) async {
    final d = await _folder();
    final now = DateTime.now();
    final id = now.microsecondsSinceEpoch.toString();
    final name = fileNameFor(now, ext);
    final file = File('${d.path}/$name');
    await file.writeAsBytes(bytes, flush: true);
    final thumbFile = File('${d.path}/thumb_$id.jpg');
    await thumbFile.writeAsBytes(thumb, flush: true);
    final c = Creation(
      id: id,
      file: file.path,
      thumb: thumbFile.path,
      kind: kind,
      width: width,
      height: height,
      bytes: bytes.length,
      created: now,
    );
    _items.insert(0, c);
    await _persist();
    notifyListeners();
    return c;
  }

  Future<void> remove(Creation c) async {
    _items.removeWhere((x) => x.id == c.id);
    for (final p in [c.file, c.thumb]) {
      final f = File(p);
      if (await f.exists()) await f.delete();
    }
    await _persist();
    notifyListeners();
  }
}

final creations = CreationsStore();
