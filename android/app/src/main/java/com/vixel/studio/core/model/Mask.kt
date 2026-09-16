package com.vixel.studio.core.model

enum class MaskShape(val label: String) {
    NONE("None"),
    RECTANGLE("Rectangle"),
    ELLIPSE("Ellipse"),
    LINEAR("Linear"),
    RADIAL("Radial"),
    ;

    val isNone: Boolean get() = this == NONE
}

/**
 * Hides part of a clip, revealing the project background behind it.
 *
 * Geometry is expressed as fractions of the canvas so a mask holds its place
 * across resolutions, and [feather] softens the edge so a shape does not
 * alias against the footage.
 */
data class Mask(
    val shape: MaskShape = MaskShape.NONE,
    val centerX: Float = 0.5f,
    val centerY: Float = 0.5f,
    /** Half-extent for rectangle and ellipse; band position for the gradients. */
    val width: Float = 0.4f,
    val height: Float = 0.4f,
    val rotationDegrees: Float = 0f,
    val feather: Float = 0.08f,
    val invert: Boolean = false,
) {
    val isActive: Boolean get() = !shape.isNone
}
