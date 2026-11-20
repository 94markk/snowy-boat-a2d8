import 'dart:ui';

import 'package:delicat_studio/core/creations.dart';
import 'package:delicat_studio/core/recommendations.dart';
import 'package:delicat_studio/features/editor/editor_model.dart';
import 'package:delicat_studio/features/video/video_model.dart';
import 'package:flutter_test/flutter_test.dart';

VClip clip(String id, {double inMs = 0, double outMs = 4000, double speed = 1, bool transitionIn = false, bool image = false}) => VClip(
      id: id,
      path: '/v/$id.mp4',
      isImage: image,
      sourceMs: image ? 60000 : 10000,
      width: 1920,
      height: 1080,
      inMs: inMs,
      outMs: outMs,
      speed: speed,
      transitionIn: transitionIn,
    );

void main() {
  group('VideoDoc timing', () {
    final doc = VideoDoc(clips: [clip('a'), clip('b', inMs: 1000, outMs: 9000, speed: 2), clip('c', outMs: 3000)]);

    test('durations account for trims and speed', () {
      expect(doc.clips[1].durationMs, 4000);
      expect(doc.durationMs, 11000);
      expect(doc.startOf(2), 8000);
    });

    test('indexAt maps timeline time to clips', () {
      expect(doc.indexAt(0), 0);
      expect(doc.indexAt(3999), 0);
      expect(doc.indexAt(4000), 1);
      expect(doc.indexAt(10999), 2);
      expect(doc.indexAt(99999), 2);
    });

    test('source time follows speed', () {
      expect(doc.clips[1].sourceAt(1000), 3000);
    });

    test('split keeps total duration and continuity', () {
      final s = doc.splitAt(1, 6000, 'b2')!;
      expect(s.clips.length, 4);
      expect(s.durationMs, closeTo(doc.durationMs, 1e-9));
      expect(s.clips[1].outMs, s.clips[2].inMs);
      expect(s.clips[1].outMs, 5000);
      expect(doc.splitAt(1, 4050, 'x'), isNull, reason: 'too close to the edge');
    });
  });

  group('Fades and transitions', () {
    test('a « Fondu » dips both sides of the cut to black', () {
      final doc = VideoDoc(clips: [clip('a'), clip('b', transitionIn: true)]);
      expect(doc.fadeAt(2000), 1);
      expect(doc.fadeAt(4000 - kTransitionHalfMs / 2), closeTo(0.5, 1e-9));
      expect(doc.fadeAt(4000), 0);
      expect(doc.fadeAt(4000 + kTransitionHalfMs), 1);
      final spec = doc.timelineSpec();
      expect(spec[0]['fadeOutMs'], kTransitionHalfMs.round());
      expect(spec[1]['fadeInMs'], kTransitionHalfMs.round());
    });

    test('the first clip ignores a transition flag', () {
      final doc = VideoDoc(clips: [clip('a', transitionIn: true)]);
      expect(doc.fadeAt(10), 1);
    });

    test('zoom motion grows across the clip', () {
      final doc = VideoDoc(clips: [clip('a').copyWith(motion: VMotion.zoomIn)]);
      expect(doc.zoomAt(0), 1);
      expect(doc.zoomAt(2000), closeTo(1.09, 1e-9));
    });
  });

  group('Overlays and export', () {
    test('overlay segments cover each text exactly', () {
      final t1 = VText(layer: const EditorLayer(id: 't1', text: 'Promo'), startMs: 1000, endMs: 3000);
      final t2 = VText(layer: const EditorLayer(id: 't2', text: '-20 %'), startMs: 2000, endMs: 5000);
      final doc = VideoDoc(clips: [clip('a', outMs: 6000)], texts: [t1, t2]);
      final segs = doc.overlaySegments();
      expect(segs.map((s) => (s.$1, s.$2, s.$3.length)).toList(), [(1000.0, 2000.0, 1), (2000.0, 3000.0, 2), (3000.0, 5000.0, 1)]);
    });

    test('a logo makes the overlay span the whole video', () {
      final doc = VideoDoc(clips: [clip('a')], logo: const VLogo(path: '/logo.png'));
      expect(doc.overlaySegments().map((s) => (s.$1, s.$2)).toList(), [(0.0, 4000.0)]);
    });

    test('texts are clamped when the video gets shorter', () {
      final doc = VideoDoc(
        clips: [clip('a', outMs: 2000)],
        texts: [
          VText(layer: const EditorLayer(id: 'x'), startMs: 1000, endMs: 5000),
          VText(layer: const EditorLayer(id: 'y'), startMs: 4000, endMs: 5000),
        ],
      ).clampTexts();
      expect(doc.texts.length, 1);
      expect(doc.texts.first.endMs, 2000);
    });

    test('export sizes are even and keep the ratio', () {
      expect(exportSize(9 / 16, 1080), const Size(1080, 1920));
      expect(exportSize(16 / 9, 720), const Size(1280, 720));
      final odd = exportSize(4 / 3, 721);
      expect(odd.width % 2, 0);
    });

    test('original ratio follows the first clip, rotated clips swap it', () {
      expect(VideoDoc(clips: [clip('a')]).aspect, closeTo(16 / 9, 1e-9));
      expect(VideoDoc(clips: [clip('a').copyWith(rotation: 90)]).aspect, closeTo(9 / 16, 1e-9));
      expect(VideoDoc(clips: [clip('a')], ratio: VRatio.square).aspect, 1);
    });
  });

  group('Recommendations', () {
    final june = DateTime(2026, 6, 10);

    test('new users are offered the video editor first', () {
      final r = recommend(uses: const {}, made: const {}, now: june);
      expect(r.first.action, RecAction.video);
      expect(r.length, 4);
    });

    test('heavy use sinks a tool; detouring boosts stickers', () {
      final r = recommend(uses: const {'video': 9, 'cutout': 3}, made: const {CreationKind.cutout: 2}, now: june);
      expect(r.first.action, RecAction.sticker);
      expect(r.first.reason, contains('détoures'));
      expect(r.any((x) => x.action == RecAction.logoVideo), isTrue);
    });

    test('seasonal ideas appear only in season', () {
      final dec = recommend(uses: const {}, made: const {}, now: DateTime(2026, 12, 20));
      expect(dec.first.action, RecAction.polaroid);
      expect(recommend(uses: const {}, made: const {}, now: june).any((x) => x.action == RecAction.polaroid), isFalse);
      expect(recommend(uses: const {}, made: const {}, now: DateTime(2026, 5, 10)).first.action, RecAction.flagDay);
    });
  });
}
