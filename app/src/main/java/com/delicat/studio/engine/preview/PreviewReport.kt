package com.delicat.studio.engine.preview

/**
 * What the preview knows about itself.
 *
 * A black rectangle is the same picture whatever went wrong behind it, and
 * this app has now spent several rounds guessing which of the possible causes
 * it was. The renderer therefore says what it found, and the screen shows it
 * where the picture would have been — so the next report is a fact rather
 * than a description of an absence.
 */
data class PreviewReport(
    val vendor: String = "",
    val renderer: String = "",
    val version: String = "",
    val shaderCompiled: Boolean = false,
    /** False when only the fallback shader compiled, so colour has no effect. */
    val gradingAvailable: Boolean = false,
    val shaderError: String? = null,
    val surfaceReady: Boolean = false,
    val framesDrawn: Long = 0L,
    val videoFramesReceived: Long = 0L,
) {
    val isHealthy: Boolean get() = shaderCompiled && surfaceReady && gradingAvailable

    /** One line per fact, for a screen small enough that prose will not fit. */
    fun lines(): List<String> = listOf(
        "GPU  ${renderer.ifBlank { "unknown" }}",
        "GL   ${version.ifBlank { "unknown" }}",
        "Shader  ${if (shaderCompiled) "compiled" else "FAILED"}",
        "Colour  ${if (gradingAvailable) "active" else "UNAVAILABLE on this device"}",
        "Surface ${if (surfaceReady) "ready" else "missing"}",
        "Frames drawn $framesDrawn, from video $videoFramesReceived",
    ) + listOfNotNull(shaderError?.let { "Error  $it" })
}
