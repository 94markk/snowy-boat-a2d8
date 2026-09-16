package com.delicat.studio.ui.theme

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.AspectRatio
import androidx.compose.material.icons.rounded.AutoAwesome
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.ContentCut
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Download
import androidx.compose.material.icons.rounded.Image
import androidx.compose.material.icons.rounded.KeyboardArrowLeft
import androidx.compose.material.icons.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Movie
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Redo
import androidx.compose.material.icons.rounded.Refresh
import androidx.compose.material.icons.rounded.RotateRight
import androidx.compose.material.icons.rounded.Speed
import androidx.compose.material.icons.rounded.Tune
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material.icons.rounded.VolumeOff
import androidx.compose.material.icons.rounded.VolumeUp
import androidx.compose.ui.graphics.vector.ImageVector

/**
 * Every icon the app draws, named once.
 *
 * Collected here for a practical reason: this module is the only part of the
 * project that cannot be compiled outside CI, and a mistyped icon name is a
 * compile error. One list is one place to check and one round trip to fix,
 * rather than a scavenger hunt through twenty files.
 */
object Glyphs {
    val Add: ImageVector = Icons.Rounded.Add
    val Close: ImageVector = Icons.Rounded.Close
    val Check: ImageVector = Icons.Rounded.Check
    val Play: ImageVector = Icons.Rounded.PlayArrow
    val Pause: ImageVector = Icons.Rounded.Pause
    val Split: ImageVector = Icons.Rounded.ContentCut
    val Duplicate: ImageVector = Icons.Rounded.ContentCopy
    val Delete: ImageVector = Icons.Rounded.Delete
    val Undo: ImageVector = Icons.Rounded.Undo
    val Redo: ImageVector = Icons.Rounded.Redo
    val Reset: ImageVector = Icons.Rounded.Refresh
    val Adjust: ImageVector = Icons.Rounded.Tune
    val Looks: ImageVector = Icons.Rounded.AutoAwesome
    val Speed: ImageVector = Icons.Rounded.Speed
    val VolumeOn: ImageVector = Icons.Rounded.VolumeUp
    val VolumeOff: ImageVector = Icons.Rounded.VolumeOff
    val Canvas: ImageVector = Icons.Rounded.AspectRatio
    val Rotate: ImageVector = Icons.Rounded.RotateRight
    val Transition: ImageVector = Icons.Rounded.Movie
    val Export: ImageVector = Icons.Rounded.Download
    val Photo: ImageVector = Icons.Rounded.Image
    val Earlier: ImageVector = Icons.Rounded.KeyboardArrowLeft
    val Later: ImageVector = Icons.Rounded.KeyboardArrowRight
}
