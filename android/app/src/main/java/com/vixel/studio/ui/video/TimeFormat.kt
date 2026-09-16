package com.vixel.studio.ui.video

/**
 * Timeline clock, as mm:ss.
 *
 * Fixed width on purpose: a readout that switches between "9.4s" and "1:09"
 * changes length as it counts, which makes the surrounding row twitch while
 * the video plays.
 */
fun formatTime(us: Long): String {
    val totalSeconds = (us.coerceAtLeast(0L)) / 1_000_000
    return "%02d:%02d".format(totalSeconds / 60, totalSeconds % 60)
}

/** Same clock with tenths, for the playhead, where a frame's worth matters. */
fun formatTimePrecise(us: Long): String {
    val safe = us.coerceAtLeast(0L)
    val totalSeconds = safe / 1_000_000
    val tenths = (safe % 1_000_000) / 100_000
    return "%02d:%02d.%d".format(totalSeconds / 60, totalSeconds % 60, tenths)
}
