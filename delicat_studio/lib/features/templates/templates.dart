import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../core/brand.dart';
import '../editor/editor_model.dart';
import '../editor/layer_tools.dart';
import '../video/video_model.dart';

enum PromoLang {
  fr('Français'),
  ht('Kreyòl');

  const PromoLang(this.label);
  final String label;
}

enum PosterFormat {
  story('Story 9:16', 1080, 1920, VRatio.story),
  portrait('Portrait 4:5', 1080, 1350, VRatio.portrait),
  square('Carré 1:1', 1080, 1080, VRatio.square);

  const PosterFormat(this.label, this.width, this.height, this.ratio);
  final String label;
  final int width;
  final int height;
  final VRatio ratio;
  Size get size => Size(width.toDouble(), height.toDouble());
}

enum PosterLook {
  /// Obsidian with indigo shapes: the default Delicat look.
  kat,

  /// Brand colour background with confetti, for holidays.
  festive,

  /// Obsidian with sun accents, for the rewards programme.
  reward,
}

/// A ready-made promo poster. Copy is written in both languages; prices are always typed
/// by the shop (never invented here).
class PromoTemplate {
  const PromoTemplate({
    required this.id,
    required this.title,
    required this.icon,
    required this.emoji,
    required this.headline,
    required this.badge,
    this.options = const {},
    this.productHint = const {PromoLang.fr: 'Produit', PromoLang.ht: 'Pwodui'},
    this.look = PosterLook.kat,
    this.headlineIsProduct = false,
    this.music = 'energie',
  });

  final String id;

  /// Name in the template gallery (French UI).
  final String title;
  final IconData icon;

  /// Shown large when no product photo is added.
  final String emoji;
  final Map<PromoLang, String> headline;
  final Map<PromoLang, String> badge;

  /// Quick choices for the product line, per language (same order in both).
  final Map<PromoLang, List<String>> options;
  final Map<PromoLang, String> productHint;
  final PosterLook look;

  /// The chosen option replaces the headline (holiday wishes).
  final bool headlineIsProduct;

  /// Name of the [MusicMood] used for the video version.
  final String music;
}

const kPromoTemplates = <PromoTemplate>[
  PromoTemplate(
    id: 'freefire',
    title: 'Diamants Free Fire',
    icon: Icons.diamond_rounded,
    emoji: '💎',
    headline: {PromoLang.fr: 'Diamants Free Fire', PromoLang.ht: 'Dyaman Free Fire'},
    badge: {PromoLang.fr: 'Recharge rapide', PromoLang.ht: 'Rechaj rapid'},
    options: {
      PromoLang.fr: ['110 diamants', '341 diamants', '572 diamants', '1 166 diamants'],
      PromoLang.ht: ['110 dyaman', '341 dyaman', '572 dyaman', '1 166 dyaman'],
    },
    productHint: {PromoLang.fr: 'Pack', PromoLang.ht: 'Pakè'},
  ),
  PromoTemplate(
    id: 'efootball',
    title: 'Pièces eFootball',
    icon: Icons.sports_soccer_rounded,
    emoji: '⚽',
    headline: {PromoLang.fr: 'Pièces eFootball', PromoLang.ht: 'Pyès eFootball'},
    badge: {PromoLang.fr: 'Recharge rapide', PromoLang.ht: 'Rechaj rapid'},
    productHint: {PromoLang.fr: 'Ex. : 550 pièces', PromoLang.ht: 'Egz. : 550 pyès'},
  ),
  PromoTemplate(
    id: 'giftcard',
    title: 'Cartes cadeaux',
    icon: Icons.card_giftcard_rounded,
    emoji: '🎁',
    headline: {PromoLang.fr: 'Cartes cadeaux', PromoLang.ht: 'Kat kado'},
    badge: {PromoLang.fr: 'Disponible', PromoLang.ht: 'Disponib'},
    productHint: {PromoLang.fr: 'Quelle carte ?', PromoLang.ht: 'Ki kat ?'},
  ),
  PromoTemplate(
    id: 'streaming',
    title: 'Abonnements streaming',
    icon: Icons.live_tv_rounded,
    emoji: '🎬',
    headline: {PromoLang.fr: 'Abonnements streaming', PromoLang.ht: 'Abònman streaming'},
    badge: {PromoLang.fr: 'Disponible', PromoLang.ht: 'Disponib'},
    productHint: {PromoLang.fr: 'Quel abonnement ?', PromoLang.ht: 'Ki abònman ?'},
    music: 'chill',
  ),
  PromoTemplate(
    id: 'promo',
    title: 'Promo du jour',
    icon: Icons.local_fire_department_rounded,
    emoji: '🔥',
    headline: {PromoLang.fr: 'Prix spécial', PromoLang.ht: 'Pri espesyal'},
    badge: {PromoLang.fr: 'Promo du jour', PromoLang.ht: 'Pwomo jodi a'},
  ),
  PromoTemplate(
    id: 'nouveau',
    title: 'Nouveau en boutique',
    icon: Icons.auto_awesome_rounded,
    emoji: '✨',
    headline: {PromoLang.fr: 'Nouveau en boutique', PromoLang.ht: 'Nouvo nan boutik la'},
    badge: {PromoLang.fr: 'Nouveau', PromoLang.ht: 'Nouvo'},
    music: 'afro',
  ),
  PromoTemplate(
    id: 'wallet',
    title: 'Recharge Wallet',
    icon: Icons.account_balance_wallet_rounded,
    emoji: '💳',
    headline: {PromoLang.fr: 'Recharge ton Wallet', PromoLang.ht: 'Rechaje Wallet ou'},
    badge: {PromoLang.fr: 'Wallet', PromoLang.ht: 'Wallet'},
    productHint: {PromoLang.fr: 'Message', PromoLang.ht: 'Mesaj'},
    music: 'chill',
  ),
  PromoTemplate(
    id: 'fetes',
    title: 'Fêtes',
    icon: Icons.celebration_rounded,
    emoji: '🎉',
    headline: {PromoLang.fr: 'Joyeux Noël', PromoLang.ht: 'Jwaye Nwèl'},
    badge: {PromoLang.fr: 'Delicat Store', PromoLang.ht: 'Delicat Store'},
    options: {
      PromoLang.fr: ['Joyeux Noël', 'Bonne année', 'Bonne fête des mères', 'Bonne fête des pères', 'Bonne fête du drapeau', 'Bonne Saint-Valentin'],
      PromoLang.ht: ['Jwaye Nwèl', 'Bòn ane', 'Bòn fèt manman', 'Bòn fèt papa', 'Bòn fèt drapo', 'Bòn fèt Sen Valanten'],
    },
    productHint: {PromoLang.fr: 'Ton message', PromoLang.ht: 'Mesaj ou'},
    look: PosterLook.festive,
    headlineIsProduct: true,
    music: 'fete',
  ),
  PromoTemplate(
    id: 'rewards',
    title: 'Bon Kliyan',
    icon: Icons.stars_rounded,
    emoji: '⭐',
    headline: {PromoLang.fr: 'Programme Bon Kliyan', PromoLang.ht: 'Pwogram Bon Kliyan'},
    badge: {PromoLang.fr: 'Récompenses', PromoLang.ht: 'Rekonpans'},
    options: {
      PromoLang.fr: ['Merci pour ta fidélité'],
      PromoLang.ht: ['Mèsi paske ou fidèl'],
    },
    productHint: {PromoLang.fr: 'Message', PromoLang.ht: 'Mesaj'},
    look: PosterLook.reward,
    music: 'chill',
  ),
];

/// « HTG 1 500,00 », with no-break spaces so the price never splits across lines.
/// Returns null when [raw] is not a positive amount.
String? formatHtg(String raw) {
  final t = raw.replaceAll(RegExp(r'[\s  ]|HTG|htg|G$'), '').replaceAll(',', '.');
  if (t.isEmpty || RegExp(r'\..*\.').hasMatch(t)) return null;
  final v = double.tryParse(t);
  if (v == null || v <= 0 || v > 1e9) return null;
  final cents = (v * 100).round();
  final whole = (cents ~/ 100).toString();
  final buf = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) buf.write(' ');
    buf.write(whole[i]);
  }
  return 'HTG $buf,${(cents % 100).toString().padLeft(2, '0')}';
}

/// Everything the shop chose in the form.
@immutable
class PosterSpec {
  const PosterSpec({
    required this.template,
    this.lang = PromoLang.fr,
    this.format = PosterFormat.story,
    this.product = '',
    this.price = '',
    this.picture,
    this.qr = true,
  });

  final PromoTemplate template;
  final PromoLang lang;
  final PosterFormat format;
  final String product;
  final String price;
  final LayerPicture? picture;
  final bool qr;

  PosterSpec copyWith({PromoLang? lang, PosterFormat? format, String? product, String? price, LayerPicture? picture, bool clearPicture = false, bool? qr}) =>
      PosterSpec(
        template: template,
        lang: lang ?? this.lang,
        format: format ?? this.format,
        product: product ?? this.product,
        price: price ?? this.price,
        picture: clearPicture ? null : (picture ?? this.picture),
        qr: qr ?? this.qr,
      );
}

/// Vertical positions (fractions of the height) per format.
class _Grid {
  const _Grid(this.logo, this.badge, this.head, this.option, this.picture, this.pictureSize, this.ticket, this.price, this.cta, this.link, this.qr, this.qrSize);
  final double logo, badge, head, option, picture, pictureSize, ticket, price, cta, link, qr, qrSize;

  static _Grid of(PosterFormat f) => switch (f) {
        PosterFormat.story => const _Grid(0.055, 0.15, 0.245, 0.335, 0.5, 0.52, 0.665, 0.755, 0.83, 0.885, 0.805, 0.26),
        PosterFormat.portrait => const _Grid(0.07, 0.13, 0.225, 0.32, 0.485, 0.36, 0.645, 0.735, 0.815, 0.885, 0.79, 0.22),
        PosterFormat.square => const _Grid(0.075, 0.12, 0.215, 0.31, 0.47, 0.28, 0.625, 0.715, 0.8, 0.88, 0.785, 0.2),
      };
}

const _ink = Color(0xFF141834);
const _sun = Color(0xFFF5B400);
const _white = Color(0xFFFFFFFF);

Color _bgOf(PosterLook look, BrandKit kit) => look == PosterLook.festive ? kit.primary : _ink;

/// Flat background: shapes and the ticket with its tear line (texts are editable layers).
void paintPosterBackground(Canvas canvas, Size size, PosterSpec spec, BrandKit kit) {
  final w = size.width;
  final h = size.height;
  final g = _Grid.of(spec.format);
  final look = spec.template.look;
  final bg = _bgOf(look, kit);
  canvas.drawRect(Offset.zero & size, Paint()..color = bg);

  final shape = look == PosterLook.reward ? _sun : (look == PosterLook.festive ? _ink : kit.primary);
  canvas.drawCircle(Offset(w * 0.98, h * 0.06), w * 0.42, Paint()..color = shape);
  canvas.drawCircle(
    Offset(w * 0.04, h * (g.picture + 0.04)),
    w * 0.16,
    Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = w * 0.022
      ..color = look == PosterLook.reward ? _sun : kit.accent,
  );
  if (look == PosterLook.festive) {
    final rnd = math.Random(7);
    const colors = [_sun, Color(0xFFC8358F), _white, _ink];
    for (var i = 0; i < 46; i++) {
      final x = rnd.nextDouble() * w;
      final y = rnd.nextDouble() * h * (g.ticket - 0.04);
      final s = w * (0.012 + rnd.nextDouble() * 0.018);
      canvas.save();
      canvas.translate(x, y);
      canvas.rotate(rnd.nextDouble() * math.pi);
      canvas.drawRRect(RRect.fromRectAndRadius(Rect.fromCenter(center: Offset.zero, width: s * 2.2, height: s), Radius.circular(s / 3)), Paint()..color = colors[i % colors.length]);
      canvas.restore();
    }
  }
  if (look == PosterLook.reward) {
    final star = Paint()..color = _sun;
    for (final (x, y, r) in [(0.12, g.badge + 0.02, 0.03), (0.86, g.option + 0.05, 0.022), (0.2, g.picture + 0.12, 0.018)]) {
      canvas.drawPath(_star(Offset(w * x, h * y), w * r), star);
    }
  }

  // Ticket.
  final m = w * 0.06;
  final top = h * g.ticket;
  final card = Rect.fromLTRB(m, top, w - m, h - m);
  canvas.drawRRect(RRect.fromRectAndRadius(card, Radius.circular(w * 0.04)), Paint()..color = _white);
  final tear = top + (card.height) * 0.16;
  final notch = w * 0.035;
  final cut = Paint()..color = bg;
  canvas.drawCircle(Offset(card.left, tear), notch, cut);
  canvas.drawCircle(Offset(card.right, tear), notch, cut);
  final dash = Paint()
    ..color = const Color(0xFFD9D8E4)
    ..strokeWidth = math.max(2, w * 0.003);
  for (var x = card.left + notch * 1.6; x < card.right - notch * 1.6; x += w * 0.03) {
    canvas.drawLine(Offset(x, tear), Offset(math.min(x + w * 0.015, card.right - notch * 1.6), tear), dash);
  }
}

Path _star(Offset c, double r) {
  final p = Path();
  for (var i = 0; i < 10; i++) {
    final a = -math.pi / 2 + i * math.pi / 5;
    final rr = i.isEven ? r : r * 0.45;
    final pt = c + Offset(math.cos(a) * rr, math.sin(a) * rr);
    i == 0 ? p.moveTo(pt.dx, pt.dy) : p.lineTo(pt.dx, pt.dy);
  }
  return p..close();
}

String _waLink(BrandKit kit) {
  final digits = kit.whatsapp.replaceAll(RegExp(r'[^0-9]'), '');
  return digits.length < 8 ? '' : 'https://wa.me/$digits';
}

/// Editable layers for the poster, each with the time it appears in the video version.
List<(EditorLayer, double)> posterLayers(PosterSpec spec, BrandKit kit, {double logoAspect = 1}) {
  final t = spec.template;
  final g = _Grid.of(spec.format);
  final l = spec.lang;
  final out = <(EditorLayer, double)>[];
  var n = 0;
  String id() => 'tpl${n++}';
  final headFont = kit.font == 'Bricolage' ? 'BebasNeue' : kit.font;
  const onBg = _white;
  final product = spec.product.trim();
  final headline = t.headlineIsProduct && product.isNotEmpty ? product : t.headline[l]!;
  final optionLine = t.headlineIsProduct ? '' : product;

  if (kit.hasLogo) {
    // Logos are kept within a 0.2 × 0.08 box of the width, whatever their shape.
    final a = logoAspect.clamp(0.3, 5.0);
    out.add((EditorLayer(id: id(), image: kit.logoPath, aspect: a, size: math.min(0.22, 0.09 * a), pos: Offset(0.06 + math.min(0.22, 0.09 * a) / 2, g.logo + 0.01)), 0));
  } else {
    out.add((EditorLayer(id: id(), text: kit.name, font: 'Bricolage', color: onBg, size: 0.04, pos: Offset(0.5, g.logo + 0.01)), 0));
  }
  final badge = t.id == 'fetes' ? kit.name : t.badge[l]!;
  out.add((EditorLayer(id: id(), text: badge, font: 'Bricolage', color: t.look == PosterLook.reward ? _sun : kit.accent, look: TextLook.label, size: 0.045, pos: Offset(0.5, g.badge)), 0));
  out.add((EditorLayer(id: id(), text: headline, font: headFont, color: onBg, size: 0.13, pos: Offset(0.5, g.head)), 300));
  if (optionLine.isNotEmpty) {
    out.add((EditorLayer(id: id(), text: optionLine, font: headFont, color: t.look == PosterLook.festive ? _ink : _sun, size: 0.095, pos: Offset(0.5, g.option)), 800));
  }
  final pic = spec.picture;
  if (pic != null) {
    final width = math.min(0.86, g.pictureSize * math.max(0.6, pic.aspect));
    out.add((EditorLayer(id: id(), image: pic.path, aspect: pic.aspect, size: width, pos: Offset(0.5, g.picture)), 0));
  } else {
    out.add((EditorLayer(id: id(), emoji: t.emoji, size: g.pictureSize * 0.5, pos: Offset(0.5, g.picture)), 0));
  }

  final hasQr = spec.qr;
  final textX = hasQr ? 0.37 : 0.5;
  final price = formatHtg(spec.price);
  final wa = _waLink(kit);
  final cta = switch (l) {
    PromoLang.fr => wa.isNotEmpty ? 'Commande sur WhatsApp' : 'Commande sur notre site',
    PromoLang.ht => wa.isNotEmpty ? 'Kòmande sou WhatsApp' : 'Kòmande sou sit nou an',
  };
  final contact = wa.isNotEmpty ? kit.whatsapp.trim() : kit.link;
  if (price != null) {
    out.add((EditorLayer(id: id(), text: price, font: 'BebasNeue', color: _ink, size: 0.1, pos: Offset(textX, g.price)), 1500));
  }
  out.add((EditorLayer(id: id(), text: cta, font: 'Bricolage', color: _ink, size: price == null ? 0.05 : 0.04, pos: Offset(textX, price == null ? g.price + 0.02 : g.cta)), 2200));
  if (contact.isNotEmpty) {
    out.add((EditorLayer(id: id(), text: contact, font: 'JetBrainsMono', color: const Color(0xFF5A5E7A), size: 0.03, pos: Offset(textX, g.link)), 2200));
  }
  if (hasQr) {
    final data = wa.isNotEmpty ? wa : kit.url;
    out.add((EditorLayer(id: id(), qr: data, size: g.qrSize, color: _ink, pos: Offset(0.79, g.qr)), 2200));
  }
  return out;
}

/// Paints the whole poster (background and layers), for previews.
void paintPoster(Canvas canvas, Size size, PosterSpec spec, BrandKit kit, {double logoAspect = 1}) {
  final s = size.width / spec.format.width;
  canvas.save();
  canvas.scale(s);
  final full = spec.format.size;
  paintPosterBackground(canvas, full, spec, kit);
  for (final (l, _) in posterLayers(spec, kit, logoAspect: logoAspect)) {
    paintLayer(canvas, l, full);
  }
  canvas.restore();
}

/// Renders the background at full size (the editable layers go on top in the editor).
Future<ui.Image> renderPosterBackground(PosterSpec spec, BrandKit kit) {
  final rec = ui.PictureRecorder();
  final size = spec.format.size;
  final canvas = Canvas(rec, Offset.zero & size);
  paintPosterBackground(canvas, size, spec, kit);
  final pic = rec.endRecording();
  return pic.toImage(spec.format.width, spec.format.height).whenComplete(pic.dispose);
}

/// The video version: the poster background slowly zooming, texts appearing one by one.
VideoDoc posterVideo(PosterSpec spec, BrandKit kit, {required String backgroundPath, VMusic? music, double durationMs = 8000, double logoAspect = 1}) {
  final clip = VClip(
    id: 'tplbg',
    path: backgroundPath,
    isImage: true,
    sourceMs: 60000,
    width: spec.format.width,
    height: spec.format.height,
    inMs: 0,
    outMs: durationMs,
    hasAudio: false,
    motion: VMotion.zoomIn,
    fadeInMs: 400,
  );
  return VideoDoc(
    clips: [clip],
    texts: [for (final (l, at) in posterLayers(spec, kit, logoAspect: logoAspect)) VText(layer: l, startMs: math.min(at, durationMs - 500), endMs: durationMs)],
    music: music,
    ratio: spec.format.ratio,
    fill: true,
  );
}
