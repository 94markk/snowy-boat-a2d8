import 'dart:io';

import 'package:material_ui/material_ui.dart';

import '../core/drafts.dart';
import '../core/image_io.dart';
import '../features/launch.dart';
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/pressable.dart';

/// Thumbnail card for a draft, used on Home (« Reprendre ») and in the drafts list.
class DraftCard extends StatelessWidget {
  const DraftCard({super.key, required this.draft, this.width = 132});
  final Draft draft;
  final double width;

  IconData get _icon => switch (draft.kind) {
        DraftKind.video => Icons.movie_rounded,
        DraftKind.photo => Icons.tune_rounded,
        DraftKind.collage => Icons.dashboard_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final thumb = draft.thumb;
    return Pressable(
      onTap: () => openDraft(context, draft),
      semanticLabel: '${draft.kind.label}, ${timeAgo(draft.updated)}',
      child: SizedBox(
        width: width,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            AspectRatio(
              aspectRatio: 1,
              child: ClipRRect(
                borderRadius: BorderRadius.circular(KatRadius.tile),
                child: Stack(
                  fit: StackFit.expand,
                  children: [
                    ColoredBox(color: k.sheetAlt),
                    if (thumb != null)
                      Image.file(
                        File(thumb),
                        fit: BoxFit.cover,
                        cacheWidth: 300,
                        errorBuilder: (_, _, _) => const SizedBox.shrink(),
                      ),
                    Positioned(
                      left: KatSpace.s8,
                      top: KatSpace.s8,
                      child: Container(
                        width: 28,
                        height: 28,
                        decoration: BoxDecoration(color: const Color(0xB3000000), borderRadius: BorderRadius.circular(8)),
                        child: Icon(_icon, size: 16, color: const Color(0xFFFFFFFF)),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: KatSpace.s8),
            Text(draft.kind.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: KatType.ui(14, weight: FontWeight.w600, color: k.ink)),
            Text(timeAgo(draft.updated), maxLines: 1, style: KatType.ui(12, color: k.inkMuted)),
          ],
        ),
      ),
    );
  }
}

class DraftsScreen extends StatelessWidget {
  const DraftsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('Brouillons', style: KatType.ui(20, weight: FontWeight.w600, color: k.ink))),
      body: ListenableBuilder(
        listenable: drafts,
        builder: (context, _) {
          final items = drafts.items;
          if (items.isEmpty) {
            return Center(
              child: Padding(
                padding: const EdgeInsets.all(KatSpace.s32),
                child: Text(
                  'Rien en cours. Tes montages, retouches et collages sont gardés ici automatiquement.',
                  textAlign: TextAlign.center,
                  style: KatType.ui(16, color: k.inkMuted),
                ),
              ),
            );
          }
          return CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, KatSpace.s16),
                  child: FutureBuilder<int>(
                    future: drafts.totalBytes(),
                    builder: (context, snap) => Text(
                      snap.hasData
                          ? 'Les brouillons occupent ${formatBytes(snap.data!)} sur ton téléphone. Appui long pour en retirer un.'
                          : 'Appui long sur un brouillon pour le retirer.',
                      style: KatType.ui(14, color: k.inkMuted),
                    ),
                  ),
                ),
              ),
              SliverPadding(
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                sliver: SliverGrid(
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                    maxCrossAxisExtent: 180,
                    mainAxisSpacing: KatSpace.s16,
                    crossAxisSpacing: KatSpace.s12,
                    childAspectRatio: 0.74,
                  ),
                  delegate: SliverChildBuilderDelegate(
                    (context, i) {
                      final d = items[i];
                      return GestureDetector(
                        onLongPress: () async {
                          final ok = await confirmKat(
                            context,
                            title: 'Retirer ce brouillon ?',
                            message: 'Ses fichiers seront effacés de l’appli. Tes créations enregistrées restent.',
                            confirm: 'Retirer',
                          );
                          if (ok) await drafts.remove(d.id);
                        },
                        child: LayoutBuilder(builder: (context, box) => DraftCard(draft: d, width: box.maxWidth)),
                      );
                    },
                    childCount: items.length,
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
