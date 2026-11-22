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
  CutoutController(this.photo, {required CutoutDoc initial, bool runDetection = true, Float32List? confidence, this.ownsPhoto = false})
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

  /// Frees [photo] on dispose, once any work still reading it has finished.
  final bool ownsPhoto;
  final History<CutoutDoc> history;
  bool _disposed = false;
  final Set<Future<void>> _inflight = {};

  /// Keeps track of async work that reads [photo], so it is never freed under it.
  Future<T> _track<T>(Future<T> f) {
    final done = f.then<void>((_) {}, onError: (Object _) {});
    _inflight.add(done);
    done.whenComplete(() => _inflight.remove(done));
    return f;
  }

  @override
  void notifyListeners() {
    if (!_disposed) super.notifyListeners();
  }
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
  (double, double, Uint8List?)? _maskKey;

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
      final r = await _track(segmentPhoto(photo));
      if (_disposed) return;
      _confidence = r.confidence;
      engine = r.engine;
      status = CutoutStatus.ready;
      _maskKey = null;
      await _rebuildMask();
    } catch (e) {
      if (_disposed) return;
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

  /// Erased pixels are kept as one packed union per history step (see [eraseColorAt]),
  /// so the key compares that union by identity.
  (double, double, Uint8List?) _keyOf(CutoutDoc d) => (d.threshold, d.softness, d.regions.lastOrNull);

  void _onDoc() {
    // While a rebuild runs, always ask for another pass: an undo can bring back the key
    // that was current before the running rebuild started.
    if (_rebuilding || _keyOf(doc) != _maskKey) _rebuildMask();
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
        final key = _keyOf(d);
        final conf = _confidence;
        final packed = d.regions.lastOrNull;
        final length = w * h;
        if (conf == null && packed == null) {
          _swapMask(null);
        } else {
          final rgba = await Isolate.run(() {
            final erased = packed == null ? null : unpackBits(packed, length);
            return buildMaskRgba(conf, length, d.threshold, d.softness, erased);
          });
          _swapMask(await ImageIO.fromRgba(rgba, w, h));
        }
        _maskKey = key;
      } while (!_disposed && (_rebuildAgain || _keyOf(doc) != _maskKey));
    } finally {
      _rebuilding = false;
      _maskReady?.complete();
      notifyListeners();
    }
  }

  void _swapMask(ui.Image? next) {
    if (_disposed) {
      next?.dispose();
      return;
    }
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
  /// Returns the number of erased pixels, or -1 while a previous erase is still running.
  Future<int> eraseColorAt(Offset p, double tolerance, bool contiguous) async {
    if (busy) return -1;
    busy = true;
    notifyListeners();
    try {
      final rgba = _rgba ??= await _track<Uint8List>(ImageIO.rgba(photo));
      if (_disposed) return 0;
      final x = p.dx.floor();
      final y = p.dy.floor();
      final ww = w;
      final hh = h;
      final previous = doc.regions.lastOrNull;
      final (count, merged) = await Isolate.run(() {
        final region = colorRegion(rgba, ww, hh, x, y, tolerance, contiguous);
        var n = 0;
        for (final v in region) {
          n += v;
        }
        return (n, n > 0 ? mergePacked(previous, region) : null);
      });
      if (_disposed) return 0;
      if (merged != null) history.push(doc.copyWith(regions: [merged]));
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
    _disposed = true;
    history.removeListener(_onDoc);
    history.dispose();
    mask?.dispose();
    mask = null;
    bgPhoto?.dispose();
    bgPhoto = null;
    if (ownsPhoto) Future.wait(_inflight.toList()).whenComplete(photo.dispose);
    super.dispose();
  }
}
