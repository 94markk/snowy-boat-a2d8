import 'package:material_ui/material_ui.dart';

import '../theme/tokens.dart';
import 'kat_button.dart';
import 'pressable.dart';

/// Section title with an optional trailing action.
class SectionHeader extends StatelessWidget {
  const SectionHeader(this.title, {super.key, this.action, this.onAction});
  final String title;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s24, KatSpace.s4, KatSpace.s8),
      child: Row(
        children: [
          Expanded(child: Text(title, style: KatType.ui(20, weight: FontWeight.w600, color: k.ink))),
          if (action != null)
            Pressable(
              onTap: onAction,
              semanticLabel: action,
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: KatSpace.minTap, minWidth: KatSpace.minTap),
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
                  child: Center(child: Text(action!, style: KatType.ui(14, weight: FontWeight.w600, color: k.ink))),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

/// Fuchsia badge (badges and unread dots only).
class KatBadge extends StatelessWidget {
  const KatBadge(this.label, {super.key});
  final String label;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: KatSpace.s8, vertical: 2),
      decoration: BoxDecoration(color: k.badge, borderRadius: BorderRadius.circular(999)),
      child: Text(label, style: KatType.ui(12, weight: FontWeight.w600, color: KatPalette.white)),
    );
  }
}

/// A white sheet with a hairline border (no shadows).
class KatSheet extends StatelessWidget {
  const KatSheet({super.key, required this.child, this.padding = const EdgeInsets.all(KatSpace.s16), this.radius = KatRadius.tile, this.color});
  final Widget child;
  final EdgeInsets padding;
  final double radius;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        color: color ?? k.sheet,
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: k.line),
      ),
      child: child,
    );
  }
}

/// Tool tile for the home grid.
class ToolTile extends StatelessWidget {
  const ToolTile({super.key, required this.icon, required this.title, required this.subtitle, required this.onTap, this.badge});
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  final String? badge;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Pressable(
      onTap: onTap,
      semanticLabel: title,
      child: KatSheet(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(color: k.sheetAlt, borderRadius: BorderRadius.circular(KatRadius.tile)),
                  child: Icon(icon, color: k.ink, size: 24),
                ),
                const Spacer(),
                if (badge != null) KatBadge(badge!),
              ],
            ),
            const SizedBox(height: KatSpace.s12),
            Text(title, style: KatType.ui(16, weight: FontWeight.w600, color: k.ink), maxLines: 1, overflow: TextOverflow.ellipsis),
            const SizedBox(height: KatSpace.s4),
            Text(subtitle, style: KatType.ui(14, color: k.inkMuted), maxLines: 2, overflow: TextOverflow.ellipsis),
          ],
        ),
      ),
    );
  }
}

/// Labelled slider with a tabular value readout.
class SliderRow extends StatelessWidget {
  const SliderRow({
    super.key,
    required this.label,
    required this.value,
    required this.min,
    required this.max,
    required this.onChanged,
    this.onChangeEnd,
    this.format,
    this.divisions,
  });

  final String label;
  final double value;
  final double min;
  final double max;
  final ValueChanged<double> onChanged;
  final ValueChanged<double>? onChangeEnd;
  final String Function(double v)? format;
  final int? divisions;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final text = format?.call(value) ?? value.round().toString();
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
      child: Row(
        children: [
          SizedBox(width: 104, child: Text(label, style: KatType.ui(14, color: k.inkMuted), maxLines: 1, overflow: TextOverflow.ellipsis)),
          Expanded(
            child: Slider(
              value: value.clamp(min, max),
              min: min,
              max: max,
              divisions: divisions,
              onChanged: onChanged,
              onChangeEnd: onChangeEnd,
              semanticFormatterCallback: (v) => '$label ${format?.call(v) ?? v.round()}',
            ),
          ),
          SizedBox(
            width: 48,
            child: Text(text, textAlign: TextAlign.right, style: KatType.ui(14, weight: FontWeight.w600, tabular: true, color: k.ink)),
          ),
        ],
      ),
    );
  }
}

/// Horizontal single-choice chips (44px hit area, 36px visual).
class KatChoices<T> extends StatelessWidget {
  const KatChoices({super.key, required this.items, required this.value, required this.onChanged, required this.label, this.icons});
  final List<T> items;
  final T value;
  final ValueChanged<T> onChanged;
  final String Function(T) label;
  final IconData? Function(T)? icons;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SizedBox(
      height: KatSpace.minTap + 8,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: 4),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s8),
        itemBuilder: (context, i) {
          final it = items[i];
          final sel = it == value;
          final ic = icons?.call(it);
          return Pressable(
            onTap: () => onChanged(it),
            selected: sel,
            semanticLabel: label(it),
            child: AnimatedContainer(
              duration: KatMotion.paint,
              curve: Curves.easeInOut,
              padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: sel ? k.ink : k.sheet,
                borderRadius: BorderRadius.circular(KatRadius.button),
                border: Border.all(color: sel ? k.ink : k.line),
              ),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (ic != null) ...[Icon(ic, size: 18, color: sel ? k.canvas : k.ink), const SizedBox(width: KatSpace.s8)],
                  Text(label(it), style: KatType.ui(14, weight: FontWeight.w600, color: sel ? k.canvas : k.ink)),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Palette used for backgrounds, text and borders.
const kSwatches = <Color>[
  Color(0xFFFFFFFF),
  Color(0xFF000000),
  Color(0xFF141834),
  Color(0xFF4B3FE0),
  Color(0xFFC8358F),
  Color(0xFFF5B400),
  Color(0xFFE84A3C),
  Color(0xFFFF8A3D),
  Color(0xFF2BB673),
  Color(0xFF0FA3B1),
  Color(0xFF2D7FF9),
  Color(0xFFF7D9E3),
  Color(0xFFFFF4DE),
  Color(0xFFDDF3E7),
  Color(0xFFDCE8FF),
  Color(0xFF8C6A4F),
];

String hexOf(Color c) {
  final v = c.toARGB32() & 0xFFFFFF;
  return '#${v.toRadixString(16).padLeft(6, '0').toUpperCase()}';
}

class ColorSwatches extends StatelessWidget {
  const ColorSwatches({super.key, required this.value, required this.onChanged, this.colors = kSwatches});
  final Color? value;
  final ValueChanged<Color> onChanged;
  final List<Color> colors;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SizedBox(
      height: KatSpace.minTap + 8,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
        itemCount: colors.length,
        itemBuilder: (context, i) {
          final c = colors[i];
          final sel = value != null && c.toARGB32() == value!.toARGB32();
          return Pressable(
            onTap: () => onChanged(c),
            selected: sel,
            semanticLabel: 'Couleur ${hexOf(c)}',
            child: SizedBox.square(
              dimension: 48,
              child: Center(
                child: AnimatedContainer(
                  duration: KatMotion.paint,
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: c,
                    shape: BoxShape.circle,
                    border: Border.all(color: sel ? k.ink : k.line, width: sel ? 3 : 1),
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Transparency checkerboard.
class CheckerPainter extends CustomPainter {
  const CheckerPainter(this.a, this.b, {this.cell = 12});
  final Color a;
  final Color b;
  final double cell;

  @override
  void paint(Canvas canvas, Size size) {
    canvas.drawRect(Offset.zero & size, Paint()..color = a);
    final p = Paint()..color = b;
    for (var y = 0.0, r = 0; y < size.height; y += cell, r++) {
      for (var x = (r.isOdd ? cell : 0.0); x < size.width; x += cell * 2) {
        canvas.drawRect(Rect.fromLTWH(x, y, cell, cell), p);
      }
    }
  }

  @override
  bool shouldRepaint(CheckerPainter old) => old.a != a || old.b != b || old.cell != cell;
}

/// Top bar shared by the editors: close, title, extra actions, one primary.
class EditorTopBar extends StatelessWidget {
  const EditorTopBar({
    super.key,
    required this.title,
    required this.onClose,
    this.actions = const [],
    this.primaryLabel = 'Enregistrer',
    this.onPrimary,
  });

  final String title;
  final VoidCallback onClose;
  final List<Widget> actions;
  final String primaryLabel;
  final VoidCallback? onPrimary;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return SafeArea(
      bottom: false,
      child: SizedBox(
        height: 60,
        child: Row(
          children: [
            const SizedBox(width: KatSpace.s4),
            KatIconButton(icon: Icons.close_rounded, tooltip: 'Fermer', onPressed: onClose),
            Expanded(
              child: Text(title, style: KatType.ui(16, weight: FontWeight.w600, color: k.ink), maxLines: 1, overflow: TextOverflow.ellipsis),
            ),
            ...actions,
            const SizedBox(width: KatSpace.s4),
            KatButton(label: primaryLabel, onPressed: onPrimary, compact: true),
            const SizedBox(width: KatSpace.s12),
          ],
        ),
      ),
    );
  }
}

class ToolItem {
  const ToolItem(this.id, this.icon, this.label);
  final String id;
  final IconData icon;
  final String label;
}

/// Bottom tool rail for the editors.
class EditorToolBar extends StatelessWidget {
  const EditorToolBar({super.key, required this.items, required this.selected, required this.onSelect});
  final List<ToolItem> items;
  final String? selected;
  final ValueChanged<String> onSelect;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Container(
      decoration: BoxDecoration(color: k.sheet, border: Border(top: BorderSide(color: k.line))),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 72,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: KatSpace.s8),
            children: [
              for (final it in items)
                Pressable(
                  onTap: () => onSelect(it.id),
                  selected: it.id == selected,
                  semanticLabel: it.label,
                  child: Container(
                    constraints: const BoxConstraints(minWidth: 72),
                    padding: const EdgeInsets.symmetric(horizontal: KatSpace.s8),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        AnimatedContainer(
                          duration: KatMotion.paint,
                          width: 52,
                          height: 32,
                          decoration: BoxDecoration(
                            color: it.id == selected ? k.primarySoft : const Color(0x00000000),
                            borderRadius: BorderRadius.circular(999),
                          ),
                          child: Icon(it.icon, size: 22, color: it.id == selected ? k.ink : k.inkMuted),
                        ),
                        const SizedBox(height: KatSpace.s4),
                        Text(
                          it.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: KatType.ui(12,
                              weight: it.id == selected ? FontWeight.w600 : FontWeight.w500, color: it.id == selected ? k.ink : k.inkMuted),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Panel above the tool rail that hosts the active tool's controls.
class ToolPanel extends StatelessWidget {
  const ToolPanel({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return Container(
      width: double.infinity,
      decoration: BoxDecoration(color: k.sheet, border: Border(top: BorderSide(color: k.line))),
      padding: const EdgeInsets.symmetric(vertical: KatSpace.s8),
      child: child,
    );
  }
}

void showKatSnack(BuildContext context, String message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(content: Text(message)));
}

/// Confirmation dialog with a clear verb.
Future<bool> confirmKat(BuildContext context, {required String title, required String message, required String confirm}) async {
  final k = KatColors.of(context);
  final ok = await showDialog<bool>(
    context: context,
    builder: (c) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actionsPadding: const EdgeInsets.fromLTRB(KatSpace.s16, 0, KatSpace.s16, KatSpace.s16),
      actions: [
        KatButton(label: 'Annuler', kind: KatButtonKind.ghost, onPressed: () => Navigator.pop(c, false), compact: true),
        Pressable(
          onTap: () => Navigator.pop(c, true),
          semanticLabel: confirm,
          child: Container(
            height: 44,
            padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16),
            alignment: Alignment.center,
            decoration: BoxDecoration(color: k.danger, borderRadius: BorderRadius.circular(KatRadius.button)),
            child: Text(confirm, style: KatType.ui(14, weight: FontWeight.w600, color: KatPalette.white)),
          ),
        ),
      ],
    ),
  );
  return ok ?? false;
}
