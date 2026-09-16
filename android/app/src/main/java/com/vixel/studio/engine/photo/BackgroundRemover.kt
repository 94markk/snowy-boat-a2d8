package com.vixel.studio.engine.photo

import android.graphics.Bitmap
import android.graphics.Color
import kotlin.math.abs
import kotlin.math.cbrt
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.sqrt

/**
 * Background removal that runs entirely on device with no model download.
 *
 * Two strategies, because they suit different footage:
 *
 *  - [chromaKey] for green/blue screen. Deterministic and exact.
 *  - [removeBackground] for everything else: colour models are learned from
 *    the border (background) and the centre (subject), each pixel is scored by
 *    which model it is closer to, and the resulting matte is regularised
 *    spatially and feathered.
 *
 * The automatic path is a matting heuristic, not segmentation — it is strong
 * on a subject that contrasts with a reasonably uniform background and weak
 * on a busy one. The editor pairs it with refine brushes for that reason.
 */
object BackgroundRemover {

    /** Matte is computed at this resolution and upsampled; keeps it fast. */
    private const val WORK_EDGE = 320
    private const val CLUSTERS = 6
    private const val KMEANS_ITERATIONS = 8

    data class Options(
        /** Higher pulls more of the image into the background. 0..1. */
        val sensitivity: Float = 0.5f,
        /** Softness of the cutout edge. 0..1. */
        val feather: Float = 0.35f,
        /** Discard islands that are not part of the main subject. */
        val keepLargestOnly: Boolean = true,
    )

    data class ChromaOptions(
        val keyColor: Int = Color.rgb(0, 177, 64),
        /** How far from the key colour still counts as background. 0..1. */
        val tolerance: Float = 0.35f,
        /** Width of the partial-transparency band at the edge. 0..1. */
        val softness: Float = 0.15f,
        /** Removes the colour cast the screen throws onto the subject. 0..1. */
        val spillRemoval: Float = 0.6f,
    )

    // ------------------------------------------------------------ chroma key

    fun chromaKey(source: Bitmap, options: ChromaOptions): Bitmap {
        val width = source.width
        val height = source.height
        val pixels = IntArray(width * height)
        source.getPixels(pixels, 0, width, 0, 0, width, height)

        val keyLab = rgbToLab(
            Color.red(options.keyColor),
            Color.green(options.keyColor),
            Color.blue(options.keyColor),
        )

        // Distances are in Lab, where a fixed radius means roughly the same
        // perceived difference regardless of hue.
        val tolerance = 8f + options.tolerance * 60f
        val soft = 1f + options.softness * 45f

        val lab = FloatArray(3)
        for (i in pixels.indices) {
            val p = pixels[i]
            val r = (p shr 16) and 0xFF
            val g = (p shr 8) and 0xFF
            val b = p and 0xFF
            rgbToLab(r, g, b, lab)

            val distance = sqrt(
                (lab[0] - keyLab[0]) * (lab[0] - keyLab[0]) +
                    (lab[1] - keyLab[1]) * (lab[1] - keyLab[1]) +
                    (lab[2] - keyLab[2]) * (lab[2] - keyLab[2]),
            )

            val alpha = smoothstep(tolerance, tolerance + soft, distance)
            if (alpha <= 0f) {
                pixels[i] = 0
                continue
            }

            var rr = r
            var gg = g
            var bb = b
            if (options.spillRemoval > 0f) {
                // Green spill shows up as green exceeding both neighbours.
                val limit = ((r + b) / 2f)
                if (gg > limit) {
                    gg = (gg - (gg - limit) * options.spillRemoval).roundToInt()
                }
            }
            pixels[i] = ((alpha * 255).roundToInt().coerceIn(0, 255) shl 24) or
                (rr shl 16) or (gg shl 8) or bb
        }

        return Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888).also {
            it.setPixels(pixels, 0, width, 0, 0, width, height)
        }
    }

    // -------------------------------------------------------------- auto cut

    fun removeBackground(source: Bitmap, options: Options = Options()): Bitmap {
        val width = source.width
        val height = source.height

        val scale = WORK_EDGE.toFloat() / max(width, height)
        val workW = max(16, (width * scale).roundToInt())
        val workH = max(16, (height * scale).roundToInt())
        val work = Bitmap.createScaledBitmap(source, workW, workH, true)

        val workPixels = IntArray(workW * workH)
        work.getPixels(workPixels, 0, workW, 0, 0, workW, workH)
        if (work !== source) work.recycle()

        // Lab for every working pixel; all the scoring happens here.
        val lab = FloatArray(workPixels.size * 3)
        val tmp = FloatArray(3)
        for (i in workPixels.indices) {
            val p = workPixels[i]
            rgbToLab((p shr 16) and 0xFF, (p shr 8) and 0xFF, p and 0xFF, tmp)
            lab[i * 3] = tmp[0]
            lab[i * 3 + 1] = tmp[1]
            lab[i * 3 + 2] = tmp[2]
        }

        val background = kMeans(collectBorder(lab, workW, workH), CLUSTERS)
        val foreground = kMeans(collectCentre(lab, workW, workH), CLUSTERS)
        if (background.isEmpty() || foreground.isEmpty()) {
            return source.copy(Bitmap.Config.ARGB_8888, false)
        }

        var alpha = FloatArray(workPixels.size)
        for (i in alpha.indices) {
            val l = lab[i * 3]
            val a = lab[i * 3 + 1]
            val b = lab[i * 3 + 2]
            val dBg = nearestDistance(background, l, a, b)
            val dFg = nearestDistance(foreground, l, a, b)
            val total = dBg + dFg
            alpha[i] = if (total < 1e-4f) 0.5f else dBg / total
        }

        // Bias the decision point, then harden it into a matte.
        val threshold = 0.35f + options.sensitivity * 0.3f
        for (i in alpha.indices) {
            alpha[i] = smoothstep(threshold - 0.12f, threshold + 0.12f, alpha[i])
        }

        alpha = smoothMatte(alpha, workW, workH, iterations = 2)
        if (options.keepLargestOnly) alpha = keepLargestComponent(alpha, workW, workH)
        alpha = smoothMatte(alpha, workW, workH, iterations = 1 + (options.feather * 3).toInt())

        return applyMatte(source, alpha, workW, workH)
    }

    /** Border ring: what the background almost certainly looks like. */
    private fun collectBorder(lab: FloatArray, width: Int, height: Int): FloatArray {
        val band = max(2, min(width, height) / 12)
        val samples = ArrayList<Float>()
        for (y in 0 until height) {
            for (x in 0 until width) {
                val isBorder = x < band || y < band || x >= width - band || y >= height - band
                if (!isBorder) continue
                val i = (y * width + x) * 3
                samples.add(lab[i]); samples.add(lab[i + 1]); samples.add(lab[i + 2])
            }
        }
        return samples.toFloatArray()
    }

    /** Central region: where the subject usually is in a phone photo. */
    private fun collectCentre(lab: FloatArray, width: Int, height: Int): FloatArray {
        val x0 = width / 4
        val x1 = width - width / 4
        val y0 = height / 5
        val y1 = height - height / 5
        val samples = ArrayList<Float>()
        for (y in y0 until y1) {
            for (x in x0 until x1) {
                val i = (y * width + x) * 3
                samples.add(lab[i]); samples.add(lab[i + 1]); samples.add(lab[i + 2])
            }
        }
        return samples.toFloatArray()
    }

    /** Plain Lloyd's algorithm over Lab triplets. */
    private fun kMeans(samples: FloatArray, k: Int): FloatArray {
        val count = samples.size / 3
        if (count == 0) return FloatArray(0)
        val clusters = min(k, count)

        val centres = FloatArray(clusters * 3)
        for (c in 0 until clusters) {
            val pick = (c.toLong() * count / clusters).toInt().coerceIn(0, count - 1)
            centres[c * 3] = samples[pick * 3]
            centres[c * 3 + 1] = samples[pick * 3 + 1]
            centres[c * 3 + 2] = samples[pick * 3 + 2]
        }

        val sums = FloatArray(clusters * 3)
        val counts = IntArray(clusters)

        repeat(KMEANS_ITERATIONS) {
            java.util.Arrays.fill(sums, 0f)
            java.util.Arrays.fill(counts, 0)

            for (i in 0 until count) {
                val l = samples[i * 3]
                val a = samples[i * 3 + 1]
                val b = samples[i * 3 + 2]
                var best = 0
                var bestDistance = Float.MAX_VALUE
                for (c in 0 until clusters) {
                    val dl = l - centres[c * 3]
                    val da = a - centres[c * 3 + 1]
                    val db = b - centres[c * 3 + 2]
                    val d = dl * dl + da * da + db * db
                    if (d < bestDistance) {
                        bestDistance = d
                        best = c
                    }
                }
                sums[best * 3] += l
                sums[best * 3 + 1] += a
                sums[best * 3 + 2] += b
                counts[best]++
            }

            for (c in 0 until clusters) {
                if (counts[c] == 0) continue
                centres[c * 3] = sums[c * 3] / counts[c]
                centres[c * 3 + 1] = sums[c * 3 + 1] / counts[c]
                centres[c * 3 + 2] = sums[c * 3 + 2] / counts[c]
            }
        }
        return centres
    }

    private fun nearestDistance(centres: FloatArray, l: Float, a: Float, b: Float): Float {
        var best = Float.MAX_VALUE
        var c = 0
        while (c < centres.size) {
            val dl = l - centres[c]
            val da = a - centres[c + 1]
            val db = b - centres[c + 2]
            val d = dl * dl + da * da + db * db
            if (d < best) best = d
            c += 3
        }
        return sqrt(best)
    }

    /** Separable box blur on the matte; cheap spatial regularisation. */
    private fun smoothMatte(alpha: FloatArray, width: Int, height: Int, iterations: Int): FloatArray {
        if (iterations <= 0) return alpha
        // Each iteration already ping-pongs current -> scratch -> current, so
        // the two buffers must stay distinct for the whole run.
        val current = alpha
        val scratch = FloatArray(alpha.size)

        repeat(iterations) {
            for (y in 0 until height) {
                for (x in 0 until width) {
                    var sum = 0f
                    var n = 0
                    for (dx in -1..1) {
                        val sx = x + dx
                        if (sx < 0 || sx >= width) continue
                        sum += current[y * width + sx]
                        n++
                    }
                    scratch[y * width + x] = sum / n
                }
            }
            for (x in 0 until width) {
                for (y in 0 until height) {
                    var sum = 0f
                    var n = 0
                    for (dy in -1..1) {
                        val sy = y + dy
                        if (sy < 0 || sy >= height) continue
                        sum += scratch[sy * width + x]
                        n++
                    }
                    current[y * width + x] = sum / n
                }
            }
        }
        return current
    }

    /**
     * Keeps the connected blob nearest the centre and drops the rest, which
     * removes speckle from a busy background.
     */
    private fun keepLargestComponent(alpha: FloatArray, width: Int, height: Int): FloatArray {
        val labels = IntArray(alpha.size) { -1 }
        var nextLabel = 0
        var bestLabel = -1
        var bestScore = -1.0

        val stack = ArrayDeque<Int>()
        val centreX = width / 2.0
        val centreY = height / 2.0

        for (start in alpha.indices) {
            if (alpha[start] < 0.5f || labels[start] != -1) continue
            val label = nextLabel++
            var size = 0
            var distanceSum = 0.0

            stack.addLast(start)
            labels[start] = label

            while (stack.isNotEmpty()) {
                val index = stack.removeLast()
                size++
                val x = index % width
                val y = index / width
                distanceSum += abs(x - centreX) / width + abs(y - centreY) / height

                if (x > 0) push(stack, labels, alpha, index - 1, label)
                if (x < width - 1) push(stack, labels, alpha, index + 1, label)
                if (y > 0) push(stack, labels, alpha, index - width, label)
                if (y < height - 1) push(stack, labels, alpha, index + width, label)
            }

            // Prefer big and central over merely big.
            val centrality = 1.0 - (distanceSum / size)
            val score = size * (0.5 + centrality)
            if (score > bestScore) {
                bestScore = score
                bestLabel = label
            }
        }

        if (bestLabel < 0) return alpha
        for (i in alpha.indices) {
            if (labels[i] != bestLabel) alpha[i] = 0f
        }
        return alpha
    }

    private fun push(
        stack: ArrayDeque<Int>,
        labels: IntArray,
        alpha: FloatArray,
        index: Int,
        label: Int,
    ) {
        if (labels[index] == -1 && alpha[index] >= 0.5f) {
            labels[index] = label
            stack.addLast(index)
        }
    }

    /** Upsamples the working matte and applies it to the full-size image. */
    private fun applyMatte(
        source: Bitmap,
        alpha: FloatArray,
        workW: Int,
        workH: Int,
    ): Bitmap {
        val width = source.width
        val height = source.height
        val pixels = IntArray(width * height)
        source.getPixels(pixels, 0, width, 0, 0, width, height)

        for (y in 0 until height) {
            val fy = (y.toFloat() * workH / height).coerceIn(0f, (workH - 1).toFloat())
            val y0 = fy.toInt()
            val y1 = min(y0 + 1, workH - 1)
            val wy = fy - y0

            for (x in 0 until width) {
                val fx = (x.toFloat() * workW / width).coerceIn(0f, (workW - 1).toFloat())
                val x0 = fx.toInt()
                val x1 = min(x0 + 1, workW - 1)
                val wx = fx - x0

                val a00 = alpha[y0 * workW + x0]
                val a10 = alpha[y0 * workW + x1]
                val a01 = alpha[y1 * workW + x0]
                val a11 = alpha[y1 * workW + x1]
                val top = a00 + (a10 - a00) * wx
                val bottom = a01 + (a11 - a01) * wx
                val a = (top + (bottom - top) * wy).coerceIn(0f, 1f)

                val index = y * width + x
                val p = pixels[index]
                pixels[index] = ((a * 255).roundToInt() shl 24) or (p and 0x00FFFFFF)
            }
        }

        return Bitmap.createBitmap(width, height, Bitmap.Config.ARGB_8888).also {
            it.setPixels(pixels, 0, width, 0, 0, width, height)
        }
    }

    // ------------------------------------------------------------- colour

    private fun rgbToLab(r: Int, g: Int, b: Int): FloatArray {
        val out = FloatArray(3)
        rgbToLab(r, g, b, out)
        return out
    }

    /** sRGB to CIE L*a*b* under D65. */
    private fun rgbToLab(r: Int, g: Int, b: Int, out: FloatArray) {
        val rf = linearise(r / 255f)
        val gf = linearise(g / 255f)
        val bf = linearise(b / 255f)

        val x = (rf * 0.4124f + gf * 0.3576f + bf * 0.1805f) / 0.95047f
        val y = rf * 0.2126f + gf * 0.7152f + bf * 0.0722f
        val z = (rf * 0.0193f + gf * 0.1192f + bf * 0.9505f) / 1.08883f

        val fx = pivot(x)
        val fy = pivot(y)
        val fz = pivot(z)

        out[0] = 116f * fy - 16f
        out[1] = 500f * (fx - fy)
        out[2] = 200f * (fy - fz)
    }

    private fun linearise(c: Float): Float =
        if (c <= 0.04045f) c / 12.92f else Math.pow(((c + 0.055f) / 1.055f).toDouble(), 2.4).toFloat()

    private fun pivot(t: Float): Float =
        if (t > 0.008856f) cbrt(t.toDouble()).toFloat() else (7.787f * t + 16f / 116f)

    private fun smoothstep(edge0: Float, edge1: Float, x: Float): Float {
        if (edge1 <= edge0) return if (x < edge0) 0f else 1f
        val t = ((x - edge0) / (edge1 - edge0)).coerceIn(0f, 1f)
        return t * t * (3f - 2f * t)
    }
}
