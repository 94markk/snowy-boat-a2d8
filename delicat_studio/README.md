# Delicat Studio

Delicat Studio is an Android photo studio built with Flutter. It removes backgrounds on the phone, makes collages, and applies styles. The interface is in French and follows the Delicat « Kat » design system.

## Features

**What's new in 1.2.0**
- **Drafts with autosave.** Montages, retouches and collages save themselves a moment after each change and when the app goes to the background. They come back under « Reprendre » on Home; long-press in the drafts list to remove one. Media is kept inside each draft's folder, so clearing the cache never breaks a draft.
- **Promo templates.** Free Fire diamonds, eFootball coins, gift cards, streaming, promo du jour, nouveau, Wallet, holidays and Bon Kliyan. Each template is available in French or Kreyòl, as a 9:16, 4:5 or 1:1 poster or an 8-second video. You can add a product photo with its background removed. The shop types the price, and it is shown as « HTG 1 500,00 ». Leave it empty to hide it.
- **Ma marque.** Set the logo, two colours, title font, shop link and WhatsApp number once. Brand colours come first in every colour picker, and the logo can be added to each new montage automatically.
- **QR codes.** Add a QR layer in the photo and video editors, linking to the shop or to the shop's WhatsApp chat.
- **WhatsApp.** One tap sends a photo or video to WhatsApp or WhatsApp Business, ready for a status or a chat. If neither is installed, the system share sheet opens.
- **Voice-over.** Record from the playhead while the video plays silently, which avoids echo. Recordings sit on their own lane, where you can move them or change their volume.
- **Royalty-free music.** Six loops are generated on the phone: Kompa, Afro, Fête, Chill, Énergie and Suspense. No download is needed.
- **Picture overlays.** Place a product photo or another picture over the video for a chosen time.
- **Stability.**
  - A local crash journal, with « Signaler un problème » in Réglages.
  - Lighter defaults on phones with 3 GB of RAM or less.
  - If Android closes the app while the photo picker is open, the chosen photos are recovered.
  - Atomic library writes.
  - Preview players are released during export, so the hardware encoder is free.
  - Many fixes from a full code review: collage crop crash, export cancel lock, mirrored-and-rotated exports, overlay transparency, colour-erase memory, music loop sync and trimming very short clips.

**Video editing**
- Import videos and photos together. Photos become 3-second still clips that you can lengthen or shorten.
- A CapCut-style timeline: the playhead stays in the centre while the tracks scroll under it. Pinch to zoom, see filmstrip frames, and use separate lanes for text and music.
- Clip tools:
  - Split at the playhead, and trim with handles at each end of a clip.
  - Speed from 0.25× to 4× (pitch is kept).
  - Volume and mute.
  - Fade in and fade out, plus a slow zoom in or out.
  - Filters (the 24 styles) and colour adjustments.
  - Rotate, mirror, duplicate, move earlier or later, and delete.
- « Figer » inserts a 3-second freeze frame at the playhead. « Capture » sends the current frame to the photo editor.
- « Fondu » transitions: tap the marker between two clips for a dip to black.
- Timed texts and emoji stickers: drag, pinch and rotate them on the preview. Set where they start and end from the playhead, or drag them along the timeline.
- Music from your phone (MP3, M4A…) or the sound of another video. Set its volume, where it starts in the song, and loop or play once. You can mute every clip's original sound in one tap.
- Store logo watermark: choose the corner, size and opacity.
- Formats: original, 9:16, 1:1, 4:5, 3:4 or 16:9, either fitted with bars or filling the frame.
- Export to MP4 (H.264 + AAC) at 720p or 1080p, in standard or high quality. Export runs on the phone's hardware encoder with Google's Media3 Transformer and shows progress you can cancel.

**Recommended for you**
- The Home screen suggests tools based on what you have and haven't used yet: a tool you've never tried rises, and one you use a lot sinks.
- Related ideas get a boost. For example, if you cut out photos, it suggests stickers.
- Seasonal suggestions appear at the right time of year: a Polaroïd frame for the end-of-year holidays, and Fête du Drapeau videos before 18 May.
- Each suggestion opens the right tool already set up (story collage, the Jacmel style, the Polaroïd frame…).

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
- Créations: every export, photo or video, is kept in the app and can be filtered by type. Videos play inside the app. Open any creation to share it, save it to the gallery, re-edit it (photos) or delete it.
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
├── core/                         settings, image I/O, colour matrices, styles, history, creations, drafts,
│                                 brand kit, crash journal, device profile, generated music library
├── widgets/                      kat voucher, buttons, press feedback, sliders, chips, tool rails
├── screens/                      shell (NavigationBar), home, créations, viewer, réglages, ma marque,
│                                 brouillons, confidentialité
└── features/
    ├── cutout/                   segmentation, mask maths, controller, compositor, screen
    ├── collage/                  layout generator and screen
    ├── editor/                   document model, renderer, text and QR sheets, picture layers, screen
    ├── templates/                promo templates (copy, layout, poster and video builders) and form
    ├── video/                    video model, preview playback, timeline, editor, export
    └── export/                   export sheet and kat receipt
android/app/src/main/kotlin/…/VideoEngine.kt   probing, thumbnails, frame grabs, Media3 Transformer export
android/app/src/main/kotlin/…/SystemChannel.kt device profile, WhatsApp detection and direct sharing
test/                             unit tests (matrices, layouts, mask/flood fill) and widget tests
screenshots/                      renders store-style screenshots with the real fonts
```

## Build

You need Flutter 3.50 or later, Java 21 (the release build's Android lint step needs it), and the Android SDK (platform 36).

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release --split-per-abi   # build/app/outputs/flutter-apk/
flutter test screenshots --update-goldens     # optional: screenshots in screenshots/goldens/
```

Without `android/key.properties`, release builds are signed with the debug key so they install directly. For Google Play, run `tool/make_upload_key.sh` once to create your upload key outside the repository, then build with `flutter build appbundle --release`. The key and `key.properties` are git-ignored. The full release checklist, store listing and Data safety answers are in `docs/play-store.md`. The privacy policy is in `PRIVACY.md`, and the app shows it in Réglages.

CI (`.github/workflows/delicat-studio.yml`) runs analyze and tests, then builds the APKs and the App Bundle on every push that touches `delicat_studio/`.

## Notes

- The subject model is downloaded by Google Play services. `AndroidManifest.xml` requests it at install time. Phones without Play services use the bundled people model, or manual cut-out with the brush and the colour eraser.
- The video export uses Media3 1.9.2, the same version as the `video_player` plugin, so all Media3 modules stay aligned. Colour effects run on display-encoded values (`WORKING_COLOR_SPACE_ORIGINAL`), so exported videos match the preview.
- `WRITE_EXTERNAL_STORAGE` is requested only on Android 10 and older, for gallery saves. Newer versions need no storage permission. `RECORD_AUDIO` is used only while « Enregistrer ma voix » is active.
- Android backup and device transfer are turned off (`allowBackup="false"` and `data_extraction_rules.xml`), so drafts and the brand kit stay on the phone.
- Not included, on purpose:
  - Video picture-in-picture and reverse playback. Each would need extra video decoders at the same time, which budget phones don't have.
  - AI captions. These would need an online speech service or a large on-device model.
- The `INTERNET` and network-state permissions come from Google Play services / ML Kit (model download). The app never uploads photos.
