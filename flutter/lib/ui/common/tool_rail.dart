import 'package:flutter/material.dart';

import '../theme/theme.dart';

/// Anything that can appear on a [ToolRail].
class RailItem {
  const RailItem(this.id, this.label, this.icon);

  final String id;
  final String label;
  final IconData icon;
}

/// The bottom rail of tools, scrolled horizontally.
///
/// A rail rather than tabs because tabs divide the available width between
/// them: past about five, every one is too narrow to label or to hit. A rail
/// keeps each item at a fixed, tappable size and lets the list grow past what
/// fits on screen.
class ToolRail extends StatelessWidget {
  const ToolRail({super.key, required this.tools, required this.onSelect});

  final List<RailItem> tools;
  final ValueChanged<RailItem> onSelect;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Shade.i900,
      padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
      child: SingleChildScrollView(
        scrollDirection: Axis.horizontal,
        child: Row(
          children: [
            for (final tool in tools)
              InkWell(
                onTap: () => onSelect(tool),
                borderRadius: BorderRadius.circular(10),
                child: SizedBox(
                  width: 64,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(tool.icon, color: Mist.m200, size: 22),
                        const SizedBox(height: 5),
                        Text(
                          tool.label,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: Theme.of(context)
                              .textTheme
                              .labelSmall
                              ?.copyWith(color: Mist.m400),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// A tool's controls, opened over the timeline with the canvas left visible.
///
/// Keeping the frame on screen is the whole point. A colour or text change
/// judged against a hidden canvas has to be applied, dismissed, inspected and
/// reopened for every nudge, which is what makes an editor exhausting to use
/// rather than merely slow.
class ToolSheet extends StatelessWidget {
  const ToolSheet({
    super.key,
    required this.title,
    required this.onClose,
    required this.child,
  });

  final String title;
  final VoidCallback onClose;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Shade.i800,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            color: Shade.i700,
            child: Row(
              children: [
                IconButton(
                  onPressed: onClose,
                  icon: const Icon(Icons.arrow_back_rounded,
                      size: 20, color: Mist.m200),
                ),
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context)
                        .textTheme
                        .labelMedium
                        ?.copyWith(color: Mist.m200),
                  ),
                ),
                TextButton(
                  onPressed: onClose,
                  child: const Text(
                    'Done',
                    style: TextStyle(
                      color: aqua,
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                    ),
                  ),
                ),
              ],
            ),
          ),
          ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 150, maxHeight: 320),
            child: child,
          ),
        ],
      ),
    );
  }
}

/// A labelled slider with its value shown, and a tap-to-reset on the label.
///
/// The reset matters more than it looks: without one, returning a parameter to
/// neutral means dragging by eye and never quite landing, so people leave a
/// value slightly off rather than undo it.
class ParamSlider extends StatelessWidget {
  const ParamSlider({
    super.key,
    required this.label,
    required this.value,
    required this.min,
    required this.max,
    required this.display,
    required this.onChanged,
    this.onChangeStart,
    this.onChangeEnd,
    this.onReset,
    this.isDefault = true,
  });

  final String label;
  final double value;
  final double min;
  final double max;
  final String display;
  final ValueChanged<double> onChanged;
  final VoidCallback? onChangeStart;
  final VoidCallback? onChangeEnd;
  final VoidCallback? onReset;
  final bool isDefault;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 2),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              GestureDetector(
                onTap: onReset,
                child: Text(
                  label,
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(
                        color: isDefault ? Mist.m400 : aqua,
                      ),
                ),
              ),
              const Spacer(),
              Text(
                display,
                style: Theme.of(context).textTheme.labelSmall?.copyWith(
                      color: isDefault ? Mist.m600 : Mist.m200,
                    ),
              ),
            ],
          ),
          SizedBox(
            height: 28,
            child: Slider(
              value: value.clamp(min, max),
              min: min,
              max: max,
              onChanged: onChanged,
              onChangeStart: (_) => onChangeStart?.call(),
              onChangeEnd: (_) => onChangeEnd?.call(),
            ),
          ),
        ],
      ),
    );
  }
}

/// Small selectable pill, used wherever a short list of choices is offered.
class Chip extends StatelessWidget {
  const Chip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(right: 8),
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? aqua : Shade.i500,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: selected ? Colors.black : Mist.m200,
            fontSize: 12,
            fontWeight: selected ? FontWeight.w600 : FontWeight.w500,
          ),
        ),
      ),
    );
  }
}
