import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';

import '../../core/brand.dart';
import '../../core/crash_log.dart';
import '../../core/image_io.dart';
import '../../core/music_library.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/pressable.dart';
import '../editor/editor_model.dart';
import '../editor/editor_screen.dart';
import '../editor/layer_tools.dart';
import '../video/video_editor_screen.dart';
import '../video/video_model.dart';
import 'templates.dart';

/// Brand logo shape, needed to place it without stretching.
double _logoAspect() {
  final p = brand.kit.logoPath;
  final img = p == null ? null : LayerImages.instance[p];
  return img == null ? 1 : img.width / img.height;
}

class _PosterPreview extends StatelessWidget {
  const _PosterPreview({required this.spec});
  final PosterSpec spec;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: Listenable.merge([LayerImages.instance, brand]),
      builder: (context, _) => AspectRatio(
        aspectRatio: spec.format.width / spec.format.height,
        child: ClipRRect(
          borderRadius: BorderRadius.circular(KatRadius.tile),
          child: CustomPaint(painter: _PosterPainter(spec, brand.kit, _logoAspect())),
        ),
      ),
    );
  }
}

class _PosterPainter extends CustomPainter {
  _PosterPainter(this.spec, this.kit, this.logoAspect);
  final PosterSpec spec;
  final BrandKit kit;
  final double logoAspect;

  @override
  void paint(Canvas canvas, Size size) => paintPoster(canvas, size, spec, kit, logoAspect: logoAspect);

  @override
  bool shouldRepaint(_PosterPainter old) => true;
}

/// Horizontal strip of templates for Home.
class TemplateStrip extends StatelessWidget {
  const TemplateStrip({super.key});

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final logo = brand.kit.logoPath;
    if (logo != null) LayerImages.instance.ensure(logo);
    return SizedBox(
      height: 252,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
        itemCount: kPromoTemplates.length,
        separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s12),
        itemBuilder: (context, i) {
          final t = kPromoTemplates[i];
          return Pressable(
            onTap: () => openTemplate(context, t),
            semanticLabel: 'Modèle ${t.title}',
            child: SizedBox(
              width: 112,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _PosterPreview(spec: PosterSpec(template: t, product: t.options[PromoLang.fr]?.firstOrNull ?? '')),
                  const SizedBox(height: KatSpace.s8),
                  Text(t.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: KatType.ui(14, weight: FontWeight.w600, color: k.ink, height: 1.2)),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

Future<void> openTemplate(BuildContext context, PromoTemplate t) {
  settings.recordUse('template');
  return Navigator.of(context).push(MaterialPageRoute(builder: (_) => TemplateScreen(template: t)));
}

/// Form for one template: language, format, product, price, photo and QR code,
/// then a poster (photo editor) or a short video (video editor).
class TemplateScreen extends StatefulWidget {
  const TemplateScreen({super.key, required this.template});
  final PromoTemplate template;

  @override
  State<TemplateScreen> createState() => _TemplateScreenState();
}

class _TemplateScreenState extends State<TemplateScreen> {
  late PosterSpec _spec = PosterSpec(
    template: widget.template,
    product: widget.template.options[PromoLang.fr]?.firstOrNull ?? '',
  );
  late final TextEditingController _product = TextEditingController(text: _spec.product);
  final TextEditingController _price = TextEditingController();
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    final logo = brand.kit.logoPath;
    if (logo != null) LayerImages.instance.ensure(logo);
  }

  @override
  void dispose() {
    _product.dispose();
    _price.dispose();
    super.dispose();
  }

  void _set(PosterSpec s) => setState(() => _spec = s);

  void _setLang(PromoLang l) {
    // Keep the same quick choice when switching language.
    final opts = widget.template.options;
    final i = opts[_spec.lang]?.indexOf(_product.text.trim()) ?? -1;
    final next = i >= 0 ? opts[l]![i] : _product.text;
    _product.text = next;
    _set(_spec.copyWith(lang: l, product: next));
  }

  Future<void> _pickPicture() async {
    final pic = await pickLayerPicture(context, title: 'Photo du produit');
    if (pic == null || !mounted) return;
    await LayerImages.instance.ensure(pic.path);
    _set(_spec.copyWith(picture: pic));
  }

  Future<String> _writeBackground() async {
    final img = await renderPosterBackground(_spec, brand.kit);
    try {
      final png = await ImageIO.encode(img, ExportFormat.png);
      final dir = await getTemporaryDirectory();
      final f = File('${dir.path}/ds_poster_${DateTime.now().microsecondsSinceEpoch}.png');
      await f.writeAsBytes(png, flush: true);
      return f.path;
    } finally {
      img.dispose();
    }
  }

  Future<void> _poster() async {
    if (_busy) return;
    setState(() => _busy = true);
    final nav = Navigator.of(context);
    try {
      final path = await _writeBackground();
      final photo = await ImageIO.load(path);
      final layers = [for (final (l, _) in posterLayers(_spec, brand.kit, logoAspect: _logoAspect())) l];
      if (!mounted) return;
      await nav.push(MaterialPageRoute(
        builder: (_) => EditorScreen(photo: photo, initialTool: EditorTool.text, initialDoc: EditorDoc(layers: layers), title: 'Affiche'),
      ));
    } catch (e, s) {
      CrashLog.record(e, s, context: 'template poster');
      if (mounted) showKatSnack(context, 'L’affiche n’a pas pu être préparée. Réessaie.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _video() async {
    if (_busy) return;
    setState(() => _busy = true);
    final nav = Navigator.of(context);
    try {
      final path = await _writeBackground();
      final mood = MusicMood.values.firstWhere((m) => m.name == widget.template.music, orElse: () => MusicMood.energie);
      VMusic? music;
      try {
        final m = await MusicLibrary.pathFor(mood);
        music = VMusic(path: m, name: mood.label, sourceMs: MusicLibrary.lengthOf(mood).inMilliseconds.toDouble(), volume: 0.7);
      } catch (e, s) {
        CrashLog.record(e, s, context: 'template music');
      }
      final doc = posterVideo(_spec, brand.kit, backgroundPath: path, music: music, logoAspect: _logoAspect());
      if (!mounted) return;
      await nav.push(MaterialPageRoute(builder: (_) => VideoEditorScreen(initialDoc: doc)));
    } catch (e, s) {
      CrashLog.record(e, s, context: 'template video');
      if (mounted) showKatSnack(context, 'La vidéo n’a pas pu être préparée. Réessaie.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final t = widget.template;
    final opts = t.options[_spec.lang] ?? const [];
    final priceOk = _price.text.trim().isEmpty || formatHtg(_price.text) != null;
    return Scaffold(
      appBar: AppBar(title: Text(t.title, style: KatType.ui(20, weight: FontWeight.w600, color: k.ink))),
      body: Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.only(bottom: KatSpace.s24),
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, 0),
                  child: Center(
                    child: ConstrainedBox(
                      constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.4),
                      child: _PosterPreview(spec: _spec),
                    ),
                  ),
                ),
                const SectionHeader('Langue'),
                KatChoices<PromoLang>(items: PromoLang.values, value: _spec.lang, label: (l) => l.label, onChanged: _setLang),
                const SectionHeader('Format'),
                KatChoices<PosterFormat>(items: PosterFormat.values, value: _spec.format, label: (f) => f.label, onChanged: (f) => _set(_spec.copyWith(format: f))),
                SectionHeader(t.headlineIsProduct ? 'Message' : 'Produit'),
                if (opts.isNotEmpty)
                  KatChoices<String?>(
                    items: opts,
                    value: opts.contains(_product.text.trim()) ? _product.text.trim() : null,
                    label: (o) => o ?? '',
                    onChanged: (o) {
                      if (o == null) return;
                      _product.text = o;
                      _set(_spec.copyWith(product: o));
                    },
                  ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, 0),
                  child: TextField(
                    controller: _product,
                    textCapitalization: TextCapitalization.sentences,
                    style: KatType.ui(16, color: k.ink),
                    decoration: InputDecoration(hintText: t.productHint[_spec.lang]),
                    onChanged: (v) => _set(_spec.copyWith(product: v)),
                  ),
                ),
                if (!t.headlineIsProduct) ...[
                  const SectionHeader('Prix'),
                  Padding(
                    padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                    child: TextField(
                      controller: _price,
                      keyboardType: const TextInputType.numberWithOptions(decimal: true),
                      style: KatType.ui(16, color: k.ink, tabular: true),
                      decoration: InputDecoration(
                        hintText: 'En HTG, laisse vide pour ne pas l’afficher',
                        errorText: priceOk ? null : 'Écris un montant, par exemple 1500',
                      ),
                      onChanged: (v) => _set(_spec.copyWith(price: v)),
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, 0),
                    child: Text('Vérifie le prix sur la boutique avant de publier.', style: KatType.ui(14, color: k.inkMuted)),
                  ),
                ],
                const SectionHeader('Photo du produit'),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                  child: Wrap(
                    spacing: KatSpace.s8,
                    runSpacing: KatSpace.s8,
                    children: [
                      KatButton(
                        label: _spec.picture == null ? 'Ajouter une photo' : 'Changer la photo',
                        icon: Icons.add_photo_alternate_rounded,
                        kind: KatButtonKind.secondary,
                        compact: true,
                        onPressed: _pickPicture,
                      ),
                      if (_spec.picture != null)
                        KatButton(label: 'Retirer', kind: KatButtonKind.ghost, compact: true, onPressed: () => _set(_spec.copyWith(clearPicture: true))),
                    ],
                  ),
                ),
                MergeSemantics(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, 0),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('Code QR', style: KatType.ui(16, weight: FontWeight.w500, color: k.ink)),
                              Text(
                                brand.kit.whatsapp.trim().isEmpty ? 'Ouvre ${brand.kit.link}' : 'Ouvre ta conversation WhatsApp',
                                style: KatType.ui(14, color: k.inkMuted),
                              ),
                            ],
                          ),
                        ),
                        Switch(value: _spec.qr, onChanged: (v) => _set(_spec.copyWith(qr: v))),
                      ],
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, 0),
                  child: Text('Tout reste modifiable ensuite : textes, couleurs, position.', style: KatType.ui(14, color: k.inkMuted)),
                ),
              ],
            ),
          ),
          DecoratedBox(
            decoration: BoxDecoration(color: k.canvas, border: Border(top: BorderSide(color: k.line))),
            child: SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s12, KatSpace.gutter, KatSpace.s12),
                child: Row(
                  children: [
                    Expanded(
                      child: KatButton(label: 'Vidéo', icon: Icons.movie_rounded, kind: KatButtonKind.secondary, expand: true, onPressed: _busy || !priceOk ? null : _video),
                    ),
                    const SizedBox(width: KatSpace.s8),
                    Expanded(
                      child: KatButton(label: 'Affiche', icon: Icons.image_rounded, expand: true, loading: _busy, onPressed: !priceOk ? null : _poster),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
