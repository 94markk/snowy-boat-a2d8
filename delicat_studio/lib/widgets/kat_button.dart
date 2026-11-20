import 'package:material_ui/material_ui.dart';

import '../theme/tokens.dart';
import 'pressable.dart';

enum KatButtonKind {
  /// Indigo. One per screen.
  primary,

  /// Outlined with a hairline.
  secondary,

  /// Text only, on any surface.
  ghost,

  /// White on the obsidian kat voucher.
  onKat,
}

class KatButton extends StatelessWidget {
  const KatButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.kind = KatButtonKind.primary,
    this.expand = false,
    this.loading = false,
    this.compact = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final KatButtonKind kind;
  final bool expand;
  final bool loading;

  /// 44px high instead of 52px, for top bars.
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final (Color bg, Color fg, Color border) = switch (kind) {
      KatButtonKind.primary => (k.primary, k.onPrimary, k.primary),
      KatButtonKind.secondary => (const Color(0x00000000), k.ink, k.line),
      KatButtonKind.ghost => (const Color(0x00000000), k.ink, const Color(0x00000000)),
      KatButtonKind.onKat => (const Color(0x00000000), k.onKat, k.katLine == k.kat ? const Color(0xFF3A3F6B) : k.katLine),
    };
    final content = Row(
      mainAxisSize: expand ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (loading)
          SizedBox.square(
            dimension: 20,
            child: CircularProgressIndicator(strokeWidth: 2, color: fg, backgroundColor: const Color(0x00000000)),
          )
        else if (icon != null)
          Icon(icon, size: 20, color: fg),
        if (loading || icon != null) const SizedBox(width: KatSpace.s8),
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: KatType.ui(compact ? 14 : 16, weight: FontWeight.w600, color: fg),
          ),
        ),
      ],
    );
    return Pressable(
      onTap: loading ? null : onPressed,
      enabled: onPressed != null,
      semanticLabel: label,
      child: Container(
        height: compact ? 44 : 52,
        padding: EdgeInsets.symmetric(horizontal: compact ? KatSpace.s16 : KatSpace.s20),
        decoration: BoxDecoration(
          color: bg,
          borderRadius: BorderRadius.circular(KatRadius.button),
          border: Border.all(color: border),
        ),
        child: content,
      ),
    );
  }
}

/// Round icon button with a 44px hit area.
class KatIconButton extends StatelessWidget {
  const KatIconButton({
    super.key,
    required this.icon,
    required this.tooltip,
    this.onPressed,
    this.selected = false,
    this.filled = false,
    this.color,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback? onPressed;
  final bool selected;
  final bool filled;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Tooltip(
      message: tooltip,
      child: Pressable(
        onTap: onPressed,
        enabled: onPressed != null,
        semanticLabel: tooltip,
        selected: selected,
        child: SizedBox.square(
          dimension: KatSpace.minTap,
          child: Center(
            child: AnimatedContainer(
              duration: KatMotion.paint,
              curve: Curves.easeInOut,
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: selected ? k.primarySoft : (filled ? k.sheetAlt : const Color(0x00000000)),
                borderRadius: BorderRadius.circular(KatRadius.tile),
              ),
              child: Icon(icon, size: 22, color: color ?? k.ink),
            ),
          ),
        ),
      ),
    );
  }
}
