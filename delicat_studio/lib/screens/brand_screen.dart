import 'dart:io';

import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';

import '../core/brand.dart';
import '../features/editor/editor_model.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/kat_button.dart';
import '../widgets/pressable.dart';

/// « Ma marque »: the shop identity reused by templates, QR codes and new videos.
class BrandScreen extends StatefulWidget {
  const BrandScreen({super.key});

  @override
  State<BrandScreen> createState() => _BrandScreenState();
}

class _BrandScreenState extends State<BrandScreen> {
  late final TextEditingController _name = TextEditingController(text: brand.kit.name);
  late final TextEditingController _link = TextEditingController(text: brand.kit.link);
  late final TextEditingController _wa = TextEditingController(text: brand.kit.whatsapp);

  @override
  void dispose() {
    _save();
    _name.dispose();
    _link.dispose();
    _wa.dispose();
    super.dispose();
  }

  void _save() {
    final k = brand.kit;
    final name = _name.text.trim().isEmpty ? k.name : _name.text.trim();
    final link = _link.text.trim().replaceFirst(RegExp(r'^https?://'), '').replaceAll(RegExp(r'/+$'), '');
    final wa = _wa.text.trim();
    if (name != k.name || link != k.link || wa != k.whatsapp) {
      brand.update(k.copyWith(name: name, link: link, whatsapp: wa));
    }
  }

  Future<void> _pickLogo() async {
    try {
      final f = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1024, maxHeight: 1024, requestFullMetadata: false);
      if (f == null) return;
      await brand.setLogo(f.path);
      if (mounted) setState(() {});
    } catch (_) {
      if (mounted) showKatSnack(context, 'Ce logo n’a pas pu être ajouté. Choisis une image PNG ou JPG.');
    }
  }

  Widget _field(KatColors k, String label, TextEditingController c, {String? hint, TextInputType? type, bool code = false}) => Padding(
        padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s12, KatSpace.gutter, 0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: KatType.ui(14, weight: FontWeight.w600, color: k.ink)),
            const SizedBox(height: KatSpace.s4),
            TextField(
              controller: c,
              keyboardType: type,
              autocorrect: !code,
              style: code ? KatType.code(16, color: k.ink) : KatType.ui(16, color: k.ink),
              decoration: InputDecoration(hintText: hint),
              onEditingComplete: () {
                _save();
                FocusScope.of(context).unfocus();
              },
            ),
          ],
        ),
      );

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('Ma marque', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink))),
      body: ListenableBuilder(
        listenable: brand,
        builder: (context, _) {
          final kit = brand.kit;
          return ListView(
            padding: const EdgeInsets.only(bottom: KatSpace.s32),
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, 0),
                child: Text(
                  'Utilisée par les modèles promo, les codes QR et le logo ajouté à chaque nouveau montage.',
                  style: KatType.ui(14, color: k.inkMuted),
                ),
              ),
              const SectionHeader('Logo'),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                child: Row(
                  children: [
                    Container(
                      width: 72,
                      height: 72,
                      decoration: BoxDecoration(
                        color: k.sheetAlt,
                        borderRadius: BorderRadius.circular(KatRadius.tile),
                        border: Border.all(color: k.line),
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: kit.hasLogo
                          ? Padding(
                              padding: const EdgeInsets.all(KatSpace.s8),
                              child: Image.file(File(kit.logoPath!), fit: BoxFit.contain, errorBuilder: (_, _, _) => const SizedBox.shrink()),
                            )
                          : Icon(Icons.storefront_rounded, color: k.inkMuted),
                    ),
                    const SizedBox(width: KatSpace.s16),
                    Expanded(
                      child: Wrap(
                        spacing: KatSpace.s8,
                        runSpacing: KatSpace.s8,
                        children: [
                          KatButton(
                            label: kit.hasLogo ? 'Changer' : 'Choisir le logo',
                            icon: Icons.add_photo_alternate_rounded,
                            kind: KatButtonKind.secondary,
                            compact: true,
                            onPressed: _pickLogo,
                          ),
                          if (kit.hasLogo)
                            KatButton(
                              label: 'Retirer',
                              kind: KatButtonKind.ghost,
                              compact: true,
                              onPressed: () => brand.update(kit.copyWith(clearLogo: true)),
                            ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
              MergeSemantics(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s12, KatSpace.gutter, 0),
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Logo sur chaque montage', style: KatType.ui(16, weight: FontWeight.w500, color: k.ink)),
                            Text('Placé dans un coin dès l’import, tu peux le retirer.', style: KatType.ui(14, color: k.inkMuted)),
                          ],
                        ),
                      ),
                      Switch(value: kit.autoLogo, onChanged: (v) => brand.update(kit.copyWith(autoLogo: v))),
                    ],
                  ),
                ),
              ),
              const SectionHeader('Coordonnées'),
              _field(k, 'Nom de la boutique', _name, hint: 'Delicat Store Haiti'),
              _field(k, 'Lien de la boutique', _link, hint: 'delicastoreha.com', type: TextInputType.url, code: true),
              _field(k, 'Numéro WhatsApp', _wa, hint: '+509 …', type: TextInputType.phone, code: true),
              Padding(
                padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, 0),
                child: Text('Avec l’indicatif du pays, pour le code QR « Mon WhatsApp » et les modèles.', style: KatType.ui(14, color: k.inkMuted)),
              ),
              const SectionHeader('Couleur principale'),
              ColorSwatches(colors: kSwatches, value: kit.primary, onChanged: (c) => brand.update(kit.copyWith(primary: c))),
              const SectionHeader('Couleur d’accent'),
              ColorSwatches(colors: kSwatches, value: kit.accent, onChanged: (c) => brand.update(kit.copyWith(accent: c))),
              const SectionHeader('Police des titres'),
              SizedBox(
                height: 60,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: 4),
                  itemCount: kTextFonts.length,
                  separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s8),
                  itemBuilder: (context, i) {
                    final (family, label) = kTextFonts[i];
                    final sel = family == kit.font;
                    return Pressable(
                      onTap: () => brand.update(kit.copyWith(font: family)),
                      selected: sel,
                      semanticLabel: 'Police $label',
                      child: AnimatedContainer(
                        duration: KatMotion.paint,
                        padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: sel ? k.ink : k.sheet,
                          borderRadius: BorderRadius.circular(KatRadius.button),
                          border: Border.all(color: sel ? k.ink : k.line),
                        ),
                        child: Text(label, style: TextStyle(fontFamily: family, fontSize: 16, color: sel ? k.canvas : k.ink)),
                      ),
                    );
                  },
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
