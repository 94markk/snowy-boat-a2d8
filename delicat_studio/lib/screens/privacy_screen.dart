import 'package:material_ui/material_ui.dart';

import '../theme/tokens.dart';

/// Privacy policy, French only. Same text as PRIVACY.md at the root of the app.
class PrivacyScreen extends StatelessWidget {
  const PrivacyScreen({super.key});

  /// Date of the current policy; change it together with PRIVACY.md.
  static const updated = '22 novembre 2026';

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Scaffold(
      backgroundColor: k.canvas,
      appBar: AppBar(title: const Text('Confidentialité')),
      body: SafeArea(
        top: false,
        child: SelectionArea(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, KatSpace.s32),
            children: [
              Text('Dernière mise à jour : $updated', style: KatType.ui(14, color: k.inkMuted)),
              const SizedBox(height: KatSpace.s12),
              Text(
                'Delicat Studio est l’application de création visuelle de Delicat Store Haiti (delicastoreha.com). '
                'Elle sert à préparer des photos, des affiches et des vidéos pour vendre. '
                'Cette page explique quelles données l’application utilise et où elles restent.',
                style: KatType.ui(16, color: k.ink),
              ),
              const SizedBox(height: KatSpace.s24),
              const _Summary(),
              for (final s in _sections) _SectionView(s),
            ],
          ),
        ),
      ),
    );
  }
}

class _Section {
  const _Section(this.title, this.paragraphs, [this.points = const []]);
  final String title;
  final List<String> paragraphs;
  final List<String> points;
}

const _sections = <_Section>[
  _Section('Photos et vidéos', [
    'Vous choisissez vos photos et vidéos avec le sélecteur d’Android. L’application accède seulement aux fichiers que vous choisissez. '
        'Elle ne demande pas l’accès à l’appareil photo.',
    'Tout le travail se fait sur le téléphone : détourage, collages, retouches et styles, affiches promo, codes QR, montage et export des vidéos.',
  ]),
  _Section('Détourage avec Google ML Kit', [
    'Le détourage utilise la segmentation de sujet de Google ML Kit. L’analyse de l’image se fait sur le téléphone : '
        'la photo n’est pas envoyée sur un serveur.',
    'Le modèle de détourage est fourni par les services Google Play, qui peuvent le télécharger. '
        'Les services Google Play suivent les règles de confidentialité de Google : policies.google.com/privacy',
  ]),
  _Section('Micro', [
    'L’autorisation du micro sert uniquement à enregistrer une voix off pour vos vidéos. '
        'Le micro enregistre seulement quand vous appuyez sur le bouton d’enregistrement. Les enregistrements restent dans l’application.',
    'Vous pouvez retirer cette autorisation à tout moment dans les réglages d’Android.',
  ]),
  _Section('Galerie et partage', [], [
    'Quand vous enregistrez une création, elle est ajoutée à la galerie du téléphone. '
        'Sur Android 10 et les versions plus anciennes, l’application demande l’accès au stockage uniquement pour cela.',
    'Quand vous partagez vers WhatsApp ou avec le menu de partage d’Android, le fichier est remis à l’application que vous choisissez. '
        'Les règles de cette application s’appliquent ensuite.',
    'L’application vérifie si WhatsApp ou WhatsApp Business est installé, pour proposer le partage direct.',
  ]),
  _Section('Ce qui est gardé sur le téléphone', [
    'Vos brouillons, votre kit de marque (nom, logo, couleurs) et vos réglages sont enregistrés dans l’espace privé de l’application. '
        'Les autres applications n’y ont pas accès. Désinstaller Delicat Studio les efface.',
  ]),
  _Section('Journal des problèmes', [
    'Quand une erreur se produit, l’application l’inscrit dans un journal technique gardé sur le téléphone. '
        'Ce journal ne quitte le téléphone que si vous touchez « Signaler un problème ».',
    'Le menu de partage s’ouvre alors avec le modèle du téléphone, la version d’Android, la mémoire (RAM) et le journal. '
        'Vous choisissez à qui l’envoyer, ou vous annulez.',
  ]),
  _Section('Changements', [
    'Si cette politique change, la nouvelle version sera publiée ici avec une nouvelle date.',
  ]),
  _Section('Contact', [
    'Delicat Store Haiti\n[adresse e-mail de contact]\ndelicastoreha.com',
  ]),
];

/// « En bref »: the three promises, on one hairline sheet.
class _Summary extends StatelessWidget {
  const _Summary();

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    Widget row(IconData icon, String text) => Padding(
          padding: const EdgeInsets.symmetric(vertical: KatSpace.s8),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(icon, size: 24, color: k.ink),
              const SizedBox(width: KatSpace.s12),
              Expanded(child: Text(text, style: KatType.ui(16, color: k.ink))),
            ],
          ),
        );
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16, vertical: KatSpace.s8),
      decoration: BoxDecoration(
        color: k.sheet,
        borderRadius: BorderRadius.circular(KatRadius.tile),
        border: Border.all(color: k.line),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.only(top: KatSpace.s8, bottom: KatSpace.s4),
            child: Text('En bref', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
          ),
          row(Icons.person_off_rounded, 'Pas de compte, pas de publicité, pas de statistiques d’utilisation.'),
          row(Icons.cloud_off_rounded, 'L’application n’a pas de serveur et n’envoie pas vos données sur Internet.'),
          row(
            Icons.phone_android_rounded,
            'Vos photos, vidéos, enregistrements et brouillons restent sur votre téléphone, sauf si vous choisissez de les partager.',
          ),
        ],
      ),
    );
  }
}

class _SectionView extends StatelessWidget {
  const _SectionView(this.section);
  final _Section section;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final body = KatType.ui(16, color: k.ink);
    return Padding(
      padding: const EdgeInsets.only(top: KatSpace.s24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Semantics(
            header: true,
            child: Text(section.title, style: KatType.ui(20, weight: FontWeight.w600, color: k.ink)),
          ),
          for (final p in section.paragraphs)
            Padding(
              padding: const EdgeInsets.only(top: KatSpace.s8),
              child: Text(p, style: body),
            ),
          for (final p in section.points)
            Padding(
              padding: const EdgeInsets.only(top: KatSpace.s8),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Bullet centred on the first line (16 px text, 1.4 line height).
                  Padding(
                    padding: const EdgeInsets.only(top: KatSpace.s8, right: KatSpace.s12),
                    child: Container(
                      width: 6,
                      height: 6,
                      decoration: BoxDecoration(color: k.inkMuted, shape: BoxShape.circle),
                    ),
                  ),
                  Expanded(child: Text(p, style: body)),
                ],
              ),
            ),
        ],
      ),
    );
  }
}
