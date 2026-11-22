import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../core/creations.dart';
import '../../core/drafts.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../core/styles.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/pressable.dart';
import '../../widgets/style_strip.dart';
import '../export/export_sheet.dart';
import '../launch.dart';
import 'collage_layouts.dart';

class CollageCell {
  CollageCell(this.image, this.path);
  ui.Image image;

  /// File the photo came from (kept in the draft folder once saved).
  String path;
  Offset focus = const Offset(0.5, 0.5);
  double zoom = 1;
  bool flip = false;
  String style = 'original';
}

enum CollageRatio {
  square('1:1', 1),
  portrait('4:5', 4 / 5),
  photo('3:4', 3 / 4),
  story('9:16', 9 / 16),
  wide('16:9', 16 / 9);

  const CollageRatio(this.label, this.value);
  final String label;
  final double value;
}

/// Everything needed to paint the collage at any size.
class CollageScene {
  CollageScene({required this.layout, required this.cells, required this.spacing, required this.radius, required this.background});
  final CollageLayout layout;
  final List<CollageCell> cells;
  final double spacing;
  final double radius;
  final Color background;
}

Rect srcRectFor(CollageCell c, Rect dst) {
  final iw = c.image.width.toDouble();
  final ih = c.image.height.toDouble();
  final s = math.max(dst.width / iw, dst.height / ih) * c.zoom;
  // Rounding can make the window a hair wider than the photo: never let it exceed it.
  final sw = math.min(dst.width / s, iw);
  final sh = math.min(dst.height / s, ih);
  double centre(double f, double size, double full) {
    final lo = size / 2;
    final hi = full - size / 2;
    return lo <= hi ? (f * full).clamp(lo, hi) : full / 2;
  }

  return Rect.fromCenter(center: Offset(centre(c.focus.dx, sw, iw), centre(c.focus.dy, sh, ih)), width: sw, height: sh);
}

void paintCollage(Canvas canvas, Size size, CollageScene s, {int? selected, Color? selection}) {
  canvas.drawRect(Offset.zero & size, Paint()..color = s.background);
  final rects = cellRects(size, s.layout, s.spacing);
  final r = s.radius * size.width;
  for (var i = 0; i < rects.length && i < s.cells.length; i++) {
    final dst = rects[i];
    final cell = s.cells[i];
    canvas.save();
    canvas.clipRRect(RRect.fromRectAndRadius(dst, Radius.circular(r)));
    if (cell.flip) {
      canvas.translate(dst.center.dx, 0);
      canvas.scale(-1, 1);
      canvas.translate(-dst.center.dx, 0);
    }
    final paint = Paint()..filterQuality = FilterQuality.medium;
    if (cell.style != 'original') paint.colorFilter = ColorFilter.matrix(styleById(cell.style).matrix());
    canvas.drawImageRect(cell.image, srcRectFor(cell, dst), dst, paint);
    canvas.restore();
    if (selected == i && selection != null) {
      canvas.drawRRect(
        RRect.fromRectAndRadius(dst.deflate(1.5), Radius.circular(r)),
        Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 3
          ..color = selection,
      );
    }
  }
}

/// Collage maker. It owns the photos it receives and frees them when closed.
class CollageScreen extends StatefulWidget {
  const CollageScreen({super.key, required this.photos, this.initialRatio = CollageRatio.portrait, this.draftId, this.initialState});
  final List<Photo> photos;
  final CollageRatio initialRatio;

  /// Reopens a saved collage; [initialState] then holds its layout and settings.
  final String? draftId;
  final Map<String, dynamic>? initialState;

  @override
  State<CollageScreen> createState() => _CollageScreenState();
}

class _CollageScreenState extends State<CollageScreen> {
  late List<CollageCell> _cells = widget.photos.map((p) => CollageCell(p.image, p.path)).toList();

  /// Every image this screen decoded, freed together on close (cells can be swapped or removed).
  late final List<ui.Image> _owned = [for (final p in widget.photos) p.image];
  late final String _draftId = widget.draftId ?? DraftsStore.newId();
  late bool _hasDraft = widget.draftId != null;
  late final Autosaver _autosave = Autosaver(_saveDraft);
  late final AppLifecycleListener _life = AppLifecycleListener(onHide: () {
    if (_hasDraft) _autosave.flush();
  });
  late List<CollageLayout> _layouts = layoutsFor(_cells.length);
  late CollageLayout _layout = _layouts.first;
  late CollageRatio _ratio = widget.initialRatio;
  double _spacing = 0.015;
  double _radius = 0.012;
  Color _bg = const Color(0xFFFFFFFF);
  String _tool = 'disposition';
  int? _selected;
  int? _swapFrom;
  bool _dirty = false;

  // Gesture state.
  int? _gCell;
  Offset _gFocus0 = Offset.zero;
  double _gZoom0 = 1;
  Offset _gStart = Offset.zero;

  @override
  void initState() {
    super.initState();
    _life;
    final st = widget.initialState;
    if (st != null) _applyState(st);
  }

  @override
  void dispose() {
    _life.dispose();
    _autosave.dispose();
    for (final i in _owned) {
      i.dispose();
    }
    super.dispose();
  }

  void _applyState(Map<String, dynamic> j) {
    final cells = (j['cells'] as List? ?? const []).cast<Map<String, dynamic>>();
    for (var i = 0; i < cells.length && i < _cells.length; i++) {
      final c = cells[i];
      final f = (c['focus'] as List?)?.cast<num>();
      _cells[i]
        ..focus = f != null && f.length == 2 ? Offset(f[0].toDouble(), f[1].toDouble()) : const Offset(0.5, 0.5)
        ..zoom = (c['zoom'] as num?)?.toDouble() ?? 1
        ..flip = c['flip'] == true
        ..style = c['style'] as String? ?? 'original';
    }
    _layout = _layouts.where((l) => l.id == j['layout']).firstOrNull ?? _layouts.first;
    _ratio = CollageRatio.values.where((r) => r.name == j['ratio']).firstOrNull ?? _ratio;
    _spacing = (j['spacing'] as num?)?.toDouble() ?? _spacing;
    _radius = (j['radius'] as num?)?.toDouble() ?? _radius;
    _bg = Color((j['bg'] as num?)?.toInt() ?? _bg.toARGB32());
  }

  Future<void> _saveDraft() async {
    for (final c in _cells) {
      c.path = await drafts.adopt(_draftId, c.path);
    }
    const side = 320.0;
    final size = _ratio.value >= 1 ? Size(side, side / _ratio.value) : Size(side * _ratio.value, side);
    final scene = _scene;
    final img = await ImageIO.render(size.width.round(), size.height.round(), (c) => paintCollage(c, size, scene));
    final thumb = await ImageIO.thumbnail(img, side: 320);
    img.dispose();
    await drafts.save(
      _draftId,
      DraftKind.collage,
      {
        'v': 1,
        'layout': _layout.id,
        'ratio': _ratio.name,
        'spacing': _spacing,
        'radius': _radius,
        'bg': _bg.toARGB32(),
        'cells': [
          for (final c in _cells)
            {'path': c.path, 'focus': [c.focus.dx, c.focus.dy], 'zoom': c.zoom, 'flip': c.flip, 'style': c.style},
        ],
      },
      thumb: thumb,
    );
  }

  void _change(VoidCallback fn) {
    setState(() {
      fn();
      _dirty = true;
      _hasDraft = true;
    });
    _autosave.schedule();
  }

  void _setCount(List<CollageCell> next) {
    _cells = next;
    _layouts = layoutsFor(_cells.length);
    _layout = _layouts.first;
    _selected = null;
    _swapFrom = null;
  }

  Future<void> _close() async {
    if (_hasDraft) {
      await _autosave.flush();
      if (mounted && _dirty) showKatSnack(context, 'Collage gardé dans tes brouillons.');
    }
    if (mounted) Navigator.pop(context);
  }

  Size _canvasSize(BoxConstraints box) {
    final maxW = box.maxWidth - 32;
    final maxH = box.maxHeight - 32;
    var w = maxW;
    var h = w / _ratio.value;
    if (h > maxH) {
      h = maxH;
      w = h * _ratio.value;
    }
    return Size(w, h);
  }

  int? _cellAt(Size size, Offset p) {
    final rects = cellRects(size, _layout, _spacing);
    for (var i = 0; i < rects.length; i++) {
      if (rects[i].inflate(_spacing * size.width / 2).contains(p)) return i;
    }
    return null;
  }

  void _onTap(Size size, Offset p) {
    final i = _cellAt(size, p);
    if (i == null) return;
    if (_swapFrom != null) {
      final from = _swapFrom!;
      _change(() {
        final tmp = _cells[from];
        _cells[from] = _cells[i];
        _cells[i] = tmp;
        _swapFrom = null;
        _selected = i;
      });
      return;
    }
    setState(() {
      _selected = _selected == i ? null : i;
      _tool = _selected == null ? 'disposition' : 'photo';
    });
  }

  void _onScaleStart(Size size, ScaleStartDetails d) {
    _gCell = _cellAt(size, d.localFocalPoint);
    if (_gCell == null) return;
    final c = _cells[_gCell!];
    _gFocus0 = c.focus;
    _gZoom0 = c.zoom;
    _gStart = d.localFocalPoint;
  }

  void _onScaleUpdate(Size size, ScaleUpdateDetails d) {
    final i = _gCell;
    if (i == null || _swapFrom != null) return;
    final c = _cells[i];
    final dst = cellRects(size, _layout, _spacing)[i];
    final zoom = (_gZoom0 * d.scale).clamp(1.0, 5.0);
    final s = math.max(dst.width / c.image.width, dst.height / c.image.height) * zoom;
    final delta = d.localFocalPoint - _gStart;
    _change(() {
      c.zoom = zoom;
      c.focus = Offset(
        (_gFocus0.dx - delta.dx * (c.flip ? -1 : 1) / s / c.image.width).clamp(0.0, 1.0),
        (_gFocus0.dy - delta.dy / s / c.image.height).clamp(0.0, 1.0),
      );
    });
  }

  Future<void> _replace(int i) async {
    final src = await pickSource(context);
    if (src == null) return;
    try {
      final p = await ImageIO.pick(src, maxSide: ImageIO.collageSide);
      if (p != null) {
        _owned.add(p.image);
        _change(() => _cells[i] = CollageCell(p.image, p.path));
      }
    } catch (_) {
      if (mounted) showKatSnack(context, 'Impossible d’ouvrir cette photo. Essaie une autre image.');
    }
  }

  Future<void> _addPhotos() async {
    try {
      final more = await ImageIO.pickMany(limit: 9 - _cells.length, maxSide: ImageIO.collageSide);
      if (more.isEmpty) return;
      _owned.addAll(more.map((p) => p.image));
      _change(() => _setCount([..._cells, ...more.take(9 - _cells.length).map((p) => CollageCell(p.image, p.path))]));
    } catch (_) {
      if (mounted) showKatSnack(context, 'Impossible d’ajouter ces photos. Réessaie.');
    }
  }

  CollageScene get _scene => CollageScene(layout: _layout, cells: _cells, spacing: _spacing, radius: _radius, background: _bg);

  Future<void> _export() async {
    setState(() {
      _selected = null;
      _swapFrom = null;
    });
    final saved = await showExportSheet(
      context,
      kind: CreationKind.collage,
      render: (_) {
        const long = 2160.0;
        final size = _ratio.value >= 1 ? Size(long, long / _ratio.value) : Size(long * _ratio.value, long);
        final scene = _scene;
        return ImageIO.render(size.width.round(), size.height.round(), (c) => paintCollage(c, size, scene));
      },
    );
    if (saved != null && _hasDraft) {
      await _autosave.flush();
      await drafts.markExported(_draftId);
    }
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final tools = [
      const ToolItem('disposition', Icons.dashboard_rounded, 'Disposition'),
      const ToolItem('format', Icons.aspect_ratio_rounded, 'Format'),
      const ToolItem('bordures', Icons.border_inner_rounded, 'Bordures'),
      const ToolItem('fond', Icons.format_color_fill_rounded, 'Fond'),
      if (_selected != null) const ToolItem('photo', Icons.photo_rounded, 'Photo'),
    ];
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _close();
      },
      child: Scaffold(
        backgroundColor: k.stage,
        body: Column(
          children: [
            ColoredBox(color: k.canvas, child: EditorTopBar(title: 'Collage', onClose: _close, onPrimary: _export)),
            Expanded(
              child: LayoutBuilder(builder: (context, box) {
                final size = _canvasSize(box);
                return Stack(
                  children: [
                    Center(
                      child: GestureDetector(
                        onTapUp: (d) => _onTap(size, d.localPosition),
                        onScaleStart: (d) => _onScaleStart(size, d),
                        onScaleUpdate: (d) => _onScaleUpdate(size, d),
                        child: CustomPaint(
                          size: size,
                          painter: _CollagePainter(_scene, _selected, k.primary),
                        ),
                      ),
                    ),
                    if (_swapFrom != null)
                      Positioned(
                        top: KatSpace.s16,
                        left: 0,
                        right: 0,
                        child: Center(
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16, vertical: KatSpace.s12),
                            decoration: BoxDecoration(color: k.kat, borderRadius: BorderRadius.circular(999)),
                            child: Text('Touche la photo à échanger', style: KatType.ui(14, weight: FontWeight.w500, color: k.onKat)),
                          ),
                        ),
                      ),
                  ],
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
            EditorToolBar(items: tools, selected: _tool, onSelect: (t) => setState(() => _tool = t)),
          ],
        ),
      ),
    );
  }

  Widget _panel(KatColors k) {
    switch (_tool) {
      case 'format':
        return KatChoices<CollageRatio>(
          key: const ValueKey('format'),
          items: CollageRatio.values,
          value: _ratio,
          label: (r) => r.label,
          onChanged: (r) => _change(() => _ratio = r),
        );
      case 'bordures':
        return Column(
          key: const ValueKey('bordures'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SliderRow(label: 'Espacement', value: _spacing * 2000, min: 0, max: 100, onChanged: (v) => _change(() => _spacing = v / 2000)),
            SliderRow(label: 'Arrondi', value: _radius * 1000, min: 0, max: 100, onChanged: (v) => _change(() => _radius = v / 1000)),
          ],
        );
      case 'fond':
        return ColorSwatches(key: const ValueKey('fond'), value: _bg, onChanged: (c) => _change(() => _bg = c));
      case 'photo':
        final i = _selected;
        if (i == null) return const SizedBox.shrink();
        final cell = _cells[i];
        return Column(
          key: ValueKey('photo$i'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: 52,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
                children: [
                  _action(k, Icons.swap_horiz_rounded, 'Échanger', () => setState(() => _swapFrom = i)),
                  _action(k, Icons.image_search_rounded, 'Remplacer', () => _replace(i)),
                  _action(k, Icons.flip_rounded, 'Miroir', () => _change(() => cell.flip = !cell.flip)),
                  _action(k, Icons.fit_screen_rounded, 'Recentrer', () => _change(() {
                        cell.zoom = 1;
                        cell.focus = const Offset(0.5, 0.5);
                      })),
                  if (_cells.length > 2)
                    _action(k, Icons.delete_outline_rounded, 'Retirer', () => _change(() => _setCount([..._cells]..removeAt(i)))),
                ],
              ),
            ),
            StyleStrip(image: cell.image, selected: cell.style, onSelect: (s) => _change(() => cell.style = s)),
          ],
        );
      default:
        return Column(
          key: const ValueKey('disposition'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: 76,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                itemCount: _layouts.length + (_cells.length < 9 ? 1 : 0),
                separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s8),
                itemBuilder: (context, i) {
                  if (i == _layouts.length) {
                    return Pressable(
                      onTap: _addPhotos,
                      semanticLabel: 'Ajouter des photos',
                      child: Container(
                        width: 64,
                        decoration: BoxDecoration(borderRadius: BorderRadius.circular(KatRadius.tile), border: Border.all(color: k.line)),
                        child: Icon(Icons.add_photo_alternate_rounded, color: k.ink),
                      ),
                    );
                  }
                  final l = _layouts[i];
                  final sel = l.id == _layout.id;
                  return Pressable(
                    onTap: () => _change(() => _layout = l),
                    selected: sel,
                    semanticLabel: 'Disposition ${i + 1}',
                    child: AnimatedContainer(
                      duration: KatMotion.paint,
                      width: 64,
                      padding: const EdgeInsets.all(6),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(KatRadius.tile),
                        border: Border.all(color: sel ? k.ink : k.line, width: sel ? 2 : 1),
                      ),
                      child: CustomPaint(painter: _LayoutThumb(l, sel ? k.ink : k.inkMuted)),
                    ),
                  );
                },
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, KatSpace.s4),
              child: Text(
                '${_cells.length} photos. Touche une photo pour la modifier, pince pour zoomer, glisse pour la recadrer.',
                style: KatType.ui(14, color: k.inkMuted),
              ),
            ),
          ],
        );
    }
  }

  Widget _action(KatColors k, IconData icon, String label, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.s4),
        child: KatButton(label: label, icon: icon, kind: KatButtonKind.secondary, compact: true, onPressed: onTap),
      );
}

class _CollagePainter extends CustomPainter {
  _CollagePainter(this.scene, this.selected, this.selection);
  final CollageScene scene;
  final int? selected;
  final Color selection;

  @override
  void paint(Canvas canvas, Size size) => paintCollage(canvas, size, scene, selected: selected, selection: selection);

  @override
  bool shouldRepaint(_CollagePainter old) => true;
}

class _LayoutThumb extends CustomPainter {
  _LayoutThumb(this.layout, this.color);
  final CollageLayout layout;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final p = Paint()..color = color;
    for (final r in cellRects(size, layout, 0.08)) {
      canvas.drawRRect(RRect.fromRectAndRadius(r, const Radius.circular(2)), p);
    }
  }

  @override
  bool shouldRepaint(_LayoutThumb old) => old.layout != layout || old.color != color;
}
