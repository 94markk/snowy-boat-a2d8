package com.vixel.studio.engine.gl

/**
 * GLSL ES 3.0 sources for the colour pipeline.
 *
 * The same fragment program serves preview and export; only the sampler type
 * differs (a bitmap is `sampler2D`, a decoded video frame arrives as
 * `samplerExternalOES`). Keeping one program is what guarantees the exported
 * file matches what was on screen.
 */
object Shaders {

    const val VERTEX = """#version 300 es
layout(location = 0) in vec4 aPosition;
layout(location = 1) in vec2 aTexCoord;
uniform mat4 uTexMatrix;
out vec2 vTex;
void main() {
    gl_Position = aPosition;
    vTex = (uTexMatrix * vec4(aTexCoord, 0.0, 1.0)).xy;
}
"""

    /** Separable Gaussian, run once horizontally and once vertically. */
    const val BLUR_FRAGMENT = """#version 300 es
precision highp float;
in vec2 vTex;
out vec4 fragColor;
uniform sampler2D uTexture;
uniform vec2 uDirection;   // texel-space step, already scaled by radius
void main() {
    // 9-tap Gaussian (sigma ~ radius/2), weights normalised to 1.
    const float w0 = 0.2270270270;
    const float w1 = 0.1945945946;
    const float w2 = 0.1216216216;
    const float w3 = 0.0540540541;
    const float w4 = 0.0162162162;
    vec4 c = texture(uTexture, vTex) * w0;
    c += texture(uTexture, vTex + uDirection * 1.0) * w1;
    c += texture(uTexture, vTex - uDirection * 1.0) * w1;
    c += texture(uTexture, vTex + uDirection * 2.0) * w2;
    c += texture(uTexture, vTex - uDirection * 2.0) * w2;
    c += texture(uTexture, vTex + uDirection * 3.0) * w3;
    c += texture(uTexture, vTex - uDirection * 3.0) * w3;
    c += texture(uTexture, vTex + uDirection * 4.0) * w4;
    c += texture(uTexture, vTex - uDirection * 4.0) * w4;
    fragColor = c;
}
"""

    /** Straight copy, used to pull an external (video) frame into a 2D target. */
    fun copyFragment(external: Boolean): String = buildString {
        append("#version 300 es\n")
        if (external) append("#extension GL_OES_EGL_image_external_essl3 : require\n")
        append("precision highp float;\n")
        append("in vec2 vTex;\n")
        append("out vec4 fragColor;\n")
        append(if (external) "uniform samplerExternalOES uTexture;\n" else "uniform sampler2D uTexture;\n")
        append("void main() { fragColor = texture(uTexture, vTex); }\n")
    }

    /**
     * The colour stack. Operations run in the order a photographer expects:
     * white balance, exposure, tone, curves, HSL, look, then texture and
     * atmosphere effects last.
     */
    fun colorFragment(external: Boolean): String = buildString {
        append("#version 300 es\n")
        if (external) append("#extension GL_OES_EGL_image_external_essl3 : require\n")
        append(COLOR_BODY_HEAD)
        append(if (external) "uniform samplerExternalOES uTexture;\n" else "uniform sampler2D uTexture;\n")
        append(COLOR_BODY)
    }

    private const val COLOR_BODY_HEAD = """precision highp float;
in vec2 vTex;
out vec4 fragColor;
"""

    private const val COLOR_BODY = """
uniform sampler2D uBlurred;     // pre-blurred copy of the same frame
uniform sampler2D uCurve;       // 256x1: r=red, g=green, b=blue, a=master
uniform sampler2D uLut;         // 512x512 strip LUT (64^3)

uniform vec2  uTexelSize;
uniform float uHasBlur;         // 0 or 1 - is uBlurred populated
uniform float uHasCurve;
uniform float uHasLut;

uniform float uExposure;
uniform float uBrightness;
uniform float uContrast;
uniform float uHighlights;
uniform float uShadows;
uniform float uWhites;
uniform float uBlacks;
uniform float uSaturation;
uniform float uVibrance;
uniform float uTemperature;
uniform float uTint;
uniform float uHueShift;
uniform float uSharpen;
uniform float uBlur;
uniform float uFade;
uniform float uVignette;
uniform float uGrain;
uniform float uGlow;
uniform float uLutStrength;
uniform float uOpacity;
uniform float uSeed;

uniform float uHslHue[8];
uniform float uHslSat[8];
uniform float uHslLum[8];

const float kHslCenters[8] = float[8](0.0, 30.0, 60.0, 120.0, 180.0, 220.0, 280.0, 320.0);

float luma(vec3 c) { return dot(c, vec3(0.2126, 0.7152, 0.0722)); }

vec3 rgb2hsl(vec3 c) {
    float maxc = max(c.r, max(c.g, c.b));
    float minc = min(c.r, min(c.g, c.b));
    float l = (maxc + minc) * 0.5;
    float h = 0.0;
    float s = 0.0;
    float d = maxc - minc;
    if (d > 1e-5) {
        s = l > 0.5 ? d / (2.0 - maxc - minc) : d / (maxc + minc);
        if (maxc == c.r)      h = (c.g - c.b) / d + (c.g < c.b ? 6.0 : 0.0);
        else if (maxc == c.g) h = (c.b - c.r) / d + 2.0;
        else                  h = (c.r - c.g) / d + 4.0;
        h /= 6.0;
    }
    return vec3(h, s, l);
}

float hue2rgb(float p, float q, float t) {
    if (t < 0.0) t += 1.0;
    if (t > 1.0) t -= 1.0;
    if (t < 1.0 / 6.0) return p + (q - p) * 6.0 * t;
    if (t < 1.0 / 2.0) return q;
    if (t < 2.0 / 3.0) return p + (q - p) * (2.0 / 3.0 - t) * 6.0;
    return p;
}

vec3 hsl2rgb(vec3 hsl) {
    float h = hsl.x, s = hsl.y, l = hsl.z;
    if (s < 1e-5) return vec3(l);
    float q = l < 0.5 ? l * (1.0 + s) : l + s - l * s;
    float p = 2.0 * l - q;
    return vec3(hue2rgb(p, q, h + 1.0 / 3.0), hue2rgb(p, q, h), hue2rgb(p, q, h - 1.0 / 3.0));
}

vec3 sampleLut(vec3 color) {
    float slices = 63.0;
    color = clamp(color, 0.0, 1.0);
    float blue = color.b * slices;
    float b0 = floor(blue);
    float b1 = min(b0 + 1.0, slices);
    float f = blue - b0;

    // 64x64 tiles laid out 8 across in a 512x512 texture, half-texel inset so
    // linear filtering never reaches into the neighbouring tile.
    vec2 rg = (color.rg * 63.0 + 0.5) / 512.0;
    vec2 o0 = vec2(mod(b0, 8.0), floor(b0 / 8.0)) * (64.0 / 512.0);
    vec2 o1 = vec2(mod(b1, 8.0), floor(b1 / 8.0)) * (64.0 / 512.0);

    vec3 c0 = texture(uLut, o0 + rg).rgb;
    vec3 c1 = texture(uLut, o1 + rg).rgb;
    return mix(c0, c1, f);
}

vec3 applyCurves(vec3 c) {
    float r = texture(uCurve, vec2(clamp(c.r, 0.0, 1.0), 0.5)).r;
    float g = texture(uCurve, vec2(clamp(c.g, 0.0, 1.0), 0.5)).g;
    float b = texture(uCurve, vec2(clamp(c.b, 0.0, 1.0), 0.5)).b;
    vec3 perChannel = vec3(r, g, b);
    // Alpha channel carries the master curve, applied after the per-channel ones.
    return vec3(
        texture(uCurve, vec2(clamp(perChannel.r, 0.0, 1.0), 0.5)).a,
        texture(uCurve, vec2(clamp(perChannel.g, 0.0, 1.0), 0.5)).a,
        texture(uCurve, vec2(clamp(perChannel.b, 0.0, 1.0), 0.5)).a
    );
}

vec3 applyHslBands(vec3 c) {
    vec3 hsl = rgb2hsl(c);
    float h = hsl.x * 360.0;
    float dh = 0.0, ds = 0.0, dl = 0.0;
    for (int i = 0; i < 8; i++) {
        float dist = abs(mod(h - kHslCenters[i] + 540.0, 360.0) - 180.0);
        float w = clamp(1.0 - dist / 45.0, 0.0, 1.0);
        w = w * w * (3.0 - 2.0 * w);
        dh += uHslHue[i] * w;
        ds += uHslSat[i] * w;
        dl += uHslLum[i] * w;
    }
    hsl.x = fract(hsl.x + (dh * 30.0) / 360.0 + 1.0);
    hsl.y = clamp(hsl.y * (1.0 + ds), 0.0, 1.0);
    hsl.z = clamp(hsl.z + dl * 0.25, 0.0, 1.0);
    return hsl2rgb(hsl);
}

float hash(vec2 p) {
    return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453);
}

void main() {
    vec4 src = texture(uTexture, vTex);
    vec3 c = src.rgb;

    // --- Unsharp mask, taken against the blurred copy when we have one.
    if (uSharpen > 0.0) {
        vec3 soft;
        if (uHasBlur > 0.5) {
            soft = texture(uBlurred, vTex).rgb;
        } else {
            soft = (
                texture(uTexture, vTex + vec2(uTexelSize.x, 0.0)).rgb +
                texture(uTexture, vTex - vec2(uTexelSize.x, 0.0)).rgb +
                texture(uTexture, vTex + vec2(0.0, uTexelSize.y)).rgb +
                texture(uTexture, vTex - vec2(0.0, uTexelSize.y)).rgb
            ) * 0.25;
        }
        c += (c - soft) * uSharpen * 1.8;
    }

    // --- Blur replaces the sharp source outright.
    if (uBlur > 0.0 && uHasBlur > 0.5) {
        c = mix(c, texture(uBlurred, vTex).rgb, clamp(uBlur, 0.0, 1.0));
    }

    // --- White balance.
    if (uTemperature != 0.0) {
        c.r += uTemperature * 0.16;
        c.g += uTemperature * 0.02;
        c.b -= uTemperature * 0.16;
    }
    if (uTint != 0.0) {
        c.r += uTint * 0.08;
        c.g -= uTint * 0.10;
        c.b += uTint * 0.08;
    }

    // --- Exposure (stops).
    c *= pow(2.0, uExposure);

    // --- Tonal ranges.
    float l = luma(c);
    if (uHighlights != 0.0) c += uHighlights * 0.45 * smoothstep(0.45, 1.0, l);
    if (uShadows != 0.0)    c += uShadows * 0.45 * (1.0 - smoothstep(0.0, 0.55, l));
    if (uWhites != 0.0)     c += uWhites * 0.30 * smoothstep(0.65, 1.0, l);
    if (uBlacks != 0.0)     c += uBlacks * 0.30 * (1.0 - smoothstep(0.0, 0.35, l));

    // --- Contrast around mid grey, then brightness.
    c = (c - 0.5) * (1.0 + uContrast) + 0.5;
    c += uBrightness * 0.35;
    c = clamp(c, 0.0, 1.0);

    if (uHasCurve > 0.5) c = applyCurves(c);

    // --- Hue / HSL.
    if (uHueShift != 0.0) {
        vec3 hsl = rgb2hsl(c);
        hsl.x = fract(hsl.x + uHueShift / 360.0 + 1.0);
        c = hsl2rgb(hsl);
    }
    c = applyHslBands(c);

    // --- Vibrance before saturation: it protects already-saturated pixels.
    if (uVibrance != 0.0) {
        float mx = max(c.r, max(c.g, c.b));
        float mn = min(c.r, min(c.g, c.b));
        float sat = mx - mn;
        float g = luma(c);
        c = mix(vec3(g), c, 1.0 + uVibrance * (1.0 - sat));
    }
    if (uSaturation != 0.0) {
        c = mix(vec3(luma(c)), c, 1.0 + uSaturation);
    }
    c = clamp(c, 0.0, 1.0);

    // --- Look / LUT, mixed by strength.
    if (uHasLut > 0.5 && uLutStrength > 0.0) {
        c = mix(c, sampleLut(c), clamp(uLutStrength, 0.0, 1.0));
    }

    // --- Bloom on the highlights, from the blurred copy.
    if (uGlow > 0.0 && uHasBlur > 0.5) {
        vec3 b = texture(uBlurred, vTex).rgb;
        vec3 bright = max(b - 0.6, vec3(0.0)) * 2.5;
        c += bright * uGlow;
    }

    // --- Matte / faded film black point.
    if (uFade > 0.0) {
        c = mix(c, c * 0.82 + 0.16, clamp(uFade, 0.0, 1.0));
    }

    // --- Vignette. Negative values brighten the corners instead.
    if (uVignette != 0.0) {
        float d = distance(vTex, vec2(0.5)) * 1.4142;
        float v = smoothstep(0.35, 1.0, d) * uVignette;
        c *= (1.0 - v);
    }

    // --- Grain, weighted toward the midtones where it reads naturally.
    if (uGrain > 0.0) {
        float n = hash(vTex * vec2(1024.0, 1024.0) + uSeed) - 0.5;
        float midtone = 1.0 - abs(luma(c) - 0.5) * 2.0;
        c += n * uGrain * 0.22 * mix(0.4, 1.0, midtone);
    }

    fragColor = vec4(clamp(c, 0.0, 1.0), src.a * uOpacity);
}
"""
}
