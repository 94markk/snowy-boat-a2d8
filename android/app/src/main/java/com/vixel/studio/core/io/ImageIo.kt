package com.vixel.studio.core.io

import android.content.ContentValues
import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.ImageDecoder
import android.graphics.Matrix
import android.net.Uri
import android.os.Build
import android.os.Environment
import android.provider.MediaStore
import java.io.IOException
import kotlin.math.max

/** Formats the editor can write a still out as. */
enum class ImageFormat(val label: String, val extension: String, val mimeType: String) {
    JPEG("JPEG", "jpg", "image/jpeg"),
    PNG("PNG", "png", "image/png"),
    WEBP("WebP", "webp", "image/webp");

    val supportsTransparency: Boolean get() = this != JPEG
}

object ImageIo {

    /**
     * Decodes [uri] downsampled to at most [maxEdge] and rotated upright.
     *
     * Phone cameras write the orientation into EXIF rather than baking it into
     * the pixels, so a photo decoded naively shows up on its side — which is a
     * large part of why an editor can look like it is "stuck in landscape".
     */
    fun decode(context: Context, uri: Uri, maxEdge: Int = 4096): Bitmap? {
        val decoded = decodeWithBitmapFactory(context, uri, maxEdge)
            ?: decodeWithImageDecoder(context, uri, maxEdge)
            ?: return null

        val rotation = readOrientation(context, uri)
        return if (rotation == 0f) decoded else rotate(decoded, rotation)
    }

    /**
     * Everything here returns null rather than throwing.
     *
     * A picked uri can fail in ways that are entirely normal — the grant has
     * lapsed, the provider is gone, the file was deleted between the pick and
     * the read — and openInputStream signals all of them by throwing. Decoding
     * usually runs inside a coroutine on a background dispatcher, so letting
     * one escape takes the app down over a file that simply is not there.
     */
    private fun decodeWithBitmapFactory(
        context: Context,
        uri: Uri,
        maxEdge: Int,
    ): Bitmap? = try {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, bounds)
        }

        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
            null
        } else {
            val options = BitmapFactory.Options().apply {
                inSampleSize = sampleSizeFor(bounds.outWidth, bounds.outHeight, maxEdge)
                inPreferredConfig = Bitmap.Config.ARGB_8888
            }
            context.contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, options)
            }
        }
    } catch (t: Throwable) {
        null
    }

    /**
     * Second attempt, for formats BitmapFactory will not open.
     *
     * Phones increasingly save HEIC by default, and newer ones AVIF; both fail
     * in BitmapFactory on older releases and read fine through ImageDecoder.
     * Without this an "unsupported image" is indistinguishable from a broken
     * import, which is the more likely conclusion a user draws.
     *
     * Software allocation is requested because the result is uploaded as a GL
     * texture and read back on the CPU, neither of which a hardware bitmap
     * supports.
     */
    private fun decodeWithImageDecoder(
        context: Context,
        uri: Uri,
        maxEdge: Int,
    ): Bitmap? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.P) return null
        return try {
            val source = ImageDecoder.createSource(context.contentResolver, uri)
            ImageDecoder.decodeBitmap(source) { decoder, info, _ ->
                decoder.allocator = ImageDecoder.ALLOCATOR_SOFTWARE
                decoder.isMutableRequired = true
                val sample = sampleSizeFor(info.size.width, info.size.height, maxEdge)
                if (sample > 1) decoder.setTargetSampleSize(sample)
            }
        } catch (t: Throwable) {
            null
        }
    }

    fun sampleSizeFor(width: Int, height: Int, maxEdge: Int): Int {
        var sample = 1
        var longest = max(width, height)
        while (longest / 2 >= maxEdge) {
            longest /= 2
            sample *= 2
        }
        return sample
    }

    /**
     * Upright pixel size of [uri], or null if it is not a decodable image.
     *
     * Normally a header read, so it stays cheap over a whole multi-select. It
     * falls back to a small decode for the formats BitmapFactory cannot open,
     * which costs more but only for files that would otherwise be turned away
     * at import despite [decode] being able to read them.
     *
     * The EXIF swap matters: [decode] hands back an upright bitmap, so
     * anything laying this image out has to be told the upright size too, or a
     * portrait photo gets a landscape slot on the timeline.
     */
    fun boundsOf(context: Context, uri: Uri): Pair<Int, Int>? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        try {
            context.contentResolver.openInputStream(uri)?.use {
                BitmapFactory.decodeStream(it, null, bounds)
            }
        } catch (t: Throwable) {
            // Fall through to the decoder fallback below.
        }

        var width = bounds.outWidth
        var height = bounds.outHeight

        if (width <= 0 || height <= 0) {
            val probe = decodeWithImageDecoder(context, uri, maxEdge = 512) ?: return null
            width = probe.width
            height = probe.height
            probe.recycle()
        }

        val quarterTurn = readOrientation(context, uri).let { it == 90f || it == 270f }
        return if (quarterTurn) height to width else width to height
    }

    /**
     * Uses the framework ExifInterface rather than the AndroidX one: it covers
     * every format we decode here and keeps this layer free of AndroidX so it
     * can be typechecked against a plain android.jar.
     */
    @Suppress("DEPRECATION")
    private fun readOrientation(context: Context, uri: Uri): Float = try {
        context.contentResolver.openInputStream(uri)?.use { stream ->
            val exif = android.media.ExifInterface(stream)
            when (
                exif.getAttributeInt(
                    android.media.ExifInterface.TAG_ORIENTATION,
                    android.media.ExifInterface.ORIENTATION_NORMAL,
                )
            ) {
                android.media.ExifInterface.ORIENTATION_ROTATE_90 -> 90f
                android.media.ExifInterface.ORIENTATION_ROTATE_180 -> 180f
                android.media.ExifInterface.ORIENTATION_ROTATE_270 -> 270f
                else -> 0f
            }
        } ?: 0f
    } catch (e: IOException) {
        0f
    }

    fun rotate(bitmap: Bitmap, degrees: Float): Bitmap {
        if (degrees == 0f) return bitmap
        val matrix = Matrix().apply { postRotate(degrees) }
        val out = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
        if (out !== bitmap) bitmap.recycle()
        return out
    }

    fun flip(bitmap: Bitmap, horizontal: Boolean): Bitmap {
        val matrix = Matrix().apply {
            if (horizontal) postScale(-1f, 1f) else postScale(1f, -1f)
        }
        val out = Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, matrix, true)
        if (out !== bitmap) bitmap.recycle()
        return out
    }

    /**
     * Writes [bitmap] into the shared Pictures collection under a Vixel Studio
     * album and returns its content uri.
     */
    fun saveToGallery(
        context: Context,
        bitmap: Bitmap,
        format: ImageFormat,
        quality: Int = 95,
        displayName: String = defaultName(format),
    ): Uri {
        val values = ContentValues().apply {
            put(MediaStore.MediaColumns.DISPLAY_NAME, displayName)
            put(MediaStore.MediaColumns.MIME_TYPE, format.mimeType)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                put(MediaStore.MediaColumns.RELATIVE_PATH, "${Environment.DIRECTORY_PICTURES}/$ALBUM")
                put(MediaStore.MediaColumns.IS_PENDING, 1)
            }
        }

        val resolver = context.contentResolver
        val collection = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            MediaStore.Images.Media.getContentUri(MediaStore.VOLUME_EXTERNAL_PRIMARY)
        } else {
            MediaStore.Images.Media.EXTERNAL_CONTENT_URI
        }

        val uri = resolver.insert(collection, values)
            ?: throw IOException("MediaStore rejected the insert")

        try {
            resolver.openOutputStream(uri)?.use { out ->
                val compressFormat = when (format) {
                    ImageFormat.JPEG -> Bitmap.CompressFormat.JPEG
                    ImageFormat.PNG -> Bitmap.CompressFormat.PNG
                    ImageFormat.WEBP ->
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                            Bitmap.CompressFormat.WEBP_LOSSY
                        } else {
                            @Suppress("DEPRECATION")
                            Bitmap.CompressFormat.WEBP
                        }
                }
                if (!bitmap.compress(compressFormat, quality.coerceIn(1, 100), out)) {
                    throw IOException("Bitmap.compress failed for ${format.label}")
                }
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

    fun defaultName(format: ImageFormat): String =
        "vixel_${System.currentTimeMillis()}.${format.extension}"

    const val ALBUM = "Vixel Studio"
}
