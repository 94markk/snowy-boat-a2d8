import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../core/image_io.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/pressable.dart';
import 'collage/collage_screen.dart';
import 'cutout/cutout_screen.dart';
import 'editor/editor_screen.dart';

/// Every entry point into the editors, shared by Home and Créations.
enum StudioTool {
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

Future<void> openTool(BuildContext context, StudioTool tool) async {
  final nav = Navigator.of(context);
  try {
    if (tool == StudioTool.collage) {
      final photos = await ImageIO.pickMany(limit: 9);
      if (photos.isEmpty) return;
      await nav.push(MaterialPageRoute(builder: (_) => CollageScreen(photos: photos)));
      return;
    }
    if (!context.mounted) return;
    final source = await pickSource(context);
    if (source == null || !context.mounted) return;
    final photo = await ImageIO.pick(source);
    if (photo == null) return;
    final Widget screen = switch (tool) {
      StudioTool.cutout => CutoutScreen(photo: photo),
      StudioTool.portrait => CutoutScreen(photo: photo, preset: CutoutPreset.portrait),
      StudioTool.sticker => CutoutScreen(photo: photo, preset: CutoutPreset.sticker),
      StudioTool.selective => CutoutScreen(photo: photo, preset: CutoutPreset.selective),
      StudioTool.colorErase => CutoutScreen(photo: photo, preset: CutoutPreset.colorErase),
      StudioTool.styles => EditorScreen(photo: photo, initialTool: EditorTool.styles),
      StudioTool.retouch || StudioTool.collage => EditorScreen(photo: photo),
    };
    await nav.push(MaterialPageRoute(builder: (_) => screen));
  } catch (e) {
    if (context.mounted) {
      showKatSnack(context, 'Impossible d’ouvrir la photo. Vérifie l’accès aux photos dans les réglages Android, puis réessaie.');
    }
  }
}
