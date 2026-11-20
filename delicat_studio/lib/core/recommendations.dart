import 'creations.dart';

/// Things Delicat Studio can suggest. Kept free of UI code so it can be unit tested.
enum RecAction { video, logoVideo, flagDay, sticker, collageStory, portrait, styleJacmel, colorErase, selective, polaroid }

class Recommendation {
  const Recommendation(this.action, this.title, this.reason, this.score);
  final RecAction action;
  final String title;

  /// Why it is suggested, shown under the title.
  final String reason;
  final double score;
}

/// Rule-based personal suggestions: tools not tried yet rise, tools used a lot sink,
/// related tools get a boost, and seasonal ideas appear at the right time of year.
List<Recommendation> recommend({
  required Map<String, int> uses,
  required Map<CreationKind, int> made,
  required DateTime now,
  int limit = 4,
}) {
  int u(String k) => uses[k] ?? 0;
  double fresh(String k, double base) => base + (u(k) == 0 ? 15 : -4.0 * u(k).clamp(0, 5));
  final out = <Recommendation>[];

  out.add(Recommendation(
    RecAction.video,
    'Monte une vidéo pour ta boutique',
    u('video') == 0 ? 'Nouveau : clips, musique, texte et export MP4' : 'Enchaîne tes clips avec des fondus et une musique',
    fresh('video', 50),
  ));
  if (u('video') > 0) {
    out.add(Recommendation(RecAction.logoVideo, 'Signe tes vidéos avec ton logo', 'Parce que tu montes des vidéos', fresh('logoVideo', 34)));
  }
  final cutouts = u('cutout') + (made[CreationKind.cutout] ?? 0);
  out.add(Recommendation(
    RecAction.sticker,
    'Transforme un produit en sticker',
    cutouts > 0 ? 'Parce que tu détoures déjà tes photos' : 'Contour blanc et fond transparent',
    fresh('sticker', 30) + (cutouts > 0 ? 20 : 0),
  ));
  out.add(Recommendation(RecAction.collageStory, 'Collage au format story', 'Idéal pour tes statuts WhatsApp', fresh('collage', 28)));
  out.add(Recommendation(RecAction.portrait, 'Un portrait façon objectif pro', 'Le décor se floute, ton sujet ressort', fresh('portrait', 26)));
  out.add(Recommendation(RecAction.styleJacmel, 'Essaie le style Jacmel', 'Des couleurs vives et chaudes en un toucher', fresh('styles', 24)));
  out.add(Recommendation(RecAction.colorErase, 'Fond uni ? Efface-le en un toucher', 'Parfait pour tes logos et tes produits', fresh('colorErase', 20)));
  out.add(Recommendation(RecAction.selective, 'Couleur sélective', 'Ton sujet en couleur, le décor en noir et blanc', fresh('selective', 18)));

  final m = now.month;
  final d = now.day;
  if (m == 12 || (m == 1 && d <= 6)) {
    out.add(Recommendation(RecAction.polaroid, 'Cadre Polaroïd des fêtes', 'Spécial fêtes de fin d’année', 80 - 4.0 * u('polaroid').clamp(0, 5)));
  }
  if (m == 5 && d <= 18) {
    out.add(Recommendation(RecAction.flagDay, 'Fête du Drapeau en vidéo', 'Ajoute 🇭🇹 et tes couleurs avant le 18 mai', 80 - 4.0 * u('flagDay').clamp(0, 5)));
  }

  out.sort((a, b) => b.score.compareTo(a.score));
  return out.take(limit).toList();
}
