import 'dart:async';
import 'dart:isolate';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../core/history.dart';
import '../../core/image_io.dart';
import 'cutout_model.dart';
import 'mask_ops.dart';
import 'segmentation.dart';

enum CutoutStatus { analyzing, ready, failed, manual }

/// Owns the photo, the ML confidence mask and the undoable [CutoutDoc].
class CutoutController extends ChangeNotifier {
  CutoutController(this.photo, {required CutoutDoc initial, bool runDetection = true, Float32List? confidence})
      : history = History<CutoutDoc>(initial),
        _confidence = confidence,
        status = confidence != null
            ? CutoutStatus.ready
            : runDetection
                ? CutoutStatus.analyzing
                : CutoutStatus.manual {
    history.addListener(_onDoc);
    if (status == CutoutStatus.analyzing) {
      detect();
    } else {
      _rebuildMask();
    }
  }

  final ui.Image photo;
  final History<CutoutDoc> history;
  CutoutStatus status;
  String? error;
  SegEngine? engine;

  Float32List? _confidence;
  Uint8List? _rgba;
  ui.Image? mask;
  ui.Image? bgPhoto;
  MaskStroke? live;
  bool showOriginal = false;
  bool busy = false;

  bool _rebuilding = false;
  bool _rebuildAgain = false;
  Completer<void>? _maskReady;
  (double, double, int)? _maskKey;

  CutoutDoc get doc => history.value;
  int get w => photo.width;
  int get h => photo.height;
  double get unit => math.max(w, h).toDouble();
  bool get hasAiMask => _confidence != null;

  CutoutScene scene() => CutoutScene(photo: photo, mask: mask, doc: doc, bgPhoto: bgPhoto, live: live, showOriginal: showOriginal);

  Future<void> detect() async {
    status = CutoutStatus.analyzing;
    error = null;
    notifyListeners();
    try {
      final r = await segmentPhoto(photo);
      _confidence = r.confidence;
      engine = r.engine;
      status = CutoutStatus.ready;
      _maskKey = null;
      await _rebuildMask();
    } catch (e) {
      status = CutoutStatus.failed;
      error = e is SegmentationException ? e.message : 'La détection a échoué. Réessaie ou détoure à la main.';
      notifyListeners();
    }
  }

  /// Continue without AI: everything stays visible until brushed or erased.
  void continueManually() {
    status = CutoutStatus.manual;
    error = null;
    _maskKey = null;
    _rebuildMask();
  }

  void _onDoc() {
    final key = (doc.threshold, doc.softness, doc.regions.length);
    if (key != _maskKey) _rebuildMask();
    notifyListeners();
  }

  Future<void> _rebuildMask() async {
    if (status == CutoutStatus.analyzing || status == CutoutStatus.failed) return;
    if (_rebuilding) {
      _rebuildAgain = true;
      return _maskReady?.future;
    }
    _rebuilding = true;
    _maskReady = Completer<void>();
    try {
      do {
        _rebuildAgain = false;
        final d = doc;
        final key = (d.threshold, d.softness, d.regions.length);
        final conf = _confidence;
        final regions = d.regions;
        final length = w * h;
        if (conf == null && regions.isEmpty) {
          _swapMask(null);
        } else {
          final rgba = await Isolate.run(() {
            final erased = unionRegions(regions, length);
            return buildMaskRgba(conf, length, d.threshold, d.softness, erased);
          });
          _swapMask(await ImageIO.fromRgba(rgba, w, h));
        }
        _maskKey = key;
      } while (_rebuildAgain);
    } finally {
      _rebuilding = false;
      _maskReady?.complete();
      notifyListeners();
    }
  }

  void _swapMask(ui.Image? next) {
    final old = mask;
    mask = next;
    if (old != null && old != next) old.dispose();
  }

  /// Waits for any pending mask rebuild (before export).
  Future<void> settle() async {
    final m = _maskReady;
    if (m != null && !m.isCompleted) await m.future;
  }

  // ---- editing --------------------------------------------------------

  void set(CutoutDoc next) => history.push(next);

  /// Live update for sliders; call [commit] with the starting doc on release.
  void preview(CutoutDoc next) => history.replace(next);
  void commit(CutoutDoc from) {
    if (!identical(from, doc)) history.commitFrom(from);
  }

  void startStroke(Offset p, double width, double hardness, BrushMode mode) {
    live = MaskStroke([p], width, hardness, mode);
    notifyListeners();
  }

  void extendStroke(Offset p) {
    final l = live;
    if (l == null) return;
    if ((l.points.last - p).distance < l.width * 0.08) return;
    live = l.adding(p);
    notifyListeners();
  }

  void cancelStroke() {
    live = null;
    notifyListeners();
  }

  void endStroke() {
    final l = live;
    live = null;
    if (l == null) return;
    history.push(doc.copyWith(strokes: [...doc.strokes, l]));
  }

  /// « Gomme couleur »: erases pixels similar to the colour under [p].
  Future<int> eraseColorAt(Offset p, double tolerance, bool contiguous) async {
    if (busy) return 0;
    busy = true;
    notifyListeners();
    try {
      final rgba = _rgba ??= await ImageIO.rgba(photo);
      final x = p.dx.floor();
      final y = p.dy.floor();
      final ww = w;
      final hh = h;
      final region = await Isolate.run(() => colorRegion(rgba, ww, hh, x, y, tolerance, contiguous));
      var count = 0;
      for (final v in region) {
        count += v;
      }
      if (count > 0) history.push(doc.copyWith(regions: [...doc.regions, region]));
      return count;
    } finally {
      busy = false;
      notifyListeners();
    }
  }

  void setOriginal(bool v) {
    if (showOriginal == v) return;
    showOriginal = v;
    notifyListeners();
  }

  void setBgPhoto(ui.Image? img) {
    bgPhoto?.dispose();
    bgPhoto = img;
    history.push(doc.copyWith(bg: BgMode.photo));
  }

  /// Tight subject bounds in photo pixels (for sticker export).
  Future<Rect> subjectBounds() async {
    await settle();
    final m = mask;
    if (m == null) return Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble());
    final rgba = await ImageIO.rgba(m);
    final ww = w;
    final hh = h;
    final b = await Isolate.run(() {
      final alpha = Uint8List(ww * hh);
      for (var i = 0; i < alpha.length; i++) {
        alpha[i] = rgba[i * 4 + 3];
      }
      return alphaBounds(alpha, ww, hh);
    });
    var r = b == null ? Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble()) : Rect.fromLTRB(b[0].toDouble(), b[1].toDouble(), b[2].toDouble(), b[3].toDouble());
    for (final s in doc.strokes.where((s) => s.mode == BrushMode.restore)) {
      for (final p in s.points) {
        r = r.expandToInclude(Rect.fromCircle(center: p, radius: s.width / 2));
      }
    }
    final pad = unit * 0.03 + doc.outline * unit * 1.5;
    return r.inflate(pad).intersect(Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble()));
  }

  Future<ui.Image> renderFull({bool cropToSubject = false}) async {
    await settle();
    final crop = cropToSubject ? await subjectBounds() : Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble());
    final s = CutoutScene(photo: photo, mask: mask, doc: doc, bgPhoto: bgPhoto);
    return ImageIO.render(math.max(1, crop.width.round()), math.max(1, crop.height.round()), (c) {
      c.translate(-crop.left, -crop.top);
      paintCutout(c, s);
    });
  }

  @override
  void dispose() {
    history.removeListener(_onDoc);
    history.dispose();
    mask?.dispose();
    bgPhoto?.dispose();
    super.dispose();
  }
}
