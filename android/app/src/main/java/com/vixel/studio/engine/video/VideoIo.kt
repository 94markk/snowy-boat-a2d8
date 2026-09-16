package com.vixel.studio.engine.video

import android.content.ContentValues
import android.content.Context
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import java.io.File
import java.io.IOException

object VideoIo {

    const val ALBUM = "Vixel Studio"

    fun exportsDir(context: Context): File =
        File(context.getExternalFilesDir(null) ?: context.filesDir, "exports").apply { mkdirs() }

    fun newExportFile(context: Context, extension: String = "mp4"): File =
        File(exportsDir(context), "vixel_${System.currentTimeMillis()}.$extension")

    /**
     * Copies a finished export into the shared Movies collection so it shows up
     * in the gallery. The working file stays put; a failure here must not lose
     * the user's render.
     */
    fun publishToGallery(
        context: Context,
        file: File,
        mimeType: String = "video/mp4",
        displayName: String = file.name,
    ): Uri {
        val values = ContentValues().apply {
            put(MediaStore.MediaColumns.DISPLAY_NAME, displayName)
            put(MediaStore.MediaColumns.MIME_TYPE, mimeType)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                put(MediaStore.MediaColumns.RELATIVE_PATH, "${Environment.DIRECTORY_MOVIES}/$ALBUM")
                put(MediaStore.MediaColumns.IS_PENDING, 1)
            }
        }

        val resolver = context.contentResolver
        val collection = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            MediaStore.Video.Media.getContentUri(MediaStore.VOLUME_EXTERNAL_PRIMARY)
        } else {
            MediaStore.Video.Media.EXTERNAL_CONTENT_URI
        }

        val uri = resolver.insert(collection, values)
            ?: throw IOException("MediaStore rejected the insert")

        try {
            resolver.openOutputStream(uri)?.use { out ->
                file.inputStream().use { input -> input.copyTo(out) }
            } ?: throw IOException("Could not open an output stream")
        } catch (t: Throwable) {
            resolver.delete(uri, null, null)
            throw t
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            values.clear()
            values.put(MediaStore.MediaColumns.IS_PENDING, 0)
            resolver.update(uri, values, null, null)
        }
        return uri
    }
}
