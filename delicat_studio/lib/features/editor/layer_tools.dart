import 'dart:io';
import 'dart:isolate';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';

import '../../core/brand.dart';
import '../../core/crash_log.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../cutout/mask_ops.dart';
import '../cutout/segmentation.dart';
import 'editor_model.dart';

/// A picture saved for a layer: PNG path and width / height.
typedef LayerPicture = ({String path, double aspect});

/// Removes the background of [photo] on the phone and crops to the subject.
Future<ui.Image> cutOutSubject(ui.Image photo) async {
  final seg = await segmentPhoto(photo);
  final w = photo.width;
  final h = photo.height;
  final conf = seg.confidence;
  final (rgba, bounds) = await Isolate.run(() {
    final alpha = alphaFromConfidence(conf, w * h, 0.5, 0.2, null);
    return (alphaToRgba(alpha), alphaBounds(alpha, w, h));
  });
  final mask = await ImageIO.fromRgba(rgba, w, h);
  try {
    final b = bounds == null ? Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble()) : Rect.fromLTRB(bounds[0].toDouble(), bounds[1].toDouble(), bounds[2].toDouble(), bounds[3].toDouble());
    final crop = b.inflate(math.max(w, h) * 0.02).intersect(Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble()));
    return await ImageIO.render(math.max(1, crop.width.round()), math.max(1, crop.height.round()), (c) {
      c.translate(-crop.left, -crop.top);
      final full = Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble());
      c.saveLayer(full, Paint());
      c.drawImage(photo, Offset.zero, Paint());
      c.drawImage(mask, Offset.zero, Paint()..blendMode = BlendMode.dstIn);
      c.restore();
    });
  } finally {
    mask.dispose();
  }
}

/// Lets the person pick a picture (optionally without its background) and stores it as a PNG.
Future<LayerPicture?> pickLayerPicture(BuildContext context, {String? title}) async {
  final choice = await showModalBottomSheet<bool>(
    context: context,
    builder: (c) {
      final k = KatColors.of(c);
      return SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(KatSpace.gutter, 0, KatSpace.gutter, KatSpace.s24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title ?? 'Ajouter une photo', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
              const SizedBox(height: KatSpace.s8),
              Text('Retire le fond pour poser un produit sur l’affiche.', style: KatType.ui(14, color: k.inkMuted)),
              const SizedBox(height: KatSpace.s16),
              KatButton(label: 'Sans le fond', icon: Icons.auto_fix_high_rounded, expand: true, onPressed: () => Navigator.pop(c, true)),
              const SizedBox(height: KatSpace.s8),
              KatButton(label: 'Photo entière', icon: Icons.image_rounded, kind: KatButtonKind.secondary, expand: true, onPressed: () => Navigator.pop(c, false)),
            ],
          ),
        ),
      );
    },
  );
  if (choice == null || !context.mounted) return null;
  final max = settings.importQuality.maxSide.toDouble();
  final XFile? f;
  try {
    f = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: max, maxHeight: max, requestFullMetadata: false);
  } catch (_) {
    if (context.mounted) showKatSnack(context, 'La galerie n’a pas pu s’ouvrir. Vérifie l’accès aux photos.');
    return null;
  }
  if (f == null || !context.mounted) return null;
  final path = f.path;
  return showLoaderWhile(context, choice ? 'Détourage du produit…' : 'Préparation de la photo…', () async {
    ui.Image? src;
    ui.Image? out;
    try {
      src = await ImageIO.decode(await File(path).readAsBytes(), maxSide: 1600);
      out = choice ? await cutOutSubject(src) : src;
      final png = await ImageIO.encode(out, ExportFormat.png);
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/ds_layer_${DateTime.now().microsecondsSinceEpoch}.png');
      await file.writeAsBytes(png, flush: true);
      return (path: file.path, aspect: out.width / out.height);
    } on SegmentationException catch (e) {
      if (context.mounted) showKatSnack(context, e.message);
      return null;
    } catch (e, s) {
      CrashLog.record(e, s, context: 'layer picture');
      if (context.mounted) showKatSnack(context, 'Cette photo n’a pas pu être ajoutée. Essaie une autre image.');
      return null;
    } finally {
      if (out != null && out != src) out.dispose();
      src?.dispose();
    }
  });
}

/// Calm modal loader that cannot be dismissed while [task] runs.
Future<T?> showLoaderWhile<T>(BuildContext context, String message, Future<T?> Function() task) async {
  final nav = Navigator.of(context, rootNavigator: true);
  var shown = true;
  showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (c) {
      final k = KatColors.of(c);
      return PopScope(
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
      );
    },
  ).whenComplete(() => shown = false);
  try {
    return await task();
  } finally {
    if (shown) nav.pop();
  }
}

/// Asks for the text or link of a QR code. Returns null when dismissed.
Future<String?> showQrSheet(BuildContext context, {String? initial}) {
  return showModalBottomSheet<String>(
    context: context,
    isScrollControlled: true,
    builder: (_) => _QrSheet(initial: initial),
  );
}

class _QrSheet extends StatefulWidget {
  const _QrSheet({this.initial});
  final String? initial;

  @override
  State<_QrSheet> createState() => _QrSheetState();
}

class _QrSheetState extends State<_QrSheet> {
  late final TextEditingController _c = TextEditingController(text: widget.initial ?? brand.kit.url);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  String? get _waLink {
    final digits = brand.kit.whatsapp.replaceAll(RegExp(r'[^0-9]'), '');
    return digits.length < 8 ? null : 'https://wa.me/$digits';
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final value = _c.text.trim();
    final valid = value.isNotEmpty && qrFor(value) != null;
    final wa = _waLink;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(KatSpace.gutter, 0, KatSpace.gutter, KatSpace.s16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Code QR', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
              const SizedBox(height: KatSpace.s4),
              Text('Tes clients le scannent pour ouvrir ta boutique ou t’écrire.', style: KatType.ui(14, color: k.inkMuted)),
              const SizedBox(height: KatSpace.s12),
              Wrap(
                spacing: KatSpace.s8,
                runSpacing: KatSpace.s8,
                children: [
                  KatButton(label: 'Ma boutique', icon: Icons.storefront_rounded, kind: KatButtonKind.secondary, compact: true, onPressed: () => setState(() => _c.text = brand.kit.url)),
                  if (wa != null)
                    KatButton(label: 'Mon WhatsApp', icon: Icons.chat_rounded, kind: KatButtonKind.secondary, compact: true, onPressed: () => setState(() => _c.text = wa)),
                ],
              ),
              const SizedBox(height: KatSpace.s12),
              TextField(
                controller: _c,
                keyboardType: TextInputType.url,
                autocorrect: false,
                onChanged: (_) => setState(() {}),
                style: KatType.code(16, color: k.ink),
                decoration: const InputDecoration(hintText: 'Lien ou texte'),
              ),
              const SizedBox(height: KatSpace.s16),
              Center(
                child: SizedBox.square(
                  dimension: 160,
                  child: valid
                      ? CustomPaint(painter: _QrPreview(value))
                      : DecoratedBox(
                          decoration: BoxDecoration(border: Border.all(color: k.line), borderRadius: BorderRadius.circular(KatRadius.tile)),
                          child: Center(
                            child: Padding(
                              padding: const EdgeInsets.all(KatSpace.s12),
                              child: Text(value.isEmpty ? 'Écris un lien' : 'Texte trop long pour un code QR', textAlign: TextAlign.center, style: KatType.ui(14, color: k.inkMuted)),
                            ),
                          ),
                        ),
                ),
              ),
              const SizedBox(height: KatSpace.s16),
              KatButton(label: 'Utiliser ce code', expand: true, onPressed: valid ? () => Navigator.pop(context, value) : null),
            ],
          ),
        ),
      ),
    );
  }
}

class _QrPreview extends CustomPainter {
  _QrPreview(this.data);
  final String data;

  @override
  void paint(Canvas canvas, Size size) {
    paintLayer(canvas, EditorLayer(id: 'preview', qr: data, size: 1, color: const Color(0xFF141834), pos: const Offset(0.5, 0.5)), size);
  }

  @override
  bool shouldRepaint(_QrPreview old) => old.data != data;
}
