import 'dart:math' as math;
import 'dart:typed_data';

import 'package:material_ui/material_ui.dart';

import '../../core/creations.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../export/export_sheet.dart';
import '../launch.dart';
import 'cutout_controller.dart';
import 'cutout_model.dart';
import 'segmentation.dart';

enum CutoutPreset { standard, portrait, sticker, selective, colorErase }

const _tools = [
  ToolItem('fond', Icons.wallpaper_rounded, 'Fond'),
  ToolItem('pinceau', Icons.brush_rounded, 'Pinceau'),
  ToolItem('gomme', Icons.format_color_reset_rounded, 'Gomme couleur'),
  ToolItem('bords', Icons.blur_linear_rounded, 'Bords'),
  ToolItem('contour', Icons.border_outer_rounded, 'Contour'),
  ToolItem('ombre', Icons.gradient_rounded, 'Ombre'),
];

class CutoutScreen extends StatefulWidget {
  const CutoutScreen({super.key, required this.photo, this.preset = CutoutPreset.standard, this.debugConfidence});
  final Photo photo;
  final CutoutPreset preset;

  /// Test hook: a precomputed subject mask instead of running ML Kit.
  @visibleForTesting
  final Float32List? debugConfidence;

  @override
  State<CutoutScreen> createState() => _CutoutScreenState();
}

class _CutoutScreenState extends State<CutoutScreen> {
  late final CutoutController c;
  late String _tool;

  // Brush settings (size in screen pixels so it feels the same at any zoom).
  BrushMode _brush = BrushMode.erase;
  double _brushSize = 40;
  double _hardness = 0.7;
  double _tolerance = 0.16;
  bool _contiguous = true;

  // View transform.
  Size? _viewport;
  double _fit = 1;
  double _scale = 1;
  Offset _origin = Offset.zero;
  double _gScale0 = 1;
  Offset _gOrigin0 = Offset.zero;
  Offset _gFocal0 = Offset.zero;
  Offset _gImage0 = Offset.zero;
  bool _navigating = false;
  Offset? _cursor;

  CutoutDoc? _dragBase;

  String get _title => switch (widget.preset) {
        CutoutPreset.standard => 'Retirer le fond',
        CutoutPreset.portrait => 'Portrait flou',
        CutoutPreset.sticker => 'Sticker',
        CutoutPreset.selective => 'Couleur sélective',
        CutoutPreset.colorErase => 'Gomme couleur',
      };

  @override
  void initState() {
    super.initState();
    final initial = switch (widget.preset) {
      CutoutPreset.standard => const CutoutDoc(),
      CutoutPreset.portrait => const CutoutDoc(bg: BgMode.blur, bgBlur: 0.016, feather: 0.002),
      CutoutPreset.sticker => const CutoutDoc(
          outline: 0.012, shadow: true, shadowBlur: 0.008, shadowOffset: 0.006, shadowOpacity: 0.3),
      CutoutPreset.selective => const CutoutDoc(bg: BgMode.mono, feather: 0.002),
      CutoutPreset.colorErase => const CutoutDoc(),
    };
    _tool = switch (widget.preset) {
      CutoutPreset.sticker => 'contour',
      CutoutPreset.colorErase => 'gomme',
      _ => 'fond',
    };
    c = CutoutController(
      widget.photo.image,
      initial: initial,
      runDetection: widget.preset != CutoutPreset.colorErase,
      confidence: widget.debugConfidence,
    );
  }

  @override
  void dispose() {
    c.dispose();
    super.dispose();
  }

  // ---- view ---------------------------------------------------------------

  void _fitTo(Size s) {
    _viewport = s;
    _fit = math.min((s.width - 32) / c.w, (s.height - 32) / c.h);
    _scale = _fit;
    _origin = Offset((s.width - c.w * _scale) / 2, (s.height - c.h * _scale) / 2);
  }

  Offset _toImage(Offset local) => (local - _origin) / _scale;

  bool get _canEdit => c.status == CutoutStatus.ready || c.status == CutoutStatus.manual;

  void _onScaleStart(ScaleStartDetails d) {
    _gScale0 = _scale;
    _gOrigin0 = _origin;
    _gFocal0 = d.localFocalPoint;
    _gImage0 = _toImage(d.localFocalPoint);
    if (d.pointerCount >= 2) _navigating = true;
    if (_tool == 'pinceau' && d.pointerCount == 1 && !_navigating && _canEdit) {
      c.startStroke(_gImage0, _brushSize / _scale, _hardness, _brush);
      setState(() => _cursor = d.localFocalPoint);
    }
  }

  void _onScaleUpdate(ScaleUpdateDetails d) {
    if (d.pointerCount >= 2) {
      _navigating = true;
      if (c.live != null) c.cancelStroke();
      setState(() {
        _scale = (_gScale0 * d.scale).clamp(_fit * 0.5, _fit * 12);
        _origin = d.localFocalPoint - _gImage0 * _scale;
        _cursor = null;
      });
    } else if (c.live != null) {
      c.extendStroke(_toImage(d.localFocalPoint));
      setState(() => _cursor = d.localFocalPoint);
    } else if (_tool != 'pinceau' && _scale > _fit * 1.01) {
      setState(() => _origin = _gOrigin0 + (d.localFocalPoint - _gFocal0));
    }
  }

  void _onScaleEnd(ScaleEndDetails d) {
    if (c.live != null) {
      // A finger was added or lifted mid-gesture: keep long strokes, drop accidental starts.
      if (d.pointerCount == 0 || c.live!.points.length > 12) {
        c.endStroke();
      } else {
        c.cancelStroke();
      }
    }
    if (d.pointerCount == 0) _navigating = false;
    setState(() => _cursor = null);
  }

  Future<void> _onTapUp(TapUpDetails d) async {
    if (_tool != 'gomme' || !_canEdit) return;
    final p = _toImage(d.localPosition);
    if (p.dx < 0 || p.dy < 0 || p.dx >= c.w || p.dy >= c.h) return;
    final n = await c.eraseColorAt(p, _tolerance, _contiguous);
    if (!mounted) return;
    if (n == 0) showKatSnack(context, 'Rien à effacer ici. Augmente la tolérance ou touche une zone plus unie.');
  }

  // ---- actions ------------------------------------------------------------

  Future<void> _close() async {
    if (c.history.canUndo) {
      final ok = await confirmKat(context,
          title: 'Quitter sans enregistrer ?', message: 'Tes retouches sur cette photo seront perdues.', confirm: 'Quitter');
      if (!ok) return;
    }
    if (mounted) Navigator.pop(context);
  }

  Future<void> _export() async {
    final transparent = c.doc.bg == BgMode.transparent;
    await showExportSheet(
      context,
      kind: CreationKind.cutout,
      transparent: transparent,
      offerSubjectCrop: transparent,
      render: (r) => c.renderFull(cropToSubject: r.cropToSubject),
    );
  }

  Future<void> _pickBackground() async {
    final src = await pickSource(context);
    if (src == null) return;
    try {
      final p = await ImageIO.pick(src);
      if (p != null) c.setBgPhoto(p.image);
    } catch (_) {
      if (mounted) showKatSnack(context, 'Impossible d’ouvrir cette photo. Essaie une autre image.');
    }
  }

  void _live(CutoutDoc next) {
    _dragBase ??= c.doc;
    c.preview(next);
  }

  void _commitLive() {
    final base = _dragBase;
    _dragBase = null;
    if (base != null) c.commit(base);
  }

  // ---- build ----------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _close();
      },
      child: Scaffold(
        backgroundColor: k.stage,
        body: ListenableBuilder(
          listenable: c,
          builder: (context, _) => Column(
            children: [
              ColoredBox(
                color: k.canvas,
                child: EditorTopBar(
                  title: _title,
                  onClose: _close,
                  onPrimary: c.status == CutoutStatus.analyzing ? null : _export,
                  actions: [
                    KatIconButton(icon: Icons.undo_rounded, tooltip: 'Annuler', onPressed: c.history.canUndo ? c.history.undo : null),
                    KatIconButton(icon: Icons.redo_rounded, tooltip: 'Rétablir', onPressed: c.history.canRedo ? c.history.redo : null),
                    HoldToCompare(onChanged: c.setOriginal),
                  ],
                ),
              ),
              Expanded(child: _canvas(k)),
              ToolPanel(
                child: AnimatedSize(
                  duration: reduceMotion(context) ? Duration.zero : KatMotion.press,
                  curve: KatMotion.curve,
                  alignment: Alignment.topCenter,
                  child: _panel(k),
                ),
              ),
              EditorToolBar(items: _tools, selected: _tool, onSelect: (t) => setState(() => _tool = t)),
            ],
          ),
        ),
      ),
    );
  }

  Widget _canvas(KatColors k) {
    return LayoutBuilder(builder: (context, box) {
      final size = box.biggest;
      if (_viewport != size) _fitTo(size);
      return Stack(
        children: [
          Positioned.fill(
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onScaleStart: _onScaleStart,
              onScaleUpdate: _onScaleUpdate,
              onScaleEnd: _onScaleEnd,
              onTapUp: _onTapUp,
              onDoubleTap: () => setState(() => _fitTo(size)),
              child: CustomPaint(
                painter: _CutoutPainter(
                  controller: c,
                  origin: _origin,
                  scale: _scale,
                  checkA: k.sheet,
                  checkB: k.sheetAlt,
                  frame: k.line,
                  cursor: _cursor,
                  cursorRadius: _brushSize / 2,
                ),
              ),
            ),
          ),
          if (c.status == CutoutStatus.analyzing)
            Positioned(top: KatSpace.s16, left: 0, right: 0, child: Center(child: _Pill(spinner: true, text: 'Détection du sujet…'))),
          if (c.busy) Positioned(top: KatSpace.s16, left: 0, right: 0, child: Center(child: _Pill(spinner: true, text: 'Effacement…'))),
          if (c.status == CutoutStatus.failed)
            Positioned(
              left: KatSpace.gutter,
              right: KatSpace.gutter,
              bottom: KatSpace.gutter,
              child: KatSheet(
                radius: KatRadius.sheet,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Détection impossible', style: KatType.ui(16, weight: FontWeight.w600, color: k.ink)),
                    const SizedBox(height: KatSpace.s4),
                    Text(c.error ?? '', style: KatType.ui(14, color: k.inkMuted)),
                    const SizedBox(height: KatSpace.s12),
                    Row(
                      children: [
                        KatButton(label: 'Réessayer', kind: KatButtonKind.secondary, compact: true, onPressed: c.detect),
                        const SizedBox(width: KatSpace.s8),
                        KatButton(label: 'Détourer à la main', kind: KatButtonKind.ghost, compact: true, onPressed: () {
                          c.continueManually();
                          setState(() => _tool = 'gomme');
                        }),
                      ],
                    ),
                  ],
                ),
              ),
            ),
        ],
      );
    });
  }

  Widget _note(KatColors k, String text) => Padding(
        padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, KatSpace.s4),
        child: Text(text, style: KatType.ui(14, color: k.inkMuted)),
      );

  Widget _panel(KatColors k) {
    final d = c.doc;
    String pct(double v) => '${v.round()}';
    switch (_tool) {
      case 'fond':
        return Column(
          key: const ValueKey('fond'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<BgMode>(
              items: BgMode.values,
              value: d.bg,
              icons: (b) => b.icon,
              label: (b) => b.label,
              onChanged: (b) => b == BgMode.photo && c.bgPhoto == null ? _pickBackground() : c.set(d.copyWith(bg: b)),
            ),
            if (d.bg == BgMode.color) ColorSwatches(value: d.bgColor, onChanged: (col) => c.set(d.copyWith(bgColor: col))),
            if (d.bg == BgMode.blur)
              SliderRow(
                label: 'Flou',
                value: d.bgBlur * 2000,
                min: 2,
                max: 100,
                format: pct,
                onChanged: (v) => _live(c.doc.copyWith(bgBlur: v / 2000)),
                onChangeEnd: (_) => _commitLive(),
              ),
            if (d.bg == BgMode.photo)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s4),
                child: KatButton(label: 'Changer la photo de fond', icon: Icons.image_rounded, kind: KatButtonKind.secondary, expand: true, onPressed: _pickBackground),
              ),
            if (d.bg == BgMode.transparent) _note(k, 'Le damier montre la transparence. Enregistre en PNG pour la garder.'),
            if (d.bg == BgMode.mono) _note(k, 'Ton sujet garde ses couleurs, le décor passe en noir et blanc.'),
          ],
        );
      case 'pinceau':
        return Column(
          key: const ValueKey('pinceau'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<BrushMode>(
              items: BrushMode.values,
              value: _brush,
              icons: (b) => b == BrushMode.erase ? Icons.backspace_rounded : Icons.healing_rounded,
              label: (b) => b == BrushMode.erase ? 'Effacer' : 'Restaurer',
              onChanged: (b) => setState(() => _brush = b),
            ),
            SliderRow(label: 'Taille', value: _brushSize, min: 8, max: 140, onChanged: (v) => setState(() => _brushSize = v)),
            SliderRow(
              label: 'Dureté',
              value: _hardness * 100,
              min: 0,
              max: 100,
              format: (v) => '${v.round()} %',
              onChanged: (v) => setState(() => _hardness = v / 100),
            ),
            _note(k, 'Un doigt pour peindre, deux doigts pour zoomer et te déplacer.'),
          ],
        );
      case 'gomme':
        return Column(
          key: const ValueKey('gomme'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<bool>(
              items: const [true, false],
              value: _contiguous,
              label: (v) => v ? 'Zone touchée' : 'Toute la couleur',
              onChanged: (v) => setState(() => _contiguous = v),
            ),
            SliderRow(
              label: 'Tolérance',
              value: _tolerance * 100,
              min: 2,
              max: 60,
              format: (v) => '${v.round()} %',
              onChanged: (v) => setState(() => _tolerance = v / 100),
            ),
            _note(k, 'Touche la couleur à effacer. Parfait pour les fonds unis, les logos et les produits.'),
          ],
        );
      case 'bords':
        if (!c.hasAiMask) {
          return Column(
            key: const ValueKey('bords-off'),
            mainAxisSize: MainAxisSize.min,
            children: [
              _note(k, 'Les réglages de bords s’appliquent à la détection automatique.'),
              if (c.status == CutoutStatus.manual)
                Padding(
                  padding: const EdgeInsets.all(KatSpace.s8),
                  child: KatButton(label: 'Lancer la détection', icon: Icons.auto_awesome_rounded, kind: KatButtonKind.secondary, onPressed: c.detect),
                ),
            ],
          );
        }
        return Column(
          key: const ValueKey('bords'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SliderRow(
              label: 'Seuil',
              value: d.threshold * 100,
              min: 10,
              max: 90,
              format: pct,
              onChanged: (v) => _live(c.doc.copyWith(threshold: v / 100)),
              onChangeEnd: (_) => _commitLive(),
            ),
            SliderRow(
              label: 'Transition',
              value: d.softness * 100,
              min: 0,
              max: 60,
              format: pct,
              onChanged: (v) => _live(c.doc.copyWith(softness: v / 100)),
              onChangeEnd: (_) => _commitLive(),
            ),
            SliderRow(
              label: 'Adoucir',
              value: d.feather * 10000,
              min: 0,
              max: 80,
              format: pct,
              onChanged: (v) => _live(c.doc.copyWith(feather: v / 10000)),
              onChangeEnd: (_) => _commitLive(),
            ),
            _note(
              k,
              c.engine == SegEngine.selfie
                  ? 'Détection portrait active : optimisée pour les personnes.'
                  : 'Monte le seuil pour resserrer autour du sujet, baisse-le pour en garder plus.',
            ),
          ],
        );
      case 'contour':
        final on = d.outline > 0;
        return Column(
          key: const ValueKey('contour'),
          mainAxisSize: MainAxisSize.min,
          children: [
            ColorSwatches(
              value: on ? d.outlineColor : null,
              onChanged: (col) => c.set(d.copyWith(outlineColor: col, outline: on ? d.outline : 0.012)),
            ),
            SliderRow(
              label: 'Épaisseur',
              value: d.outline * 2500,
              min: 0,
              max: 100,
              format: pct,
              onChanged: (v) => _live(c.doc.copyWith(outline: v / 2500)),
              onChangeEnd: (_) => _commitLive(),
            ),
          ],
        );
      case 'ombre':
        return Column(
          key: const ValueKey('ombre'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<bool>(
              items: const [false, true],
              value: d.shadow,
              label: (v) => v ? 'Avec ombre' : 'Sans ombre',
              onChanged: (v) => c.set(d.copyWith(shadow: v)),
            ),
            if (d.shadow) ...[
              SliderRow(
                label: 'Flou',
                value: d.shadowBlur * 2500,
                min: 0,
                max: 100,
                format: pct,
                onChanged: (v) => _live(c.doc.copyWith(shadowBlur: v / 2500)),
                onChangeEnd: (_) => _commitLive(),
              ),
              SliderRow(
                label: 'Distance',
                value: d.shadowOffset * 2500,
                min: 0,
                max: 100,
                format: pct,
                onChanged: (v) => _live(c.doc.copyWith(shadowOffset: v / 2500)),
                onChangeEnd: (_) => _commitLive(),
              ),
              SliderRow(
                label: 'Opacité',
                value: d.shadowOpacity * 100,
                min: 5,
                max: 100,
                format: (v) => '${v.round()} %',
                onChanged: (v) => _live(c.doc.copyWith(shadowOpacity: v / 100)),
                onChangeEnd: (_) => _commitLive(),
              ),
            ],
          ],
        );
    }
    return const SizedBox.shrink();
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.text, this.spinner = false});
  final String text;
  final bool spinner;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16, vertical: KatSpace.s12),
      decoration: BoxDecoration(color: k.kat, borderRadius: BorderRadius.circular(999)),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (spinner) ...[
            SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2, color: k.onKat, backgroundColor: k.kat)),
            const SizedBox(width: KatSpace.s8),
          ],
          Text(text, style: KatType.ui(14, weight: FontWeight.w500, color: k.onKat)),
        ],
      ),
    );
  }
}

/// Shows the original photo while held.
class HoldToCompare extends StatelessWidget {
  const HoldToCompare({super.key, required this.onChanged});
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Tooltip(
      message: 'Maintenir pour voir l’original',
      child: Semantics(
        button: true,
        label: 'Maintenir pour voir l’original',
        child: Listener(
          onPointerDown: (_) => onChanged(true),
          onPointerUp: (_) => onChanged(false),
          onPointerCancel: (_) => onChanged(false),
          child: SizedBox.square(dimension: KatSpace.minTap, child: Icon(Icons.compare_rounded, color: k.ink)),
        ),
      ),
    );
  }
}

class _CutoutPainter extends CustomPainter {
  _CutoutPainter({
    required this.controller,
    required this.origin,
    required this.scale,
    required this.checkA,
    required this.checkB,
    required this.frame,
    required this.cursor,
    required this.cursorRadius,
  }) : super(repaint: controller);

  final CutoutController controller;
  final Offset origin;
  final double scale;
  final Color checkA;
  final Color checkB;
  final Color frame;
  final Offset? cursor;
  final double cursorRadius;

  @override
  void paint(Canvas canvas, Size size) {
    final c = controller;
    final imgRect = Rect.fromLTWH(origin.dx, origin.dy, c.w * scale, c.h * scale);
    canvas.clipRect(Offset.zero & size);
    if (c.doc.bg == BgMode.transparent && !c.showOriginal) {
      canvas.save();
      canvas.clipRect(imgRect);
      CheckerPainter(checkA, checkB, cell: 12).paint(canvas, size);
      canvas.restore();
    }
    canvas.save();
    canvas.translate(origin.dx, origin.dy);
    canvas.scale(scale);
    final scene = c.status == CutoutStatus.analyzing || c.status == CutoutStatus.failed
        ? CutoutScene(photo: c.photo, mask: null, doc: c.doc, showOriginal: true)
        : c.scene();
    paintCutout(canvas, scene);
    canvas.restore();
    canvas.drawRect(imgRect, Paint()
      ..style = PaintingStyle.stroke
      ..color = frame);
    final cur = cursor;
    if (cur != null) {
      canvas.drawCircle(cur, cursorRadius, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3
        ..color = const Color(0x99000000));
      canvas.drawCircle(cur, cursorRadius, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5
        ..color = const Color(0xFFFFFFFF));
    }
  }

  @override
  bool shouldRepaint(_CutoutPainter old) => true;
}
