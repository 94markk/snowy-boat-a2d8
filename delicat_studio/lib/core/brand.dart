import 'dart:convert';
import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// The shop's identity, set once in Réglages and reused by templates, videos and QR codes.
@immutable
class BrandKit {
  const BrandKit({
    this.name = 'Delicat Store Haiti',
    this.logoPath,
    this.primary = const Color(0xFF4B3FE0),
    this.accent = const Color(0xFFC8358F),
    this.font = 'Bricolage',
    this.link = 'delicastoreha.com',
    this.whatsapp = '',
    this.autoLogo = true,
  });

  final String name;
  final String? logoPath;
  final Color primary;
  final Color accent;
  final String font;
  final String link;

  /// Shop WhatsApp number shown on templates (empty = hidden).
  final String whatsapp;

  /// Adds the logo to every new video montage.
  final bool autoLogo;

  bool get hasLogo => logoPath != null && File(logoPath!).existsSync();

  /// Link with a scheme, for QR codes.
  String get url => link.startsWith('http') ? link : 'https://$link';

  BrandKit copyWith({
    String? name,
    String? logoPath,
    bool clearLogo = false,
    Color? primary,
    Color? accent,
    String? font,
    String? link,
    String? whatsapp,
    bool? autoLogo,
  }) =>
      BrandKit(
        name: name ?? this.name,
        logoPath: clearLogo ? null : (logoPath ?? this.logoPath),
        primary: primary ?? this.primary,
        accent: accent ?? this.accent,
        font: font ?? this.font,
        link: link ?? this.link,
        whatsapp: whatsapp ?? this.whatsapp,
        autoLogo: autoLogo ?? this.autoLogo,
      );

  Map<String, Object?> toJson() => {
        'name': name,
        'logoPath': logoPath,
        'primary': primary.toARGB32(),
        'accent': accent.toARGB32(),
        'font': font,
        'link': link,
        'whatsapp': whatsapp,
        'autoLogo': autoLogo,
      };

  static BrandKit fromJson(Map<String, dynamic> j) => BrandKit(
        name: j['name'] as String? ?? 'Delicat Store Haiti',
        logoPath: j['logoPath'] as String?,
        primary: Color((j['primary'] as num?)?.toInt() ?? 0xFF4B3FE0),
        accent: Color((j['accent'] as num?)?.toInt() ?? 0xFFC8358F),
        font: j['font'] as String? ?? 'Bricolage',
        link: j['link'] as String? ?? 'delicastoreha.com',
        whatsapp: j['whatsapp'] as String? ?? '',
        autoLogo: j['autoLogo'] as bool? ?? true,
      );
}

class BrandStore extends ChangeNotifier {
  BrandKit kit = const BrandKit();
  SharedPreferences? _prefs;

  Future<void> load() async {
    try {
      final p = await SharedPreferences.getInstance();
      _prefs = p;
      final raw = p.getString('brandKit');
      if (raw != null) kit = BrandKit.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      kit = const BrandKit();
    }
  }

  void update(BrandKit next) {
    kit = next;
    _prefs?.setString('brandKit', jsonEncode(next.toJson()));
    notifyListeners();
  }

  /// Copies a picked logo into app storage so it survives cache clean-ups.
  Future<void> setLogo(String sourcePath) async {
    final docs = await getApplicationDocumentsDirectory();
    final dir = Directory('${docs.path}/brand');
    if (!await dir.exists()) await dir.create(recursive: true);
    final ext = sourcePath.split('.').last.toLowerCase();
    final target = File('${dir.path}/logo_${DateTime.now().millisecondsSinceEpoch}.$ext');
    await File(sourcePath).copy(target.path);
    final old = kit.logoPath;
    update(kit.copyWith(logoPath: target.path));
    if (old != null && old != target.path) {
      try {
        await File(old).delete();
      } catch (_) {}
    }
  }
}

final brand = BrandStore();

/// Brand colours first, then the standard palette.
List<Color> brandSwatches(List<Color> base) {
  final k = brand.kit;
  final out = <Color>[k.primary, k.accent];
  for (final c in base) {
    if (!out.any((x) => x.toARGB32() == c.toARGB32())) out.add(c);
  }
  return out;
}
