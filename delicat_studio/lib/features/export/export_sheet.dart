import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../core/crash_log.dart';
import '../../core/creations.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/kat_voucher.dart';
import '../../widgets/share_actions.dart';

class ExportRequest {
  const ExportRequest({required this.cropToSubject});
  final bool cropToSubject;
}

typedef ExportRenderer = Future<ui.Image> Function(ExportRequest request);

/// Completes with the saved creation, or null if the sheet was closed first.
Future<Creation?> showExportSheet(
  BuildContext context, {
  required ExportRenderer render,
  required CreationKind kind,
  bool transparent = false,
  bool offerSubjectCrop = false,
}) {
  return showModalBottomSheet<Creation>(
    context: context,
    isScrollControlled: true,
    builder: (_) => _ExportSheet(render: render, kind: kind, transparent: transparent, offerSubjectCrop: offerSubjectCrop),
  );
}

enum _Stage { options, working, done }

class _ExportSheet extends StatefulWidget {
  const _ExportSheet({required this.render, required this.kind, required this.transparent, required this.offerSubjectCrop});
  final ExportRenderer render;
  final CreationKind kind;
  final bool transparent;
  final bool offerSubjectCrop;

  @override
  State<_ExportSheet> createState() => _ExportSheetState();
}

class _ExportSheetState extends State<_ExportSheet> {
  late ExportFormat _format = widget.transparent ? ExportFormat.png : settings.format;
  late int _quality = settings.jpgQuality;
  late bool _gallery = settings.saveToGallery;
  late bool _cropSubject = widget.offerSubjectCrop;
  _Stage _stage = _Stage.options;
  Creation? _result;
  String? _galleryNote;
  String? _error;

  Future<void> _save() async {
    setState(() {
      _stage = _Stage.working;
      _error = null;
    });
    try {
      final image = await widget.render(ExportRequest(cropToSubject: _cropSubject));
      final w = image.width;
      final h = image.height;
      final Uint8List bytes;
      final Uint8List thumb;
      try {
        bytes = await ImageIO.encode(image, _format, quality: _quality);
        thumb = await ImageIO.thumbnail(image);
      } finally {
        image.dispose();
      }
      final c = await creations.add(
        bytes: bytes,
        thumb: thumb,
        ext: _format == ExportFormat.png ? 'png' : 'jpg',
        kind: widget.kind,
        width: w,
        height: h,
      );
      if (_gallery) {
        try {
          await ImageIO.saveToGallery(bytes, c.fileName.split('.').first);
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
    } catch (e, st) {
      CrashLog.record(e, st, context: 'photo export');
      if (mounted) {
        setState(() {
          _stage = _Stage.options;
          _error = 'L’image n’a pas pu être créée. Libère un peu de mémoire ou baisse la taille d’import dans Réglages, puis réessaie.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SafeArea(
      child: AnimatedSize(
        duration: reduceMotion(context) ? Duration.zero : KatMotion.press,
        curve: KatMotion.curve,
        alignment: Alignment.topCenter,
        child: Padding(
          padding: const EdgeInsets.only(bottom: KatSpace.s16),
          child: switch (_stage) {
            _Stage.options => _options(k),
            _Stage.working => Padding(
                padding: const EdgeInsets.all(KatSpace.s32),
                child: Column(
                  children: [
                    SizedBox.square(dimension: 32, child: CircularProgressIndicator(strokeWidth: 3, color: k.ink)),
                    const SizedBox(height: KatSpace.s16),
                    Text('Création de l’image en pleine résolution…', style: KatType.ui(16, color: k.ink)),
                  ],
                ),
              ),
            _Stage.done => _receipt(k),
          },
        ),
      ),
    );
  }

  Widget _options(KatColors k) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
          child: Text('Enregistrer l’image', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
        ),
        const SizedBox(height: KatSpace.s12),
        KatChoices<ExportFormat>(
          items: ExportFormat.values,
          value: _format,
          label: (f) => f == ExportFormat.png ? 'PNG transparent' : 'JPG plus léger',
          onChanged: (f) => setState(() => _format = f),
        ),
        if (widget.transparent && _format == ExportFormat.jpg)
          Padding(
            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, 0),
            child: Text('Le JPG remplace la transparence par du blanc.', style: KatType.ui(14, color: k.inkMuted)),
          ),
        if (_format == ExportFormat.jpg)
          SliderRow(
            label: 'Qualité',
            value: _quality.toDouble(),
            min: 60,
            max: 100,
            divisions: 40,
            format: (v) => '${v.round()} %',
            onChanged: (v) => setState(() => _quality = v.round()),
          ),
        if (widget.offerSubjectCrop) _switch(k, 'Recadrer autour du sujet', 'Idéal pour les stickers', _cropSubject, (v) => setState(() => _cropSubject = v)),
        _switch(k, 'Ajouter à la galerie', 'Album Delicat Studio', _gallery, (v) => setState(() => _gallery = v)),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, 0),
            child: Text(_error!, style: KatType.ui(14, color: k.danger)),
          ),
        Padding(
          padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, 0),
          child: KatButton(label: 'Enregistrer', icon: Icons.download_rounded, expand: true, onPressed: _save),
        ),
      ],
    );
  }

  Widget _switch(KatColors k, String title, String sub, bool value, ValueChanged<bool> onChanged) {
    return MergeSemantics(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s4),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: KatType.ui(16, weight: FontWeight.w500, color: k.ink)),
                  Text(sub, style: KatType.ui(14, color: k.inkMuted)),
                ],
              ),
            ),
            Switch(value: value, onChanged: onChanged),
          ],
        ),
      ),
    );
  }

  Widget _receipt(KatColors k) {
    final c = _result!;
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
                Text('Image enregistrée', style: KatType.ui(24, weight: FontWeight.w700, condensed: true, color: k.onKat)),
              ],
            ),
            const SizedBox(height: KatSpace.s16),
            Text(c.fileName, style: KatType.code(14, color: k.onKat)),
            const SizedBox(height: KatSpace.s4),
            Text('${c.width} × ${c.height} px  ${formatBytes(c.bytes)}', style: KatType.code(12, color: k.onKatMuted)),
            const SizedBox(height: KatSpace.s12),
            Text(_galleryNote ?? '', style: KatType.ui(14, color: k.onKatMuted)),
          ],
        ),
        stub: Column(
          children: [
            ShareActions(path: c.file, isVideo: false, onKat: true),
            const SizedBox(height: KatSpace.s8),
            KatButton(label: 'Terminé', kind: KatButtonKind.onKat, expand: true, onPressed: () => Navigator.pop(context, c)),
          ],
        ),
      ),
    );
  }
}
