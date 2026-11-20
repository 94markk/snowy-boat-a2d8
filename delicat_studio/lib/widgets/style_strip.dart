import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../core/styles.dart';
import '../theme/tokens.dart';
import 'pressable.dart';

/// Horizontal strip of style previews rendered from the user's own photo.
class StyleStrip extends StatelessWidget {
  const StyleStrip({super.key, required this.image, required this.selected, required this.onSelect});
  final ui.Image image;
  final String selected;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SizedBox(
      height: 96,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
        itemCount: kStyles.length,
        separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s8),
        itemBuilder: (context, i) {
          final s = kStyles[i];
          final sel = s.id == selected;
          return Pressable(
            onTap: () => onSelect(s.id),
            selected: sel,
            semanticLabel: 'Style ${s.name}',
            child: SizedBox(
              width: 64,
              child: Column(
                children: [
                  AnimatedContainer(
                    duration: KatMotion.paint,
                    width: 64,
                    height: 64,
                    padding: const EdgeInsets.all(2),
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(KatRadius.tile),
                      border: Border.all(color: sel ? k.ink : k.line, width: sel ? 2 : 1),
                    ),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(KatRadius.tile - 3),
                      child: ColorFiltered(
                        colorFilter: ColorFilter.matrix(s.matrix()),
                        child: RawImage(image: image, fit: BoxFit.cover, filterQuality: FilterQuality.low),
                      ),
                    ),
                  ),
                  const SizedBox(height: KatSpace.s4),
                  Text(
                    s.name,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: KatType.ui(12, weight: sel ? FontWeight.w600 : FontWeight.w500, color: sel ? k.ink : k.inkMuted),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}
