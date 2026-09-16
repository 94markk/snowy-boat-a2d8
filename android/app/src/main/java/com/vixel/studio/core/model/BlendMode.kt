package com.vixel.studio.core.model

/**
 * How a layer combines with what is already on the canvas.
 *
 * Only the modes expressible with fixed-function GL blending are offered.
 * Photoshop's overlay and soft-light need to read the destination pixel, which
 * means an extra full-canvas pass per layer; these six cost nothing.
 */
enum class BlendMode(val label: String) {
    NORMAL("Normal"),
    MULTIPLY("Multiply"),
    SCREEN("Screen"),
    ADD("Add"),
    DARKEN("Darken"),
    LIGHTEN("Lighten"),
}
