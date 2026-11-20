import 'package:material_ui/material_ui.dart';

import '../core/settings.dart';
import '../theme/tokens.dart';

/// The one orchestrated entrance per screen: content settles 16px upward.
/// The start state stays fully visible, so screenshots never show an empty frame.
class Settle extends StatefulWidget {
  const Settle({super.key, required this.child, this.delay = Duration.zero});
  final Widget child;
  final Duration delay;

  @override
  State<Settle> createState() => _SettleState();
}

class _SettleState extends State<Settle> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: KatMotion.settle);
  late final Animation<double> _t = CurvedAnimation(parent: _c, curve: KatMotion.curve);
  bool _started = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (reduceMotion(context)) {
      _c.value = 1;
    } else {
      Future<void>.delayed(widget.delay, () {
        if (mounted) _c.forward();
      });
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _t,
      child: widget.child,
      builder: (context, child) => Transform.translate(offset: Offset(0, 16 * (1 - _t.value)), child: child),
    );
  }
}
