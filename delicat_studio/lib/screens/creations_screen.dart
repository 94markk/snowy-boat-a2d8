import 'dart:io';

import 'package:material_ui/material_ui.dart';

import '../core/creations.dart';
import '../features/launch.dart';
import '../features/video/video_export.dart' show formatDuration;
import '../theme/tokens.dart';
import '../widgets/common.dart';
import '../widgets/kat_button.dart';
import '../widgets/pressable.dart';
import 'creation_viewer.dart';

class CreationsScreen extends StatefulWidget {
  const CreationsScreen({super.key});

  @override
  State<CreationsScreen> createState() => _CreationsScreenState();
}

class _CreationsScreenState extends State<CreationsScreen> {
  CreationKind? _filter;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SafeArea(
      bottom: false,
      child: ListenableBuilder(
        listenable: creations,
        builder: (context, _) {
          final all = creations.items;
          final items = _filter == null ? all : all.where((c) => c.kind == _filter).toList();
          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s16, KatSpace.gutter, KatSpace.s8),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Expanded(child: Text('Créations', style: KatType.ui(36, weight: FontWeight.w700, condensed: true, color: k.ink))),
                    Text('${all.length}', style: KatType.ui(24, weight: FontWeight.w600, condensed: true, tabular: true, color: k.inkMuted)),
                  ],
                ),
              ),
              if (all.isNotEmpty)
                KatChoices<CreationKind?>(
                  items: const [null, ...CreationKind.values],
                  value: _filter,
                  label: (v) => v?.label ?? 'Tout',
                  onChanged: (v) => setState(() => _filter = v),
                ),
              Expanded(
                child: all.isEmpty
                    ? _Empty(k: k)
                    : items.isEmpty
                        ? Center(child: Text('Aucune création de ce type pour l’instant.', style: KatType.ui(14, color: k.inkMuted)))
                        : GridView.builder(
                            padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s8, KatSpace.gutter, KatSpace.s24),
                            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                              crossAxisCount: 3,
                              mainAxisSpacing: KatSpace.s8,
                              crossAxisSpacing: KatSpace.s8,
                            ),
                            itemCount: items.length,
                            itemBuilder: (context, i) {
                              final c = items[i];
                              return Pressable(
                                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => CreationViewer(creation: c))),
                                semanticLabel: '${c.kind.label} ${c.fileName}',
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(KatRadius.tile),
                                  child: Stack(
                                    fit: StackFit.expand,
                                    children: [
                                      ColoredBox(color: k.sheetAlt),
                                      Image.file(File(c.thumb), fit: BoxFit.cover, cacheWidth: 300),
                                      if (c.isVideo)
                                        Positioned(
                                          left: KatSpace.s4,
                                          bottom: KatSpace.s4,
                                          child: Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                            decoration: BoxDecoration(color: const Color(0xB3000000), borderRadius: BorderRadius.circular(6)),
                                            child: Row(
                                              mainAxisSize: MainAxisSize.min,
                                              children: [
                                                const Icon(Icons.play_arrow_rounded, size: 14, color: Color(0xFFFFFFFF)),
                                                Text(formatDuration(c.durationMs ?? 0),
                                                    style: KatType.ui(12, weight: FontWeight.w600, tabular: true, color: const Color(0xFFFFFFFF))),
                                              ],
                                            ),
                                          ),
                                        ),
                                    ],
                                  ),
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

class _Empty extends StatelessWidget {
  const _Empty({required this.k});
  final KatColors k;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(KatSpace.s32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(color: k.sheetAlt, borderRadius: BorderRadius.circular(KatRadius.sheet)),
              child: Icon(Icons.photo_library_rounded, size: 32, color: k.ink),
            ),
            const SizedBox(height: KatSpace.s20),
            Text('Rien ici pour l’instant', style: KatType.ui(24, weight: FontWeight.w700, condensed: true, color: k.ink)),
            const SizedBox(height: KatSpace.s8),
            Text(
              'Chaque image que tu enregistres arrive ici, prête à partager.',
              textAlign: TextAlign.center,
              style: KatType.ui(16, color: k.inkMuted),
            ),
            const SizedBox(height: KatSpace.s24),
            KatButton(label: 'Retirer un arrière-plan', icon: Icons.content_cut_rounded, onPressed: () => openTool(context, StudioTool.cutout)),
          ],
        ),
      ),
    );
  }
}

/// Also used by the viewer to close itself after deletion.
Future<bool> deleteCreation(BuildContext context, Creation c) async {
  final ok = await confirmKat(
    context,
    title: 'Supprimer cette création ?',
    message: 'Elle disparaît de Delicat Studio. Une copie déjà ajoutée à la galerie reste dans la galerie.',
    confirm: 'Supprimer',
  );
  if (ok) await creations.remove(c);
  return ok;
}
