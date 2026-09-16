/// Timeline clock, as mm:ss.
///
/// Fixed width on purpose: a readout that switches between "9.4s" and "1:09"
/// changes length as it counts, which makes the surrounding row twitch while
/// the video plays.
String formatTime(int us) {
  final totalSeconds = (us < 0 ? 0 : us) ~/ 1000000;
  final minutes = (totalSeconds ~/ 60).toString().padLeft(2, '0');
  final seconds = (totalSeconds % 60).toString().padLeft(2, '0');
  return '$minutes:$seconds';
}

/// The same clock with tenths, for the playhead and for the short durations in
/// the panels, where whole seconds would hide the value being dragged.
String formatTimePrecise(int us) {
  final safe = us < 0 ? 0 : us;
  final totalSeconds = safe ~/ 1000000;
  final minutes = (totalSeconds ~/ 60).toString().padLeft(2, '0');
  final seconds = (totalSeconds % 60).toString().padLeft(2, '0');
  final tenths = (safe % 1000000) ~/ 100000;
  return '$minutes:$seconds.$tenths';
}
