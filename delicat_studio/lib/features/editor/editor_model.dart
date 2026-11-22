import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';
import 'package:qr/qr.dart';

import '../../core/color_matrix.dart';
import '../../core/crash_log.dart';
import '../../core/image_io.dart';
import '../../core/styles.dart';

enum EditorTool { crop, adjust, styles, text, stickers, draw, frame }

enum Adj {
  brightness('Luminosité', -1, 1),
  contrast('Contraste', -1, 1),
  saturation('Saturation', -1, 1),
  exposure('Exposition', -1, 1),
  warmth('Chaleur', -1, 1),
  tint('Teinte', -1, 1),
  fade('Délavé', 0, 1),
  vignette('Vignette', 0, 1),
  grain('Grain', 0, 1);

  const Adj(this.label, this.min, this.max);
  final String label;
  final double min;
  final double max;
}

enum TextLook { plain, label, outline }

enum FrameStyle {
  none('Aucun', 0, 0),
  thin('Fin', 0.02, 0),
  thick('Épais', 0.06, 0),
  polaroid('Polaroïd', 0.05, 0.16);

  const FrameStyle(this.label, this.side, this.bottomExtra);
  final String label;
  final double side;
  final double bottomExtra;
}

const kTextFonts = <(String, String)>[
  ('Bricolage', 'Moderne'),
  ('Playfair', 'Élégant'),
  ('Pacifico', 'Script'),
  ('BebasNeue', 'Affiche'),
  ('Caveat', 'Manuscrit'),
  ('JetBrainsMono', 'Machine'),
];

@immutable
class EditorLayer {
  const EditorLayer({
    required this.id,
    this.text = '',
    this.emoji,
    this.qr,
    this.image,
    this.aspect = 1,
    this.font = 'Bricolage',
    this.color = const Color(0xFFFFFFFF),
    this.look = TextLook.plain,
    this.align = TextAlign.center,
    this.pos = const Offset(0.5, 0.5),
    this.size = 0.08,
    this.rotation = 0,
  });

  final String id;
  final String text;
  final String? emoji;

  /// Text or link encoded as a QR code.
  final String? qr;

  /// Path of a picture placed on top (product photo, logo).
  final String? image;

  /// Width / height of [image].
  final double aspect;
  final String font;
  final Color color;
  final TextLook look;
  final TextAlign align;

  /// Centre as a fraction of the photo area.
  final Offset pos;

  /// Font size as a fraction of the photo width (QR codes and pictures: their width).
  final double size;

  /// Radians.
  final double rotation;

  bool get isSticker => emoji != null;
  bool get isQr => qr != null;
  bool get isPicture => image != null;
  bool get isText => emoji == null && qr == null && image == null;

  EditorLayer copyWith({
    String? id,
    String? text,
    String? qr,
    String? font,
    Color? color,
    TextLook? look,
    TextAlign? align,
    Offset? pos,
    double? size,
    double? rotation,
  }) =>
      EditorLayer(
        id: id ?? this.id,
        text: text ?? this.text,
        emoji: emoji,
        qr: qr ?? this.qr,
        image: image,
        aspect: aspect,
        font: font ?? this.font,
        color: color ?? this.color,
        look: look ?? this.look,
        align: align ?? this.align,
        pos: pos ?? this.pos,
        size: size ?? this.size,
        rotation: rotation ?? this.rotation,
      );

  Map<String, Object?> toJson() => {
        'id': id,
        'text': text,
        'emoji': ?emoji,
        'qr': ?qr,
        'image': ?image,
        'aspect': aspect,
        'font': font,
        'color': color.toARGB32(),
        'look': look.name,
        'align': align.name,
        'pos': [pos.dx, pos.dy],
        'size': size,
        'rotation': rotation,
      };

  static EditorLayer fromJson(Map<String, dynamic> j) => EditorLayer(
        id: j['id'] as String,
        text: j['text'] as String? ?? '',
        emoji: j['emoji'] as String?,
        qr: j['qr'] as String?,
        image: j['image'] as String?,
        aspect: (j['aspect'] as num?)?.toDouble() ?? 1,
        font: j['font'] as String? ?? 'Bricolage',
        color: Color((j['color'] as num?)?.toInt() ?? 0xFFFFFFFF),
        look: _byName(TextLook.values, j['look'], TextLook.plain),
        align: _byName(TextAlign.values, j['align'], TextAlign.center),
        pos: jsonOffset(j['pos'], const Offset(0.5, 0.5)),
        size: (j['size'] as num?)?.toDouble() ?? 0.08,
        rotation: (j['rotation'] as num?)?.toDouble() ?? 0,
      );
}

T _byName<T extends Enum>(List<T> values, Object? name, T fallback) => values.firstWhere((v) => v.name == name, orElse: () => fallback);

Offset jsonOffset(Object? v, Offset fallback) {
  if (v is List && v.length == 2) return Offset((v[0] as num).toDouble(), (v[1] as num).toDouble());
  return fallback;
}

Map<String, double> adjToJson(Map<Adj, double> a) => {for (final e in a.entries) e.key.name: e.value};

Map<Adj, double> adjFromJson(Object? j) {
  if (j is! Map) return const {};
  final out = <Adj, double>{};
  for (final e in j.entries) {
    final a = Adj.values.where((x) => x.name == e.key).firstOrNull;
    if (a != null && e.value is num) out[a] = (e.value as num).toDouble();
  }
  return out;
}

@immutable
class DrawStroke {
  const DrawStroke(this.points, this.color, this.width, this.eraser);

  /// Points as fractions of the photo area.
  final List<Offset> points;
  final Color color;

  /// Fraction of the photo width.
  final double width;
  final bool eraser;

  DrawStroke adding(Offset p) => DrawStroke([...points, p], color, width, eraser);

  Map<String, Object?> toJson() => {
        'p': [for (final o in points) ...[_r(o.dx), _r(o.dy)]],
        'c': color.toARGB32(),
        'w': width,
        'e': eraser,
      };

  static DrawStroke fromJson(Map<String, dynamic> j) {
    final flat = (j['p'] as List).cast<num>();
    return DrawStroke(
      [for (var i = 0; i + 1 < flat.length; i += 2) Offset(flat[i].toDouble(), flat[i + 1].toDouble())],
      Color((j['c'] as num).toInt()),
      (j['w'] as num).toDouble(),
      j['e'] == true,
    );
  }
}

double _r(double v) => (v * 10000).roundToDouble() / 10000;

@immutable
class EditorDoc {
  const EditorDoc({
    this.quarterTurns = 0,
    this.flipH = false,
    this.straighten = 0,
    this.crop = const Rect.fromLTWH(0, 0, 1, 1),
    this.adjust = const {},
    this.style = 'original',
    this.styleAmount = 1,
    this.layers = const [],
    this.strokes = const [],
    this.frame = FrameStyle.none,
    this.frameColor = const Color(0xFFFFFFFF),
  });

  final int quarterTurns;
  final bool flipH;

  /// Degrees, -45..45.
  final double straighten;

  /// Crop in the oriented image, as fractions.
  final Rect crop;
  final Map<Adj, double> adjust;
  final String style;
  final double styleAmount;
  final List<EditorLayer> layers;
  final List<DrawStroke> strokes;
  final FrameStyle frame;
  final Color frameColor;

  double adj(Adj a) => adjust[a] ?? 0;

  EditorDoc copyWith({
    int? quarterTurns,
    bool? flipH,
    double? straighten,
    Rect? crop,
    Map<Adj, double>? adjust,
    String? style,
    double? styleAmount,
    List<EditorLayer>? layers,
    List<DrawStroke>? strokes,
    FrameStyle? frame,
    Color? frameColor,
  }) =>
      EditorDoc(
        quarterTurns: quarterTurns ?? this.quarterTurns,
        flipH: flipH ?? this.flipH,
        straighten: straighten ?? this.straighten,
        crop: crop ?? this.crop,
        adjust: adjust ?? this.adjust,
        style: style ?? this.style,
        styleAmount: styleAmount ?? this.styleAmount,
        layers: layers ?? this.layers,
        strokes: strokes ?? this.strokes,
        frame: frame ?? this.frame,
        frameColor: frameColor ?? this.frameColor,
      );

  EditorDoc withAdj(Adj a, double v) => copyWith(adjust: {...adjust, a: v});

  /// Combined colour matrix for adjustments then the style.
  List<double> matrix() {
    final steps = <List<double>>[
      if (adj(Adj.exposure) != 0) ColorMatrix.exposure(adj(Adj.exposure)),
      if (adj(Adj.brightness) != 0) ColorMatrix.brightness(adj(Adj.brightness)),
      if (adj(Adj.contrast) != 0) ColorMatrix.contrast(adj(Adj.contrast)),
      if (adj(Adj.saturation) != 0) ColorMatrix.saturation(adj(Adj.saturation)),
      if (adj(Adj.warmth) != 0) ColorMatrix.warmth(adj(Adj.warmth)),
      if (adj(Adj.tint) != 0) ColorMatrix.tint(adj(Adj.tint)),
      if (adj(Adj.fade) != 0) ColorMatrix.fade(adj(Adj.fade)),
      if (style != 'original') styleById(style).matrix(styleAmount),
    ];
    return ColorMatrix.chain(steps);
  }

  bool get hasColorWork => adjust.values.any((v) => v != 0) || style != 'original';

  Map<String, Object?> toJson() => {
        'quarterTurns': quarterTurns,
        'flipH': flipH,
        'straighten': straighten,
        'crop': [crop.left, crop.top, crop.right, crop.bottom],
        'adjust': adjToJson(adjust),
        'style': style,
        'styleAmount': styleAmount,
        'layers': [for (final l in layers) l.toJson()],
        'strokes': [for (final s in strokes) s.toJson()],
        'frame': frame.name,
        'frameColor': frameColor.toARGB32(),
      };

  static EditorDoc fromJson(Map<String, dynamic> j) {
    final c = (j['crop'] as List?)?.cast<num>();
    return EditorDoc(
      quarterTurns: (j['quarterTurns'] as num?)?.toInt() ?? 0,
      flipH: j['flipH'] == true,
      straighten: (j['straighten'] as num?)?.toDouble() ?? 0,
      crop: c != null && c.length == 4 ? Rect.fromLTRB(c[0].toDouble(), c[1].toDouble(), c[2].toDouble(), c[3].toDouble()) : const Rect.fromLTWH(0, 0, 1, 1),
      adjust: adjFromJson(j['adjust']),
      style: j['style'] as String? ?? 'original',
      styleAmount: (j['styleAmount'] as num?)?.toDouble() ?? 1,
      layers: [for (final l in (j['layers'] as List? ?? const [])) EditorLayer.fromJson(l as Map<String, dynamic>)],
      strokes: [for (final s in (j['strokes'] as List? ?? const [])) DrawStroke.fromJson(s as Map<String, dynamic>)],
      frame: _byName(FrameStyle.values, j['frame'], FrameStyle.none),
      frameColor: Color((j['frameColor'] as num?)?.toInt() ?? 0xFFFFFFFF),
    );
  }
}

/// Geometry derived from the base photo and the document.
class EditorGeometry {
  EditorGeometry(this.image, this.doc);
  final ui.Image image;
  final EditorDoc doc;

  double get w0 => image.width.toDouble();
  double get h0 => image.height.toDouble();
  bool get swapped => doc.quarterTurns.isOdd;
  Size get oriented => swapped ? Size(h0, w0) : Size(w0, h0);

  Rect cropPx([Rect? crop]) {
    final c = crop ?? doc.crop;
    final o = oriented;
    return Rect.fromLTRB(c.left * o.width, c.top * o.height, c.right * o.width, c.bottom * o.height);
  }

  /// Photo area size (after crop).
  Size get photoSize => cropPx().size;

  double get frameSide => doc.frame.side * math.max(photoSize.width, photoSize.height);
  double get frameBottom => doc.frame.bottomExtra * math.max(photoSize.width, photoSize.height);

  /// Full output size including the frame.
  Size get outputSize => Size(photoSize.width + frameSide * 2, photoSize.height + frameSide * 2 + frameBottom);

  double get coverScale {
    final a = doc.straighten.abs() * math.pi / 180;
    final o = oriented;
    final c = math.cos(a);
    final s = math.sin(a);
    return math.max((o.width * c + o.height * s) / o.width, (o.width * s + o.height * c) / o.height);
  }

  /// Draws the oriented, straightened photo so that oriented-space pixels land at (0,0)..oriented.
  void paintOriented(Canvas canvas, {List<double>? matrix}) {
    final o = oriented;
    canvas.save();
    canvas.translate(o.width / 2, o.height / 2);
    canvas.rotate(doc.straighten * math.pi / 180);
    canvas.scale(coverScale);
    if (doc.flipH) canvas.scale(-1, 1);
    canvas.rotate(doc.quarterTurns * math.pi / 2);
    canvas.translate(-w0 / 2, -h0 / 2);
    final paint = Paint()..filterQuality = FilterQuality.medium;
    if (matrix != null) paint.colorFilter = ColorFilter.matrix(matrix);
    canvas.drawImage(image, Offset.zero, paint);
    canvas.restore();
  }
}

/// Noise tile for the grain effect (deterministic).
class Grain {
  static ui.Image? _tile;

  static Future<ui.Image> tile() async {
    if (_tile != null) return _tile!;
    const n = 192;
    final px = Uint8List(n * n * 4);
    var seed = 0x2F6E2B1;
    for (var i = 0; i < n * n; i++) {
      seed = (seed * 1103515245 + 12345) & 0x7fffffff;
      final v = 64 + (seed >> 16) % 128;
      px[i * 4] = v;
      px[i * 4 + 1] = v;
      px[i * 4 + 2] = v;
      px[i * 4 + 3] = 255;
    }
    return _tile = await ImageIO.fromRgba(px, n, n);
  }
}

/// Decoded pictures used by picture layers, shared by every screen (small LRU).
class LayerImages extends ChangeNotifier {
  LayerImages._();
  static final instance = LayerImages._();
  static const _max = 10;

  final Map<String, ui.Image> _images = {};
  final Map<String, Future<void>> _loading = {};

  ui.Image? operator [](String path) {
    final img = _images.remove(path);
    if (img != null) _images[path] = img; // most recently used last
    return img;
  }

  Future<void> ensure(String path, {int maxSide = 1440}) {
    if (_images.containsKey(path)) return Future.value();
    return _loading[path] ??= () async {
      try {
        final img = await ImageIO.decode(await File(path).readAsBytes(), maxSide: maxSide);
        _images[path] = img;
        while (_images.length > _max) {
          final oldest = _images.keys.first;
          _images.remove(oldest)?.dispose();
        }
        notifyListeners();
      } catch (e, s) {
        CrashLog.record(e, s, context: 'layer image');
      } finally {
        _loading.remove(path);
      }
    }();
  }

  Future<void> ensureAll(Iterable<EditorLayer> layers) async {
    for (final l in layers) {
      if (l.image != null) await ensure(l.image!);
    }
  }
}

final Map<String, QrImage> _qrCache = {};

QrImage? qrFor(String data) {
  final hit = _qrCache[data];
  if (hit != null) return hit;
  try {
    final q = QrImage(QrCode(payload: QrPayload.fromString(data), errorCorrectLevel: QrErrorCorrectLevel.medium));
    if (_qrCache.length > 16) _qrCache.remove(_qrCache.keys.first);
    return _qrCache[data] = q;
  } catch (_) {
    return null; // too long for a QR code
  }
}

/// Quiet zone around QR codes, in modules.
const kQrQuiet = 3;

TextPainter layoutLayer(EditorLayer l, double photoWidth) {
  final fontSize = math.max(4.0, l.size * photoWidth);
  if (l.isSticker) {
    return TextPainter(
      text: TextSpan(text: l.emoji, style: TextStyle(fontSize: fontSize, height: 1.1)),
      textDirection: TextDirection.ltr,
    )..layout();
  }
  final weight = l.font == 'Pacifico' || l.font == 'BebasNeue' ? FontWeight.w400 : FontWeight.w700;
  final textColor = l.look == TextLook.label ? _contrastOn(l.color) : l.color;
  final style = TextStyle(
    fontFamily: l.font,
    fontSize: fontSize,
    height: 1.15,
    color: textColor,
    fontWeight: weight,
    fontVariations: [ui.FontVariation('wght', weight.value.toDouble())],
  );
  return TextPainter(
    text: TextSpan(text: l.text, style: style),
    textAlign: l.align,
    textDirection: TextDirection.ltr,
  )..layout(maxWidth: photoWidth * 0.95);
}

Color _contrastOn(Color c) => c.computeLuminance() > 0.5 ? const Color(0xFF111111) : const Color(0xFFFFFFFF);

/// Half-size of a layer in photo pixels (for hit testing and the selection box).
Size layerHalfSize(EditorLayer l, double photoWidth) {
  if (l.isQr) {
    final side = l.size * photoWidth;
    return Size(side / 2, side / 2);
  }
  if (l.isPicture) {
    final w = l.size * photoWidth;
    return Size(w / 2, w / l.aspect.clamp(0.1, 10) / 2);
  }
  final tp = layoutLayer(l, photoWidth);
  final pad = l.look == TextLook.label && !l.isSticker ? l.size * photoWidth * 0.35 : 0.0;
  return Size(tp.width / 2 + pad, tp.height / 2 + pad * 0.6);
}

void _paintQr(Canvas canvas, EditorLayer l, Size photo) {
  final side = l.size * photo.width;
  final rect = Rect.fromCenter(center: Offset.zero, width: side, height: side);
  canvas.drawRRect(RRect.fromRectAndRadius(rect, Radius.circular(side * 0.06)), Paint()..color = const Color(0xFFFFFFFF));
  final q = qrFor(l.qr!);
  if (q == null) return;
  final n = q.moduleCount;
  final m = side / (n + kQrQuiet * 2);
  final ink = l.color.computeLuminance() < 0.3 ? l.color : const Color(0xFF141834);
  final path = Path();
  for (var r = 0; r < n; r++) {
    for (var c = 0; c < n; c++) {
      if (q.isDark(r, c)) {
        // Slight overlap avoids hairline seams between modules.
        path.addRect(Rect.fromLTWH(rect.left + (c + kQrQuiet) * m, rect.top + (r + kQrQuiet) * m, m + 0.5, m + 0.5));
      }
    }
  }
  canvas.drawPath(path, Paint()..color = ink..isAntiAlias = false);
}

void _paintPicture(Canvas canvas, EditorLayer l, Size photo) {
  final half = layerHalfSize(l, photo.width);
  final dst = Rect.fromLTRB(-half.width, -half.height, half.width, half.height);
  final img = LayerImages.instance[l.image!];
  if (img == null) {
    LayerImages.instance.ensure(l.image!);
    canvas.drawRect(dst, Paint()..color = const Color(0x33FFFFFF));
    return;
  }
  canvas.drawImageRect(
    img,
    Rect.fromLTWH(0, 0, img.width.toDouble(), img.height.toDouble()),
    dst,
    Paint()..filterQuality = FilterQuality.medium,
  );
}

void paintLayer(Canvas canvas, EditorLayer l, Size photo) {
  if (l.isQr || l.isPicture) {
    canvas.save();
    canvas.translate(l.pos.dx * photo.width, l.pos.dy * photo.height);
    canvas.rotate(l.rotation);
    l.isQr ? _paintQr(canvas, l, photo) : _paintPicture(canvas, l, photo);
    canvas.restore();
    return;
  }
  final tp = layoutLayer(l, photo.width);
  canvas.save();
  canvas.translate(l.pos.dx * photo.width, l.pos.dy * photo.height);
  canvas.rotate(l.rotation);
  final topLeft = Offset(-tp.width / 2, -tp.height / 2);
  if (!l.isSticker && l.look == TextLook.label) {
    final pad = l.size * photo.width * 0.35;
    canvas.drawRRect(
      RRect.fromRectAndRadius(Rect.fromLTWH(topLeft.dx - pad, topLeft.dy - pad * 0.6, tp.width + pad * 2, tp.height + pad * 1.2), Radius.circular(pad)),
      Paint()..color = l.color,
    );
  }
  if (!l.isSticker && l.look == TextLook.outline) {
    final stroke = TextPainter(
      text: TextSpan(
        text: l.text,
        style: (tp.text! as TextSpan).style!.copyWith(
          color: null,
          foreground: Paint()
            ..style = PaintingStyle.stroke
            ..strokeWidth = l.size * photo.width * 0.14
            ..strokeJoin = StrokeJoin.round
            ..color = _contrastOn(l.color),
        ),
      ),
      textAlign: l.align,
      textDirection: TextDirection.ltr,
    )..layout(maxWidth: photo.width * 0.95);
    stroke.paint(canvas, topLeft);
  }
  tp.paint(canvas, topLeft);
  canvas.restore();
}

void _paintStrokes(Canvas canvas, List<DrawStroke> strokes, Size photo) {
  if (strokes.isEmpty) return;
  canvas.saveLayer(Offset.zero & photo, Paint());
  for (final s in strokes) {
    final paint = Paint()
      ..color = s.color
      ..style = PaintingStyle.stroke
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round
      ..strokeWidth = s.width * photo.width
      ..blendMode = s.eraser ? BlendMode.clear : BlendMode.srcOver;
    final pts = [for (final p in s.points) Offset(p.dx * photo.width, p.dy * photo.height)];
    if (pts.length == 1) {
      canvas.drawCircle(pts.first, paint.strokeWidth / 2, paint..style = PaintingStyle.fill);
      continue;
    }
    final path = Path()..moveTo(pts.first.dx, pts.first.dy);
    for (var i = 1; i < pts.length - 1; i++) {
      path.quadraticBezierTo(pts[i].dx, pts[i].dy, (pts[i].dx + pts[i + 1].dx) / 2, (pts[i].dy + pts[i + 1].dy) / 2);
    }
    path.lineTo(pts.last.dx, pts.last.dy);
    canvas.drawPath(path, paint);
  }
  canvas.restore();
}

/// Paints the finished picture at output resolution (frame included).
void paintEdited(Canvas canvas, EditorGeometry g, {ui.Image? grain, DrawStroke? live, String? hiddenLayer}) {
  final d = g.doc;
  final photo = g.photoSize;
  final out = g.outputSize;
  if (d.frame != FrameStyle.none) {
    canvas.drawRect(Offset.zero & out, Paint()..color = d.frameColor);
  }
  canvas.save();
  canvas.translate(g.frameSide, g.frameSide);
  canvas.clipRect(Offset.zero & photo);
  final crop = g.cropPx();
  canvas.save();
  canvas.translate(-crop.left, -crop.top);
  g.paintOriented(canvas, matrix: d.hasColorWork ? d.matrix() : null);
  canvas.restore();

  final vig = d.adj(Adj.vignette);
  if (vig > 0) {
    final center = Offset(photo.width / 2, photo.height / 2);
    final radius = math.sqrt(photo.width * photo.width + photo.height * photo.height) / 2;
    canvas.drawRect(
      Offset.zero & photo,
      Paint()
        ..shader = ui.Gradient.radial(center, radius, [const Color(0x00000000), Color.fromRGBO(0, 0, 0, 0.85 * vig)], [0.45, 1]),
    );
  }
  final gr = d.adj(Adj.grain);
  if (gr > 0 && grain != null) {
    final scale = math.max(1.0, photo.width / 1400);
    canvas.drawRect(
      Offset.zero & photo,
      Paint()
        ..shader = ImageShader(grain, TileMode.repeated, TileMode.repeated, Matrix4.diagonal3Values(scale, scale, 1).storage)
        ..blendMode = BlendMode.overlay
        ..color = Color.fromRGBO(0, 0, 0, 0.55 * gr),
    );
  }
  _paintStrokes(canvas, [...d.strokes, ?live], photo);
  for (final l in d.layers) {
    if (l.id != hiddenLayer) paintLayer(canvas, l, photo);
  }
  canvas.restore();
}
