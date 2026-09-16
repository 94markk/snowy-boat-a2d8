import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

import '../model/project.dart';

/// Summary of a stored project, for listing without parsing every file.
class ProjectSummary {
  const ProjectSummary({
    required this.id,
    required this.name,
    required this.clipCount,
    required this.durationUs,
    required this.updatedAt,
  });

  final String id;
  final String name;
  final int clipCount;
  final int durationUs;
  final DateTime updatedAt;
}

/// Projects on disk, one JSON file each.
///
/// A file per project rather than one index: a corrupt write can then only
/// cost the project being edited, not the whole library, and listing is a
/// directory read instead of a parse of everything.
class ProjectStore {
  const ProjectStore._();

  static const String _folder = 'projects';

  static Future<Directory> _dir() async {
    final base = await getApplicationDocumentsDirectory();
    final dir = Directory('${base.path}/$_folder');
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  static Future<File> _fileFor(String id) async =>
      File('${(await _dir()).path}/$id.json');

  /// Writes [project], via a temporary file that is renamed into place.
  ///
  /// Autosave fires while the user is still editing, so a write interrupted by
  /// the process being killed has to leave the previous save intact rather
  /// than a half-written file where the project used to be. Rename is atomic;
  /// writing in place is not.
  static Future<void> save(Project project) async {
    final file = await _fileFor(project.id);
    final temp = File('${file.path}.tmp');
    await temp.writeAsString(jsonEncode(project.toJson()), flush: true);
    await temp.rename(file.path);
  }

  static Future<Project?> load(String id) async {
    try {
      final file = await _fileFor(id);
      if (!await file.exists()) return null;
      final json = jsonDecode(await file.readAsString());
      if (json is! Map) return null;
      return Project.fromJson(Map<String, dynamic>.from(json));
    } catch (_) {
      // A project that will not parse is reported as missing rather than
      // thrown from: the caller's only useful response is the same either way,
      // and a crash on the home screen would make the app unopenable.
      return null;
    }
  }

  static Future<List<ProjectSummary>> list() async {
    final dir = await _dir();
    final summaries = <ProjectSummary>[];

    await for (final entry in dir.list()) {
      if (entry is! File || !entry.path.endsWith('.json')) continue;
      try {
        final json = jsonDecode(await entry.readAsString());
        if (json is! Map) continue;
        final project = Project.fromJson(Map<String, dynamic>.from(json));
        summaries.add(
          ProjectSummary(
            id: project.id,
            name: project.name,
            clipCount: project.clips.length,
            durationUs: project.durationUs,
            updatedAt: await entry.lastModified(),
          ),
        );
      } catch (_) {
        continue;
      }
    }

    summaries.sort((a, b) => b.updatedAt.compareTo(a.updatedAt));
    return summaries;
  }

  static Future<void> delete(String id) async {
    final file = await _fileFor(id);
    if (await file.exists()) await file.delete();
  }

  /// Ids of clips whose media can no longer be opened.
  ///
  /// A picked file is a reference, not a copy, so it can be deleted from under
  /// a saved project. Naming the affected clips lets the editor say which ones
  /// are unavailable instead of rendering black frames and leaving the user to
  /// work out which of twenty clips went missing.
  static Future<Set<String>> missingMedia(Project project) async {
    final missing = <String>{};
    for (final clip in project.clips) {
      final path = Uri.tryParse(clip.uri)?.toFilePath(windows: false);
      if (path == null) continue;
      if (!await File(path).exists()) missing.add(clip.id);
    }
    return missing;
  }
}
