import 'dart:math' as math;
import 'dart:ui';

/// A collage layout: cells as fractions (0..1) of the canvas.
class CollageLayout {
  const CollageLayout(this.id, this.cells);
  final String id;
  final List<Rect> cells;
}

/// All ordered ways to write [n] as a sum of parts in 1..[maxPart], at most [maxParts] parts.
List<List<int>> compositions(int n, {int maxPart = 4, int maxParts = 4}) {
  final out = <List<int>>[];
  void go(int left, List<int> acc) {
    if (left == 0) {
      out.add(List.of(acc));
      return;
    }
    if (acc.length == maxParts) return;
    for (var p = 1; p <= math.min(maxPart, left); p++) {
      acc.add(p);
      go(left - p, acc);
      acc.removeLast();
    }
  }

  go(n, []);
  return out;
}

List<Rect> _rows(List<int> comp) {
  final cells = <Rect>[];
  final k = comp.length;
  for (var i = 0; i < k; i++) {
    final m = comp[i];
    for (var j = 0; j < m; j++) {
      cells.add(Rect.fromLTWH(j / m, i / k, 1 / m, 1 / k));
    }
  }
  return cells;
}

List<Rect> _cols(List<int> comp) => _rows(comp).map((r) => Rect.fromLTWH(r.top, r.left, r.height, r.width)).toList();

List<Rect> _hero(int n, String side) {
  const big = 0.6;
  final rest = n - 1;
  final cells = <Rect>[];
  switch (side) {
    case 'left':
      cells.add(const Rect.fromLTWH(0, 0, big, 1));
      for (var i = 0; i < rest; i++) {
        cells.add(Rect.fromLTWH(big, i / rest, 1 - big, 1 / rest));
      }
    case 'right':
      for (var i = 0; i < rest; i++) {
        cells.add(Rect.fromLTWH(0, i / rest, 1 - big, 1 / rest));
      }
      cells.add(const Rect.fromLTWH(1 - big, 0, big, 1));
    case 'top':
      cells.add(const Rect.fromLTWH(0, 0, 1, big));
      for (var i = 0; i < rest; i++) {
        cells.add(Rect.fromLTWH(i / rest, big, 1 / rest, 1 - big));
      }
    default:
      for (var i = 0; i < rest; i++) {
        cells.add(Rect.fromLTWH(i / rest, 0, 1 / rest, 1 - big));
      }
      cells.add(const Rect.fromLTWH(0, 1 - big, 1, big));
  }
  return cells;
}

/// Layouts for [n] photos, most balanced first.
List<CollageLayout> layoutsFor(int n, {int limit = 18}) {
  if (n <= 1) return const [CollageLayout('single', [Rect.fromLTWH(0, 0, 1, 1)])];
  final scored = <(double, CollageLayout)>[];
  final seen = <String>{};
  void add(double score, String id, List<Rect> cells) {
    final key = (cells.map((r) => '${(r.left * 100).round()},${(r.top * 100).round()},${(r.width * 100).round()},${(r.height * 100).round()}').toList()
          ..sort())
        .join('|');
    if (seen.add(key)) scored.add((score, CollageLayout(id, cells)));
  }

  final ideal = math.sqrt(n);
  for (final comp in compositions(n)) {
    final spread = comp.reduce(math.max) - comp.reduce(math.min);
    final score = spread * 2 + (comp.length - ideal).abs();
    add(score, 'r${comp.join()}', _rows(comp));
    add(score + 0.1, 'c${comp.join()}', _cols(comp));
  }
  if (n >= 3 && n <= 5) {
    for (final side in ['left', 'top', 'right', 'bottom']) {
      add(1.5, 'h$side', _hero(n, side));
    }
  }
  scored.sort((a, b) => a.$1.compareTo(b.$1));
  return scored.take(limit).map((e) => e.$2).toList();
}

/// Cell rectangles in pixels for a canvas of [size] with gap [spacing] (fraction of width).
List<Rect> cellRects(Size size, CollageLayout layout, double spacing) {
  final g = spacing * size.width;
  final inner = Rect.fromLTRB(g / 2, g / 2, size.width - g / 2, size.height - g / 2);
  return [
    for (final r in layout.cells)
      Rect.fromLTRB(
        inner.left + r.left * inner.width,
        inner.top + r.top * inner.height,
        inner.left + r.right * inner.width,
        inner.top + r.bottom * inner.height,
      ).deflate(g / 2),
  ];
}
