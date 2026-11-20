import 'dart:io';

import 'package:material_ui/material_ui.dart';

import '../core/creations.dart';
import '../features/launch.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/kat_button.dart';
import '../widgets/kat_voucher.dart';
import '../widgets/pressable.dart';
import '../widgets/settle.dart';
import 'creation_viewer.dart';
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
      (StudioTool.cutout, Icons.content_cut_rounded, 'Retirer le fond', 'Détoure un sujet en un geste', 'Nouveau'),
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
                              label: 'Retirer un arrière-plan',
                              icon: Icons.content_cut_rounded,
                              expand: true,
                              onPressed: () => openTool(context, StudioTool.cutout),
                            ),
                          ),
                          const SizedBox(height: KatSpace.s8),
                          Settle(
                            delay: KatMotion.stagger * 2,
                            child: KatButton(
                              label: 'Créer un collage',
                              icon: Icons.dashboard_rounded,
                              kind: KatButtonKind.onKat,
                              expand: true,
                              onPressed: () => openTool(context, StudioTool.collage),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
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
                                  child: Image.file(File(c.thumb), fit: BoxFit.cover, cacheWidth: 240),
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
