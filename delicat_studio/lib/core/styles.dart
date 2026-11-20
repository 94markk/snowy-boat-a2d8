import 'color_matrix.dart';

/// A named look built from colour-matrix steps. Intensity blends it with
/// the original (0 = untouched, 1 = full look).
class StylePreset {
  const StylePreset(this.id, this.name, this.family, this._build);
  final String id;
  final String name;
  final String family;
  final Cm Function() _build;

  Cm matrix([double intensity = 1]) => ColorMatrix.lerp(ColorMatrix.identity(), _build(), intensity.clamp(0, 1));
}

final kStyles = <StylePreset>[
  StylePreset('original', 'Original', 'Naturel', ColorMatrix.identity),
  StylePreset('delicat', 'Délicat', 'Naturel', () => ColorMatrix.chain([
        ColorMatrix.warmth(0.25),
        ColorMatrix.fade(0.22),
        ColorMatrix.saturation(-0.05),
        ColorMatrix.brightness(0.04),
      ])),
  StylePreset('lumiere', 'Lumière', 'Naturel', () => ColorMatrix.chain([
        ColorMatrix.exposure(0.25),
        ColorMatrix.contrast(-0.05),
        ColorMatrix.fade(0.1),
      ])),
  StylePreset('eclat', 'Éclat', 'Naturel', () => ColorMatrix.chain([
        ColorMatrix.saturation(0.35),
        ColorMatrix.contrast(0.15),
      ])),
  StylePreset('velours', 'Velours', 'Doux', () => ColorMatrix.chain([
        ColorMatrix.contrast(0.1),
        ColorMatrix.saturation(-0.15),
        ColorMatrix.tint(0.12),
        ColorMatrix.fade(0.15),
      ])),
  StylePreset('pastel', 'Pastel', 'Doux', () => ColorMatrix.chain([
        ColorMatrix.saturation(-0.3),
        ColorMatrix.brightness(0.12),
        ColorMatrix.fade(0.3),
      ])),
  StylePreset('kenscoff', 'Kenscoff', 'Doux', () => ColorMatrix.chain([
        ColorMatrix.warmth(-0.4),
        ColorMatrix.fade(0.2),
        ColorMatrix.saturation(-0.1),
        ColorMatrix.brightness(0.05),
      ])),
  StylePreset('ambre', 'Ambre', 'Chaud', () => ColorMatrix.chain([
        ColorMatrix.warmth(0.5),
        ColorMatrix.saturation(0.1),
        ColorMatrix.contrast(0.05),
      ])),
  StylePreset('miel', 'Miel', 'Chaud', () => ColorMatrix.chain([
        ColorMatrix.sepia(0.35),
        ColorMatrix.warmth(0.25),
        ColorMatrix.brightness(0.05),
      ])),
  StylePreset('cap', 'Cap-Haïtien', 'Chaud', () => ColorMatrix.chain([
        ColorMatrix.warmth(0.35),
        ColorMatrix.contrast(0.12),
        ColorMatrix.sepia(0.15),
      ])),
  StylePreset('coucher', 'Coucher', 'Chaud', () => ColorMatrix.chain([
        ColorMatrix.warmth(0.6),
        ColorMatrix.tint(0.2),
        ColorMatrix.saturation(0.15),
      ])),
  StylePreset('jacmel', 'Jacmel', 'Vif', () => ColorMatrix.chain([
        ColorMatrix.saturation(0.45),
        ColorMatrix.warmth(0.2),
        ColorMatrix.contrast(0.1),
      ])),
  StylePreset('labadee', 'Labadee', 'Vif', () => ColorMatrix.chain([
        ColorMatrix.hue(-10),
        ColorMatrix.tint(-0.15),
        ColorMatrix.warmth(-0.3),
        ColorMatrix.saturation(0.2),
      ])),
  StylePreset('tropique', 'Tropique', 'Vif', () => ColorMatrix.chain([
        ColorMatrix.saturation(0.3),
        ColorMatrix.hue(-15),
        ColorMatrix.warmth(0.1),
      ])),
  StylePreset('neon', 'Néon', 'Vif', () => ColorMatrix.chain([
        ColorMatrix.saturation(0.6),
        ColorMatrix.contrast(0.25),
        ColorMatrix.hue(15),
      ])),
  StylePreset('film', 'Film', 'Rétro', () => ColorMatrix.chain([
        ColorMatrix.contrast(0.2),
        ColorMatrix.fade(0.15),
        ColorMatrix.saturation(-0.12),
        ColorMatrix.tint(-0.05),
      ])),
  StylePreset('vintage', 'Vintage', 'Rétro', () => ColorMatrix.chain([
        ColorMatrix.sepia(0.3),
        ColorMatrix.fade(0.35),
        ColorMatrix.contrast(-0.1),
        ColorMatrix.warmth(0.15),
      ])),
  StylePreset('retro70', 'Rétro 70', 'Rétro', () => ColorMatrix.chain([
        ColorMatrix.warmth(0.4),
        ColorMatrix.tint(0.15),
        ColorMatrix.fade(0.2),
        ColorMatrix.saturation(-0.1),
      ])),
  StylePreset('cafe', 'Café', 'Rétro', () => ColorMatrix.chain([
        ColorMatrix.sepia(0.5),
        ColorMatrix.contrast(0.15),
        ColorMatrix.brightness(-0.05),
      ])),
  StylePreset('sepia', 'Sépia', 'Rétro', () => ColorMatrix.sepia(0.85)),
  StylePreset('nuit', 'Nuit', 'Ciné', () => ColorMatrix.chain([
        ColorMatrix.exposure(-0.25),
        ColorMatrix.warmth(-0.35),
        ColorMatrix.contrast(0.2),
        ColorMatrix.saturation(-0.2),
      ])),
  StylePreset('noir', 'Noir', 'N&B', () => ColorMatrix.chain([
        ColorMatrix.grayscale(1),
        ColorMatrix.contrast(0.35),
      ])),
  StylePreset('graphite', 'Graphite', 'N&B', () => ColorMatrix.chain([
        ColorMatrix.grayscale(1),
        ColorMatrix.fade(0.3),
        ColorMatrix.contrast(-0.05),
      ])),
  StylePreset('argent', 'Argent', 'N&B', () => ColorMatrix.chain([
        ColorMatrix.grayscale(1),
        ColorMatrix.warmth(-0.15),
        ColorMatrix.brightness(0.03),
      ])),
];

StylePreset styleById(String id) => kStyles.firstWhere((s) => s.id == id, orElse: () => kStyles.first);
