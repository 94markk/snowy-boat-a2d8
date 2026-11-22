import 'dart:io';

import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../core/crash_log.dart';
import '../core/drafts.dart';
import '../core/image_io.dart';
import '../core/recommendations.dart';
import '../core/settings.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/pressable.dart';
import 'collage/collage_screen.dart';
import 'cutout/cutout_screen.dart';
import 'editor/editor_model.dart' show EditorDoc, FrameStyle;
import 'editor/editor_screen.dart';
import 'video/video_editor_screen.dart';
import 'video/video_model.dart';

/// Every entry point into the editors, shared by Home and Créations.
enum StudioTool {
  video,
  cutout,
  portrait,
  sticker,
  selective,
  colorErase,
  collage,
  styles,
  retouch,
}

Future<ImageSource?> pickSource(BuildContext context) {
  final k = KatColors.of(context);
  Widget option(BuildContext c, IconData icon, String title, String sub, ImageSource s) => Expanded(
        child: Pressable(
          onTap: () => Navigator.pop(c, s),
          semanticLabel: title,
          child: KatSheet(
            color: k.sheetAlt,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Icon(icon, size: 28, color: k.ink),
                const SizedBox(height: KatSpace.s12),
                Text(title, style: KatType.ui(16, weight: FontWeight.w600, color: k.ink)),
                const SizedBox(height: KatSpace.s4),
                Text(sub, style: KatType.ui(14, color: k.inkMuted)),
              ],
            ),
          ),
        ),
      );
  return showModalBottomSheet<ImageSource>(
    context: context,
    builder: (c) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(KatSpace.gutter, 0, KatSpace.gutter, KatSpace.s24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Choisir une photo', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
            const SizedBox(height: KatSpace.s16),
            Row(
              children: [
                option(c, Icons.photo_library_rounded, 'Galerie', 'Une photo de ton téléphone', ImageSource.gallery),
                const SizedBox(width: KatSpace.s12),
                option(c, Icons.photo_camera_rounded, 'Appareil photo', 'Prendre une photo maintenant', ImageSource.camera),
              ],
            ),
          ],
        ),
      ),
    ),
  );
}

/// Runs [task] behind a calm modal loader.
Future<T?> withLoader<T>(BuildContext context, String message, Future<T> Function() task) async {
  final k = KatColors.of(context);
  final nav = Navigator.of(context, rootNavigator: true);
  var shown = true;
  showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (_) => PopScope(
      canPop: false,
      child: Center(
        child: KatSheet(
          radius: KatRadius.sheet,
          padding: const EdgeInsets.all(KatSpace.s24),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox.square(dimension: 24, child: CircularProgressIndicator(strokeWidth: 3, color: k.ink)),
              const SizedBox(width: KatSpace.s16),
              DefaultTextStyle(style: KatType.ui(16, weight: FontWeight.w500, color: k.ink), child: Text(message)),
            ],
          ),
        ),
      ),
    ),
  ).whenComplete(() => shown = false);
  try {
    return await task();
  } finally {
    if (shown) nav.pop();
  }
}

/// Opens a tool. Optional presets let recommendations land on the right setting.
Future<void> openTool(
  BuildContext context,
  StudioTool tool, {
  String? style,
  FrameStyle? frame,
  CollageRatio? collageRatio,
  VRatio? videoRatio,
  String? usageKey,
}) async {
  final nav = Navigator.of(context);
  settings.recordUse(usageKey ?? tool.name);
  try {
    // Remembered in case Android closes the app while the picker is open (see [recoverLostPick]).
    await settings.setPendingPick(tool.name);
    if (tool == StudioTool.video) {
      final max = settings.importQuality.maxSide.toDouble();
      final files = await ImagePicker().pickMultipleMedia(maxWidth: max, maxHeight: max, imageQuality: 92);
      await settings.setPendingPick(null);
      if (files.isEmpty) return;
      await nav.push(MaterialPageRoute(
        builder: (_) => VideoEditorScreen(paths: [for (final f in files) f.path], initialRatio: videoRatio ?? VRatio.original),
      ));
      return;
    }
    if (tool == StudioTool.collage) {
      final photos = await ImageIO.pickMany(limit: 9, maxSide: ImageIO.collageSide);
      await settings.setPendingPick(null);
      if (photos.isEmpty) return;
      await nav.push(MaterialPageRoute(builder: (_) => CollageScreen(photos: photos, initialRatio: collageRatio ?? CollageRatio.portrait)));
      return;
    }
    if (!context.mounted) return;
    final source = await pickSource(context);
    if (source == null || !context.mounted) {
      await settings.setPendingPick(null);
      return;
    }
    final photo = await ImageIO.pick(source);
    await settings.setPendingPick(null);
    if (photo == null) return;
    await nav.push(MaterialPageRoute(builder: (_) => _photoTool(tool, photo, style: style, frame: frame)));
  } catch (e, s) {
    await settings.setPendingPick(null);
    CrashLog.record(e, s, context: 'open ${tool.name}');
    if (context.mounted) {
      showKatSnack(context, 'Impossible d’ouvrir ce fichier. Vérifie l’accès aux photos et vidéos dans les réglages Android, puis réessaie.');
    }
  }
}

/// The screen for a single-photo tool. The screen takes ownership of [photo].
Widget _photoTool(StudioTool tool, Photo photo, {String? style, FrameStyle? frame}) => switch (tool) {
      StudioTool.cutout => CutoutScreen(photo: photo),
      StudioTool.portrait => CutoutScreen(photo: photo, preset: CutoutPreset.portrait),
      StudioTool.sticker => CutoutScreen(photo: photo, preset: CutoutPreset.sticker),
      StudioTool.selective => CutoutScreen(photo: photo, preset: CutoutPreset.selective),
      StudioTool.colorErase => CutoutScreen(photo: photo, preset: CutoutPreset.colorErase),
      StudioTool.styles => EditorScreen(photo: photo, initialTool: EditorTool.styles, initialStyle: style),
      StudioTool.retouch => EditorScreen(
          photo: photo,
          initialTool: frame != null ? EditorTool.frame : EditorTool.adjust,
          initialFrame: frame,
          initialStyle: style,
        ),
      StudioTool.collage || StudioTool.video => EditorScreen(photo: photo),
    };

/// On Android, the system may close Delicat Studio while the gallery or camera is open
/// (often on 2 GB phones). The picked files are then handed back at the next start: this
/// reopens the tool that asked for them.
Future<void> recoverLostPick(BuildContext context) async {
  final tool = StudioTool.values.where((t) => t.name == settings.pendingPick).firstOrNull;
  if (tool == null) return;
  await settings.setPendingPick(null);
  try {
    final lost = await ImagePicker().retrieveLostData();
    if (lost.isEmpty) return;
    final files = [...?lost.files, if (lost.files == null && lost.file != null) lost.file!];
    if (files.isEmpty || !context.mounted) return;
    final nav = Navigator.of(context);
    showKatSnack(context, 'Android avait fermé l’appli pendant le choix. On reprend avec tes fichiers.');
    final paths = [for (final f in files) f.path];
    if (tool == StudioTool.video) {
      await nav.push(MaterialPageRoute(builder: (_) => VideoEditorScreen(paths: paths)));
      return;
    }
    if (tool == StudioTool.collage) {
      final photos = <Photo>[for (final p in paths.take(9)) await ImageIO.load(p, maxSide: ImageIO.collageSide)];
      if (photos.isEmpty) return;
      await nav.push(MaterialPageRoute(builder: (_) => CollageScreen(photos: photos)));
      return;
    }
    final photo = await ImageIO.load(paths.first);
    await nav.push(MaterialPageRoute(builder: (_) => _photoTool(tool, photo)));
  } catch (e, s) {
    CrashLog.record(e, s, context: 'recover pick');
  }
}

/// Reopens a saved draft in the right editor.
Future<void> openDraft(BuildContext context, Draft d) async {
  final nav = Navigator.of(context);
  if (d.kind == DraftKind.video) {
    await nav.push(MaterialPageRoute(builder: (_) => VideoEditorScreen(draftId: d.id)));
    return;
  }
  final screen = await withLoader<Widget?>(context, 'Ouverture du brouillon…', () async {
    final j = await drafts.read(d.id);
    if (j == null) return null;
    try {
      if (d.kind == DraftKind.photo) {
        final base = j['base'] as String;
        if (!File(base).existsSync()) return null;
        final photo = await ImageIO.load(base);
        return EditorScreen(
          photo: photo,
          draftId: d.id,
          initialDoc: EditorDoc.fromJson(j['doc'] as Map<String, dynamic>),
          title: j['title'] as String? ?? 'Retouche',
        );
      }
      final cells = (j['cells'] as List? ?? const []).cast<Map<String, dynamic>>();
      final photos = <Photo>[];
      for (final c in cells) {
        final p = c['path'] as String;
        if (File(p).existsSync()) photos.add(await ImageIO.load(p, maxSide: ImageIO.collageSide));
      }
      if (photos.length < 2) {
        for (final p in photos) {
          p.image.dispose();
        }
        return null;
      }
      // Cell settings only line up when every photo is still there.
      return CollageScreen(photos: photos, draftId: d.id, initialState: photos.length == cells.length ? j : null);
    } catch (e, s) {
      CrashLog.record(e, s, context: 'open draft');
      return null;
    }
  });
  if (!context.mounted) return;
  if (screen == null) {
    final remove = await confirmKat(
      context,
      title: 'Brouillon illisible',
      message: 'Ses photos ont été supprimées du téléphone. Retirer ce brouillon ?',
      confirm: 'Retirer',
    );
    if (remove) await drafts.remove(d.id);
    return;
  }
  await nav.push(MaterialPageRoute(builder: (_) => screen));
}

/// Launches what a recommendation suggests.
Future<void> openRecommendation(BuildContext context, RecAction a) => switch (a) {
      RecAction.video => openTool(context, StudioTool.video),
      RecAction.logoVideo => openTool(context, StudioTool.video, usageKey: 'logoVideo'),
      RecAction.flagDay => openTool(context, StudioTool.video, videoRatio: VRatio.story, usageKey: 'flagDay'),
      RecAction.sticker => openTool(context, StudioTool.sticker),
      RecAction.collageStory => openTool(context, StudioTool.collage, collageRatio: CollageRatio.story),
      RecAction.portrait => openTool(context, StudioTool.portrait),
      RecAction.styleJacmel => openTool(context, StudioTool.styles, style: 'jacmel'),
      RecAction.colorErase => openTool(context, StudioTool.colorErase),
      RecAction.selective => openTool(context, StudioTool.selective),
      RecAction.polaroid => openTool(context, StudioTool.retouch, frame: FrameStyle.polaroid, usageKey: 'polaroid'),
    };
