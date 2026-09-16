package com.vixel.studio.core

import android.content.Context
import android.content.SharedPreferences

/**
 * Small preference store. The orientation flag lives here because it has to be
 * readable before the first frame is composed.
 */
class Settings(context: Context) {

    private val prefs: SharedPreferences =
        context.applicationContext.getSharedPreferences(NAME, Context.MODE_PRIVATE)

    /**
     * Phones open in portrait. This is the default and stays the default —
     * rotation is strictly opt-in, per device, and never inferred from the
     * sensor at launch.
     */
    var allowRotation: Boolean
        get() = prefs.getBoolean(KEY_ALLOW_ROTATION, false)
        set(value) = prefs.edit().putBoolean(KEY_ALLOW_ROTATION, value).apply()

    var lastAspectId: String
        get() = prefs.getString(KEY_LAST_ASPECT, "9:16") ?: "9:16"
        set(value) = prefs.edit().putString(KEY_LAST_ASPECT, value).apply()

    var exportResolution: Int
        get() = prefs.getInt(KEY_EXPORT_RES, 1080)
        set(value) = prefs.edit().putInt(KEY_EXPORT_RES, value).apply()

    var exportFps: Int
        get() = prefs.getInt(KEY_EXPORT_FPS, 30)
        set(value) = prefs.edit().putInt(KEY_EXPORT_FPS, value).apply()

    companion object {
        private const val NAME = "vixel_settings"
        private const val KEY_ALLOW_ROTATION = "allow_rotation"
        private const val KEY_LAST_ASPECT = "last_aspect"
        private const val KEY_EXPORT_RES = "export_resolution"
        private const val KEY_EXPORT_FPS = "export_fps"
    }
}
