import 'dart:async';
import 'dart:io';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:gal/gal.dart';
import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';

import '../../core/creations.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/kat_voucher.dart';
import '../editor/editor_model.dart';
import 'video_model.dart';
import 'video_native.dart';

/// Paints texts, stickers and the logo for one frame of [size].
void paintVideoOverlay(Canvas canvas, Size size, Iterable<VText> texts, VLogo? logo, ui.Image? logoImage) {
  if (logo != null && logoImage != null) {
    final w = size.width * logo.size;
    final h = w * logoImage.height / logoImage.width;
    final margin = size.width * 0.04;
    final x = (size.width - w - margin * 2) * (logo.corner.x + 1) / 2 + margin;
    final y = (size.height - h - margin * 2) * (logo.corner.y + 1) / 2 + margin;
    canvas.drawImageRect(
      logoImage,
      Rect.fromLTWH(0, 0, logoImage.width.toDouble(), logoImage.height.toDouble()),
      Rect.fromLTWH(x, y, w, h),
      Paint()
        ..filterQuality = FilterQuality.medium
        ..color = Color.fromRGBO(255, 255, 255, logo.opacity),
    );
  }
  for (final t in texts) {
    paintLayer(canvas, t.layer, size);
  }
}

enum _Stage { options, working, done }

Future<void> showVideoExportSheet(BuildContext context, VideoDoc doc) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      isDismissible: false,
      enableDrag: false,
      builder: (_) => _VideoExportSheet(doc: doc),
    );

class _VideoExportSheet extends StatefulWidget {
  const _VideoExportSheet({required this.doc});
  final VideoDoc doc;

  @override
  State<_VideoExportSheet> createState() => _VideoExportSheetState();
}

class _VideoExportSheetState extends State<_VideoExportSheet> {
  int _res = 1080;
  bool _high = false;
  late bool _gallery = settings.saveToGallery;
  _Stage _stage = _Stage.options;
  int _progress = 0;
  String _step = '';
  String? _error;
  Creation? _result;
  String? _galleryNote;
  Timer? _poll;
  bool _cancelled = false;

  int get _bitrate {
    final base = _res >= 1080 ? 8000000 : 5000000;
    return _high ? (base * 1.75).round() : base;
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  Future<void> _export() async {
    final doc = widget.doc;
    setState(() {
      _stage = _Stage.working;
      _progress = 0;
      _step = 'Préparation des textes et du logo…';
      _error = null;
      _cancelled = false;
    });
    final tmp = await getTemporaryDirectory();
    final stamp = DateTime.now().microsecondsSinceEpoch;
    final temps = <File>[];
    try {
      final size = exportSize(doc.aspect, _res);
      ui.Image? logoImage;
      if (doc.logo != null) logoImage = await ImageIO.decode(await File(doc.logo!.path).readAsBytes(), maxSide: 1024);
      final overlays = <Map<String, Object>>[];
      final segs = doc.overlaySegments();
      for (var i = 0; i < segs.length; i++) {
        final (a, b, texts) = segs[i];
        final img = await ImageIO.render(size.width.round(), size.height.round(), (c) => paintVideoOverlay(c, size, texts, doc.logo, logoImage));
        final png = await ImageIO.encode(img, ExportFormat.png);
        img.dispose();
        final f = File('${tmp.path}/ds_ov_${stamp}_$i.png');
        await f.writeAsBytes(png, flush: true);
        temps.add(f);
        overlays.add({'startMs': a.round(), 'endMs': b.round(), 'path': f.path});
      }
      logoImage?.dispose();

      final out = '${tmp.path}/ds_video_$stamp.mp4';
      final m = doc.music;
      final spec = <String, Object?>{
        'output': out,
        'width': size.width.round(),
        'height': size.height.round(),
        'fill': doc.fill,
        'fps': 30,
        'bitrate': _bitrate,
        'clips': [
          for (final c in doc.clips)
            {
              'path': c.path,
              'image': c.isImage,
              'inMs': c.inMs.round(),
              'outMs': c.outMs.round(),
              'durationMs': c.durationMs.round(),
              'speed': c.speed,
              'volume': c.volume,
              'mute': doc.muteAll || c.mute || !c.hasAudio,
              'rotation': c.rotation,
              'flipH': c.flipH,
              if (c.hasColorWork) 'matrix': c.matrix(),
            },
        ],
        'timeline': doc.timelineSpec(),
        'overlays': overlays,
        if (m != null)
          'music': {
            'path': m.path,
            'inMs': m.inMs.round(),
            if (!m.loop) 'outMs': (m.inMs + math.min(doc.durationMs, m.sourceMs - m.inMs)).round(),
            'volume': m.volume,
            'loop': m.loop,
          },
      };

      if (!mounted || _cancelled) return;
      setState(() => _step = 'Encodage de la vidéo…');
      _poll = Timer.periodic(const Duration(milliseconds: 400), (_) async {
        final p = await VideoNative.progress();
        if (mounted && p >= 0) setState(() => _progress = p);
      });
      final path = await VideoNative.export(spec);
      _poll?.cancel();
      if (!mounted) return;
      setState(() {
        _progress = 100;
        _step = 'Enregistrement…';
      });

      final thumbPath = '${tmp.path}/ds_vthumb_$stamp.jpg';
      await VideoNative.frame(path, 0, thumbPath, maxSide: 480);
      final thumb = await File(thumbPath).readAsBytes();
      temps.add(File(thumbPath));
      final c = await creations.addVideo(
        source: path,
        thumb: thumb,
        width: size.width.round(),
        height: size.height.round(),
        durationMs: doc.durationMs.round(),
      );
      if (_gallery) {
        try {
          if (!await Gal.hasPermission(toAlbum: true)) await Gal.requestPermission(toAlbum: true);
          await Gal.putVideo(c.file, album: 'Delicat Studio');
          _galleryNote = 'Aussi ajoutée à la galerie, album Delicat Studio.';
        } catch (_) {
          _galleryNote = 'Gardée dans tes créations. La galerie a refusé l’accès : autorise-le dans les réglages Android.';
        }
      } else {
        _galleryNote = 'Gardée dans tes créations.';
      }
      if (mounted) {
        setState(() {
          _result = c;
          _stage = _Stage.done;
        });
      }
    } catch (e) {
      _poll?.cancel();
      if (!mounted) return;
      setState(() {
        _stage = _Stage.options;
        _error = _cancelled
            ? 'Export annulé.'
            : 'La vidéo n’a pas pu être créée. Essaie en 720p, raccourcis le montage ou libère de l’espace, puis réessaie.';
      });
    } finally {
      for (final f in temps) {
        try {
          if (await f.exists()) await f.delete();
        } catch (_) {}
      }
    }
  }

  Future<void> _cancel() async {
    _cancelled = true;
    await VideoNative.cancel();
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return PopScope(
      canPop: _stage != _Stage.working,
      child: SafeArea(
        child: Padding(
          padding: const EdgeInsets.only(bottom: KatSpace.s16),
          child: switch (_stage) {
            _Stage.options => _options(k),
            _Stage.working => _working(k),
            _Stage.done => _receipt(k),
          },
        ),
      ),
    );
  }

  Widget _options(KatColors k) {
    final size = exportSize(widget.doc.aspect, _res);
    final mb = _bitrate * widget.doc.durationMs / 1000 / 8 / 1024 / 1024;
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
          child: Text('Exporter la vidéo', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
        ),
        const SizedBox(height: KatSpace.s12),
        KatChoices<int>(items: const [720, 1080], value: _res, label: (r) => '${r}p', onChanged: (r) => setState(() => _res = r)),
        KatChoices<bool>(
          items: const [false, true],
          value: _high,
          label: (h) => h ? 'Haute qualité' : 'Standard',
          onChanged: (h) => setState(() => _high = h),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, KatSpace.s4),
          child: Text(
            '${size.width.round()} × ${size.height.round()} px, MP4 H.264, environ ${mb.toStringAsFixed(mb < 10 ? 1 : 0).replaceAll('.', ',')} Mo',
            style: KatType.ui(14, color: k.inkMuted, tabular: true),
          ),
        ),
        MergeSemantics(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s4),
            child: Row(
              children: [
                Expanded(child: Text('Ajouter à la galerie', style: KatType.ui(16, weight: FontWeight.w500, color: k.ink))),
                Switch(value: _gallery, onChanged: (v) => setState(() => _gallery = v)),
              ],
            ),
          ),
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, 0),
            child: Text(_error!, style: KatType.ui(14, color: k.danger)),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, 0),
          child: Row(
            children: [
              KatButton(label: 'Annuler', kind: KatButtonKind.ghost, onPressed: () => Navigator.pop(context)),
              const SizedBox(width: KatSpace.s8),
              Expanded(child: KatButton(label: 'Exporter la vidéo', icon: Icons.movie_rounded, expand: true, onPressed: _export)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _working(KatColors k) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, 0),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Expanded(child: Text('Export en cours', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink))),
              Text('$_progress %', style: KatType.ui(24, weight: FontWeight.w700, condensed: true, tabular: true, color: k.ink)),
            ],
          ),
          const SizedBox(height: KatSpace.s12),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(value: _progress / 100, minHeight: 8),
          ),
          const SizedBox(height: KatSpace.s12),
          Text(_step, style: KatType.ui(14, color: k.inkMuted)),
          Text('Garde Delicat Studio ouvert pendant l’export.', style: KatType.ui(14, color: k.inkMuted)),
          const SizedBox(height: KatSpace.s16),
          KatButton(label: 'Annuler l’export', kind: KatButtonKind.secondary, expand: true, onPressed: _cancel),
        ],
      ),
    );
  }

  Widget _receipt(KatColors k) {
    final c = _result!;
    final secs = (c.durationMs ?? 0) ~/ 1000;
    final dur = '${(secs ~/ 60).toString().padLeft(2, '0')}:${(secs % 60).toString().padLeft(2, '0')}';
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
      child: KatVoucher(
        body: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.check_circle_rounded, color: k.onKat),
                const SizedBox(width: KatSpace.s8),
                Text('Vidéo enregistrée', style: KatType.ui(24, weight: FontWeight.w700, condensed: true, color: k.onKat)),
              ],
            ),
            const SizedBox(height: KatSpace.s16),
            Text(c.fileName, style: KatType.code(14, color: k.onKat)),
            const SizedBox(height: KatSpace.s4),
            Text('${c.width} × ${c.height} px  $dur  ${formatBytes(c.bytes)}', style: KatType.code(12, color: k.onKatMuted)),
            const SizedBox(height: KatSpace.s12),
            Text(_galleryNote ?? '', style: KatType.ui(14, color: k.onKatMuted)),
          ],
        ),
        stub: Row(
          children: [
            Expanded(
              child: KatButton(
                label: 'Partager',
                icon: Icons.share_rounded,
                expand: true,
                onPressed: () => ImageIO.shareFile(c.file, text: 'Créé avec Delicat Studio'),
              ),
            ),
            const SizedBox(width: KatSpace.s8),
            Expanded(child: KatButton(label: 'Terminé', kind: KatButtonKind.onKat, expand: true, onPressed: () => Navigator.pop(context))),
          ],
        ),
      ),
    );
  }
}

/// mm:ss for receipts and the library.
String formatDuration(int ms) {
  final s = ms ~/ 1000;
  return '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';
}
