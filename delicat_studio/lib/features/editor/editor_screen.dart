import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../core/creations.dart';
import '../../core/history.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/pressable.dart';
import '../../widgets/style_strip.dart';
import '../export/export_sheet.dart';
import 'editor_model.dart';
import 'text_sheet.dart';

export 'editor_model.dart' show EditorTool;

const _toolItems = [
  ToolItem('crop', Icons.crop_rotate_rounded, 'Recadrer'),
  ToolItem('adjust', Icons.tune_rounded, 'Réglages'),
  ToolItem('styles', Icons.palette_rounded, 'Styles'),
  ToolItem('text', Icons.title_rounded, 'Texte'),
  ToolItem('stickers', Icons.emoji_emotions_rounded, 'Stickers'),
  ToolItem('draw', Icons.draw_rounded, 'Dessin'),
  ToolItem('frame', Icons.crop_din_rounded, 'Cadre'),
];

const _emojis = [
  '😍', '🥰', '😎', '🤩', '😂', '🥳', '😇', '🤍', '❤️', '🧡', '💛', '💚', '💙', '💜', '✨', '⭐', '🌟', '🔥', //
  '💯', '🎉', '🎁', '👑', '💎', '🌸', '🌺', '🌻', '🌴', '🌊', '☀️', '🌙', '🦋', '🐚', '🍹', '🥥', '🍍', '🥭',
  '📸', '🎶', '💬', '👍', '🙌', '💪', '👀', '🇭🇹', '📍', '🛍️', '💄', '👗',
];

enum _CropRatio {
  free('Libre', null),
  original('Original', -1),
  square('1:1', 1),
  portrait('4:5', 4 / 5),
  photo('3:4', 3 / 4),
  story('9:16', 9 / 16),
  wide('16:9', 16 / 9);

  const _CropRatio(this.label, this.value);
  final String label;
  final double? value;
}

class EditorScreen extends StatefulWidget {
  const EditorScreen({super.key, required this.photo, this.initialTool = EditorTool.adjust});
  final Photo photo;
  final EditorTool initialTool;

  @override
  State<EditorScreen> createState() => _EditorScreenState();
}

class _EditorScreenState extends State<EditorScreen> {
  final History<EditorDoc> _h = History(const EditorDoc());
  late String _tool = widget.initialTool.name;
  ui.Image get _img => widget.photo.image;
  ui.Image? _grain;
  ui.Image? _thumb;
  bool _compare = false;

  Adj _adj = Adj.brightness;
  _CropRatio _ratio = _CropRatio.free;
  Color _drawColor = const Color(0xFFFFFFFF);
  double _drawWidth = 0.012;
  bool _eraser = false;

  String? _selected;
  DrawStroke? _live;

  // Gesture bookkeeping.
  EditorDoc? _base;
  EditorLayer? _baseLayer;
  Offset _gStart = Offset.zero;
  String? _cropHandle;
  Rect _cropStart = Rect.zero;

  // Current view mapping (output px → screen).
  double _scale = 1;
  Offset _origin = Offset.zero;

  EditorDoc get _d => _h.value;
  bool get _cropping => _tool == 'crop';

  @override
  void initState() {
    super.initState();
    _h.addListener(() => setState(() {}));
    Grain.tile().then((g) {
      if (mounted) setState(() => _grain = g);
    });
    final s = 160 / math.max(_img.width, _img.height);
    ImageIO.render((_img.width * s).round(), (_img.height * s).round(), (c) {
      c.scale(s);
      c.drawImage(_img, Offset.zero, Paint()..filterQuality = FilterQuality.medium);
    }).then((t) {
      if (mounted) setState(() => _thumb = t);
    });
  }

  @override
  void dispose() {
    _h.dispose();
    _thumb?.dispose();
    super.dispose();
  }

  EditorGeometry get _geo => EditorGeometry(_img, _d);

  EditorLayer? get _selLayer {
    for (final l in _d.layers) {
      if (l.id == _selected) return l;
    }
    return null;
  }

  // ---- live edits ----------------------------------------------------------

  void _live2(EditorDoc next) {
    _base ??= _d;
    _h.replace(next);
  }

  void _commit() {
    final b = _base;
    _base = null;
    if (b != null && !identical(b, _d)) _h.commitFrom(b);
  }

  void _updateLayer(EditorLayer l, {bool live = false}) {
    final next = _d.copyWith(layers: [for (final x in _d.layers) x.id == l.id ? l : x]);
    live ? _live2(next) : _h.push(next);
  }

  // ---- geometry -----------------------------------------------------------

  Size get _contentSize => _cropping ? _geo.oriented : _geo.outputSize;

  void _layout(Size view) {
    final c = _contentSize;
    _scale = math.min((view.width - 32) / c.width, (view.height - 32) / c.height);
    _origin = Offset((view.width - c.width * _scale) / 2, (view.height - c.height * _scale) / 2);
  }

  /// Screen → photo-area pixels.
  Offset _toPhoto(Offset local) => (local - _origin) / _scale - Offset(_geo.frameSide, _geo.frameSide);

  EditorLayer? _hitLayer(Offset local) {
    final photo = _geo.photoSize;
    final p = _toPhoto(local);
    for (final l in _d.layers.reversed) {
      final half = layerHalfSize(l, photo.width);
      final pad = 20 / _scale;
      final v = p - Offset(l.pos.dx * photo.width, l.pos.dy * photo.height);
      final c = math.cos(-l.rotation);
      final s = math.sin(-l.rotation);
      final lx = v.dx * c - v.dy * s;
      final ly = v.dx * s + v.dy * c;
      if (lx.abs() <= half.width + pad && ly.abs() <= half.height + pad) return l;
    }
    return null;
  }

  // ---- crop gestures ------------------------------------------------------

  Rect get _cropScreen {
    final o = _geo.oriented;
    final c = _d.crop;
    return Rect.fromLTRB(_origin.dx + c.left * o.width * _scale, _origin.dy + c.top * o.height * _scale,
        _origin.dx + c.right * o.width * _scale, _origin.dy + c.bottom * o.height * _scale);
  }

  double? get _ratioValue {
    final v = _ratio.value;
    if (v == null) return null;
    if (v == -1) return _geo.oriented.width / _geo.oriented.height;
    return v;
  }

  Rect _fitRatio(double ratio) {
    final o = _geo.oriented;
    final imgRatio = o.width / o.height;
    if (ratio > imgRatio) {
      final h = imgRatio / ratio;
      return Rect.fromLTWH(0, (1 - h) / 2, 1, h);
    }
    final w = ratio / imgRatio;
    return Rect.fromLTWH((1 - w) / 2, 0, w, 1);
  }

  void _cropStartAt(Offset p) {
    final r = _cropScreen;
    const hit = 32.0;
    _cropHandle = null;
    for (final (id, pt) in [('tl', r.topLeft), ('tr', r.topRight), ('bl', r.bottomLeft), ('br', r.bottomRight)]) {
      if ((p - pt).distance < hit) _cropHandle = id;
    }
    if (_cropHandle == null && r.contains(p)) _cropHandle = 'move';
    _cropStart = _d.crop;
    _gStart = p;
  }

  void _cropUpdate(Offset p) {
    if (_cropHandle == null) return;
    final o = _geo.oriented;
    final dx = (p.dx - _gStart.dx) / (o.width * _scale);
    final dy = (p.dy - _gStart.dy) / (o.height * _scale);
    var r = _cropStart;
    const minSize = 0.08;
    if (_cropHandle == 'move') {
      final nx = (r.left + dx).clamp(0.0, 1 - r.width);
      final ny = (r.top + dy).clamp(0.0, 1 - r.height);
      r = Rect.fromLTWH(nx, ny, r.width, r.height);
    } else {
      var l = r.left, t = r.top, rr = r.right, b = r.bottom;
      if (_cropHandle!.contains('l')) l = (l + dx).clamp(0.0, rr - minSize);
      if (_cropHandle!.contains('r')) rr = (rr + dx).clamp(l + minSize, 1.0);
      if (_cropHandle!.startsWith('t')) t = (t + dy).clamp(0.0, b - minSize);
      if (_cropHandle!.startsWith('b')) b = (b + dy).clamp(t + minSize, 1.0);
      final ratio = _ratioValue;
      if (ratio != null) {
        // Keep the aspect ratio by deriving height from width (in pixels).
        final wPx = (rr - l) * o.width;
        var hN = wPx / ratio / o.height;
        if (_cropHandle!.startsWith('t')) {
          t = (b - hN).clamp(0.0, b - minSize);
        } else {
          b = (t + hN).clamp(t + minSize, 1.0);
        }
        hN = b - t;
        final wN = hN * o.height * ratio / o.width;
        if (_cropHandle!.contains('l')) {
          l = rr - wN;
        } else {
          rr = l + wN;
        }
      }
      r = Rect.fromLTRB(l, t, rr, b);
    }
    _live2(_d.copyWith(crop: r));
  }

  // ---- main gestures ------------------------------------------------------

  void _onScaleStart(ScaleStartDetails d) {
    _gStart = d.localFocalPoint;
    if (_cropping) {
      _cropStartAt(d.localFocalPoint);
      return;
    }
    final hit = _hitLayer(d.localFocalPoint);
    if (hit != null) {
      setState(() => _selected = hit.id);
      _base = _d;
      _baseLayer = hit;
      return;
    }
    _baseLayer = null;
    if (_tool == 'draw') {
      final photo = _geo.photoSize;
      final p = _toPhoto(d.localFocalPoint);
      setState(() => _live = DrawStroke([Offset(p.dx / photo.width, p.dy / photo.height)], _drawColor, _drawWidth, _eraser));
    }
  }

  void _onScaleUpdate(ScaleUpdateDetails d) {
    if (_cropping) {
      _cropUpdate(d.localFocalPoint);
      return;
    }
    final base = _baseLayer;
    if (base != null) {
      final photo = _geo.photoSize;
      final delta = (d.localFocalPoint - _gStart) / _scale;
      _updateLayer(
        base.copyWith(
          pos: base.pos + Offset(delta.dx / photo.width, delta.dy / photo.height),
          size: (base.size * d.scale).clamp(0.015, 1.2),
          rotation: base.rotation + d.rotation,
        ),
        live: true,
      );
      return;
    }
    if (_live != null && d.pointerCount == 1) {
      final photo = _geo.photoSize;
      final p = _toPhoto(d.localFocalPoint);
      final n = Offset(p.dx / photo.width, p.dy / photo.height);
      if ((n - _live!.points.last).distance > 0.002) setState(() => _live = _live!.adding(n));
    }
  }

  void _onScaleEnd(ScaleEndDetails d) {
    if (_cropping) {
      _cropHandle = null;
      _commit();
      return;
    }
    if (_baseLayer != null) {
      _baseLayer = null;
      _commit();
      return;
    }
    final l = _live;
    if (l != null) {
      setState(() => _live = null);
      _h.push(_d.copyWith(strokes: [..._d.strokes, l]));
    }
  }

  Future<void> _onTapUp(TapUpDetails d) async {
    if (_cropping) return;
    final hit = _hitLayer(d.localPosition);
    if (hit == null) {
      setState(() => _selected = null);
      return;
    }
    if (hit.id == _selected && !hit.isSticker) {
      await _editText(hit);
    } else {
      setState(() => _selected = hit.id);
    }
  }

  // ---- layer actions -------------------------------------------------------

  String _newId() => DateTime.now().microsecondsSinceEpoch.toString();

  Future<void> _addText() async {
    final l = await showTextSheet(context, EditorLayer(id: _newId(), pos: const Offset(0.5, 0.45)), isNew: true);
    if (l == null) return;
    _h.push(_d.copyWith(layers: [..._d.layers, l]));
    setState(() => _selected = l.id);
  }

  Future<void> _editText(EditorLayer l) async {
    final next = await showTextSheet(context, l, isNew: false);
    if (next != null) _updateLayer(next);
  }

  void _addSticker(String emoji) {
    final l = EditorLayer(id: _newId(), emoji: emoji, size: 0.22, pos: const Offset(0.5, 0.5));
    _h.push(_d.copyWith(layers: [..._d.layers, l]));
    setState(() => _selected = l.id);
  }

  void _deleteLayer(EditorLayer l) {
    _h.push(_d.copyWith(layers: _d.layers.where((x) => x.id != l.id).toList()));
    setState(() => _selected = null);
  }

  void _duplicateLayer(EditorLayer l) {
    final copy = l.copyWith(id: _newId(), pos: l.pos + const Offset(0.04, 0.04));
    _h.push(_d.copyWith(layers: [..._d.layers, copy]));
    setState(() => _selected = copy.id);
  }

  void _bringFront(EditorLayer l) => _h.push(_d.copyWith(layers: [..._d.layers.where((x) => x.id != l.id), l]));

  // ---- top bar --------------------------------------------------------------

  Future<void> _close() async {
    if (_h.canUndo) {
      final ok = await confirmKat(context, title: 'Quitter sans enregistrer ?', message: 'Tes retouches seront perdues.', confirm: 'Quitter');
      if (!ok) return;
    }
    if (mounted) Navigator.pop(context);
  }

  Future<void> _export() async {
    setState(() {
      _selected = null;
      if (_cropping) _tool = 'adjust';
    });
    final grain = await Grain.tile();
    if (!mounted) return;
    await showExportSheet(
      context,
      kind: CreationKind.edit,
      render: (_) {
        final g = EditorGeometry(_img, _d);
        final out = g.outputSize;
        return ImageIO.render(out.width.round(), out.height.round(), (c) => paintEdited(c, g, grain: grain));
      },
    );
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
        resizeToAvoidBottomInset: false,
        body: Column(
          children: [
            ColoredBox(
              color: k.canvas,
              child: EditorTopBar(
                title: 'Retouche',
                onClose: _close,
                onPrimary: _export,
                actions: [
                  KatIconButton(icon: Icons.undo_rounded, tooltip: 'Annuler', onPressed: _h.canUndo ? _h.undo : null),
                  KatIconButton(icon: Icons.redo_rounded, tooltip: 'Rétablir', onPressed: _h.canRedo ? _h.redo : null),
                  Listener(
                    onPointerDown: (_) => setState(() => _compare = true),
                    onPointerUp: (_) => setState(() => _compare = false),
                    onPointerCancel: (_) => setState(() => _compare = false),
                    child: Tooltip(
                      message: 'Maintenir pour voir l’original',
                      child: SizedBox.square(dimension: KatSpace.minTap, child: Icon(Icons.compare_rounded, color: k.ink)),
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: LayoutBuilder(builder: (context, box) {
                _layout(box.biggest);
                return GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onScaleStart: _onScaleStart,
                  onScaleUpdate: _onScaleUpdate,
                  onScaleEnd: _onScaleEnd,
                  onTapUp: _onTapUp,
                  child: CustomPaint(
                    size: box.biggest,
                    painter: _EditorPainter(
                      geo: _geo,
                      scale: _scale,
                      origin: _origin,
                      cropping: _cropping,
                      compare: _compare,
                      grain: _grain,
                      live: _live,
                      selected: _selLayer,
                      selectionColor: k.ink,
                    ),
                  ),
                );
              }),
            ),
            ToolPanel(
              child: AnimatedSize(
                duration: reduceMotion(context) ? Duration.zero : KatMotion.press,
                curve: KatMotion.curve,
                alignment: Alignment.topCenter,
                child: _panel(k),
              ),
            ),
            EditorToolBar(
              items: _toolItems,
              selected: _tool,
              onSelect: (t) => setState(() {
                _tool = t;
                if (t == 'crop') _selected = null;
              }),
            ),
          ],
        ),
      ),
    );
  }

  Widget _layerActions(KatColors k, EditorLayer l) => SizedBox(
        height: 52,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
          children: [
            if (!l.isSticker) _act(Icons.edit_rounded, 'Modifier', () => _editText(l)),
            _act(Icons.copy_rounded, 'Dupliquer', () => _duplicateLayer(l)),
            _act(Icons.flip_to_front_rounded, 'Premier plan', () => _bringFront(l)),
            _act(Icons.delete_outline_rounded, 'Supprimer', () => _deleteLayer(l)),
          ],
        ),
      );

  Widget _act(IconData icon, String label, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.s4),
        child: KatButton(label: label, icon: icon, kind: KatButtonKind.secondary, compact: true, onPressed: onTap),
      );

  Widget _note(KatColors k, String t) => Padding(
        padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, KatSpace.s4),
        child: Text(t, style: KatType.ui(14, color: k.inkMuted)),
      );

  Widget _panel(KatColors k) {
    final sel = _selLayer;
    switch (_tool) {
      case 'crop':
        return Column(
          key: const ValueKey('crop'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<_CropRatio>(
              items: _CropRatio.values,
              value: _ratio,
              label: (r) => r.label,
              onChanged: (r) {
                setState(() => _ratio = r);
                final v = _ratioValue;
                _h.push(_d.copyWith(crop: v == null ? const Rect.fromLTWH(0, 0, 1, 1) : _fitRatio(v)));
              },
            ),
            SliderRow(
              label: 'Redresser',
              value: _d.straighten,
              min: -45,
              max: 45,
              format: (v) => '${v.toStringAsFixed(1).replaceAll('.', ',')}°',
              onChanged: (v) => _live2(_d.copyWith(straighten: v)),
              onChangeEnd: (_) => _commit(),
            ),
            SizedBox(
              height: 52,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
                children: [
                  _act(Icons.rotate_90_degrees_cw_rounded, 'Pivoter', () {
                    _h.push(_d.copyWith(quarterTurns: (_d.quarterTurns + 1) % 4, crop: const Rect.fromLTWH(0, 0, 1, 1)));
                    setState(() => _ratio = _CropRatio.free);
                  }),
                  _act(Icons.flip_rounded, 'Miroir', () => _h.push(_d.copyWith(flipH: !_d.flipH))),
                  _act(Icons.restart_alt_rounded, 'Réinitialiser', () {
                    _h.push(_d.copyWith(quarterTurns: 0, flipH: false, straighten: 0, crop: const Rect.fromLTWH(0, 0, 1, 1)));
                    setState(() => _ratio = _CropRatio.free);
                  }),
                ],
              ),
            ),
          ],
        );
      case 'adjust':
        final v = _d.adj(_adj);
        return Column(
          key: const ValueKey('adjust'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<Adj>(items: Adj.values, value: _adj, label: (a) => a.label, onChanged: (a) => setState(() => _adj = a)),
            SliderRow(
              label: _adj.label,
              value: v * 100,
              min: _adj.min * 100,
              max: _adj.max * 100,
              format: (x) => x.round() > 0 && _adj.min < 0 ? '+${x.round()}' : '${x.round()}',
              onChanged: (x) => _live2(_d.withAdj(_adj, x / 100)),
              onChangeEnd: (_) => _commit(),
            ),
            if (_d.adjust.values.any((x) => x != 0))
              Padding(
                padding: const EdgeInsets.only(bottom: KatSpace.s4),
                child: KatButton(
                  label: 'Réinitialiser les réglages',
                  icon: Icons.restart_alt_rounded,
                  kind: KatButtonKind.ghost,
                  compact: true,
                  onPressed: () => _h.push(_d.copyWith(adjust: const {})),
                ),
              ),
          ],
        );
      case 'styles':
        final t = _thumb;
        return Column(
          key: const ValueKey('styles'),
          mainAxisSize: MainAxisSize.min,
          children: [
            if (t != null)
              StyleStrip(image: t, selected: _d.style, onSelect: (s) => _h.push(_d.copyWith(style: s, styleAmount: 1)))
            else
              const SizedBox(height: 96),
            if (_d.style != 'original')
              SliderRow(
                label: 'Intensité',
                value: _d.styleAmount * 100,
                min: 0,
                max: 100,
                format: (x) => '${x.round()} %',
                onChanged: (x) => _live2(_d.copyWith(styleAmount: x / 100)),
                onChangeEnd: (_) => _commit(),
              ),
          ],
        );
      case 'text':
        return Column(
          key: const ValueKey('text'),
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s4),
              child: KatButton(label: 'Ajouter du texte', icon: Icons.add_rounded, kind: KatButtonKind.secondary, expand: true, onPressed: _addText),
            ),
            if (sel != null && !sel.isSticker) _layerActions(k, sel) else _note(k, 'Glisse pour déplacer, pince pour agrandir et tourner. Touche deux fois pour modifier.'),
          ],
        );
      case 'stickers':
        return Column(
          key: const ValueKey('stickers'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: 112,
              child: GridView.builder(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, mainAxisExtent: 52),
                itemCount: _emojis.length,
                itemBuilder: (context, i) => Pressable(
                  onTap: () => _addSticker(_emojis[i]),
                  semanticLabel: 'Sticker ${_emojis[i]}',
                  child: Center(child: Text(_emojis[i], style: const TextStyle(fontSize: 28))),
                ),
              ),
            ),
            if (sel != null && sel.isSticker) _layerActions(k, sel),
          ],
        );
      case 'draw':
        return Column(
          key: const ValueKey('draw'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<bool>(
              items: const [false, true],
              value: _eraser,
              icons: (e) => e ? Icons.cleaning_services_rounded : Icons.draw_rounded,
              label: (e) => e ? 'Gomme' : 'Pinceau',
              onChanged: (e) => setState(() => _eraser = e),
            ),
            if (!_eraser) ColorSwatches(value: _drawColor, onChanged: (c) => setState(() => _drawColor = c)),
            SliderRow(
              label: 'Épaisseur',
              value: _drawWidth * 1000,
              min: 2,
              max: 60,
              onChanged: (v) => setState(() => _drawWidth = v / 1000),
            ),
            if (_d.strokes.isNotEmpty)
              KatButton(
                label: 'Effacer tout le dessin',
                icon: Icons.layers_clear_rounded,
                kind: KatButtonKind.ghost,
                compact: true,
                onPressed: () => _h.push(_d.copyWith(strokes: const [])),
              ),
          ],
        );
      case 'frame':
        return Column(
          key: const ValueKey('frame'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<FrameStyle>(
              items: FrameStyle.values,
              value: _d.frame,
              label: (f) => f.label,
              onChanged: (f) => _h.push(_d.copyWith(frame: f)),
            ),
            if (_d.frame != FrameStyle.none) ColorSwatches(value: _d.frameColor, onChanged: (c) => _h.push(_d.copyWith(frameColor: c))),
          ],
        );
    }
    return const SizedBox.shrink();
  }
}

class _EditorPainter extends CustomPainter {
  _EditorPainter({
    required this.geo,
    required this.scale,
    required this.origin,
    required this.cropping,
    required this.compare,
    required this.grain,
    required this.live,
    required this.selected,
    required this.selectionColor,
  });

  final EditorGeometry geo;
  final double scale;
  final Offset origin;
  final bool cropping;
  final bool compare;
  final ui.Image? grain;
  final DrawStroke? live;
  final EditorLayer? selected;
  final Color selectionColor;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.clipRect(Offset.zero & size);
    if (compare) {
      final img = geo.image;
      final s = math.min((size.width - 32) / img.width, (size.height - 32) / img.height);
      final dst = Rect.fromCenter(center: size.center(Offset.zero), width: img.width * s, height: img.height * s);
      canvas.drawImageRect(img, Offset.zero & Size(img.width.toDouble(), img.height.toDouble()), dst, Paint()..filterQuality = FilterQuality.medium);
      return;
    }
    canvas.save();
    canvas.translate(origin.dx, origin.dy);
    canvas.scale(scale);
    if (cropping) {
      final d = geo.doc;
      geo.paintOriented(canvas, matrix: d.hasColorWork ? d.matrix() : null);
      canvas.restore();
      _cropOverlay(canvas);
      return;
    }
    paintEdited(canvas, geo, grain: grain, live: live);
    final sel = selected;
    if (sel != null) {
      final photo = geo.photoSize;
      final half = layerHalfSize(sel, photo.width);
      canvas.translate(geo.frameSide + sel.pos.dx * photo.width, geo.frameSide + sel.pos.dy * photo.height);
      canvas.rotate(sel.rotation);
      final pad = 8 / scale;
      final r = Rect.fromLTRB(-half.width - pad, -half.height - pad, half.width + pad, half.height + pad);
      final p = Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5 / scale
        ..color = selectionColor;
      canvas.drawRRect(RRect.fromRectAndRadius(r, Radius.circular(8 / scale)), p);
    }
    canvas.restore();
  }

  void _cropOverlay(Canvas canvas) {
    final o = geo.oriented;
    final c = geo.doc.crop;
    final full = Rect.fromLTWH(origin.dx, origin.dy, o.width * scale, o.height * scale);
    final r = Rect.fromLTRB(full.left + c.left * full.width, full.top + c.top * full.height, full.left + c.right * full.width, full.top + c.bottom * full.height);
    final shade = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(full)
      ..addRect(r);
    canvas.drawPath(shade, Paint()..color = const Color(0x99000000));
    final line = Paint()
      ..style = PaintingStyle.stroke
      ..color = const Color(0xFFFFFFFF)
      ..strokeWidth = 1.5;
    canvas.drawRect(r, line);
    final thin = Paint()
      ..color = const Color(0x80FFFFFF)
      ..strokeWidth = 1;
    for (var i = 1; i < 3; i++) {
      canvas.drawLine(Offset(r.left + r.width * i / 3, r.top), Offset(r.left + r.width * i / 3, r.bottom), thin);
      canvas.drawLine(Offset(r.left, r.top + r.height * i / 3), Offset(r.right, r.top + r.height * i / 3), thin);
    }
    final corner = Paint()
      ..style = PaintingStyle.stroke
      ..color = const Color(0xFFFFFFFF)
      ..strokeWidth = 4
      ..strokeCap = StrokeCap.round;
    const len = 18.0;
    for (final (pt, sx, sy) in [(r.topLeft, 1.0, 1.0), (r.topRight, -1.0, 1.0), (r.bottomLeft, 1.0, -1.0), (r.bottomRight, -1.0, -1.0)]) {
      canvas.drawLine(pt, pt + Offset(len * sx, 0), corner);
      canvas.drawLine(pt, pt + Offset(0, len * sy), corner);
    }
  }

  @override
  bool shouldRepaint(_EditorPainter old) => true;
}
