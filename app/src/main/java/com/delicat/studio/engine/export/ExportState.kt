package com.delicat.studio.engine.export

/** Where an export has got to. */
sealed interface ExportState {
    data object Idle : ExportState

    data class Running(val fraction: Float, val stage: String) : ExportState

    data class Done(val uri: String, val displayName: String) : ExportState

    data class Failed(val reason: String) : ExportState
}
