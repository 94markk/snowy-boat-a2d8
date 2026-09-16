# Delicat Studio

A video and photo editor for Android. Timeline editing, colour grading and
export, all on the device — nothing is uploaded anywhere.

## Get the app

**[Download `delicat-studio.apk`](../../releases/download/latest/delicat-studio.apk)**

Open that link in Chrome on the phone and tap the file in Downloads. Android
will ask once to allow installs from your browser. The link needs no GitHub
login and always points at the newest build.

It is signed with the standard debug key, so it installs alongside anything
from the Play Store rather than over it.

## What it does

- **Import** photos and video through the system picker, which needs no
  storage permission.
- **Arrange** clips on a timeline with a fixed centre playhead: split at the
  playhead, duplicate, delete, reorder, and drag either end of a clip to trim
  it. A photo has no footage to trim, so dragging its end sets how long it
  lasts.
- **Grade** with eighteen parameters — exposure through to grain, vignette and
  glow — and twenty-four looks, each with its own strength.
- **Retime** from a quarter speed to four times, with the timeline and the
  sound following.
- **Join** clips with twelve transitions: dissolves, washes through black or
  white, slides, wipes and zooms, each with an adjustable length.
- **Shape** the canvas to any of seven aspect ratios, with each clip fitted
  inside it or filling it.
- **Export** to an MP4 in the gallery, with the sound mixed and crossfaded
  across every transition.

## How it is put together

**One shader draws every frame.** The preview compiles it against the surface
the player writes into; the exporter compiles the same source against the
encoder's input surface. The graded colour on screen and the graded colour in
the file therefore come out of one piece of code, rather than from two kept in
agreement by hand.

**Colour is baked into a lookup table.** Eighteen parameters and a look
collapse into one 32-a-side cube, so moving a slider costs a single texture
read per pixel instead of the whole chain. The table is regenerated only when
something that actually affects it changes — the grain slider does not force a
re-bake.

**Transitions have no shader and no intermediate buffer.** Every one is two
draws with different opacity, offset, wash and wipe edge. Adding one means
adding parameters rather than a render pass, which is why the preview and the
exporter can share them without sharing a render target.

**The timeline is the authority on time.** The player is asked only where it is
inside the one clip it holds, and that is mapped onto the timeline — never the
other way round. Splitting or reordering a clip mid-playback therefore behaves,
instead of fighting whatever the player thinks the running order is.

| Path | What it is |
| --- | --- |
| `app/src/main/java/com/delicat/studio/model/` | Clips, projects, adjustments, looks |
| `app/src/main/java/com/delicat/studio/engine/gl/` | The shader, the colour table, geometry, transitions |
| `app/src/main/java/com/delicat/studio/engine/preview/` | Playback and the preview renderer |
| `app/src/main/java/com/delicat/studio/engine/export/` | Decoding, encoding, muxing, sound |
| `app/src/main/java/com/delicat/studio/ui/` | Compose: the editor, timeline and tool panels |
| `.github/workflows/build.yml` | Builds, tests and publishes the APK |

## Building

```
gradle assembleDebug
```

Needs JDK 17 and the Android SDK with platform 35. AGP 8.7.3 wants Gradle
between 8.9 and 8.11; CI pins 8.11.1.

CI builds the APK because the development container cannot reach
`dl.google.com`, which serves the entire Android toolchain.

## Known limits

- A transition's outgoing clip is a frozen frame in the preview. One player
  cannot decode two clips at once, and a second costs a decoder and its memory
  for half a second of preview. The exported file runs both sides live.
- Projects are not saved between launches yet, and the system picker's access
  to a file ends when the app does.
- Text, stickers and keyframes are not built.
