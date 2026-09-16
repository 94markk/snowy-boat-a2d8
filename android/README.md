# Vixel Studio

A native Android video + photo editor. Kotlin, Jetpack Compose, OpenGL ES 3.0,
MediaCodec. No server, no account, no network calls — everything runs on device.

## Getting the APK

The APK is built by GitHub Actions on every push, because the Android SDK is
not reachable from the development container (see *Why CI builds the APK*).

1. Open the [Actions tab](../../actions/workflows/android.yml)
2. Click the most recent green run
3. Download the **vixel-studio-apk** artifact
4. Unzip it and install `app-debug.apk` on your phone

`app-debug.apk` is signed with the standard Android debug key, so it installs
without extra steps. `app-release.apk` is the unminified release build, signed
with the same key unless you supply your own (below).

### Building locally

Any machine with the Android SDK:

```bash
cd android
./gradlew assembleDebug        # app/build/outputs/apk/debug/app-debug.apk
./gradlew installDebug         # straight onto a connected device
```

Requires JDK 17 and SDK platform 35. Android Studio will fetch both.

### Signing a release build

Set these before `./gradlew assembleRelease`; if `VIXEL_KEYSTORE_PATH` is
unset the build falls back to the debug key so the APK is still installable.

| Variable | Meaning |
| --- | --- |
| `VIXEL_KEYSTORE_PATH` | path to your `.jks` / `.keystore` |
| `VIXEL_KEYSTORE_PASSWORD` | keystore password |
| `VIXEL_KEY_ALIAS` | key alias |
| `VIXEL_KEY_PASSWORD` | key password |

## Orientation

The app is **portrait by default and stays that way**. This is enforced in two
places, because one is not enough:

- `AndroidManifest.xml` pins `android:screenOrientation="portrait"`
- `MainActivity.applyOrientation()` re-asserts it in `onResume`, since
  launchers and split-screen transitions can hand an activity back with a
  sensor-derived orientation

Rotation is opt-in from **Settings → Allow rotation**, off by default. Nothing
reads the accelerometer to decide the launch orientation.

Photos are also rotated upright on import from their EXIF orientation tag
(`ImageIo.decode`). A phone camera writes orientation into metadata rather
than into the pixels, so a photo decoded naively appears on its side — which
looks exactly like an editor "stuck in landscape".

## Architecture

```
core/model/      Adjustments, Filters, Project/Clip/AudioClip, AspectRatio
core/io/         image decode, EXIF, MediaStore output
core/store/      project JSON and on-disk project store
engine/gl/       EGL, shaders, ColorGrader render graph, LUT + curve baking
engine/photo/    still rendering, background removal, collage
engine/video/    probe, clip sources, preview renderer, exporter, remuxer
engine/overlay/  text and sticker rasterising, GL overlay compositing
engine/audio/    PCM decode/resample, mixer, AAC encoder, WAV + M4A export
ui/              Compose screens
```

### Timeline-driven compositing

The exporter asks the timeline what is visible at each output frame, then pulls
that frame from each participating clip:

```
for each output frame t:
    composition = project.compositionAt(t)      # one clip, or two mid-transition
    grade each visible clip into its own canvas-sized target
    blend the two targets when a transition is running
    draw overlays for t
    present to the encoder surface
```

Pulling frames rather than pushing them is what makes a transition possible at
all — two clips have to be on screen at the same instant — and it pins the
output to exactly the chosen frame rate instead of inheriting whatever cadence
the sources happened to have. Decoders are opened and closed as clips enter and
leave the window, because devices cap how many codec instances can exist.

### One colour pipeline, two consumers

`ColorGrader` is the only thing that applies a look, and both the live preview
and the exporter call it with the same `Adjustments`. The preview samples a
bitmap or an external (video) texture; the exporter draws onto the encoder's
input surface. The exported file matches the preview by construction, rather
than by two implementations agreeing.

The render graph is three passes:

```
source (2D or external OES) ──[copy]──▶ sharp
sharp ──[blur H]──[blur V]──▶ soft        (only when a parameter reads it)
sharp + soft ──[colour stack]──▶ target
```

### Why every control does something

`Adjustments` is the single source of truth. The UI builds its sliders from
`AdjustSpec.ALL`, and `ColorGrader.bindAdjustments` binds uniforms from the
same ids. There is no second path a value can be read from, so a control that
moves always moves a pixel. Adding a parameter means adding one `AdjustSpec`
entry and one uniform — the slider appears on its own.

Order of operations follows what a photographer expects: white balance,
exposure, tonal ranges, contrast, curves, HSL, vibrance/saturation, look,
then texture and atmosphere last.

## What's in it

**Video** — multi-clip timeline, trim, split at playhead, duplicate, delete,
reorder, speed (0.25×–4×), volume, mute, fade in/out, per-clip colour grade and
look, canvas aspect presets, fit/fill/stretch, MP4 export at 720p–2160p and
24/30/60 fps with progress.

**Transitions** — dissolve, fade to black or white, slide and wipe in four
directions, zoom in/out, and defocus blur, with adjustable length. A transition
overlaps the two clips it joins, so adding one shortens the timeline.

**Text** — multi-line with wrapping and alignment, five font families, bold and
italic, size, colour, outline, drop shadow and a rounded plate behind. Position,
rotation, opacity, start/end timing, snap to playhead, and eight entry/exit
animations.

**Stickers** — 24 emoji from the system font plus eight path-drawn shapes with
colour, sharing the placement, timing and animation controls with text. Neither
costs anything in APK size.

**Projects** — the timeline is saved to disk as JSON and listed on the home
screen. Autosave is debounced, so a slider drag writes once when it settles
rather than on every frame of the gesture. Saves are written to a temporary
file and swapped in, so an interrupted write cannot leave a project that fails
to open.

**Audio tracks** — add music, or record a voiceover straight into the timeline
at the playhead. Per-track volume, position, trim and fades, mixed into the
export alongside clip audio.

**Keyframes** — animate scale, position, rotation and opacity over time on
clips, text and stickers, with five easings. Keys are placed at the playhead
and timed from the owner's own start, so trimming or moving a clip carries its
animation along. Ken Burns presets (zoom in/out, pan) set a whole move in one
tap.

**Masks** — rectangle, ellipse, linear and radial, with position, size,
rotation, feather and invert. A masked clip reveals the canvas behind it.

**Blend modes** — normal, multiply, screen, add, darken and lighten on text
and stickers.

**Motion tracking** — place a sticker or caption on your subject, tap track,
and it follows. Matching is normalised cross-correlation on sampled luma, which
is invariant to brightness, so a subject moving through shade stays locked
where a plain difference metric would lose it. The result is written as
ordinary position keyframes, so it can be nudged afterwards like anything else.

**Photo** — text and stickers with the same controls as video, 18 live
parameters (exposure, brightness, contrast, highlights,
shadows, whites, blacks, saturation, vibrance, temperature, tint, hue, sharpen,
blur, grain, fade, vignette, glow), 8-band HSL, per-channel + master tone
curves, 24 looks with strength, rotate/flip, gesture-grouped undo/redo,
per-parameter reset, hold-to-compare, JPEG/PNG/WebP export.

**Background remover** — chroma key for green screen, plus an automatic
subject cutout. Saves a transparent PNG.

**Collage** — 11 layouts for 1–6 photos, aspect presets, spacing, corner
radius, white/black background. Photos are centre-cropped, never squashed.

**Converter** — video to M4A (stream copy, no re-encode, bit-exact) or WAV
(decoded, always works); image conversion between JPEG, PNG and WebP.

### Looks are code, not assets

The 24 filters in `Filters.kt` are plain RGB→RGB functions, baked into 64³
LUTs at runtime by `LutGenerator`. No `.cube` files ship in the APK, so a look
is readable and editable in a few lines and costs nothing in download size.

## Known limits

Worth being straight about:

- **Automatic background removal is a matting heuristic, not segmentation.**
  It learns a background colour model from the image border and a subject
  model from the centre, then scores each pixel by which it is closer to. That
  is strong on a clear subject against a reasonably plain background and weak
  on a busy one. Green screen footage should use the chroma key path, which is
  exact. A proper on-device segmentation model would need ML Kit.
- **Transitions are approximated in the preview.** ExoPlayer decodes one clip
  at a time, so the preview ramps the incoming clip up instead of showing the
  real two-source blend. The exported file has the true transition. Making the
  preview exact means replacing ExoPlayer with the same pull-based decoder the
  exporter uses, along with its own clock and audio sync.
- **Speed changes pitch.** Audio is resampled, so a sped-up clip rises in
  pitch like tape. There is no pitch-preserving time stretch yet.
- **Export re-encodes every clip**, including ones that were not modified.
- **R8 is off**, so the release APK is larger than it needs to be. The GL and
  effect classes need a keep-rule pass before shrinking can be trusted.
- **Media is referenced, not copied.** A project stores the uri it was given
  rather than duplicating the file, so a project opens only while that media is
  still reachable. Clips whose media has gone are reported by name when the
  project loads instead of rendering as black frames. Copying every import
  would make projects self-contained at the cost of duplicating every video the
  user edits.
- **Blend modes stop at six.** Only the modes expressible with fixed-function
  GL blending are offered. Overlay and soft light need to read the destination
  pixel, which costs a full-canvas pass per layer.
- **Keyframes are edited at the playhead**, not on a curve editor. Scrub, set
  the value, tap the property. A graph editor would give finer control over
  timing than the five easings do.
- **Tracking follows one point, not a region's scale or rotation.** It samples
  at 10fps through MediaMetadataRetriever rather than running a second decoder,
  which keeps it off the export path but caps how fast a subject can move
  before the search window loses it. A low-contrast or heavily occluded subject
  will drift; the match score holds the last good position rather than snapping
  somewhere wrong.
- **Not built yet: auto captions.** The platform's speech recogniser listens to
  a live microphone and will not transcribe a file, so this needs either a
  bundled recognition model or a cloud service — a different kind of dependency
  from anything else here, and not a small addition.

## Why CI builds the APK

The development container this was written in cannot reach `dl.google.com` —
the egress policy returns 403 for it. That host serves the Android SDK
platform, `aapt2`/`d8`/`apksigner`, the Android Gradle Plugin, and all of
AndroidX and Compose. `maven.google.com` only redirects there. So no APK can
be produced locally, and CI does it instead.

Maven Central *is* reachable, which allowed a partial local check: the whole
`core/` and `engine/` tree (everything that touches only framework APIs) is
typechecked against a real `android.jar` from `org.robolectric:android-all`
before each push. The Compose layer can only be compiled by CI.
