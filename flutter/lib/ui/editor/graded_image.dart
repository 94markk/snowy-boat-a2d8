import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../core/model/adjustments.dart';
import '../../engine/lut.dart';

/// Loads and holds the grading shader program.
///
/// The program is compiled once for the process. Compiling it per widget would
/// stall the first frame of every screen that grades something.
class GradingProgram {
  GradingProgram._(this.program);

  final ui.FragmentProgram program;

  static GradingProgram? _instance;
  static Future<GradingProgram>? _loading;

  static GradingProgram? get maybe => _instance;

  static Future<GradingProgram> load() {
    final ready = _instance;
    if (ready != null) return Future.value(ready);
    return _loading ??= ui.FragmentProgram.fromAsset('shaders/color_grade.frag')
        .then((program) {
      final wrapped = GradingProgram._(program);
      _instance = wrapped;
      return wrapped;
    });
  }
}

/// An image drawn through the colour grading shader.
///
/// The LUT is rebaked whenever the adjustments change, which is the expensive
/// part, so it is cached against the exact adjustments that produced it: a
/// rebuild that changes nothing about the grade reuses the table rather than
/// spending thirty thousand evaluations to arrive at the same bytes.
class GradedImage extends StatefulWidget {
  const GradedImage({
    super.key,
    required this.image,
    required this.adjustments,
    this.opacity = 1,
    this.fit = BoxFit.contain,
  });

  final ui.Image image;
  final Adjustments adjustments;
  final double opacity;
  final BoxFit fit;

  @override
  State<GradedImage> createState() => _GradedImageState();
}

class _GradedImageState extends State<GradedImage> {
  ui.Image? _lut;
  Adjustments? _lutFor;
  GradingProgram? _program;

  @override
  void initState() {
    super.initState();
    _prepare();
  }

  @override
  void didUpdateWidget(GradedImage old) {
    super.didUpdateWidget(old);
    if (old.adjustments != widget.adjustments) _bakeLut();
  }

  Future<void> _prepare() async {
    final program = await GradingProgram.load();
    if (!mounted) return;
    setState(() => _program = program);
    await _bakeLut();
  }

  Future<void> _bakeLut() async {
    final wanted = widget.adjustments;
    if (_lutFor == wanted) return;
    final lut = await LutBaker.bakeImage(wanted, size: LutBaker.previewSize);
    if (!mounted) {
      lut.dispose();
      return;
    }
    setState(() {
      _lut?.dispose();
      _lut = lut;
      _lutFor = wanted;
    });
  }

  @override
  void dispose() {
    _lut?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final program = _program;
    final lut = _lut;

    // Until the shader and table are ready, the ungraded frame is shown rather
    // than a blank space. A moment of wrong colour reads as loading; an empty
    // rectangle reads as broken.
    if (program == null || lut == null) {
      return RawImage(image: widget.image, fit: widget.fit);
    }

    return CustomPaint(
      painter: _GradePainter(
        program: program.program,
        image: widget.image,
        lut: lut,
        adjustments: widget.adjustments,
        opacity: widget.opacity,
        fit: widget.fit,
      ),
      size: Size.infinite,
    );
  }
}

class _GradePainter extends CustomPainter {
  _GradePainter({
    required this.program,
    required this.image,
    required this.lut,
    required this.adjustments,
    required this.opacity,
    required this.fit,
  });

  final ui.FragmentProgram program;
  final ui.Image image;
  final ui.Image lut;
  final Adjustments adjustments;
  final double opacity;
  final BoxFit fit;

  @override
  void paint(Canvas canvas, Size size) {
    final destination = _fitRect(
      Size(image.width.toDouble(), image.height.toDouble()),
      size,
    );

    final shader = program.fragmentShader();

    // Slot order follows the declaration order in color_grade.frag. There is
    // no binding by name, so the two have to be read side by side; a uniform
    // added to one and not the other silently shifts every slot after it.
    var slot = 0;
    shader
      ..setFloat(slot++, destination.width)
      ..setFloat(slot++, destination.height)
      ..setFloat(slot++, LutBaker.previewSize.toDouble())
      ..setFloat(slot++, LutBaker.tilesAcross.toDouble())
      ..setFloat(slot++, adjustments.sharpen)
      ..setFloat(slot++, adjustments.blur)
      ..setFloat(slot++, adjustments.vignette)
      ..setFloat(slot++, adjustments.grain)
      ..setFloat(slot++, adjustments.glow)
      // A fixed seed rather than a clock: grain that reshuffles every frame
      // crawls distractingly on a still preview.
      ..setFloat(slot++, 17.0)
      ..setFloat(slot++, opacity)
      ..setImageSampler(0, image)
      ..setImageSampler(1, lut);

    canvas.save();
    canvas.translate(destination.left, destination.top);
    canvas.drawRect(
      Rect.fromLTWH(0, 0, destination.width, destination.height),
      Paint()..shader = shader,
    );
    canvas.restore();
  }

  /// Where the image sits inside [available], honouring [fit].
  Rect _fitRect(Size source, Size available) {
    if (source.width <= 0 || source.height <= 0) {
      return Offset.zero & available;
    }
    final sizes = applyBoxFit(fit, source, available);
    final destination = sizes.destination;
    final dx = (available.width - destination.width) / 2;
    final dy = (available.height - destination.height) / 2;
    return Rect.fromLTWH(dx, dy, destination.width, destination.height);
  }

  @override
  bool shouldRepaint(_GradePainter old) =>
      old.image != image ||
      old.lut != lut ||
      old.adjustments != adjustments ||
      old.opacity != opacity ||
      old.fit != fit;
}
