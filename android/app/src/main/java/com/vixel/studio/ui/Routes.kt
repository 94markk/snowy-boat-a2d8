package com.vixel.studio.ui

object Routes {
    const val HOME = "home"
    const val VIDEO_EDITOR = "editor/video"
    const val ARG_PROJECT_ID = "projectId"

    /** Route pattern registered in the nav graph. */
    const val VIDEO_EDITOR_ROUTE = "editor/video?$ARG_PROJECT_ID={$ARG_PROJECT_ID}"

    /** Opens an existing project, or a fresh one when [id] is null. */
    fun videoEditor(id: String? = null): String =
        if (id == null) VIDEO_EDITOR else "editor/video?$ARG_PROJECT_ID=$id"
    const val PHOTO_EDITOR = "editor/photo"
    const val COLLAGE = "tools/collage"
    const val CUTOUT = "tools/cutout"
    const val CONVERT = "tools/convert"
    const val SETTINGS = "settings"
}
