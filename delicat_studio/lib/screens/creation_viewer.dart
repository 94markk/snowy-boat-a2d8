import 'dart:io';

import 'package:gal/gal.dart';
import 'package:material_ui/material_ui.dart';
import 'package:video_player/video_player.dart';

import '../core/creations.dart';
import '../core/image_io.dart';
import '../features/editor/editor_screen.dart';
import '../features/launch.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/kat_button.dart';
import '../widgets/share_actions.dart';
import 'creations_screen.dart';

class CreationViewer extends StatelessWidget {
  const CreationViewer({super.key, required this.creation});
  final Creation creation;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final c = creation;
    final d = c.created;
    String two(int v) => v.toString().padLeft(2, '0');
    final date = '${two(d.day)}/${two(d.month)}/${d.year} à ${two(d.hour)}h${two(d.minute)}';
    return Scaffold(
      body: Column(
        children: [
          SafeArea(
            bottom: false,
            child: SizedBox(
              height: 60,
              child: Row(
                children: [
                  const SizedBox(width: KatSpace.s4),
                  KatIconButton(icon: Icons.arrow_back_rounded, tooltip: 'Retour', onPressed: () => Navigator.pop(context)),
                  Expanded(
                    child: Text(
                      c.kind.label,
                      style: KatType.ui(16, weight: FontWeight.w600, color: k.ink),
                    ),
                  ),
                  KatIconButton(
                    icon: Icons.download_rounded,
                    tooltip: 'Ajouter à la galerie',
                    onPressed: () async {
                      try {
                        if (c.isVideo) {
                          if (!await Gal.hasPermission(toAlbum: true)) await Gal.requestPermission(toAlbum: true);
                          await Gal.putVideo(c.file, album: 'Delicat Studio');
                        } else {
                          await ImageIO.saveToGallery(await File(c.file).readAsBytes(), c.fileName.split('.').first);
                        }
                        if (context.mounted) showKatSnack(context, 'Ajoutée à la galerie, album Delicat Studio.');
                      } catch (_) {
                        if (context.mounted) showKatSnack(context, 'La galerie a refusé l’accès. Autorise-le dans les réglages Android.');
                      }
                    },
                  ),
                  KatIconButton(
                    icon: Icons.delete_outline_rounded,
                    tooltip: 'Supprimer',
                    onPressed: () async {
                      if (await deleteCreation(context, c) && context.mounted) Navigator.pop(context);
                    },
                  ),
                  const SizedBox(width: KatSpace.s8),
                ],
              ),
            ),
          ),
          Expanded(
            child: Container(
              color: k.stage,
              width: double.infinity,
              child: c.isVideo
                  ? _VideoView(path: c.file)
                  : InteractiveViewer(
                      maxScale: 6,
                      child: Center(
                        child: CustomPaint(
                          painter: CheckerPainter(k.stage, k.sheetAlt),
                          child: Image.file(File(c.file), fit: BoxFit.contain, errorBuilder: (context, _, _) => Center(child: Text('Ce fichier ne peut pas être affiché.', style: KatType.ui(14, color: KatColors.of(context).inkMuted)))),
                        ),
                      ),
                    ),
            ),
          ),
          Container(
            decoration: BoxDecoration(
              color: k.sheet,
              border: Border(top: BorderSide(color: k.line)),
            ),
            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, KatSpace.s16),
            child: SafeArea(
              top: false,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(c.fileName, style: KatType.code(14, color: k.ink)),
                  const SizedBox(height: KatSpace.s4),
                  Text('${c.width} × ${c.height} px  ${formatBytes(c.bytes)}', style: KatType.code(12, color: k.inkMuted)),
                  Text('Créée le $date', style: KatType.ui(14, color: k.inkMuted)),
                  const SizedBox(height: KatSpace.s16),
                  ShareActions(path: c.file, isVideo: c.isVideo, durationMs: c.durationMs),
                  if (!c.isVideo) ...[
                    const SizedBox(height: KatSpace.s8),
                    KatButton(
                      label: 'Retoucher',
                      icon: Icons.tune_rounded,
                      kind: KatButtonKind.ghost,
                      expand: true,
                      onPressed: () async {
                        final nav = Navigator.of(context);
                        try {
                          final photo = await withLoader(context, 'Ouverture…', () => ImageIO.load(c.file));
                          if (photo != null) await nav.push(MaterialPageRoute(builder: (_) => EditorScreen(photo: photo)));
                        } catch (_) {
                          if (context.mounted) showKatSnack(context, 'Cette image ne peut pas être ouverte.');
                        }
                      },
                    ),
                  ],
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _VideoView extends StatefulWidget {
  const _VideoView({required this.path});
  final String path;

  @override
  State<_VideoView> createState() => _VideoViewState();
}

class _VideoViewState extends State<_VideoView> {
  late final VideoPlayerController _c = VideoPlayerController.file(File(widget.path));
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _c.initialize().then((_) {
      if (!mounted) return;
      _c.setLooping(true);
      _c.play();
      setState(() {});
    }).catchError((Object _) {
      if (mounted) setState(() => _failed = true);
    });
    _c.addListener(() {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(KatSpace.s24),
          child: Text('Cette vidéo ne peut pas être lue ici. Partage-la ou ouvre-la depuis la galerie.',
              textAlign: TextAlign.center, style: KatType.ui(14, color: KatColors.of(context).inkMuted)),
        ),
      );
    }
    if (!_c.value.isInitialized) return const Center(child: CircularProgressIndicator());
    final playing = _c.value.isPlaying;
    return GestureDetector(
      onTap: () => playing ? _c.pause() : _c.play(),
      child: Stack(
        alignment: Alignment.center,
        children: [
          AspectRatio(aspectRatio: _c.value.aspectRatio, child: VideoPlayer(_c)),
          if (!playing)
            Container(
              width: 64,
              height: 64,
              decoration: const BoxDecoration(color: Color(0xB3000000), shape: BoxShape.circle),
              child: const Icon(Icons.play_arrow_rounded, size: 40, color: Color(0xFFFFFFFF)),
            ),
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: VideoProgressIndicator(_c, allowScrubbing: true, padding: const EdgeInsets.all(KatSpace.s12)),
          ),
        ],
      ),
    );
  }
}
