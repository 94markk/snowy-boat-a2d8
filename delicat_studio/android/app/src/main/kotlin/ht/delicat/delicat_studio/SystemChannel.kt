package ht.delicat.delicat_studio

import android.app.ActivityManager
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Handler
import android.os.Looper
import androidx.core.content.FileProvider
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel
import java.io.File

/** Device facts for safe defaults, and direct sharing to WhatsApp. */
class SystemChannel(private val activity: android.app.Activity) : MethodChannel.MethodCallHandler {
    private var channel: MethodChannel? = null
    private val main = Handler(Looper.getMainLooper())

    fun attach(messenger: BinaryMessenger) {
        channel = MethodChannel(messenger, "ht.delicat.studio/system").also { it.setMethodCallHandler(this) }
    }

    fun dispose() {
        channel?.setMethodCallHandler(null)
    }

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        when (call.method) {
            "deviceInfo" -> result.success(deviceInfo())
            "installedApps" -> result.success(
                listOf(WHATSAPP, WHATSAPP_BUSINESS).filter { isInstalled(it) },
            )
            "shareTo" -> {
                val path = call.argument<String>("path")
                if (path == null) {
                    result.error("share", "path manquant", null)
                    return
                }
                val mime = call.argument<String>("mime") ?: "*/*"
                val pkg = call.argument<String>("package")
                val text = call.argument<String>("text")
                // Copying a long video can take a moment: do it off the main thread.
                Thread {
                    try {
                        val copy = shareCopy(File(path))
                        main.post {
                            try {
                                result.success(shareTo(copy, mime, pkg, text))
                            } catch (e: Exception) {
                                result.error("share", e.message ?: e.toString(), null)
                            }
                        }
                    } catch (e: Throwable) {
                        main.post { result.error("share", e.message ?: e.toString(), null) }
                    }
                }.start()
            }
            else -> result.notImplemented()
        }
    }

    private fun deviceInfo(): Map<String, Any> {
        val am = activity.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager
        val mem = ActivityManager.MemoryInfo()
        am.getMemoryInfo(mem)
        return mapOf(
            "manufacturer" to Build.MANUFACTURER,
            "model" to Build.MODEL,
            "sdk" to Build.VERSION.SDK_INT,
            "release" to (Build.VERSION.RELEASE ?: ""),
            "totalRamMb" to (mem.totalMem / (1024 * 1024)).toInt(),
            "availRamMb" to (mem.availMem / (1024 * 1024)).toInt(),
            "lowRam" to am.isLowRamDevice,
            "memoryClassMb" to am.memoryClass,
        )
    }

    private fun isInstalled(pkg: String): Boolean = try {
        if (Build.VERSION.SDK_INT >= 33) {
            activity.packageManager.getPackageInfo(pkg, PackageManager.PackageInfoFlags.of(0))
        } else {
            @Suppress("DEPRECATION")
            activity.packageManager.getPackageInfo(pkg, 0)
        }
        true
    } catch (e: PackageManager.NameNotFoundException) {
        false
    }

    /**
     * Shares [file] to [pkg] (e.g. WhatsApp). Falls back to the system share sheet when that
     * app is missing. Returns the package actually used, or "chooser".
     */
    private fun shareTo(file: File, mime: String, pkg: String?, text: String?): String {
        val uri = FileProvider.getUriForFile(activity, "${activity.packageName}.files", file)
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = mime
            putExtra(Intent.EXTRA_STREAM, uri)
            if (!text.isNullOrBlank()) putExtra(Intent.EXTRA_TEXT, text)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        val candidates = when (pkg) {
            null -> emptyList()
            WHATSAPP -> listOf(WHATSAPP, WHATSAPP_BUSINESS)
            else -> listOf(pkg)
        }
        for (p in candidates) {
            if (!isInstalled(p)) continue
            try {
                activity.startActivity(Intent(intent).setPackage(p))
                return p
            } catch (_: ActivityNotFoundException) {
                // Try the next candidate.
            }
        }
        activity.startActivity(Intent.createChooser(intent, null).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION))
        return "chooser"
    }

    /**
     * Creations live in app_flutter, which the FileProvider does not expose, so a copy goes to
     * cache/share. Copies older than a day are cleaned up on each share.
     */
    private fun shareCopy(src: File): File {
        val dir = File(activity.cacheDir, "share").apply { mkdirs() }
        val dayAgo = System.currentTimeMillis() - 24 * 60 * 60 * 1000L
        dir.listFiles()?.forEach { if (it.lastModified() < dayAgo) it.delete() }
        val dst = File(dir, src.name)
        if (!dst.exists() || dst.length() != src.length()) src.copyTo(dst, overwrite = true)
        return dst
    }

    companion object {
        const val WHATSAPP = "com.whatsapp"
        const val WHATSAPP_BUSINESS = "com.whatsapp.w4b"
    }
}
