# Vixel Studio

A native Android video and photo editor — timeline editing, a full colour
grading stack, background removal, collage and media conversion, all on device.

**The app lives in [`android/`](android/). Start with
[`android/README.md`](android/README.md)** for install instructions, the
architecture, and an honest list of what is and isn't built yet.

## Get the APK

Download the **vixel-studio-apk** artifact from the latest green run in the
[Actions tab](../../actions/workflows/android.yml), unzip, and install
`app-debug.apk`.

CI builds it because the development container is blocked from reaching
`dl.google.com`, which serves the entire Android toolchain.

## Repository layout

| Path | What it is |
| --- | --- |
| `android/` | The Android app: Kotlin, Compose, OpenGL ES, MediaCodec |
| `.github/workflows/android.yml` | Builds and publishes the APK |
| `src/`, `astro.config.mjs`, `public/` | Leftovers from the Astro starter this repo was created from. Nothing depends on them and they can be deleted. |
