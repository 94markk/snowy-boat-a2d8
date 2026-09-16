package com.vixel.studio.core.model

enum class TransitionType(val label: String) {
    NONE("None"),
    DISSOLVE("Dissolve"),
    FADE_BLACK("Fade to black"),
    FADE_WHITE("Fade to white"),
    SLIDE_LEFT("Slide left"),
    SLIDE_RIGHT("Slide right"),
    SLIDE_UP("Slide up"),
    SLIDE_DOWN("Slide down"),
    WIPE_LEFT("Wipe left"),
    WIPE_RIGHT("Wipe right"),
    ZOOM_IN("Zoom in"),
    ZOOM_OUT("Zoom out"),
    BLUR("Blur"),
    ;

    val isNone: Boolean get() = this == NONE
}

/**
 * A transition *into* a clip, overlapping it with the one before.
 *
 * Because the two clips overlap, a transition shortens the timeline by its own
 * duration — which is why [Project.durationUs] subtracts it rather than just
 * summing clip lengths.
 */
data class Transition(
    val type: TransitionType = TransitionType.NONE,
    val durationUs: Long = 500_000L,
) {
    val isActive: Boolean get() = !type.isNone && durationUs > 0L

    companion object {
        val NONE = Transition(TransitionType.NONE, 0L)
        const val MIN_US = 100_000L
        const val MAX_US = 2_000_000L
    }
}

/**
 * What the compositor should draw at one instant.
 *
 * Outside a transition only [primaryIndex] is set. Inside one, [fromIndex] is
 * the outgoing clip and [progress] runs 0 to 1 across the overlap.
 */
data class Composition(
    val primaryIndex: Int,
    val fromIndex: Int = -1,
    val progress: Float = 1f,
    val transition: Transition = Transition.NONE,
) {
    val isTransitioning: Boolean get() = fromIndex >= 0 && transition.isActive
}
