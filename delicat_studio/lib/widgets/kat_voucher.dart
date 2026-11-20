import 'package:flutter/rendering.dart';
import 'package:material_ui/material_ui.dart';

import '../theme/tokens.dart';

/// The « kat » voucher: solid obsidian, radius 24, a dashed tear line with
/// two 24px notches between [body] and [stub]. Use one per screen.
class KatVoucher extends StatelessWidget {
  const KatVoucher({super.key, required this.body, this.stub, this.padding = const EdgeInsets.all(KatSpace.s20)});

  final Widget body;
  final Widget? stub;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return DefaultTextStyle.merge(
      style: TextStyle(color: k.onKat),
      child: IconTheme.merge(
        data: IconThemeData(color: k.onKat),
        child: _KatLayout(
          color: k.kat,
          border: k.katLine == k.kat ? null : k.katLine,
          dash: k.onKatMuted.withValues(alpha: 0.45),
          children: [
            Padding(padding: padding, child: body),
            if (stub != null) Padding(padding: padding.copyWith(top: KatSpace.s12), child: stub),
          ],
        ),
      ),
    );
  }
}

class _KatLayout extends MultiChildRenderObjectWidget {
  const _KatLayout({required this.color, required this.dash, this.border, required super.children});

  final Color color;
  final Color dash;
  final Color? border;

  @override
  RenderObject createRenderObject(BuildContext context) => _RenderKat(color, dash, border);

  @override
  void updateRenderObject(BuildContext context, _RenderKat renderObject) {
    renderObject
      ..color = color
      ..dash = dash
      ..border = border;
  }
}

class _KatParentData extends ContainerBoxParentData<RenderBox> {}

class _RenderKat extends RenderBox
    with ContainerRenderObjectMixin<RenderBox, _KatParentData>, RenderBoxContainerDefaultsMixin<RenderBox, _KatParentData> {
  _RenderKat(this._color, this._dash, this._border);

  static const notch = 12.0; // radius → 24px notch
  static const tearGap = 24.0;

  Color _color;
  set color(Color v) {
    if (v == _color) return;
    _color = v;
    markNeedsPaint();
  }

  Color _dash;
  set dash(Color v) {
    if (v == _dash) return;
    _dash = v;
    markNeedsPaint();
  }

  Color? _border;
  set border(Color? v) {
    if (v == _border) return;
    _border = v;
    markNeedsPaint();
  }

  double? _tearY;

  @override
  void setupParentData(RenderBox child) {
    if (child.parentData is! _KatParentData) child.parentData = _KatParentData();
  }

  @override
  void performLayout() {
    final width = constraints.maxWidth.isFinite ? constraints.maxWidth : 360.0;
    final inner = BoxConstraints(minWidth: width, maxWidth: width);
    var y = 0.0;
    var i = 0;
    _tearY = null;
    var child = firstChild;
    while (child != null) {
      final pd = child.parentData! as _KatParentData;
      if (i == 1) {
        _tearY = y + tearGap / 2;
        y += tearGap;
      }
      child.layout(inner, parentUsesSize: true);
      pd.offset = Offset(0, y);
      y += child.size.height;
      i++;
      child = pd.nextSibling;
    }
    size = constraints.constrain(Size(width, y));
  }

  Path _shape() {
    final r = RRect.fromRectAndRadius(Offset.zero & size, const Radius.circular(KatRadius.kat));
    var path = Path()..addRRect(r);
    final ty = _tearY;
    if (ty != null) {
      final holes = Path()
        ..addOval(Rect.fromCircle(center: Offset(0, ty), radius: notch))
        ..addOval(Rect.fromCircle(center: Offset(size.width, ty), radius: notch));
      path = Path.combine(PathOperation.difference, path, holes);
    }
    return path;
  }

  @override
  void paint(PaintingContext context, Offset offset) {
    final canvas = context.canvas;
    canvas.save();
    canvas.translate(offset.dx, offset.dy);
    final shape = _shape();
    canvas.drawPath(shape, Paint()..color = _color);
    final b = _border;
    if (b != null) {
      canvas.drawPath(
        shape,
        Paint()
          ..color = b
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1,
      );
    }
    final ty = _tearY;
    if (ty != null) {
      final p = Paint()
        ..color = _dash
        ..strokeWidth = 1.5
        ..strokeCap = StrokeCap.round;
      const dashW = 6.0;
      const gapW = 6.0;
      var x = notch + 8;
      while (x < size.width - notch - 8) {
        canvas.drawLine(Offset(x, ty), Offset(x + dashW, ty), p);
        x += dashW + gapW;
      }
    }
    canvas.restore();
    defaultPaint(context, offset);
  }

  @override
  bool hitTestChildren(BoxHitTestResult result, {required Offset position}) =>
      defaultHitTestChildren(result, position: position);
}
