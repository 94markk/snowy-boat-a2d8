package com.vixel.studio.core.store

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.util.Log
import com.vixel.studio.core.model.Project
import org.json.JSONObject
import java.io.File

private const val TAG = "VixelProjects"

data class ProjectSummary(
    val id: String,
    val name: String,
    val durationUs: Long,
    val clipCount: Int,
    val overlayCount: Int,
    val aspectId: String,
    val updatedAt: Long,
)

/**
 * Projects on disk, one JSON file each under `files/projects`.
 *
 * Media is referenced by uri rather than copied. Copying would make a project
 * self-contained but would also duplicate every video the user edits, so the
 * tradeoff here is storage in exchange for [missingMedia] being possible —
 * see its note.
 */
object ProjectStore {

    private const val EXTENSION = ".vixel.json"

    fun directory(context: Context): File =
        File(context.filesDir, "projects").apply { mkdirs() }

    private fun fileFor(context: Context, id: String): File =
        File(directory(context), "$id$EXTENSION")

    fun save(context: Context, project: Project): Boolean = try {
        val json = ProjectJson.toJson(project).toString()
        val target = fileFor(context, project.id)
        // Write beside the target and swap, so an interrupted save cannot
        // leave a half-written project that fails to open.
        val temp = File(target.parentFile, "${target.name}.tmp")
        temp.writeText(json)
        if (target.exists()) target.delete()
        val renamed = temp.renameTo(target)
        if (!renamed) temp.copyTo(target, overwrite = true).also { temp.delete() }
        true
    } catch (t: Throwable) {
        Log.e(TAG, "could not save project ${project.id}", t)
        false
    }

    fun load(context: Context, id: String): Project? = try {
        val file = fileFor(context, id)
        if (!file.exists()) {
            null
        } else {
            ProjectJson.fromJson(JSONObject(file.readText()))
        }
    } catch (t: Throwable) {
        Log.e(TAG, "could not load project $id", t)
        null
    }

    /** Newest first. */
    fun list(context: Context): List<ProjectSummary> =
        directory(context)
            .listFiles { file -> file.isFile && file.name.endsWith(EXTENSION) }
            ?.mapNotNull { file -> summarise(file) }
            ?.sortedByDescending { it.updatedAt }
            ?: emptyList()

    private fun summarise(file: File): ProjectSummary? = try {
        val project = ProjectJson.fromJson(JSONObject(file.readText()))
        ProjectSummary(
            id = project.id,
            name = project.name,
            durationUs = project.durationUs,
            clipCount = project.clips.size,
            overlayCount = project.overlays.size,
            aspectId = project.aspect.id,
            updatedAt = file.lastModified(),
        )
    } catch (t: Throwable) {
        Log.w(TAG, "skipping unreadable project ${file.name}", t)
        null
    }

    fun delete(context: Context, id: String): Boolean = fileFor(context, id).delete()

    fun exists(context: Context, id: String): Boolean = fileFor(context, id).exists()

    /**
     * Clips whose media can no longer be opened.
     *
     * A picked uri is a grant, not a copy, and a grant can lapse — the file is
     * deleted, the SD card is pulled, or the permission expires. Reporting this
     * lets the editor say which clip is unavailable instead of rendering black
     * frames and leaving the user to guess.
     */
    fun missingMedia(context: Context, project: Project): List<String> =
        project.clips.filterNot { canRead(context, it.uri) }.map { it.id }

    fun canRead(context: Context, uri: String): Boolean = try {
        context.contentResolver.openInputStream(Uri.parse(uri))?.use { true } ?: false
    } catch (t: Throwable) {
        false
    }

    /**
     * Asks for long-lived access to a picked uri.
     *
     * Only document-provider uris can grant this; the photo picker cannot, and
     * throws. Failing is normal, so it is swallowed.
     */
    fun tryPersistPermission(context: Context, uri: Uri) {
        try {
            context.contentResolver.takePersistableUriPermission(
                uri,
                Intent.FLAG_GRANT_READ_URI_PERMISSION,
            )
        } catch (t: Throwable) {
            Log.d(TAG, "uri is not persistable, continuing with the session grant")
        }
    }
}
