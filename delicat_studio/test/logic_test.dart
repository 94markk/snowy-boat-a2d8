import 'dart:typed_data';
import 'dart:ui';

import 'package:delicat_studio/core/color_matrix.dart';
import 'package:delicat_studio/core/styles.dart';
import 'package:delicat_studio/features/collage/collage_layouts.dart';
import 'package:delicat_studio/features/cutout/mask_ops.dart';
import 'package:flutter_test/flutter_test.dart';

List<double> apply(List<double> m, List<double> rgba) => [
      for (var r = 0; r < 4; r++)
        m[r * 5] * rgba[0] + m[r * 5 + 1] * rgba[1] + m[r * 5 + 2] * rgba[2] + m[r * 5 + 3] * rgba[3] + m[r * 5 + 4],
    ];

void main() {
  group('ColorMatrix', () {
    test('identity leaves colours unchanged and composes neutrally', () {
      final px = [200.0, 100.0, 50.0, 255.0];
      expect(apply(ColorMatrix.identity(), px), px);
      final b = ColorMatrix.brightness(0.3);
      expect(ColorMatrix.compose(ColorMatrix.identity(), b), b);
    });

    test('compose applies the first matrix, then the second', () {
      final px = [100.0, 100.0, 100.0, 255.0];
      final m = ColorMatrix.compose(ColorMatrix.exposure(1), ColorMatrix.brightness(0.2));
      final expected = apply(ColorMatrix.brightness(0.2), apply(ColorMatrix.exposure(1), px));
      final got = apply(m, px);
      for (var i = 0; i < 4; i++) {
        expect(got[i], closeTo(expected[i], 1e-6));
      }
    });

    test('full desaturation produces equal channels', () {
      final out = apply(ColorMatrix.grayscale(1), [220.0, 40.0, 90.0, 255.0]);
      expect(out[0], closeTo(out[1], 1e-6));
      expect(out[1], closeTo(out[2], 1e-6));
    });

    test('contrast keeps mid-grey fixed', () {
      final out = apply(ColorMatrix.contrast(0.7), [128.0, 128.0, 128.0, 255.0]);
      expect(out[0], closeTo(128, 1e-6));
    });

    test('warmth pushes red up and blue down', () {
      final out = apply(ColorMatrix.warmth(1), [120.0, 120.0, 120.0, 255.0]);
      expect(out[0], greaterThan(120));
      expect(out[2], lessThan(120));
    });
  });

  group('Styles', () {
    test('24 unique presets with valid matrices', () {
      expect(kStyles.length, 24);
      expect(kStyles.map((s) => s.id).toSet().length, 24);
      for (final s in kStyles) {
        final m = s.matrix(0.6);
        expect(m.length, 20);
        expect(m.every((v) => v.isFinite), isTrue, reason: s.id);
      }
    });

    test('zero intensity is the identity', () {
      expect(styleById('noir').matrix(0), ColorMatrix.identity());
    });
  });

  group('Collage layouts', () {
    for (var n = 2; n <= 9; n++) {
      test('$n photos: cells tile the canvas exactly', () {
        final layouts = layoutsFor(n);
        expect(layouts, isNotEmpty);
        expect(layouts.map((l) => l.id).toSet().length, layouts.length);
        for (final l in layouts) {
          expect(l.cells.length, n, reason: l.id);
          var area = 0.0;
          for (final c in l.cells) {
            expect(c.left, greaterThanOrEqualTo(-1e-9));
            expect(c.top, greaterThanOrEqualTo(-1e-9));
            expect(c.right, lessThanOrEqualTo(1 + 1e-9));
            expect(c.bottom, lessThanOrEqualTo(1 + 1e-9));
            area += c.width * c.height;
          }
          expect(area, closeTo(1, 1e-9), reason: l.id);
        }
      });
    }

    test('no two layouts share the same geometry', () {
      for (var n = 2; n <= 9; n++) {
        final keys = layoutsFor(n).map((l) {
          final cells = l.cells.map((r) => '${(r.left * 100).round()},${(r.top * 100).round()},${(r.width * 100).round()},${(r.height * 100).round()}').toList()..sort();
          return cells.join('|');
        }).toList();
        expect(keys.toSet().length, keys.length, reason: '$n photos');
      }
    });

    test('gaps shrink cells and keep them inside the canvas', () {
      final l = layoutsFor(4).first;
      final rects = cellRects(const Size(1000, 1000), l, 0.02);
      for (final r in rects) {
        expect(r.left, greaterThanOrEqualTo(20 - 1e-6));
        expect(r.right, lessThanOrEqualTo(980 + 1e-6));
      }
    });
  });

  group('Mask ops', () {
    test('soft threshold maps confidence to alpha', () {
      final conf = Float32List.fromList([0.0, 0.4, 0.5, 0.6, 1.0]);
      final a = alphaFromConfidence(conf, 5, 0.5, 0.2, null);
      expect(a[0], 0);
      expect(a[1], 0);
      expect(a[2], 128);
      expect(a[3], 255);
      expect(a[4], 255);
    });

    test('erased pixels become transparent; manual mode keeps everything', () {
      final erased = Uint8List.fromList([0, 1, 0]);
      expect(alphaFromConfidence(null, 3, 0.5, 0.2, erased), [255, 0, 255]);
    });

    test('colour region: contiguous fill stops at a different colour', () {
      // 5×1 strip: red red blue red red
      final px = Uint8List.fromList([
        255, 0, 0, 255, 255, 0, 0, 255, 0, 0, 255, 255, 255, 0, 0, 255, 255, 0, 0, 255, //
      ]);
      expect(colorRegion(px, 5, 1, 0, 0, 0.1, true), [1, 1, 0, 0, 0]);
      expect(colorRegion(px, 5, 1, 0, 0, 0.1, false), [1, 1, 0, 1, 1]);
    });

    test('colour region flood fills around obstacles in 2D', () {
      // 3×3 white with a black centre; seed in a corner fills the ring.
      final px = Uint8List(3 * 3 * 4);
      for (var i = 0; i < 9; i++) {
        final v = i == 4 ? 0 : 255;
        px.setAll(i * 4, [v, v, v, 255]);
      }
      expect(colorRegion(px, 3, 3, 0, 0, 0.05, true), [1, 1, 1, 1, 0, 1, 1, 1, 1]);
    });

    test('alpha bounds', () {
      final a = Uint8List(4 * 4);
      a[1 * 4 + 1] = 255;
      a[2 * 4 + 2] = 255;
      expect(alphaBounds(a, 4, 4), [1, 1, 3, 3]);
      expect(alphaBounds(Uint8List(16), 4, 4), isNull);
    });

    test('resample keeps a constant mask constant', () {
      final out = resampleMask(List.filled(4, 0.7), 2, 2, 5, 3);
      expect(out.length, 15);
      expect(out.every((v) => (v - 0.7).abs() < 1e-6), isTrue);
    });
  });
}
