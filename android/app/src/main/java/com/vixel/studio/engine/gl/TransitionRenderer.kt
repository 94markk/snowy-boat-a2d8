package com.vixel.studio.engine.gl

import android.opengl.GLES30
import com.vixel.studio.core.model.TransitionType

/**
 * Blends two already-graded frames into one.
 *
 * Both inputs arrive as canvas-sized textures, so every transition is a pure
 * function of (from, to, progress) and behaves identically whatever the source
 * clips' own sizes or fit modes were.
 */
class TransitionRenderer {

    private var program = 0
    private var initialised = false

    fun init() {
        if (initialised) return
        program = GlUtils.linkProgram(VERTEX, FRAGMENT)
        initialised = true
    }

    /**
     * Draws the blend into the bound framebuffer.
     *
     * @param progress 0 shows [fromTexture], 1 shows [toTexture].
     */
    fun render(
        fromTexture: Int,
        toTexture: Int,
        type: TransitionType,
        progress: Float,
        width: Int,
        height: Int,
    ) {
        init()
        GLES30.glViewport(0, 0, width, height)
        GLES30.glUseProgram(program)

        GLES30.glActiveTexture(GLES30.GL_TEXTURE0)
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, fromTexture)
        GLES30.glUniform1i(GLES30.glGetUniformLocation(program, "uFrom"), 0)

        GLES30.glActiveTexture(GLES30.GL_TEXTURE1)
        GLES30.glBindTexture(GLES30.GL_TEXTURE_2D, toTexture)
        GLES30.glUniform1i(GLES30.glGetUniformLocation(program, "uTo"), 1)

        GLES30.glUniform1f(
            GLES30.glGetUniformLocation(program, "uProgress"),
            progress.coerceIn(0f, 1f),
        )
        GLES30.glUniform1i(GLES30.glGetUniformLocation(program, "uType"), type.ordinal)
        GLES30.glUniform2f(
            GLES30.glGetUniformLocation(program, "uTexelSize"),
            1f / width.coerceAtLeast(1),
            1f / height.coerceAtLeast(1),
        )

        drawQuad()
    }

    /** Straight copy of one canvas texture, reusing the same program. */
    fun blit(texture: Int, width: Int, height: Int) {
        render(texture, texture, TransitionType.DISSOLVE, 1f, width, height)
    }

    private fun drawQuad() {
        val buffer = GlUtils.QUAD
        buffer.position(0)
        GLES30.glEnableVertexAttribArray(0)
        GLES30.glVertexAttribPointer(0, 2, GLES30.GL_FLOAT, false, STRIDE, buffer)
        buffer.position(2)
        GLES30.glEnableVertexAttribArray(1)
        GLES30.glVertexAttribPointer(1, 2, GLES30.GL_FLOAT, false, STRIDE, buffer)
        GLES30.glDrawArrays(GLES30.GL_TRIANGLE_STRIP, 0, 4)
        GLES30.glDisableVertexAttribArray(0)
        GLES30.glDisableVertexAttribArray(1)
        buffer.position(0)
    }

    fun release() {
        if (program != 0) GLES30.glDeleteProgram(program)
        program = 0
        initialised = false
    }

    private companion object {
        const val STRIDE = 4 * 4

        const val VERTEX = """#version 300 es
layout(location = 0) in vec4 aPosition;
layout(location = 1) in vec2 aTexCoord;
out vec2 vTex;
void main() {
    gl_Position = aPosition;
    vTex = aTexCoord;
}
"""

        /**
         * uType matches TransitionType.ordinal. NONE never reaches the shader
         * because the compositor skips blending entirely in that case.
         */
        const val FRAGMENT = """#version 300 es
precision highp float;
in vec2 vTex;
out vec4 fragColor;

uniform sampler2D uFrom;
uniform sampler2D uTo;
uniform float uProgress;
uniform int uType;
uniform vec2 uTexelSize;

const int T_NONE        = 0;
const int T_DISSOLVE    = 1;
const int T_FADE_BLACK  = 2;
const int T_FADE_WHITE  = 3;
const int T_SLIDE_LEFT  = 4;
const int T_SLIDE_RIGHT = 5;
const int T_SLIDE_UP    = 6;
const int T_SLIDE_DOWN  = 7;
const int T_WIPE_LEFT   = 8;
const int T_WIPE_RIGHT  = 9;
const int T_ZOOM_IN     = 10;
const int T_ZOOM_OUT    = 11;
const int T_BLUR        = 12;

vec4 sampleShifted(sampler2D tex, vec2 uv, vec2 shift) {
    vec2 p = uv + shift;
    if (p.x < 0.0 || p.x > 1.0 || p.y < 0.0 || p.y > 1.0) return vec4(0.0);
    return texture(tex, p);
}

/** Cheap 9-tap box blur; enough to sell a defocus transition. */
vec4 blurred(sampler2D tex, vec2 uv, float radius) {
    vec2 r = uTexelSize * radius;
    vec4 sum = texture(tex, uv) * 0.25;
    sum += texture(tex, uv + vec2( r.x, 0.0)) * 0.125;
    sum += texture(tex, uv + vec2(-r.x, 0.0)) * 0.125;
    sum += texture(tex, uv + vec2(0.0,  r.y)) * 0.125;
    sum += texture(tex, uv + vec2(0.0, -r.y)) * 0.125;
    sum += texture(tex, uv + r) * 0.0625;
    sum += texture(tex, uv - r) * 0.0625;
    sum += texture(tex, uv + vec2( r.x, -r.y)) * 0.0625;
    sum += texture(tex, uv + vec2(-r.x,  r.y)) * 0.0625;
    return sum;
}

void main() {
    float p = clamp(uProgress, 0.0, 1.0);
    // Ease so slides and zooms start and stop gently.
    float e = p * p * (3.0 - 2.0 * p);

    vec4 from = texture(uFrom, vTex);
    vec4 to = texture(uTo, vTex);
    vec4 result;

    if (uType == T_DISSOLVE || uType == T_NONE) {
        result = mix(from, to, e);

    } else if (uType == T_FADE_BLACK || uType == T_FADE_WHITE) {
        // Out to the flat colour over the first half, in over the second.
        vec3 flat_ = (uType == T_FADE_BLACK) ? vec3(0.0) : vec3(1.0);
        if (p < 0.5) {
            result = vec4(mix(from.rgb, flat_, smoothstep(0.0, 0.5, p)), 1.0);
        } else {
            result = vec4(mix(flat_, to.rgb, smoothstep(0.5, 1.0, p)), 1.0);
        }

    } else if (uType == T_SLIDE_LEFT) {
        result = sampleShifted(uFrom, vTex, vec2(e, 0.0)) + sampleShifted(uTo, vTex, vec2(e - 1.0, 0.0));
    } else if (uType == T_SLIDE_RIGHT) {
        result = sampleShifted(uFrom, vTex, vec2(-e, 0.0)) + sampleShifted(uTo, vTex, vec2(1.0 - e, 0.0));
    } else if (uType == T_SLIDE_UP) {
        result = sampleShifted(uFrom, vTex, vec2(0.0, e)) + sampleShifted(uTo, vTex, vec2(0.0, e - 1.0));
    } else if (uType == T_SLIDE_DOWN) {
        result = sampleShifted(uFrom, vTex, vec2(0.0, -e)) + sampleShifted(uTo, vTex, vec2(0.0, 1.0 - e));

    } else if (uType == T_WIPE_LEFT) {
        // Soft edge so the boundary does not alias.
        float edge = smoothstep(e - 0.03, e + 0.03, 1.0 - vTex.x);
        result = mix(to, from, edge);
    } else if (uType == T_WIPE_RIGHT) {
        float edge = smoothstep(e - 0.03, e + 0.03, vTex.x);
        result = mix(to, from, edge);

    } else if (uType == T_ZOOM_IN) {
        // Outgoing pushes in while the incoming settles from slightly large.
        vec2 c = vec2(0.5);
        vec4 f = texture(uFrom, c + (vTex - c) / max(0.001, 1.0 + e * 0.6));
        vec4 t = texture(uTo, c + (vTex - c) * mix(1.6, 1.0, e));
        result = mix(f, t, e);
    } else if (uType == T_ZOOM_OUT) {
        vec2 c = vec2(0.5);
        vec4 f = texture(uFrom, c + (vTex - c) * mix(1.0, 1.6, e));
        vec4 t = texture(uTo, c + (vTex - c) / max(0.001, 1.0 + (1.0 - e) * 0.6));
        result = mix(f, t, e);

    } else if (uType == T_BLUR) {
        // Defocus out, then back in, crossing over at the midpoint.
        float amount = 1.0 - abs(p - 0.5) * 2.0;
        float radius = amount * 14.0;
        vec4 f = blurred(uFrom, vTex, radius);
        vec4 t = blurred(uTo, vTex, radius);
        result = mix(f, t, e);

    } else {
        result = mix(from, to, e);
    }

    fragColor = vec4(result.rgb, 1.0);
}
"""
    }
}
