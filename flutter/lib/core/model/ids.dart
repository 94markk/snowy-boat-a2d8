import 'dart:math';

final _random = Random();

/// Short unique id for model objects.
///
/// Identity only ever has to hold within one project file, so a UUID would be
/// more machinery than the job needs; this is enough to make a collision
/// something you would have to try for.
String newId() {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  final buffer = StringBuffer();
  for (var i = 0; i < 16; i++) {
    buffer.write(alphabet[_random.nextInt(alphabet.length)]);
  }
  return buffer.toString();
}
