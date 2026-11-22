import 'dart:async';
import 'dart:convert';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:delicat_studio/core/brand.dart';
import 'package:delicat_studio/core/drafts.dart';
import 'package:delicat_studio/core/system.dart';
import 'package:delicat_studio/features/collage/collage_screen.dart';
import 'package:delicat_studio/features/cutout/mask_ops.dart';
import 'package:delicat_studio/features/editor/editor_model.dart';
import 'package:delicat_studio/features/templates/templates.dart';
import 'package:delicat_studio/features/video/video_model.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:material_ui/material_ui.dart';

Future<ui.Image> _image(int w, int h) async {
  final rec = ui.PictureRecorder();
  Canvas(rec).drawRect(Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble()), Paint()..color = const Color(0xFF336699));
  final pic = rec.endRecording();
  final img = await pic.toImage(w, h);
  pic.dispose();
  return img;
}

VClip _clip(String id, {String path = '/v/a.mp4', double inMs = 0, double outMs = 4000, double speed = 1}) => VClip(
      id: id,
      path: path,
      isImage: false,
      sourceMs: 10000,
      width: 1080,
      height: 1920,
      inMs: inMs,
      outMs: outMs,
      speed: speed,
    );

void main() {
  group('Prices', () {
    test('format as « HTG 1 500,00 » with no-break spaces', () {
      expect(formatHtg('1500'), 'HTG 1 500,00');
      expect(formatHtg('1 500,5'), 'HTG 1 500,50');
      expect(formatHtg('250.75'), 'HTG 250,75');
      expect(formatHtg('1250000'), 'HTG 1 250 000,00');
      expect(formatHtg('HTG 99'), 'HTG 99,00');
    });

    test('reject empty, zero, negative and garbled amounts', () {
      for (final bad in ['', '0', '-5', 'abc', '1.2.3', '12,5,0']) {
        expect(formatHtg(bad), isNull, reason: bad);
      }
    });
  });

  group('Drafts serialisation', () {
    test('a photo edit survives a JSON round trip', () {
      const doc = EditorDoc(
        quarterTurns: 1,
        flipH: true,
        straighten: 4.5,
        crop: Rect.fromLTRB(0.1, 0.2, 0.9, 0.8),
        adjust: {Adj.contrast: 0.3, Adj.vignette: 0.5},
        style: 'jacmel',
        styleAmount: 0.6,
        layers: [
          EditorLayer(id: 't', text: 'Promo', font: 'BebasNeue', color: Color(0xFFC8358F), look: TextLook.label, pos: Offset(0.3, 0.4), size: 0.1, rotation: 0.2),
          EditorLayer(id: 'q', qr: 'https://delicastoreha.com', size: 0.25),
          EditorLayer(id: 'p', image: '/x/produit.png', aspect: 0.75, size: 0.5),
          EditorLayer(id: 'e', emoji: '🔥'),
        ],
        strokes: [DrawStroke([Offset(0.1, 0.1), Offset(0.2, 0.3)], Color(0xFF000000), 0.01, false)],
        frame: FrameStyle.polaroid,
        frameColor: Color(0xFFFFF4DE),
      );
      final back = EditorDoc.fromJson(jsonDecode(jsonEncode(doc.toJson())) as Map<String, dynamic>);
      expect(back.quarterTurns, 1);
      expect(back.flipH, isTrue);
      expect(back.crop, doc.crop);
      expect(back.adjust, doc.adjust);
      expect(back.style, 'jacmel');
      expect(back.frame, FrameStyle.polaroid);
      expect(back.frameColor, doc.frameColor);
      expect(back.layers.map((l) => l.id), ['t', 'q', 'p', 'e']);
      expect(back.layers[0].look, TextLook.label);
      expect(back.layers[0].pos, const Offset(0.3, 0.4));
      expect(back.layers[1].isQr, isTrue);
      expect(back.layers[2].isPicture, isTrue);
      expect(back.layers[2].aspect, 0.75);
      expect(back.layers[3].isSticker, isTrue);
      expect(back.strokes.single.points.last, const Offset(0.2, 0.3));
    });

    test('a montage survives a JSON round trip, voices and music included', () {
      final doc = VideoDoc(
        clips: [_clip('a').copyWith(adjust: const {Adj.warmth: 0.2}, motion: VMotion.zoomIn, transitionIn: true)],
        texts: [const VText(layer: EditorLayer(id: 't', text: 'Salut'), startMs: 500, endMs: 2500)],
        voices: const [VVoice(id: 'v', path: '/d/voice.m4a', startMs: 1000, durationMs: 1500, volume: 0.8)],
        music: const VMusic(path: '/m/kompa.wav', name: 'Kompa', sourceMs: 20000, inMs: 1000, volume: 0.6, loop: false),
        logo: const VLogo(path: '/b/logo.png', corner: Alignment.topLeft, size: 0.25),
        ratio: VRatio.story,
        fill: true,
      );
      final back = VideoDoc.fromJson(jsonDecode(jsonEncode(doc.toJson())) as Map<String, dynamic>);
      expect(back.durationMs, doc.durationMs);
      expect(back.clips.single.adjust, const {Adj.warmth: 0.2});
      expect(back.clips.single.motion, VMotion.zoomIn);
      expect(back.texts.single.layer.text, 'Salut');
      expect(back.voices.single.startMs, 1000);
      expect(back.music!.loop, isFalse);
      expect(back.logo!.corner, Alignment.topLeft);
      expect(back.ratio, VRatio.story);
      expect(back.fill, isTrue);
      expect(back.mediaPaths, {'/v/a.mp4', '/d/voice.m4a', '/m/kompa.wav', '/b/logo.png'});
    });

    test('remap rewrites every media path and nothing else', () {
      final doc = VideoDoc(
        clips: [_clip('a')],
        texts: const [VText(layer: EditorLayer(id: 'p', image: '/tmp/p.png'), startMs: 0, endMs: 1000)],
        voices: const [VVoice(id: 'v', path: '/tmp/v.m4a', startMs: 0, durationMs: 500)],
      );
      final moved = doc.remap({'/v/a.mp4': '/d/a.mp4', '/tmp/p.png': '/d/p.png', '/tmp/v.m4a': '/d/v.m4a'});
      expect(moved.mediaPaths, {'/d/a.mp4', '/d/p.png', '/d/v.m4a'});
      expect(moved.durationMs, doc.durationMs);
      expect(moved.clips.single.id, 'a');
    });

    test('voices after the end stay saved but are not exported', () {
      final doc = VideoDoc(
        clips: [_clip('a')],
        voices: const [
          VVoice(id: 'in', path: '/a', startMs: 1000, durationMs: 1000),
          VVoice(id: 'out', path: '/b', startMs: 3990, durationMs: 1000),
        ],
      );
      expect(doc.audibleVoices.map((v) => v.id), ['in']);
    });

    test('brand kit round trip keeps colours, link and logo', () {
      const kit = BrandKit(name: 'Boutique', logoPath: '/b/l.png', primary: Color(0xFF2BB673), accent: Color(0xFFF5B400), font: 'Playfair', link: 'exemple.ht', whatsapp: '+509 1234 5678', autoLogo: false);
      final back = BrandKit.fromJson(jsonDecode(jsonEncode(kit.toJson())) as Map<String, dynamic>);
      expect(back.name, 'Boutique');
      expect(back.primary, kit.primary);
      expect(back.font, 'Playfair');
      expect(back.url, 'https://exemple.ht');
      expect(back.autoLogo, isFalse);
    });

    test('brand colours come first in the swatches, without duplicates', () {
      final list = brandSwatches(const [Color(0xFFFFFFFF), Color(0xFF4B3FE0)]);
      expect(list.first, brand.kit.primary);
      expect(list.where((c) => c.toARGB32() == 0xFF4B3FE0).length, 1);
    });
  });

  group('Autosave', () {
    test('coalesces bursts of edits into one save', () async {
      var saves = 0;
      final a = Autosaver(() async => saves++, delay: const Duration(milliseconds: 60));
      for (var i = 0; i < 10; i++) {
        a.schedule();
        await Future<void>.delayed(const Duration(milliseconds: 5));
      }
      expect(saves, 0);
      await Future<void>.delayed(const Duration(milliseconds: 200));
      expect(saves, 1);
      a.dispose();
    });

    test('a flush during a save runs one more save afterwards', () async {
      var saves = 0;
      final gate = Completer<void>();
      final a = Autosaver(() async {
        saves++;
        if (saves == 1) await gate.future;
      });
      final first = a.flush();
      await a.flush();
      gate.complete();
      await first;
      expect(saves, 2);
    });

    test('a failing save never throws to the editor', () async {
      final a = Autosaver(() async => throw StateError('disk full'));
      await a.flush();
    });
  });

  group('Collage crop window', () {
    test('never throws, whatever the photo and cell sizes', () async {
      final rnd = math.Random(3);
      final img = await _image(97, 61);
      for (var i = 0; i < 4000; i++) {
        final cell = CollageCell(img, '/p.jpg')
          ..zoom = rnd.nextBool() ? 1 : 1 + rnd.nextDouble() * 4
          ..focus = Offset(rnd.nextDouble(), rnd.nextDouble());
        final dst = Rect.fromLTWH(0, 0, 1 + rnd.nextDouble() * 900, 1 + rnd.nextDouble() * 900);
        final r = srcRectFor(cell, dst);
        expect(r.width, lessThanOrEqualTo(img.width + 1e-9));
        expect(r.height, lessThanOrEqualTo(img.height + 1e-9));
        expect(r.left, greaterThanOrEqualTo(-1e-6));
        expect(r.right, lessThanOrEqualTo(img.width + 1e-6));
      }
      img.dispose();
    });
  });

  group('Colour erase memory', () {
    test('bit packing round-trips and merging keeps both erases', () {
      final a = Uint8List(1001)..[0] = 1..[7] = 1..[1000] = 1;
      final b = Uint8List(1001)..[8] = 1..[999] = 1;
      final pa = packBits(a);
      expect(pa.length, 126);
      expect(unpackBits(pa, 1001), a);
      final merged = mergePacked(pa, b);
      final flags = unpackBits(merged, 1001);
      expect([for (var i = 0; i < flags.length; i++) if (flags[i] != 0) i], [0, 7, 8, 999, 1000]);
      // The previous step stays untouched, so undo still works.
      expect(unpackBits(pa, 1001), a);
    });
  });

  group('QR codes and pictures', () {
    test('QR layers are square and encode links', () {
      const l = EditorLayer(id: 'q', qr: 'https://wa.me/50912345678', size: 0.3);
      final half = layerHalfSize(l, 1000);
      expect(half.width, 150);
      expect(half.height, 150);
      expect(qrFor(l.qr!), isNotNull);
    });

    test('picture layers keep their aspect ratio', () {
      const l = EditorLayer(id: 'p', image: '/x.png', aspect: 2, size: 0.5);
      final half = layerHalfSize(l, 1000);
      expect(half.width, 250);
      expect(half.height, 125);
    });

    test('text too long for a QR code is refused instead of crashing', () {
      expect(qrFor('x' * 5000), isNull);
    });
  });

  group('Templates', () {
    const kit = BrandKit(whatsapp: '+509 3700 0000');

    test('no price typed means no price on the poster', () {
      final spec = PosterSpec(template: kPromoTemplates.first, product: '572 diamants');
      final texts = [for (final (l, _) in posterLayers(spec, kit)) l.text];
      expect(texts.any((t) => t.startsWith('HTG')), isFalse);
      expect(texts, contains('572 diamants'));
      expect(texts, contains('Commande sur WhatsApp'));
    });

    test('a typed price is formatted, and Kreyòl copy is used', () {
      final spec = PosterSpec(template: kPromoTemplates.first, lang: PromoLang.ht, product: '572 dyaman', price: '1500');
      final layers = [for (final (l, _) in posterLayers(spec, kit)) l];
      expect(layers.map((l) => l.text), containsAll(['Dyaman Free Fire', 'HTG 1 500,00', 'Kòmande sou WhatsApp']));
      expect(layers.where((l) => l.isQr).single.qr, 'https://wa.me/50937000000');
    });

    test('holiday wishes replace the headline', () {
      final t = kPromoTemplates.firstWhere((t) => t.id == 'fetes');
      final spec = PosterSpec(template: t, product: 'Bonne année');
      final texts = [for (final (l, _) in posterLayers(spec, const BrandKit())) l.text];
      expect(texts, contains('Bonne année'));
      expect(texts, isNot(contains('Joyeux Noël')));
    });

    test('both languages offer the same quick choices', () {
      for (final t in kPromoTemplates) {
        expect(t.options[PromoLang.fr]?.length, t.options[PromoLang.ht]?.length, reason: t.id);
        expect(t.headline.keys, PromoLang.values, reason: t.id);
      }
    });

    test('every layer stays inside the poster in every format', () {
      for (final t in kPromoTemplates) {
        for (final f in PosterFormat.values) {
          final spec = PosterSpec(template: t, format: f, product: 'Produit', price: '1500');
          for (final (l, _) in posterLayers(spec, kit)) {
            expect(l.pos.dx, inInclusiveRange(0, 1), reason: '${t.id} ${f.name}');
            expect(l.pos.dy, inInclusiveRange(0, 1), reason: '${t.id} ${f.name}');
          }
        }
      }
    });

    test('the video version shows texts one after another, within the clip', () {
      final spec = PosterSpec(template: kPromoTemplates.first, product: '110 diamants', price: '250');
      final doc = posterVideo(spec, kit, backgroundPath: '/tmp/bg.png');
      expect(doc.durationMs, 8000);
      expect(doc.ratio, VRatio.story);
      expect(doc.texts.every((t) => t.startMs < t.endMs && t.endMs <= doc.durationMs), isTrue);
      expect(doc.texts.map((t) => t.startMs).toSet().length, greaterThan(2));
    });
  });

  group('Device profile', () {
    test('budget phones get lighter defaults', () {
      expect(const DeviceProfile(totalRamMb: 2900).isBudget, isTrue);
      expect(const DeviceProfile(totalRamMb: 6000).isBudget, isFalse);
      expect(const DeviceProfile(totalRamMb: 6000, lowRam: true).isBudget, isTrue);
      expect(const DeviceProfile().isBudget, isFalse);
    });
  });

  group('Video editing bounds', () {
    test('clamping texts after a clip gets shorter', () {
      final doc = VideoDoc(
        clips: [_clip('a')],
        texts: const [
          VText(layer: EditorLayer(id: 'x', text: 'a'), startMs: 3000, endMs: 4000),
          VText(layer: EditorLayer(id: 'y', text: 'b'), startMs: 1000, endMs: 4000),
        ],
      );
      final shorter = doc.replaceClip(doc.clips.single.copyWith(outMs: 2000)).clampTexts();
      expect(shorter.texts.map((t) => t.id), ['y']);
      expect(shorter.texts.single.endMs, 2000);
    });
  });
}
