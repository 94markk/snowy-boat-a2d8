#version 460 core
#include <flutter/runtime_effect.glsl>

precision highp float;

// Grading is split in two, and the split is the whole design.
//
// Every operation whose output depends only on the input colour — exposure,
// contrast, the tonal ranges, white balance, hue, HSL bands, curves and the
// look itself — is collapsed in Dart into one 64³ LUT before it reaches this
// shader. That same LUT is written out as a .cube for FFmpeg's lut3d filter on
// export, so the colour on screen and the colour in the file come from the
// same table rather than from two implementations that agree only as long as
// somebody keeps them in step.
//
// What cannot go in a LUT is anything that depends on where a pixel is rather
// than what colour it is: blur, sharpen, vignette, grain and glow. Those stay
// here as uniforms, and export maps each onto its FFmpeg equivalent.

uniform vec2 uSize;

// Cube edge length and how many tiles sit across the strip. Passed in rather
// than hardcoded because the preview bakes a smaller cube than export does: a
// 64³ table is a quarter of a million evaluations, which is far too slow to
// rebuild under a moving slider, while 32³ costs an eighth of that and is
// indistinguishable at phone size. Export still writes the full 64³.
uniform float uLutSize;
uniform float uLutCols;

uniform float uSharpen;
uniform float uBlur;
uniform float uVignette;
uniform float uGrain;
uniform float uGlow;
uniform float uSeed;
uniform float uOpacity;

uniform sampler2D uTexture;
uniform sampler2D uLut;

// No layout(location) qualifiers. Flutter assigns float uniforms to slots in
// declaration order and samplers in their own order, so the qualifiers buy
// nothing, and SPIR-V rejects a location decoration on a sampler outright:
// "Location decoration must not be applied to this storage class".
out vec4 fragColor;

float luma(vec3 c) {
    return dot(c, vec3(0.2126, 0.7152, 0.0722));
}

/// Samples the LUT, held as a strip of NxN tiles laid out uLutCols across.
///
/// Blue selects the tile, red and green index within it, and the two tiles
/// either side of the blue value are blended so the cube reads as continuous
/// rather than stepping between slices.
///
/// The half-texel inset matters. Without it linear filtering at a tile edge
/// reaches into the neighbouring blue slice, which shows up as coloured seams
/// across smooth gradients — a sky is where you notice it first.
vec3 sampleLut(vec3 color) {
    float n = uLutSize;
    float cols = uLutCols;
    float rows = ceil(n / cols);
    vec2 texSize = vec2(n * cols, n * rows);
    float slices = n - 1.0;

    color = clamp(color, 0.0, 1.0);
    float blue = color.b * slices;
    float b0 = floor(blue);
    float b1 = min(b0 + 1.0, slices);
    float f = blue - b0;

    vec2 idx = color.rg * slices;
    vec2 base0 = vec2(mod(b0, cols), floor(b0 / cols)) * n;
    vec2 base1 = vec2(mod(b1, cols), floor(b1 / cols)) * n;

    vec3 c0 = texture(uLut, (base0 + idx + 0.5) / texSize).rgb;
    vec3 c1 = texture(uLut, (base1 + idx + 0.5) / texSize).rgb;
    return mix(c0, c1, f);
}

/// Nine-tap blur, radius in texels.
///
/// A single pass rather than the separable two-pass the exporter uses: a
/// fragment shader here cannot render to an intermediate target. At preview
/// size the difference is not visible, and export runs FFmpeg's gblur, which
/// is the real thing.
vec3 blurred(vec2 uv, float radius) {
    vec2 step = radius / uSize;
    vec3 sum = texture(uTexture, uv).rgb * 4.0;

    sum += texture(uTexture, uv + vec2(step.x, 0.0)).rgb * 2.0;
    sum += texture(uTexture, uv - vec2(step.x, 0.0)).rgb * 2.0;
    sum += texture(uTexture, uv + vec2(0.0, step.y)).rgb * 2.0;
    sum += texture(uTexture, uv - vec2(0.0, step.y)).rgb * 2.0;

    sum += texture(uTexture, uv + step).rgb;
    sum += texture(uTexture, uv - step).rgb;
    sum += texture(uTexture, uv + vec2(step.x, -step.y)).rgb;
    sum += texture(uTexture, uv + vec2(-step.x, step.y)).rgb;

    return sum / 16.0;
}

float hash(vec2 p) {
    return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453);
}

void main() {
    vec2 uv = FlutterFragCoord().xy / uSize;
    vec4 src = texture(uTexture, uv);
    vec3 c = src.rgb;

    // Spatial work that has to happen before grading, so the LUT sees the
    // pixel the viewer will actually be judging.
    if (uSharpen > 0.0) {
        vec3 soft = blurred(uv, 1.5);
        c += (c - soft) * uSharpen * 1.8;
    }

    if (uBlur > 0.0) {
        float radius = uBlur * 0.04 * min(uSize.x, uSize.y);
        c = mix(c, blurred(uv, radius), clamp(uBlur, 0.0, 1.0));
    }

    // Every colour operation, in one table lookup.
    c = sampleLut(clamp(c, 0.0, 1.0));

    if (uGlow > 0.0) {
        vec3 b = blurred(uv, 0.02 * min(uSize.x, uSize.y));
        vec3 bright = max(b - 0.6, vec3(0.0)) * 2.5;
        c += bright * uGlow;
    }

    // Negative vignette brightens the corners instead of darkening them.
    if (uVignette != 0.0) {
        float d = distance(uv, vec2(0.5)) * 1.4142;
        float v = smoothstep(0.35, 1.0, d) * uVignette;
        c *= (1.0 - v);
    }

    // Grain is weighted towards the midtones, where film grain actually lives.
    // Spread evenly it turns black into grey mush and clips out of highlights.
    if (uGrain > 0.0) {
        float n = hash(uv * vec2(1024.0, 1024.0) + uSeed) - 0.5;
        float midtone = 1.0 - abs(luma(c) - 0.5) * 2.0;
        c += n * uGrain * 0.22 * mix(0.4, 1.0, midtone);
    }

    float alpha = src.a * uOpacity;
    fragColor = vec4(clamp(c, 0.0, 1.0) * alpha, alpha);
}
