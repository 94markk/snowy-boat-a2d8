import 'dart:io';
import 'dart:isolate';
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:google_mlkit_selfie_segmentation/google_mlkit_selfie_segmentation.dart';
import 'package:google_mlkit_subject_segmentation/google_mlkit_subject_segmentation.dart';

import '../../core/image_io.dart';
import 'mask_ops.dart';

enum SegEngine {
  /// Any subject: people, products, pets, objects (Google Play services module).
  subject,

  /// People only, bundled with the app. Used when the subject module is unavailable.
  selfie,
}

class SegResult {
  const SegResult(this.confidence, this.engine);
  final Float32List confidence;
  final SegEngine engine;
}

class SegmentationException implements Exception {
  const SegmentationException(this.message);
  final String message;
  @override
  String toString() => message;
}

/// On-device subject detection. Tries ML Kit subject segmentation first and
/// falls back to selfie segmentation (people) if the module isn't ready.
Future<SegResult> segmentPhoto(ui.Image image) async {
  final w = image.width;
  final h = image.height;
  final path = await ImageIO.writeAnalysisCopy(image);
  try {
    final input = InputImage.fromFilePath(path);
    try {
      final seg = SubjectSegmenter(
        options: SubjectSegmenterOptions(
          enableForegroundBitmap: false,
          enableForegroundConfidenceMask: true,
          enableMultipleSubjects: SubjectResultOptions(enableConfidenceMask: false, enableSubjectBitmap: false),
        ),
      );
      try {
        final r = await seg.processImage(input);
        final m = r.foregroundConfidenceMask;
        if (m != null && m.length == w * h) {
          return SegResult(m is Float32List ? m : Float32List.fromList(m), SegEngine.subject);
        }
      } finally {
        await seg.close();
      }
    } catch (_) {
      // Module still downloading, no Play services, or unsupported device: fall back.
    }

    final selfie = SelfieSegmenter(mode: SegmenterMode.single, enableRawSizeMask: false);
    try {
      final m = await selfie.processImage(input);
      if (m != null && m.confidences.isNotEmpty) {
        final conf = m.width == w && m.height == h
            ? Float32List.fromList(m.confidences)
            : await Isolate.run(() => resampleMask(m.confidences, m.width, m.height, w, h));
        return SegResult(conf, SegEngine.selfie);
      }
    } catch (_) {
      // handled below
    } finally {
      await selfie.close();
    }
    throw const SegmentationException(
      'Le sujet n’a pas été détecté. Vérifie que Google Play services est à jour, ou détoure à la main avec le pinceau et la gomme couleur.',
    );
  } finally {
    try {
      await File(path).delete();
    } catch (_) {}
  }
}
