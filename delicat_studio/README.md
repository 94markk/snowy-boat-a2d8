# Delicat Studio

Delicat Studio is an Android photo studio built with Flutter. It removes backgrounds on the phone, makes collages, and applies styles. The interface is in French and follows the Delicat « Kat » design system.

## Features

**Background removal**
- Detects the subject on the phone with Google ML Kit (people, products, pets, objects). If the Google Play services module isn't ready, it falls back to ML Kit's bundled people detection.
- A refine brush (erase / restore) with size and hardness. One finger paints; two fingers zoom and pan.
- « Gomme couleur »: a magic eraser that removes a colour, either the touched area only (flood fill) or that colour everywhere.
- Edge controls: threshold, transition and feathering.
- Backgrounds: transparent, colour, blur (portrait mode), black and white (selective colour), or another photo.
- Sticker outline in any colour, plus an adjustable drop shadow.
- Hold to compare with the original, undo / redo, and export cropped tight around the subject.
- Shortcuts from Home: Portrait flou, Sticker, Couleur sélective, Gomme couleur.

**Collage**
- 2 to 9 photos, with up to 18 generated layouts per photo count (grids plus layouts with one large photo).
- Per photo: pinch to zoom, drag to reframe, swap, replace, mirror, recentre, remove, and its own style.
- Formats 1:1, 4:5, 3:4, 9:16 and 16:9; adjustable spacing, corner radius and background colour.
- Exports at 2160 px on the long side.

**Retouche (editor)**
- 24 styles (Délicat, Jacmel, Labadee, Cap-Haïtien, Kenscoff, Noir…), each with an intensity slider.
- Adjustments: brightness, contrast, saturation, exposure, warmth, tint, fade, vignette and grain.
- Crop with aspect presets, rotate, mirror and straighten (±45° with automatic zoom-to-fill).
- Text layers in 6 fonts with plain, label and outline looks; emoji stickers; move, pinch-resize and rotate any layer.
- Drawing with a brush and an eraser.
- Frames: thin, thick and Polaroïd.
- Undo / redo and hold-to-compare.

**Library and settings**
- Créations: every export is kept in the app, filterable by type. Open one to view, share, save to the gallery, re-edit or delete it.
- Exports are PNG (keeps transparency) or JPG with a quality setting, and are optionally added to the gallery album « Delicat Studio ». A « kat » receipt confirms each save.
- Settings: theme (system / light / dark), low-power mode (turns off all motion), import resolution (1440 / 2048 / 3072 px) and export defaults.

Photos never leave the phone: detection, editing and encoding all run on the device.

## Design

The app uses the Delicat « Kat » design system:
- Indigo `#4B3FE0` is used only for the single primary action on each screen. Fuchsia is used only for badges.
- The obsidian « kat » voucher (24 px radius, dashed tear line, two notches) appears on Home and on the export receipt.
- There are no gradients or drop shadows in the interface.
- Fonts are Bricolage Grotesque (condensed for titles and numbers) and JetBrains Mono (file names and sizes).
- Font sizes come from the scale 12 / 14 / 16 / 20 / 24 / 36 / 48. Spacing is in multiples of 4 and every tap target is at least 44 px.
- Pressed elements scale to .96 over 320 ms on the curve `Cubic(.16, 1, .3, 1)`. Motion is disabled under the system's reduced-motion setting and in low-power mode.
- Light and dark themes are tokenised in `lib/theme/tokens.dart`.

## Project layout

```text
lib/
├── main.dart                     app, themes, settings bootstrap
├── theme/                        Kat tokens (ThemeExtension) and ThemeData
├── core/                         settings, image I/O, colour matrices, styles, history, creations store
├── widgets/                      kat voucher, buttons, press feedback, sliders, chips, tool rails
├── screens/                      shell (NavigationBar), home, créations, viewer, réglages
└── features/
    ├── cutout/                   segmentation, mask maths, controller, compositor, screen
    ├── collage/                  layout generator and screen
    ├── editor/                   document model, renderer, text sheet, screen
    └── export/                   export sheet and kat receipt
test/                             unit tests (matrices, layouts, mask/flood fill) and widget tests
screenshots/                      renders store-style screenshots with the real fonts
```

## Build

You need Flutter 3.50 or later, Java 17 or later, and the Android SDK (platform 36).

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release --split-per-abi   # build/app/outputs/flutter-apk/
flutter test screenshots --update-goldens     # optional: screenshots in screenshots/goldens/
```

Release builds are signed with the debug key so they install directly. Before publishing on Google Play, create your own upload key and set `signingConfig` in `android/app/build.gradle.kts`.

## Notes

- The subject model is downloaded by Google Play services. `AndroidManifest.xml` requests it at install time. Phones without Play services use the bundled people model, or manual cut-out with the brush and the colour eraser.
- `WRITE_EXTERNAL_STORAGE` is requested only on Android 9 and older, for gallery saves. Newer versions need no storage permission.
- The `INTERNET` and network-state permissions come from Google Play services / ML Kit (model download). The app never uploads photos.
