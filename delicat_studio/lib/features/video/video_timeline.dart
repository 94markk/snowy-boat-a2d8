import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../theme/tokens.dart';
import 'video_model.dart';

enum VSelKind { none, clip, text, music }

@immutable
class VSel {
  const VSel(this.kind, [this.id]);
  const VSel.none() : this(VSelKind.none);
  final VSelKind kind;
  final String? id;

  @override
  bool operator ==(Object other) => other is VSel && other.kind == kind && other.id == id;
  @override
  int get hashCode => Object.hash(kind, id);
}

/// Filmstrip frames for one file.
class ClipThumbs {
  const ClipThumbs(this.images, this.intervalMs);
  final List<ui.Image> images;
  final double intervalMs;
}

class _Geo {
  _Geo(this.size, this.t, this.pxPerMs);
  final Size size;
  final double t;
  final double pxPerMs;

  static const clipTop = 32.0;
  static const clipH = 56.0;
  static const textTop = clipTop + clipH + 10;
  static const laneH = 32.0;
  static const musicTop = textTop + laneH + 8;
  static const height = musicTop + laneH + 8;

  double get cx => size.width / 2;
  double x(double ms) => cx + (ms - t) * pxPerMs;
  double ms(double x) => t + (x - cx) / pxPerMs;
}

/// CapCut-style timeline: the playhead stays centred and the tracks scroll under it.
class VideoTimeline extends StatefulWidget {
  const VideoTimeline({
    super.key,
    required this.doc,
    required this.t,
    required this.pxPerMs,
    required this.sel,
    required this.thumbs,
    required this.onScrub,
    required this.onZoom,
    required this.onSelect,
    required this.onTrim,
    required this.onMoveText,
    required this.onGestureEnd,
    required this.onToggleTransition,
    required this.onAddClip,
    required this.onAddMusic,
    required this.onAddText,
  });

  final VideoDoc doc;
  final double t;
  final double pxPerMs;
  final VSel sel;
  final Map<String, ClipThumbs> thumbs;
  final ValueChanged<double> onScrub;
  final ValueChanged<double> onZoom;
  final ValueChanged<VSel> onSelect;

  /// Live trim of a clip edge by [deltaMs] of timeline time since the gesture began.
  final void Function(String clipId, bool leftEdge, double deltaMs) onTrim;
  final void Function(String textId, double startMs) onMoveText;
  final VoidCallback onGestureEnd;
  final ValueChanged<int> onToggleTransition;
  final VoidCallback onAddClip;
  final VoidCallback onAddMusic;
  final VoidCallback onAddText;

  @override
  State<VideoTimeline> createState() => _VideoTimelineState();
}

enum _Drag { none, scrub, zoom, trimLeft, trimRight, moveText }

class _VideoTimelineState extends State<VideoTimeline> {
  _Drag _drag = _Drag.none;
  Offset _start = Offset.zero;
  double _t0 = 0;
  double _pps0 = 0;
  double _text0 = 0;
  String? _target;
  Size _size = Size.zero;

  _Geo get _g => _Geo(_size, widget.t, widget.pxPerMs);

  Rect _clipRect(int i) {
    final g = _g;
    final s = widget.doc.startOf(i);
    return Rect.fromLTRB(g.x(s), _Geo.clipTop, g.x(s + widget.doc.clips[i].durationMs), _Geo.clipTop + _Geo.clipH);
  }

  Rect _textRect(VText v) => Rect.fromLTRB(_g.x(v.startMs), _Geo.textTop, _g.x(v.endMs), _Geo.textTop + _Geo.laneH);

  Rect? _musicRect() {
    final m = widget.doc.music;
    if (m == null) return null;
    final d = widget.doc.durationMs;
    final len = m.loop ? d : math.min(d, m.sourceMs - m.inMs);
    return Rect.fromLTRB(_g.x(0), _Geo.musicTop, _g.x(len), _Geo.musicTop + _Geo.laneH);
  }

  Rect get _addClipRect {
    final x = _g.x(widget.doc.durationMs) + 12;
    return Rect.fromLTWH(x, _Geo.clipTop + (_Geo.clipH - 44) / 2, 44, 44);
  }

  Rect _addLaneRect(double top) => Rect.fromLTWH(_g.x(0), top, 196, _Geo.laneH);

  int _selectedClipIndex() {
    if (widget.sel.kind != VSelKind.clip) return -1;
    return widget.doc.clips.indexWhere((c) => c.id == widget.sel.id);
  }

  void _onScaleStart(ScaleStartDetails d) {
    _start = d.localFocalPoint;
    _t0 = widget.t;
    _pps0 = widget.pxPerMs;
    if (d.pointerCount >= 2) {
      _drag = _Drag.zoom;
      return;
    }
    final p = d.localFocalPoint;
    final si = _selectedClipIndex();
    if (si >= 0) {
      final r = _clipRect(si);
      if (p.dy >= r.top - 8 && p.dy <= r.bottom + 8) {
        if ((p.dx - r.left).abs() < 26) {
          _drag = _Drag.trimLeft;
          _target = widget.sel.id;
          return;
        }
        if ((p.dx - r.right).abs() < 26) {
          _drag = _Drag.trimRight;
          _target = widget.sel.id;
          return;
        }
      }
    }
    if (widget.sel.kind == VSelKind.text) {
      final v = widget.doc.texts.where((x) => x.id == widget.sel.id).firstOrNull;
      if (v != null && _textRect(v).inflate(6).contains(p)) {
        _drag = _Drag.moveText;
        _target = v.id;
        _text0 = v.startMs;
        return;
      }
    }
    _drag = _Drag.scrub;
  }

  void _onScaleUpdate(ScaleUpdateDetails d) {
    final dx = d.localFocalPoint.dx - _start.dx;
    switch (_drag) {
      case _Drag.zoom:
        widget.onZoom((_pps0 * d.scale).clamp(0.008, 0.6));
      case _Drag.scrub:
        if (d.pointerCount >= 2) {
          _drag = _Drag.zoom;
          _pps0 = widget.pxPerMs;
          return;
        }
        widget.onScrub(_t0 - dx / widget.pxPerMs);
      case _Drag.trimLeft:
      case _Drag.trimRight:
        widget.onTrim(_target!, _drag == _Drag.trimLeft, dx / widget.pxPerMs);
      case _Drag.moveText:
        widget.onMoveText(_target!, _text0 + dx / widget.pxPerMs);
      case _Drag.none:
        break;
    }
  }

  void _onScaleEnd(ScaleEndDetails d) {
    if (_drag == _Drag.trimLeft || _drag == _Drag.trimRight || _drag == _Drag.moveText) widget.onGestureEnd();
    _drag = _Drag.none;
    _target = null;
  }

  void _onTapUp(TapUpDetails d) {
    final p = d.localPosition;
    final doc = widget.doc;
    if (doc.clips.isNotEmpty && _addClipRect.inflate(4).contains(p)) {
      widget.onAddClip();
      return;
    }
    for (var i = 1; i < doc.clips.length; i++) {
      final x = _g.x(doc.startOf(i));
      final cy = _Geo.clipTop + _Geo.clipH / 2;
      if ((p.dx - x).abs() < 18 && (p.dy - cy).abs() < 18) {
        widget.onToggleTransition(i);
        return;
      }
    }
    for (var i = 0; i < doc.clips.length; i++) {
      if (_clipRect(i).contains(p)) {
        widget.onSelect(VSel(VSelKind.clip, doc.clips[i].id));
        return;
      }
    }
    for (final v in doc.texts.reversed) {
      if (_textRect(v).contains(p)) {
        widget.onSelect(VSel(VSelKind.text, v.id));
        return;
      }
    }
    if (doc.texts.isEmpty && _addLaneRect(_Geo.textTop).contains(p)) {
      widget.onAddText();
      return;
    }
    final mr = _musicRect();
    if (mr != null && mr.contains(p)) {
      widget.onSelect(const VSel(VSelKind.music, 'music'));
      return;
    }
    if (mr == null && _addLaneRect(_Geo.musicTop).contains(p)) {
      widget.onAddMusic();
      return;
    }
    widget.onSelect(const VSel.none());
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SizedBox(
      height: _Geo.height,
      child: LayoutBuilder(builder: (context, box) {
        _size = box.biggest;
        return GestureDetector(
          behavior: HitTestBehavior.opaque,
          onScaleStart: _onScaleStart,
          onScaleUpdate: _onScaleUpdate,
          onScaleEnd: _onScaleEnd,
          onTapUp: _onTapUp,
          child: CustomPaint(
            size: _size,
            painter: _TimelinePainter(
              geo: _Geo(_size, widget.t, widget.pxPerMs),
              doc: widget.doc,
              sel: widget.sel,
              thumbs: widget.thumbs,
              k: k,
            ),
          ),
        );
      }),
    );
  }
}

class _TimelinePainter extends CustomPainter {
  _TimelinePainter({required this.geo, required this.doc, required this.sel, required this.thumbs, required this.k});
  final _Geo geo;
  final VideoDoc doc;
  final VSel sel;
  final Map<String, ClipThumbs> thumbs;
  final KatColors k;

  void _text(Canvas c, String s, Offset at, {double size = 12, Color? color, FontWeight weight = FontWeight.w500, double? maxWidth}) {
    final tp = TextPainter(
      text: TextSpan(text: s, style: KatType.ui(size == 12 ? 12 : 14, weight: weight, tabular: true, color: color ?? k.inkMuted)),
      textDirection: TextDirection.ltr,
      maxLines: 1,
      ellipsis: '…',
    )..layout(maxWidth: maxWidth ?? double.infinity);
    tp.paint(c, at);
  }

  String _mmss(double ms) {
    final s = (ms / 1000).floor();
    return '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';
  }

  @override
  void paint(Canvas canvas, Size size) {
    canvas.clipRect(Offset.zero & size);
    final g = geo;
    final dur = doc.durationMs;

    // Ruler: a label every N seconds, chosen so labels stay ~90px apart.
    const steps = [500.0, 1000.0, 2000.0, 5000.0, 10000.0, 15000.0, 30000.0, 60000.0, 120000.0];
    final step = steps.firstWhere((s) => s * g.pxPerMs >= 90, orElse: () => 300000);
    final first = (g.ms(0) / step).floor() * step;
    final tick = Paint()..color = k.line;
    for (var ms = first; g.x(ms) < size.width; ms += step / 2) {
      if (ms < 0 || ms > dur + step) continue;
      final x = g.x(ms);
      final major = (ms / step - (ms / step).round()).abs() < 1e-6;
      canvas.drawRect(Rect.fromLTWH(x, major ? 14 : 18, 1, major ? 8 : 4), tick);
      if (major) _text(canvas, _mmss(ms), Offset(x + 4, 0));
    }

    // Main track.
    final selIndex = sel.kind == VSelKind.clip ? doc.clips.indexWhere((c) => c.id == sel.id) : -1;
    for (var i = 0; i < doc.clips.length; i++) {
      final c = doc.clips[i];
      final s = doc.startOf(i);
      final r = Rect.fromLTRB(g.x(s), _Geo.clipTop, g.x(s + c.durationMs), _Geo.clipTop + _Geo.clipH);
      if (r.right < 0 || r.left > size.width) continue;
      final rr = RRect.fromRectAndRadius(r.deflate(1), const Radius.circular(8));
      canvas.save();
      canvas.clipRRect(rr);
      canvas.drawRect(r, Paint()..color = k.sheetAlt);
      final th = thumbs[c.path];
      if (th != null && th.images.isNotEmpty) {
        final tileW = (_Geo.clipH * c.aspect).clamp(32.0, 120.0);
        final from = math.max(0, ((0 - r.left) / tileW).floor());
        for (var n = from; r.left + n * tileW < math.min(r.right, size.width); n++) {
          final dst = Rect.fromLTWH(r.left + n * tileW, r.top, tileW, r.height);
          final localMs = (n * tileW + tileW / 2) / g.pxPerMs;
          final src = c.isImage ? 0.0 : c.sourceAt(localMs);
          final img = th.images[(src / th.intervalMs).floor().clamp(0, th.images.length - 1)];
          final iw = img.width.toDouble();
          final ih = img.height.toDouble();
          final scale = math.max(dst.width / iw, dst.height / ih);
          final sw = dst.width / scale;
          final sh = dst.height / scale;
          canvas.drawImageRect(img, Rect.fromLTWH((iw - sw) / 2, (ih - sh) / 2, sw, sh), dst, Paint()..filterQuality = FilterQuality.low);
        }
      }
      if (c.speed != 1) {
        final label = '${c.speed.toStringAsFixed(c.speed == c.speed.roundToDouble() ? 0 : 2).replaceAll('.', ',')}x';
        canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(r.left + 6, r.top + 6, 40, 20), const Radius.circular(6)), Paint()..color = const Color(0xB3000000));
        _text(canvas, label, Offset(r.left + 10, r.top + 7), color: const Color(0xFFFFFFFF), weight: FontWeight.w600);
      }
      if (c.mute || doc.muteAll) {
        canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(r.right - 34, r.top + 6, 28, 20), const Radius.circular(6)), Paint()..color = const Color(0xB3000000));
        _text(canvas, '🔇', Offset(r.right - 30, r.top + 7), color: const Color(0xFFFFFFFF));
      }
      canvas.restore();
      if (i == selIndex) {
        canvas.drawRRect(rr, Paint()
          ..style = PaintingStyle.stroke
          ..strokeWidth = 2.5
          ..color = k.ink);
        final handle = Paint()..color = k.ink;
        for (final hx in [r.left, r.right - 14]) {
          canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(hx, r.top, 14, r.height), const Radius.circular(6)), handle);
          canvas.drawRect(Rect.fromLTWH(hx + 6, r.center.dy - 9, 2, 18), Paint()..color = k.canvas);
        }
        final secs = (c.durationMs / 1000).toStringAsFixed(1).replaceAll('.', ',');
        canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromLTWH(r.left + 18, r.bottom - 24, 52, 18), const Radius.circular(6)), Paint()..color = const Color(0xB3000000));
        _text(canvas, '$secs s', Offset(r.left + 22, r.bottom - 24), color: const Color(0xFFFFFFFF), weight: FontWeight.w600);
      }
    }

    // Transition markers between clips.
    for (var i = 1; i < doc.clips.length; i++) {
      final x = g.x(doc.startOf(i));
      if (x < -20 || x > size.width + 20) continue;
      final on = doc.clips[i].transitionIn;
      final box = Rect.fromCenter(center: Offset(x, _Geo.clipTop + _Geo.clipH / 2), width: 22, height: 22);
      canvas.drawRRect(RRect.fromRectAndRadius(box, const Radius.circular(6)), Paint()..color = on ? k.primary : k.sheet);
      canvas.drawRRect(RRect.fromRectAndRadius(box, const Radius.circular(6)), Paint()
        ..style = PaintingStyle.stroke
        ..color = on ? k.primary : k.line);
      // Bow-tie glyph: two triangles meeting at the cut.
      final c0 = box.center;
      final bow = Path()
        ..moveTo(c0.dx - 6, c0.dy - 5)
        ..lineTo(c0.dx, c0.dy)
        ..lineTo(c0.dx - 6, c0.dy + 5)
        ..close()
        ..moveTo(c0.dx + 6, c0.dy - 5)
        ..lineTo(c0.dx, c0.dy)
        ..lineTo(c0.dx + 6, c0.dy + 5)
        ..close();
      canvas.drawPath(bow, Paint()..color = on ? k.onPrimary : k.ink);
    }

    // Add-clip button after the last clip.
    if (doc.clips.isNotEmpty) {
      final x = g.x(dur) + 12;
      final b = Rect.fromLTWH(x, _Geo.clipTop + (_Geo.clipH - 44) / 2, 44, 44);
      canvas.drawRRect(RRect.fromRectAndRadius(b, const Radius.circular(12)), Paint()..color = k.ink);
      final p = Paint()
        ..color = k.canvas
        ..strokeWidth = 2.5
        ..strokeCap = StrokeCap.round;
      canvas.drawLine(b.center - const Offset(8, 0), b.center + const Offset(8, 0), p);
      canvas.drawLine(b.center - const Offset(0, 8), b.center + const Offset(0, 8), p);
    }

    // Text & sticker lane.
    if (doc.texts.isEmpty) {
      _lanePlaceholder(canvas, g.x(0), _Geo.textTop, '+  Ajouter du texte');
    }
    for (final v in doc.texts) {
      final r = Rect.fromLTRB(g.x(v.startMs), _Geo.textTop, g.x(v.endMs), _Geo.textTop + _Geo.laneH);
      if (r.right < 0 || r.left > size.width) continue;
      final selected = sel.kind == VSelKind.text && sel.id == v.id;
      final rr = RRect.fromRectAndRadius(r.deflate(1), const Radius.circular(8));
      canvas.drawRRect(rr, Paint()..color = selected ? k.primarySoft : k.sheet);
      canvas.drawRRect(rr, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = selected ? 2 : 1
        ..color = selected ? k.ink : k.line);
      final label = v.layer.isSticker ? v.layer.emoji! : 'T  ${v.layer.text.replaceAll('\n', ' ')}';
      canvas.save();
      canvas.clipRRect(rr);
      _text(canvas, label, Offset(r.left + 10, r.top + 8), color: k.ink, maxWidth: math.max(8, r.width - 16));
      canvas.restore();
    }

    // Music lane.
    final m = doc.music;
    if (m == null) {
      _lanePlaceholder(canvas, g.x(0), _Geo.musicTop, '+  Ajouter une musique');
    } else {
      final len = m.loop ? dur : math.min(dur, m.sourceMs - m.inMs);
      final r = Rect.fromLTRB(g.x(0), _Geo.musicTop, g.x(len), _Geo.musicTop + _Geo.laneH);
      final selected = sel.kind == VSelKind.music;
      final rr = RRect.fromRectAndRadius(r.deflate(1), const Radius.circular(8));
      canvas.drawRRect(rr, Paint()..color = selected ? k.primarySoft : k.sheet);
      canvas.drawRRect(rr, Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = selected ? 2 : 1
        ..color = selected ? k.ink : k.line);
      canvas.save();
      canvas.clipRRect(rr);
      // Simple rhythm bars so the lane reads as audio.
      final bar = Paint()..color = k.inkMuted.withValues(alpha: 0.35);
      for (var x = math.max(r.left, 0.0) + 120; x < math.min(r.right, size.width); x += 5) {
        final hgt = 6 + 14 * (0.5 + 0.5 * math.sin(x * 0.37) * math.cos(x * 0.11)).abs();
        canvas.drawRect(Rect.fromLTWH(x, r.center.dy - hgt / 2, 2, hgt), bar);
      }
      _text(canvas, '♪  ${m.name}', Offset(r.left + 10, r.top + 8), color: k.ink, maxWidth: math.max(8, math.min(r.width, 220) - 16));
      canvas.restore();
    }

    // Playhead.
    final px = g.cx;
    canvas.drawRect(Rect.fromLTWH(px - 1, 10, 2, size.height - 10), Paint()..color = k.ink);
    canvas.drawCircle(Offset(px, 10), 5, Paint()..color = k.ink);
  }

  void _lanePlaceholder(Canvas canvas, double x, double top, String label) {
    final r = Rect.fromLTWH(x, top, 196, _Geo.laneH);
    final rr = RRect.fromRectAndRadius(r, const Radius.circular(8));
    canvas.drawRRect(rr, Paint()..color = k.sheet);
    canvas.drawRRect(rr, Paint()
      ..style = PaintingStyle.stroke
      ..color = k.line);
    _text(canvas, label, Offset(r.left + 12, r.top + 8), color: k.ink, weight: FontWeight.w600);
  }

  @override
  bool shouldRepaint(_TimelinePainter old) => true;
}
