package com.delicat.studio.engine.gl

/**
 * The one shader the app draws everything with.
 *
 * Preview and export compile this same source. That is the whole point: the
 * graded frame on screen and the graded frame in the file come out of one
 * piece of code, so they cannot drift apart the way two implementations kept
 * in step by hand always eventually do.
 *
 * GLSL ES 1.00 rather than 3.00, because the external-image extension is
 * universally available there and every device the app runs on accepts it.
 * Nothing in this pipeline needs a feature 3.00 adds.
 */
object Shaders {

    const val VERTEX = """
uniform mat4 uMvp;
uniform mat4 uTexMatrix;

attribute vec4 aPosition;
attribute vec4 aTexCoord;

varying vec2 vTex;
varying vec2 vQuad;
varying vec2 vScreen;

void main() {
    vec4 clip = uMvp * aPosition;
    gl_Position = clip;
    vTex = (uTexMatrix * aTexCoord).xy;
    // Where this fragment sits inside the frame, for vignette.
    vQuad = aTexCoord.xy;
    // Where it sits on the canvas, for a wipe: the wipe edge belongs to the
    // screen, not to whichever layer happens to be crossing it.
    vScreen = clip.xy * 0.5 + 0.5;
}
"""

    /**
     * Two variants are compiled from this: video arrives as an external image
     * that only `samplerExternalOES` can read, and stills arrive as an
     * ordinary texture. The difference is one declaration, so it is a define
     * rather than a second copy of four hundred lines of colour maths.
     */
    private const val FRAGMENT_BODY = """
varying vec2 vTex;
varying vec2 vQuad;
varying vec2 vScreen;

uniform sampler2D uLut;
uniform vec2 uTexel;

uniform float uLutSize;
uniform float uLutTiles;
uniform float uLutRows;
uniform float uLutMix;

uniform float uBlur;
uniform float uSharpen;
uniform float uGlow;
uniform float uGrain;
uniform float uVignette;
uniform float uSeed;

uniform float uAlpha;
uniform vec4 uOverlay;
uniform vec4 uWipe;

const float TAU_SIXTH = 1.0471976;
const float TAU_TWELFTH = 0.5235988;

vec3 tap(vec2 uv) {
    return SAMPLE(uSource, clamp(uv, vec2(0.0), vec2(1.0))).rgb;
}

/**
 * The lookup table, sampled as a strip of tiles.
 *
 * Red and green index inside a tile and are interpolated by the hardware's
 * own bilinear filter; blue picks the tile and is interpolated here between
 * two of them. The half-texel inset keeps every tap inside its own tile, so
 * the filter never bleeds a neighbouring slice of blue into the result.
 */
vec3 graded(vec3 colour) {
    vec3 c = clamp(colour, 0.0, 1.0);
    float n = uLutSize;
    float last = n - 1.0;

    float blue = c.b * last;
    float lo = floor(blue);
    float hi = min(lo + 1.0, last);
    float f = blue - lo;

    vec2 texel = vec2(1.0 / (n * uLutTiles), 1.0 / (n * uLutRows));
    vec2 inside = vec2(c.r, c.g) * last + 0.5;

    vec2 a = vec2(mod(lo, uLutTiles) * n, floor(lo / uLutTiles) * n) + inside;
    vec2 b = vec2(mod(hi, uLutTiles) * n, floor(hi / uLutTiles) * n) + inside;

    return mix(
        texture2D(uLut, a * texel).rgb,
        texture2D(uLut, b * texel).rgb,
        f
    );
}

float luma(vec3 c) {
    return dot(c, vec3(0.2126, 0.7152, 0.0722));
}

float hash(vec2 p) {
    return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453);
}

/**
 * Thirteen taps on two rings.
 *
 * Rings rather than a separable gaussian because this is one pass: a
 * separable blur needs an intermediate buffer and two draws, and the extra
 * fidelity is not visible at the radii an editor's blur slider reaches. The
 * inner ring is offset half a segment from the outer one so the samples do
 * not line up into spokes.
 */
vec3 soften(vec2 uv, float radius) {
    vec3 sum = tap(uv) * 0.28;
    vec2 inner = uTexel * radius * 0.55;
    vec2 outer = uTexel * radius;

    for (int i = 0; i < 6; i++) {
        float a = float(i) * TAU_SIXTH;
        vec2 d1 = vec2(cos(a), sin(a));
        vec2 d2 = vec2(cos(a + TAU_TWELFTH), sin(a + TAU_TWELFTH));
        sum += tap(uv + d1 * inner) * 0.06;
        sum += tap(uv + d2 * outer) * 0.06;
    }
    return sum;
}

void main() {
    // A wipe is decided before any colour work: a fragment the transition has
    // not reached yet costs nothing to skip.
    float reveal = 1.0;
    if (uWipe.w >= 0.0) {
        float along = dot(vScreen, uWipe.xy);
        reveal = smoothstep(uWipe.z - uWipe.w, uWipe.z + uWipe.w, along);
    }
    float alpha = uAlpha * reveal;
    if (alpha <= 0.002) discard;

    vec3 raw = tap(vTex);

    // One neighbourhood read serves blur, sharpen and glow; they all want the
    // same low-pass and taking it three times would triple the cost of the
    // most expensive thing in the shader.
    vec3 low = raw;
    float radius = max(uBlur * 14.0, max(uGlow * 6.0, uSharpen * 1.6));
    if (radius > 0.01) {
        low = soften(vTex, radius);
    }

    vec3 base = mix(raw, low, clamp(uBlur, 0.0, 1.0));
    vec3 colour = mix(base, graded(base), uLutMix);

    if (uSharpen > 0.0) {
        // Unsharp masking: what the low-pass threw away, added back. Bounded,
        // because when blur is also up the low-pass is a wide one and the
        // difference against it is an edge halo rather than detail.
        colour += clamp(raw - low, -0.45, 0.45) * uSharpen * 1.6;
    }

    if (uGlow > 0.0) {
        vec3 bloom = max(low - 0.62, vec3(0.0)) * (uGlow * 2.4);
        // Screen rather than add, so a highlight that is already white stays
        // white instead of clipping into a flat patch.
        colour = 1.0 - (1.0 - clamp(colour, 0.0, 1.0)) * (1.0 - clamp(bloom, 0.0, 1.0));
    }

    if (uGrain > 0.0) {
        float n = hash(vTex * vec2(1024.0, 1024.0) + uSeed) - 0.5;
        // Strongest through the midtones, the way film is: grain in a
        // blown-out sky or a black shadow reads as sensor noise, not as film.
        float weight = 1.0 - abs(luma(colour) - 0.5) * 1.4;
        colour += n * uGrain * 0.22 * max(weight, 0.15);
    }

    if (uVignette != 0.0) {
        float d = length(vQuad - 0.5) * 1.4142136;
        colour *= 1.0 - uVignette * smoothstep(0.35, 1.05, d) * 0.9;
    }

    colour = mix(colour, uOverlay.rgb, clamp(uOverlay.a, 0.0, 1.0));
    gl_FragColor = vec4(clamp(colour, 0.0, 1.0), alpha);
}
"""

    /**
     * High precision where the device has it, and medium where it does not.
     *
     * GLSL ES 1.00 does not promise highp in a fragment shader; a device
     * without it rejects the declaration outright rather than quietly
     * downgrading. Asking for it unconditionally is therefore a shader that
     * will not compile, on exactly the hardware least able to spare the
     * precision — and a shader that will not compile is a preview that never
     * draws anything at all.
     */
    private const val PRECISION = """
#ifdef GL_FRAGMENT_PRECISION_HIGH
precision highp float;
#else
precision mediump float;
#endif
"""

    /** Reads an ExoPlayer frame, which only this sampler type can touch. */
    val FRAGMENT_EXTERNAL: String = """
#extension GL_OES_EGL_image_external : require
""" + PRECISION + """
#define SAMPLE texture2D
uniform samplerExternalOES uSource;
""" + FRAGMENT_BODY

    /** Reads a still: a photo, or a cached frame standing in for a clip. */
    val FRAGMENT_FLAT: String = PRECISION + """
#define SAMPLE texture2D
uniform sampler2D uSource;
""" + FRAGMENT_BODY
}
