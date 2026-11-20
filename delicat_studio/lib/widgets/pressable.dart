import 'package:material_ui/material_ui.dart';

import '../core/settings.dart';
import '../theme/tokens.dart';

/// Press feedback: scales to .96 over 320ms with Cubic(.16, 1, .3, 1).
/// Motion is skipped under reduced motion / low-power mode.
class Pressable extends StatefulWidget {
  const Pressable({
    super.key,
    required this.child,
    this.onTap,
    this.onLongPress,
    this.semanticLabel,
    this.enabled = true,
    this.selected = false,
  });

  final Widget child;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final String? semanticLabel;
  final bool enabled;
  final bool selected;

  @override
  State<Pressable> createState() => _PressableState();
}

class _PressableState extends State<Pressable> {
  bool _down = false;

  void _set(bool v) {
    if (_down != v && mounted) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) {
    final active = widget.enabled && (widget.onTap != null || widget.onLongPress != null);
    final still = reduceMotion(context);
    return Semantics(
      button: true,
      enabled: active,
      selected: widget.selected,
      label: widget.semanticLabel,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: active ? (_) => _set(true) : null,
        onTapUp: active ? (_) => _set(false) : null,
        onTapCancel: active ? () => _set(false) : null,
        onTap: active ? widget.onTap : null,
        onLongPress: active ? widget.onLongPress : null,
        child: AnimatedScale(
          scale: _down && !still ? 0.96 : 1,
          duration: still ? Duration.zero : KatMotion.press,
          curve: KatMotion.curve,
          child: AnimatedOpacity(
            opacity: widget.enabled ? 1 : 0.4,
            duration: still ? Duration.zero : KatMotion.paint,
            child: widget.child,
          ),
        ),
      ),
    );
  }
}
