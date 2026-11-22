import 'dart:convert';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:delicat_studio/core/music_library.dart';
import 'package:flutter_test/flutter_test.dart';

String _tag(Uint8List b, int offset) => ascii.decode(b.sublist(offset, offset + 4));

double _db(double amplitude) => 20 * math.log(amplitude) / math.ln10;

void main() {
  test('moods have French labels and short sentence-case hints', () {
    expect(MusicMood.values, hasLength(6));
    for (final mood in MusicMood.values) {
      expect(mood.label, isNotEmpty);
      expect(mood.hint, isNotEmpty);
      expect(mood.hint, isNot(mood.hint.toUpperCase()), reason: 'no all caps: ${mood.hint}');
      expect(mood.hint[0], mood.hint[0].toUpperCase());
      expect(mood.hint, isNot(contains('·')));
      expect(mood.hint, isNot(contains('«')));
      expect(mood.hint.length, lessThan(60));
      final seconds = MusicLibrary.lengthOf(mood).inMicroseconds / 1e6;
      expect(seconds, inInclusiveRange(15.5, 24.5), reason: mood.name);
    }
  });

  for (final mood in MusicMood.values) {
    test('${mood.name} loop is a valid, clean, seamless WAV', () {
      const sampleRate = 44100;
      final watch = Stopwatch()..start();
      final wav = synthesizeLoop(mood, sampleRate: sampleRate);
      watch.stop();
      expect(watch.elapsedMilliseconds, lessThan(1500), reason: 'synthesis took ${watch.elapsedMilliseconds} ms');

      // Header.
      final d = ByteData.sublistView(wav);
      expect(_tag(wav, 0), 'RIFF');
      expect(d.getUint32(4, Endian.little), wav.length - 8);
      expect(_tag(wav, 8), 'WAVE');
      expect(_tag(wav, 12), 'fmt ');
      expect(d.getUint32(16, Endian.little), 16);
      expect(d.getUint16(20, Endian.little), 1, reason: 'PCM');
      expect(d.getUint16(22, Endian.little), 1, reason: 'mono');
      expect(d.getUint32(24, Endian.little), sampleRate);
      expect(d.getUint32(28, Endian.little), sampleRate * 2, reason: 'byte rate');
      expect(d.getUint16(32, Endian.little), 2, reason: 'block align');
      expect(d.getUint16(34, Endian.little), 16, reason: 'bits per sample');
      expect(_tag(wav, 36), 'data');
      final dataSize = d.getUint32(40, Endian.little);
      expect(wav.length, 44 + dataSize);

      // Length matches lengthOf (to the nearest sample).
      final samples = dataSize ~/ 2;
      final expected = MusicLibrary.lengthOf(mood).inMicroseconds * sampleRate / 1e6;
      expect((samples - expected).abs(), lessThanOrEqualTo(1.0));

      // Level: peak near -1 dBFS, never clipping, and not silent.
      final pcm = Int16List(samples);
      for (var i = 0; i < samples; i++) {
        pcm[i] = d.getInt16(44 + 2 * i, Endian.little);
      }
      var peak = 0;
      var energy = 0.0;
      for (final s in pcm) {
        peak = math.max(peak, s.abs());
        energy += s * s;
      }
      final peakDb = _db(peak / 32768);
      expect(peakDb, inInclusiveRange(-6.0, -0.5));
      final rmsDb = _db(math.sqrt(energy / samples) / 32768);
      expect(rmsDb, greaterThan(-30.0));

      // Seamless loop: the wrap from the last to the first sample is small.
      expect((pcm.last - pcm.first).abs() / 32768, lessThan(0.02));

      // Deterministic.
      expect(synthesizeLoop(mood, sampleRate: sampleRate), orderedEquals(wav));
    });
  }
}
