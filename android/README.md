# Vixel Studio

A native Android video + photo editor. Kotlin, Jetpack Compose, OpenGL ES 3.0,
MediaCodec. No server, no account, no network calls — everything runs on device.

## Getting the APK

The APK is built by GitHub Actions on every push, because the Android SDK is
not reachable from the development container (see *Why CI builds the APK*).

The easy way, and the one that works from a phone:

**[Download `vixel-studio-debug.apk`](../../releases/download/debug-latest/vixel-studio-debug.apk)**

Open it on the device and tap through; Android asks once to allow installs
from whichever browser or file manager you used. The link needs no GitHub
login, never expires, and CI replaces the file on every build.

Otherwise, from CI directly:

1. Open the [Actions tab](../../actions/workflows/android.yml)
2. Click the most recent green run
3. Download the **vixel-studio-apk-debug** artifact
4. Unzip it and install `app-debug.apk`

That route needs a signed-in GitHub session and the artifact expires after
thirty days, which is why the release link exists.

The debug APK is signed with the standard Android debug key, so it installs
alongside a Play-signed copy rather than over one. `app-release.apk` is the
unminified release build, signed with the same key unless you supply your own
(below).

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

### The editor layout

```
┌──────────────────────────────┐
│ ✕            1080P  [Export] │  never scrolls away
├──────────────────────────────┤
│                              │
│           preview            │  takes whatever is left
│                              │
├──────────────────────────────┤
│  ⛶         ▶         ↶  ↷   │
├──────────────────────────────┤
│ 00:12.4 / 02:43              │
│ ═══ filmstrip ═══╎══════ +   │  playhead fixed at ╎
│      + Add audio             │
│      + Add text              │
├──────────────────────────────┤
│ ✂  ♪  T  ⊂⊃  ☺  ✦  ◐  ⚙ →  │  scrolls; opens a sheet
└──────────────────────────────┘
```

Two rules shape this:

**The preview is never covered.** Opening a tool replaces the timeline and the
rail, not the canvas. Colour, text and transforms are all judged by eye, and a
panel that hides the frame forces apply-dismiss-look-reopen for every nudge.

**The playhead does not move.** The filmstrip scrolls underneath a playhead
pinned to the centre. Dragging a playhead along a static strip fails on a
phone in two ways: the finger covers the frame it is aiming at, and the end of
the timeline sits under the screen edge where it cannot be grabbed. Pinning it
puts the current frame in the middle of the screen, directly below the preview
showing that same frame, and makes "split" land where you are already looking.

Tools are a scrolling rail rather than tabs. Tabs divide the width between
them, so past about five every one is too narrow to label or hit. `RailItem`
in `ui/common/` is shared with the photo editor.

Scroll and playback each own the playhead at different times, and telling them
apart is subtler than it looks: a programmatic scroll sets
`isScrollInProgress` exactly like a finger does, so the strip would follow
playback, read its own scroll back as a scrub, and seek the player against
itself. `Filmstrip` keys on a `DragInteraction` instead, which only a real
touch emits.

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

Two things have to hold, and for a long time only the first one did.

**The value has one home.** `Adjustments` is the single source of truth. The UI
builds its sliders from `AdjustSpec.ALL`, and `ColorGrader.bindAdjustments`
binds uniforms from the same ids. There is no second path a value can be read
from. Adding a parameter means adding one `AdjustSpec` entry and one uniform —
the slider appears on its own.

**The edit reaches a clip.** That is the part that was broken. Edits were
addressed to `selectedClipId` and silently dropped when nothing was selected,
while the preview separately fell back to the first clip. A slider moved, a
frame sat there unchanged, and nothing explained why — which reads as an app
where none of the parameters are wired up.

`VideoEditorState.activeClip` closes it: edits go to the selected clip, or to
whatever sits under the playhead when there is no selection, and the preview
grades with the clip it is actually showing. Selecting a clip brings the
playhead into it, because editing a clip you cannot see is the same bug in a
different costume.

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

**Auto captions** — transcribes speech and lays it out as timed captions,
entirely on device. Since API 31 the platform recogniser accepts a file
descriptor instead of the microphone, so audio already on disk can be fed
straight in; no model ships in the APK and no request leaves the phone. Audio
is segmented by voice activity first, so each caption spans a real utterance
rather than an arbitrary window. Captions land as ordinary text overlays, so
they can be edited and restyled like anything else.

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
- **Captions need Android 12 and an installed language pack.** Feeding audio
  from a descriptor arrived in API 31, and on-device recognition only works
  once a language has been downloaded in system settings; the app says which
  of the two is missing rather than producing empty captions. Recognition is
  deliberately on-device only — the networked recogniser is often better, but
  it would send the user's audio to a server, which an offline editor should
  not start doing quietly.
- **Caption timing is per utterance, not per word.** The platform exposes word
  timings on API 34+; using them would allow karaoke-style captions.

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
