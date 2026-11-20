# Cutline — browser video editor

Cutline is a CapCut-style video editor that runs entirely in the browser. It has a multi-track timeline, text, stickers, transitions, filters, effects, keyframes and export. It has **no AI features**. Media never leaves the device: projects and imported files are stored in the browser's IndexedDB.

The site is built with Astro and deploys to Cloudflare Workers as static assets. The editor is at `/`. The original starter-template blog pages are still at `/blog` and `/about`. You can delete them if you don't need them.

## Features

**Media and library**
- Import video, images and audio with the file picker or by dropping files anywhere on the window.
- The media bin shows thumbnails, durations, a hover-scrub preview, filter chips and a badge on media that's already in the timeline.
- Built-in music and sound effects are synthesised in the browser, so they're royalty-free.
- Record a voiceover from the microphone. It's placed at the playhead and the project plays while you record.

**Timeline**
- Multiple tracks: a main video track, video overlays, text and sticker tracks, effect tracks and audio tracks. Tracks are created and removed automatically.
- Magnetic main track (clips stay back-to-back, ripple delete), snapping, markers and zoom (slider, Ctrl+scroll, fit).
- Drag clips to move them, including between tracks and onto a new track. Drag a clip's edges to trim it.
- Split, delete left or right of the playhead (Q/W), duplicate, copy and paste, multi-select, and a right-click menu.
- Filmstrip thumbnails, audio waveforms, keyframe markers and badges for speed, mute and filter.
- Hide, mute or lock any track.
- Undo and redo (up to 200 steps).

**Player**
- Real-time canvas preview with frame stepping, loop, full screen and save-frame-as-PNG.
- Move, scale and rotate the selected layer directly on the canvas, with centre snapping guides.
- Canvas ratios 16:9, 9:16, 1:1, 4:3, 3:4, 4:5 and 21:9. The background can be a solid colour or a blurred copy of the clip.

**Clip editing (inspector)**
- Transform: position, scale, rotation, opacity, flip, rotate 90°, fit and fill.
- **Keyframes** for position, scale, rotation and opacity, with eased interpolation.
- 16 blend modes.
- Crop; masks (linear, mirror, circle, rectangle, heart, star) with feather, offset, rotation and invert; **chroma key** (green/blue screen) with a colour picker, strength, edge softness and spill suppression.
- 13 in and 11 out animations, including typewriter for text.
- Speed from 0.1× to 10× with pitch preserved, plus freeze frame.
- Volume, fade in/out, mute, and extract audio to its own track.
- Adjust: brightness, contrast, saturation, hue, temperature, tint, vignette and blur.
- 18 filter presets with an intensity control and "apply to all".

**Text and stickers**
- 14 fonts, size, colour, bold/italic/underline, alignment, letter spacing, line height, stroke, shadow and a label background.
- 12 text style presets and about 100 emoji stickers.

**Transitions and effects**
- 17 transitions: dissolve, dip to black, flash, wipes, slides, push, zoom, iris, clock, doors, blur and spin. Each has adjustable duration and "apply to all cuts".
- 16 effects on effect tracks: shake, zoom pulse, dream blur, mirror, RGB split, glitch, VHS, vignette, film grain, old film, strobe, cinema bars, light leak, snowfall, black & white and pixelate.

**Export and projects**
- Export to MP4 (H.264/AAC) where the browser supports it, or WebM (VP9 or VP8 with Opus), or audio only. Choose 480p to 4K, 24 to 60 fps and a quality level.
- Projects autosave. A project manager lets you create, open and delete projects.

**Not included:** AI features such as auto captions, text-to-speech, background removal, AI effects and smart cut.

## Keyboard shortcuts

| Key | Action |
| --- | --- |
| Space | Play / pause |
| ← / → (Shift: 1 s) | Previous / next frame |
| Ctrl+B or S | Split |
| Q / W | Delete left / right of playhead |
| Delete | Delete selection |
| Ctrl+D / Ctrl+C / Ctrl+V | Duplicate / copy / paste |
| Ctrl+Z / Ctrl+Shift+Z | Undo / redo |
| M / N / L | Marker / snapping / loop |
| + / − / Shift+Z | Zoom timeline / fit |
| ? | Show all shortcuts |

## Project structure

```text
src/
├── pages/index.astro          # App shell: head, layout and script entry
├── components/editor/         # Markup for each region of the UI
│   ├── TopBar.astro           #   logo, project name, undo/redo, export
│   ├── Library.astro          #   media, audio, text, stickers, effects, transitions, filters
│   ├── Player.astro           #   preview canvas, selection handles, transport
│   ├── Inspector.astro        #   properties panel container
│   ├── Timeline.astro         #   toolbar, ruler, tracks, playhead
│   ├── Dialogs.astro          #   export, projects, shortcuts, toasts
│   └── Icon.astro             #   inline SVG icon component
├── styles/editor.css          # Dark editor theme and responsive layout
└── editor/                    # Client-side engine (vanilla TypeScript, no dependencies)
    ├── types.ts / model.ts    #   data model and pure timeline logic (overlaps, magnet, keyframes)
    ├── store.ts               #   state, selection, playhead, undo/redo
    ├── engine.ts              #   playback clock, media elements, Web Audio graph, export
    ├── renderer.ts            #   canvas compositor: transforms, filters, masks, chroma key, transitions, effects
    ├── actions.ts / commands.ts  # editing operations and named commands
    ├── timeline.ts / preview.ts / inspector.ts / library.ts  # UI controllers
    ├── media.ts / sounds.ts   #   import, thumbnails, waveforms, synthesised sound library
    ├── exporter.ts / recorder.ts / projects.ts / db.ts       # export, voiceover, persistence
    └── main.ts                #   bootstrap
```

## Commands

| Command | Action |
| :-- | :-- |
| `npm install` | Install dependencies |
| `npm run dev` | Start the dev server at `localhost:4321` |
| `npm run build` | Build the site into `./dist/` |
| `npm run preview` | Build, then preview with Wrangler |
| `npm run check` | Build, type-check (site and editor), and dry-run the deploy |
| `npm run deploy` | Deploy to Cloudflare Workers |

## Browser notes and limits

- Use a current Chromium-based browser (Chrome or Edge) for the best results. Firefox works, but it has fewer export formats. Safari support isn't tested.
- Export runs in real time: a one-minute video takes about a minute to render. Keep the tab in front while exporting, because background tabs are throttled.
- WebM files written by MediaRecorder don't store a total duration in their header. They play normally, but some players can't seek until the file has loaded.
- Large projects are limited by the browser's storage quota.
- Reverse playback isn't supported.
