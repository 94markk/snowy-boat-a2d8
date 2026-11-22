import 'dart:io';

import 'package:material_ui/material_ui.dart';

import '../core/creations.dart';
import '../core/drafts.dart';
import '../core/recommendations.dart';
import '../core/settings.dart';
import '../features/launch.dart';
import '../features/templates/template_screens.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/kat_button.dart';
import '../widgets/kat_voucher.dart';
import '../widgets/pressable.dart';
import '../widgets/settle.dart';
import 'creation_viewer.dart';
import 'drafts_screen.dart';
import 'shell.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  String _greeting() {
    final h = DateTime.now().hour;
    if (h < 12) return 'Bonjour';
    if (h < 18) return 'Bon après-midi';
    return 'Bonsoir';
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final tools = <(StudioTool, IconData, String, String, String?)>[
      (StudioTool.video, Icons.movie_rounded, 'Montage vidéo', 'Clips, musique, voix off et logo en MP4', null),
      (StudioTool.cutout, Icons.content_cut_rounded, 'Retirer le fond', 'Détoure un sujet en un geste', null),
      (StudioTool.collage, Icons.dashboard_rounded, 'Collage', 'Jusqu’à 9 photos, dispositions au choix', null),
      (StudioTool.styles, Icons.palette_rounded, 'Styles', '24 looks à doser au pourcentage près', null),
      (StudioTool.retouch, Icons.tune_rounded, 'Retouche', 'Recadrer, régler, écrire, dessiner', null),
      (StudioTool.portrait, Icons.blur_on_rounded, 'Portrait flou', 'Flou d’arrière-plan façon objectif pro', null),
      (StudioTool.sticker, Icons.emoji_emotions_rounded, 'Sticker', 'Contour blanc et fond transparent', null),
      (StudioTool.selective, Icons.contrast_rounded, 'Couleur sélective', 'Sujet en couleur, fond en noir et blanc', null),
      (StudioTool.colorErase, Icons.format_color_reset_rounded, 'Gomme couleur', 'Efface un fond uni en un toucher', null),
    ];

    return SafeArea(
      bottom: false,
      child: ListenableBuilder(
        listenable: creations,
        builder: (context, _) {
          final items = creations.items;
          return CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, KatSpace.s16),
                  child: Row(
                    children: [
                      Text('Delicat', style: KatType.ui(24, weight: FontWeight.w700, condensed: true, color: k.ink)),
                      const SizedBox(width: KatSpace.s4),
                      Text('Studio', style: KatType.ui(24, weight: FontWeight.w400, condensed: true, color: k.inkMuted)),
                    ],
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                  child: Settle(
                    child: KatVoucher(
                      body: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(_greeting(), style: KatType.ui(14, color: k.onKatMuted)),
                          const SizedBox(height: KatSpace.s4),
                          Text('Que crée-t-on aujourd’hui ?',
                              style: KatType.ui(36, weight: FontWeight.w700, condensed: true, color: k.onKat)),
                          const SizedBox(height: KatSpace.s20),
                          Row(
                            children: [
                              _Stat(value: '${items.length}', label: items.length > 1 ? 'créations' : 'création'),
                              const SizedBox(width: KatSpace.s32),
                              _Stat(value: '${creations.thisWeek}', label: 'cette semaine'),
                            ],
                          ),
                          const SizedBox(height: KatSpace.s12),
                          Row(
                            children: [
                              Icon(Icons.lock_rounded, size: 16, color: k.onKatMuted),
                              const SizedBox(width: KatSpace.s8),
                              Expanded(
                                child: Text('Tes photos restent sur ton téléphone.', style: KatType.ui(14, color: k.onKatMuted)),
                              ),
                            ],
                          ),
                        ],
                      ),
                      stub: Column(
                        children: [
                          Settle(
                            delay: KatMotion.stagger,
                            child: KatButton(
                              label: 'Monter une vidéo',
                              icon: Icons.movie_rounded,
                              expand: true,
                              onPressed: () => openTool(context, StudioTool.video),
                            ),
                          ),
                          const SizedBox(height: KatSpace.s8),
                          Settle(
                            delay: KatMotion.stagger * 2,
                            child: Row(
                              children: [
                                Expanded(
                                  child: KatButton(
                                    label: 'Détourer',
                                    icon: Icons.content_cut_rounded,
                                    kind: KatButtonKind.onKat,
                                    expand: true,
                                    onPressed: () => openTool(context, StudioTool.cutout),
                                  ),
                                ),
                                const SizedBox(width: KatSpace.s8),
                                Expanded(
                                  child: KatButton(
                                    label: 'Collage',
                                    icon: Icons.dashboard_rounded,
                                    kind: KatButtonKind.onKat,
                                    expand: true,
                                    onPressed: () => openTool(context, StudioTool.collage),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              SliverToBoxAdapter(
                child: ListenableBuilder(
                  listenable: drafts,
                  builder: (context, _) {
                    final list = drafts.items;
                    if (list.isEmpty) return const SizedBox.shrink();
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SectionHeader(
                          'Reprendre',
                          action: list.length > 4 ? 'Tout voir' : null,
                          onAction: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const DraftsScreen())),
                        ),
                        SizedBox(
                          height: 178,
                          child: ListView.separated(
                            scrollDirection: Axis.horizontal,
                            padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                            itemCount: list.length.clamp(0, 8),
                            separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s12),
                            itemBuilder: (context, i) => DraftCard(draft: list[i]),
                          ),
                        ),
                      ],
                    );
                  },
                ),
              ),
              const SliverToBoxAdapter(child: SectionHeader('Modèles promo')),
              const SliverToBoxAdapter(child: TemplateStrip()),
              const SliverToBoxAdapter(child: SectionHeader('Recommandé pour toi')),
              SliverToBoxAdapter(child: _Recommendations(items: items)),
              const SliverToBoxAdapter(child: SectionHeader('Outils')),
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: 2,
                    mainAxisSpacing: KatSpace.s12,
                    crossAxisSpacing: KatSpace.s12,
                    mainAxisExtent: 168,
                  ),
                  delegate: SliverChildListDelegate([
                    for (final t in tools)
                      ToolTile(icon: t.$2, title: t.$3, subtitle: t.$4, badge: t.$5, onTap: () => openTool(context, t.$1)),
                  ]),
                ),
              ),
              SliverToBoxAdapter(
                child: SectionHeader(
                  'Récentes',
                  action: items.isEmpty ? null : 'Tout voir',
                  onAction: () => AppShell.of(context)?.goTo(1),
                ),
              ),
              SliverToBoxAdapter(
                child: items.isEmpty
                    ? Padding(
                        padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                        child: KatSheet(
                          child: Row(
                            children: [
                              Icon(Icons.photo_library_rounded, color: k.inkMuted),
                              const SizedBox(width: KatSpace.s12),
                              Expanded(
                                child: Text('Tes créations apparaîtront ici dès ta première image enregistrée.',
                                    style: KatType.ui(14, color: k.inkMuted)),
                              ),
                            ],
                          ),
                        ),
                      )
                    : SizedBox(
                        height: 112,
                        child: ListView.separated(
                          scrollDirection: Axis.horizontal,
                          padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                          itemCount: items.length.clamp(0, 12),
                          separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s8),
                          itemBuilder: (context, i) {
                            final c = items[i];
                            return Pressable(
                              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => CreationViewer(creation: c))),
                              semanticLabel: '${c.kind.label} ${c.fileName}',
                              child: ClipRRect(
                                borderRadius: BorderRadius.circular(KatRadius.tile),
                                child: Container(
                                  width: 112,
                                  color: k.sheetAlt,
                                  child: Image.file(File(c.thumb), fit: BoxFit.cover, cacheWidth: 240, errorBuilder: (_, _, _) => const SizedBox.shrink()),
                                ),
                              ),
                            );
                          },
                        ),
                      ),
              ),
              const SliverToBoxAdapter(child: SizedBox(height: KatSpace.s32)),
            ],
          );
        },
      ),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.value, required this.label});
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(value, style: KatType.ui(36, weight: FontWeight.w700, condensed: true, tabular: true, color: k.onKat)),
        Text(label, style: KatType.ui(14, color: k.onKatMuted)),
      ],
    );
  }
}


class _Recommendations extends StatelessWidget {
  const _Recommendations({required this.items});
  final List<Creation> items;

  static const _icons = <RecAction, IconData>{
    RecAction.video: Icons.movie_rounded,
    RecAction.logoVideo: Icons.branding_watermark_rounded,
    RecAction.flagDay: Icons.flag_rounded,
    RecAction.sticker: Icons.emoji_emotions_rounded,
    RecAction.collageStory: Icons.view_agenda_rounded,
    RecAction.portrait: Icons.blur_on_rounded,
    RecAction.styleJacmel: Icons.palette_rounded,
    RecAction.colorErase: Icons.format_color_reset_rounded,
    RecAction.selective: Icons.contrast_rounded,
    RecAction.polaroid: Icons.photo_rounded,
  };

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final made = <CreationKind, int>{};
    for (final c in items) {
      made[c.kind] = (made[c.kind] ?? 0) + 1;
    }
    return ListenableBuilder(
      listenable: settings,
      builder: (context, _) {
        final recs = recommend(uses: settings.toolUses, made: made, now: DateTime.now());
        return SizedBox(
          height: 176,
          child: ListView.separated(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
            itemCount: recs.length,
            separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s12),
            itemBuilder: (context, i) {
              final r = recs[i];
              return Pressable(
                onTap: () => openRecommendation(context, r.action),
                semanticLabel: r.title,
                child: SizedBox(
                  width: 236,
                  child: KatSheet(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Container(
                              width: 36,
                              height: 36,
                              decoration: BoxDecoration(color: k.sheetAlt, borderRadius: BorderRadius.circular(KatRadius.tile)),
                              child: Icon(_icons[r.action], size: 20, color: k.ink),
                            ),
                            const Spacer(),
                            Icon(Icons.chevron_right_rounded, color: k.inkMuted),
                          ],
                        ),
                        const SizedBox(height: KatSpace.s12),
                        Text(r.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: KatType.ui(16, weight: FontWeight.w600, color: k.ink, height: 1.25)),
                        const SizedBox(height: KatSpace.s4),
                        Text(r.reason, maxLines: 2, overflow: TextOverflow.ellipsis, style: KatType.ui(14, color: k.inkMuted, height: 1.3)),
                      ],
                    ),
                  ),
                ),
              );
            },
          ),
        );
      },
    );
  }
}
