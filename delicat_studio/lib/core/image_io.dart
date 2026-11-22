import 'dart:async';
import 'dart:io';
import 'dart:isolate';
import 'dart:math' as math;
import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:gal/gal.dart';
import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import 'settings.dart';

/// A decoded photo plus the file it came from.
class Photo {
  Photo(this.path, this.image);
  final String path;
  final ui.Image image;
  int get width => image.width;
  int get height => image.height;
}

abstract final class ImageIO {
  static final _picker = ImagePicker();

  /// Collages decode at most this size: 9 photos at full import quality would not fit
  /// in the memory of a budget phone, and each cell is a fraction of the output.
  static const collageSide = 1440;

  static Future<Photo?> pick(ImageSource source, {int? maxSide}) async {
    final side = math.min(maxSide ?? settings.importQuality.maxSide, settings.importQuality.maxSide).toDouble();
    final x = await _picker.pickImage(
      source: source,
      maxWidth: side,
      maxHeight: side,
      imageQuality: 95,
      requestFullMetadata: false,
    );
    if (x == null) return null;
    return load(x.path, maxSide: maxSide);
  }

  static Future<List<Photo>> pickMany({int limit = 9, int? maxSide}) async {
    if (limit < 1) return const [];
    if (limit == 1) {
      // pickMultiImage requires a limit of at least 2.
      final one = await pick(ImageSource.gallery, maxSide: maxSide);
      return one == null ? const [] : [one];
    }
    final side = math.min(maxSide ?? settings.importQuality.maxSide, settings.importQuality.maxSide).toDouble();
    final files = await _picker.pickMultiImage(
      maxWidth: side,
      maxHeight: side,
      imageQuality: 92,
      limit: limit,
      requestFullMetadata: false,
    );
    final out = <Photo>[];
    try {
      for (final f in files.take(limit)) {
        out.add(await load(f.path, maxSide: maxSide));
      }
    } catch (_) {
      for (final p in out) {
        p.image.dispose();
      }
      rethrow;
    }
    return out;
  }

  static Future<Photo> load(String path, {int? maxSide}) async {
    final bytes = await File(path).readAsBytes();
    return Photo(path, await decode(bytes, maxSide: math.min(maxSide ?? settings.importQuality.maxSide, settings.importQuality.maxSide)));
  }

  /// Decodes (EXIF orientation applied by the engine) and downsizes to [maxSide].
  static Future<ui.Image> decode(Uint8List bytes, {int? maxSide}) async {
    final buffer = await ui.ImmutableBuffer.fromUint8List(bytes);
    final desc = await ui.ImageDescriptor.encoded(buffer);
    int? tw;
    int? th;
    final longest = math.max(desc.width, desc.height);
    if (maxSide != null && longest > maxSide) {
      final s = maxSide / longest;
      tw = (desc.width * s).round();
      th = (desc.height * s).round();
    }
    final codec = await desc.instantiateCodec(targetWidth: tw, targetHeight: th);
    final frame = await codec.getNextFrame();
    codec.dispose();
    desc.dispose();
    buffer.dispose();
    return frame.image;
  }

  /// Straight (unpremultiplied) RGBA bytes.
  static Future<Uint8List> rgba(ui.Image image) async {
    final bd = await image.toByteData(format: ui.ImageByteFormat.rawStraightRgba);
    return bd!.buffer.asUint8List();
  }

  static Future<ui.Image> fromRgba(Uint8List pixels, int width, int height) {
    final c = Completer<ui.Image>();
    ui.decodeImageFromPixels(pixels, width, height, ui.PixelFormat.rgba8888, c.complete);
    return c.future;
  }

  /// Records [paint] into a [width]×[height] image.
  static Future<ui.Image> render(int width, int height, void Function(ui.Canvas canvas) paint) async {
    final rec = ui.PictureRecorder();
    final canvas = ui.Canvas(rec, ui.Rect.fromLTWH(0, 0, width.toDouble(), height.toDouble()));
    paint(canvas);
    final pic = rec.endRecording();
    final out = await pic.toImage(width, height);
    pic.dispose();
    return out;
  }

  /// Writes an orientation-free 24-bit BMP for ML Kit, so mask and image sizes match.
  static Future<String> writeAnalysisCopy(ui.Image image) async {
    final pixels = await rgba(image);
    final w = image.width;
    final h = image.height;
    final bmp = await Isolate.run(() => _bmp24(pixels, w, h));
    final dir = await getTemporaryDirectory();
    final f = File('${dir.path}/ds_analyse_${DateTime.now().microsecondsSinceEpoch}.bmp');
    await f.writeAsBytes(bmp, flush: true);
    return f.path;
  }

  static Uint8List _bmp24(Uint8List rgba, int w, int h) {
    final rowSize = ((w * 3 + 3) ~/ 4) * 4;
    final dataSize = rowSize * h;
    final out = Uint8List(54 + dataSize);
    final bd = ByteData.view(out.buffer);
    out[0] = 0x42;
    out[1] = 0x4D;
    bd.setUint32(2, 54 + dataSize, Endian.little);
    bd.setUint32(10, 54, Endian.little);
    bd.setUint32(14, 40, Endian.little);
    bd.setInt32(18, w, Endian.little);
    bd.setInt32(22, h, Endian.little);
    bd.setUint16(26, 1, Endian.little);
    bd.setUint16(28, 24, Endian.little);
    bd.setUint32(34, dataSize, Endian.little);
    for (var y = 0; y < h; y++) {
      final src = (h - 1 - y) * w * 4;
      var dst = 54 + y * rowSize;
      for (var x = 0; x < w; x++) {
        final i = src + x * 4;
        out[dst++] = rgba[i + 2];
        out[dst++] = rgba[i + 1];
        out[dst++] = rgba[i];
      }
    }
    return out;
  }

  /// PNG keeps transparency; JPG is flattened on white in a background isolate.
  static Future<Uint8List> encode(ui.Image image, ExportFormat format, {int quality = 92}) async {
    if (format == ExportFormat.png) {
      final bd = await image.toByteData(format: ui.ImageByteFormat.png);
      return bd!.buffer.asUint8List();
    }
    final pixels = await rgba(image);
    final w = image.width;
    final h = image.height;
    return Isolate.run(() => encodeJpg(pixels, w, h, quality));
  }

  static Uint8List encodeJpg(Uint8List rgba, int w, int h, int quality) {
    for (var i = 0; i < rgba.length; i += 4) {
      final a = rgba[i + 3];
      if (a == 255) continue;
      final inv = 255 - a;
      rgba[i] = (rgba[i] * a + 255 * inv) ~/ 255;
      rgba[i + 1] = (rgba[i + 1] * a + 255 * inv) ~/ 255;
      rgba[i + 2] = (rgba[i + 2] * a + 255 * inv) ~/ 255;
      rgba[i + 3] = 255;
    }
    final im = img.Image.fromBytes(width: w, height: h, bytes: rgba.buffer, numChannels: 4);
    return img.encodeJpg(im, quality: quality);
  }

  /// Small JPG thumbnail (longest side [side]).
  static Future<Uint8List> thumbnail(ui.Image image, {int side = 480}) async {
    final s = side / math.max(image.width, image.height);
    final w = math.max(1, (image.width * math.min(1.0, s)).round());
    final h = math.max(1, (image.height * math.min(1.0, s)).round());
    final small = await render(w, h, (c) {
      c.drawImageRect(
        image,
        ui.Rect.fromLTWH(0, 0, image.width.toDouble(), image.height.toDouble()),
        ui.Rect.fromLTWH(0, 0, w.toDouble(), h.toDouble()),
        ui.Paint()..filterQuality = ui.FilterQuality.medium,
      );
    });
    final px = await rgba(small);
    small.dispose();
    return Isolate.run(() => encodeJpg(px, w, h, 80));
  }

  static Future<void> saveToGallery(Uint8List bytes, String name) async {
    if (!await Gal.hasPermission(toAlbum: true)) {
      await Gal.requestPermission(toAlbum: true);
    }
    await Gal.putImageBytes(bytes, album: 'Delicat Studio', name: name);
  }

  static Future<void> shareFile(String path, {String? text}) async {
    await SharePlus.instance.share(ShareParams(files: [XFile(path)], text: text));
  }
}

String formatBytes(int b) {
  if (b < 1024) return '$b o';
  if (b < 1024 * 1024) return '${(b / 1024).toStringAsFixed(0)} Ko';
  return '${(b / (1024 * 1024)).toStringAsFixed(1).replaceAll('.', ',')} Mo';
}
