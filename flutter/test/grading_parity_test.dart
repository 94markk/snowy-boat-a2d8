import 'package:delicat_studio/core/model/adjustments.dart';
import 'package:delicat_studio/core/model/filters.dart';
import 'package:delicat_studio/engine/lut.dart';
import 'package:flutter_test/flutter_test.dart';

/// The preview samples the RGBA strip; export writes the .cube. If those two
/// ever describe different colour, the file stops matching the screen, and
/// that is the failure a user reads as "the filters do not work properly".
/// Both come out of gradePixel, so this test is what keeps that true rather
/// than merely intended.
void main() {
  const graded = Adjustments(
    exposure: 0.4,
    contrast: 0.25,
    saturation: -0.2,
    temperature: 0.3,
    highlights: -0.5,
    shadows: 0.35,
    hueShift: 24,
    fade: 0.3,
    filterId: 'teal_orange',
    filterStrength: 0.8,
  );

  test('the strip and the cube describe the same colour', () {
    final strip = LutBaker.bakeStrip(graded, size: LutBaker.exportSize);
    // Data rows only: the DOMAIN_ header lines also carry decimal points, so
    // matching on those would count them as colour entries.
    final cubeLines = LutBaker.bakeCube(graded)
        .split('\n')
        .where((l) => RegExp(r'^[0-9]').hasMatch(l))
        .toList();

    const size = LutBaker.exportSize;
    final stripWidth = LutBaker.stripWidthFor(size);
    expect(cubeLines.length, size * size * size);

    // Walk the cube in its own order — red fastest, blue slowest — and find
    // each entry's pixel in the tiled strip.
    var mismatches = 0;
    for (var b = 0; b < size; b += 7) {
      for (var g = 0; g < size; g += 7) {
        for (var r = 0; r < size; r += 7) {
          final index = b * size * size + g * size + r;
          final parts = cubeLines[index].trim().split(RegExp(r'\s+'));
          final cube = [
            double.parse(parts[0]),
            double.parse(parts[1]),
            double.parse(parts[2]),
          ];

          final x = (b % LutBaker.tilesAcross) * size + r;
          final y = (b ~/ LutBaker.tilesAcross) * size + g;
          final i = (y * stripWidth + x) * 4;
          final fromStrip = [
            strip[i] / 255,
            strip[i + 1] / 255,
            strip[i + 2] / 255,
          ];

          for (var ch = 0; ch < 3; ch++) {
            // The strip is 8-bit, the cube is float, so half a code value is
            // the most they can legitimately differ by.
            if ((cube[ch] - fromStrip[ch]).abs() > 1 / 255) mismatches++;
          }
        }
      }
    }

    expect(mismatches, 0);
  });

  test('the preview cube tracks the export cube', () {
    // The preview bakes a smaller cube so it can keep up with a slider. That
    // is only acceptable while the two still describe the same look: if 32³
    // drifted from 64³, grading would be done against colour the file will not
    // reproduce, which is the same class of failure as having no parity at all.
    final preview = LutBaker.bakeStrip(graded, size: LutBaker.previewSize);
    final previewWidth = LutBaker.stripWidthFor(LutBaker.previewSize);
    const n = LutBaker.previewSize;

    var worst = 0.0;
    for (var b = 0; b < n; b++) {
      for (var g = 0; g < n; g++) {
        for (var r = 0; r < n; r++) {
          final x = (b % LutBaker.tilesAcross) * n + r;
          final y = (b ~/ LutBaker.tilesAcross) * n + g;
          final i = (y * previewWidth + x) * 4;

          // Same input colour, evaluated directly rather than sampled.
          final exact = LutBaker.gradePixel(graded, [
            r / (n - 1),
            g / (n - 1),
            b / (n - 1),
          ]);

          for (var ch = 0; ch < 3; ch++) {
            final diff = (exact[ch] - preview[i + ch] / 255).abs();
            if (diff > worst) worst = diff;
          }
        }
      }
    }

    // Both tables come from gradePixel, so the only difference should be the
    // 8-bit quantisation of the strip.
    expect(worst, lessThan(1 / 255));
  });

  test('neutral adjustments leave colour untouched', () {
    const neutral = Adjustments();
    expect(neutral.isNeutral, isTrue);

    for (final v in [0.0, 0.25, 0.5, 0.75, 1.0]) {
      final out = LutBaker.gradePixel(neutral, [v, v, v]);
      expect(out[0], closeTo(v, 1e-9));
      expect(out[1], closeTo(v, 1e-9));
      expect(out[2], closeTo(v, 1e-9));
    }
  });

  test('every adjustment actually moves a pixel', () {
    // The complaint that started this app was controls that appeared to do
    // nothing. A parameter that cannot change any colour is exactly that bug,
    // so each one is exercised rather than assumed.
    const probes = [
      [0.2, 0.35, 0.6],
      [0.5, 0.5, 0.5],
      [0.8, 0.7, 0.3],
    ];

    for (final spec in AdjustSpec.all) {
      // Spatial parameters are not colour maps and correctly do nothing here;
      // they are applied by the shader and by FFmpeg instead.
      if (const {'sharpen', 'blur', 'grain', 'glow', 'vignette'}
          .contains(spec.id)) {
        continue;
      }

      final nudged = const Adjustments().set(spec.id, spec.max * 0.6);
      final moved = probes.any((probe) {
        final before = LutBaker.gradePixel(const Adjustments(), probe);
        final after = LutBaker.gradePixel(nudged, probe);
        return (before[0] - after[0]).abs() > 1e-6 ||
            (before[1] - after[1]).abs() > 1e-6 ||
            (before[2] - after[2]).abs() > 1e-6;
      });

      expect(moved, isTrue, reason: '${spec.id} changed no colour');
    }
  });

  test('every filter is reachable and named', () {
    expect(Filters.all.map((f) => f.id).toSet().length, Filters.all.length);
    for (final preset in Filters.all) {
      expect(Filters.get(preset.id).id, preset.id);
      expect(preset.name, isNotEmpty);
    }
  });

  test('a look changes colour unless it is Original', () {
    for (final preset in Filters.all) {
      final c = [0.3, 0.5, 0.7];
      final out = [c[0], c[1], c[2]];
      preset.look(out);
      final same = (out[0] - c[0]).abs() < 1e-9 &&
          (out[1] - c[1]).abs() < 1e-9 &&
          (out[2] - c[2]).abs() < 1e-9;
      expect(same, preset.id == Filters.noneId, reason: preset.id);
    }
  });
}
