import 'package:flutter/material.dart';

import '../../core/model/adjustments.dart';
import '../../core/model/filters.dart';
import '../../core/model/overlay.dart';
import '../../core/model/project.dart';
import '../../core/model/transition.dart';
import '../common/tool_rail.dart' as chrome;
import '../theme/theme.dart';
import 'editor_state.dart';
import 'time_format.dart';

export 'export_panel.dart';

Widget buildToolPanel({
  required String id,
  required EditorState state,
  required void Function(String) onMessage,
  required VoidCallback onSeek,
}) {
  switch (id) {
    case 'edit':
      return ClipPanel(state: state, onSeek: onSeek);
    case 'adjust':
      return AdjustPanel(state: state);
    case 'filters':
      return FilterPanel(state: state);
    case 'text':
      return TextPanel(state: state);
    case 'stickers':
      return StickerPanel(state: state);
    case 'canvas':
      return CanvasPanel(state: state);
    case 'audio':
      return const _PanelHint('Audio tracks are not wired up yet');
    default:
      return const _PanelHint('Nothing here yet');
  }
}

/// Cut, copy and delete, then the per-clip sliders.
///
/// The three actions sit at the top rather than inside the list of sliders:
/// they are the most-used controls in the editor, and split works off the
/// playhead, which is why the timeline keeps the playhead in the middle of the
/// screen — the cut lands where you are already looking.
class ClipPanel extends StatelessWidget {
  const ClipPanel({super.key, required this.state, required this.onSeek});

  final EditorState state;
  final VoidCallback onSeek;

  @override
  Widget build(BuildContext context) {
    final clip = state.activeClip;
    if (clip == null) {
      return const _PanelHint('Tap a clip on the timeline to edit it');
    }

    return ListView(
      padding: const EdgeInsets.only(bottom: 16),
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
          child: Row(
            children: [
              _Action(
                icon: Icons.content_cut_rounded,
                label: 'Split',
                onTap: () {
                  state.splitAtPlayhead();
                  onSeek();
                },
              ),
              _Action(
                icon: Icons.content_copy_rounded,
                label: 'Duplicate',
                onTap: state.duplicateActiveClip,
              ),
              _Action(
                icon: Icons.delete_rounded,
                label: 'Delete',
                onTap: () {
                  state.removeActiveClip();
                  onSeek();
                },
              ),
            ],
          ),
        ),
        _TransitionSection(state: state, clip: clip),
        chrome.ParamSlider(
          label: 'Trim start',
          value: clip.trimStartUs.toDouble(),
          min: 0,
          max: (clip.sourceDurationUs - TimelineClip.minClipUs)
              .clamp(1, 1 << 40)
              .toDouble(),
          display: formatTimePrecise(clip.trimStartUs),
          isDefault: clip.trimStartUs == 0,
          onChangeStart: state.beginGesture,
          onChanged: (v) => state.updateActiveClip(
            (c) => c.withTrim(v.round(), c.trimEndUs),
          ),
          onChangeEnd: () {
            state.endGesture();
            onSeek();
          },
        ),
        chrome.ParamSlider(
          label: 'Trim end',
          value: clip.trimEndUs.toDouble(),
          min: 0,
          max: clip.sourceDurationUs.toDouble(),
          display: formatTimePrecise(clip.trimEndUs),
          isDefault: clip.trimEndUs == clip.sourceDurationUs,
          onChangeStart: state.beginGesture,
          onChanged: (v) => state.updateActiveClip(
            (c) => c.withTrim(c.trimStartUs, v.round()),
          ),
          onChangeEnd: () {
            state.endGesture();
            onSeek();
          },
        ),
        chrome.ParamSlider(
          label: 'Speed',
          value: clip.speed,
          min: 0.25,
          max: 4,
          display: '${clip.speed.toStringAsFixed(2)}x',
          isDefault: clip.speed == 1,
          onReset: () => state.commitActiveClip((c) => c.copyWith(speed: 1)),
          onChangeStart: state.beginGesture,
          onChanged: (v) => state.updateActiveClip((c) => c.copyWith(speed: v)),
          onChangeEnd: () {
            state.endGesture();
            onSeek();
          },
        ),
        chrome.ParamSlider(
          label: 'Volume',
          value: clip.volume,
          min: 0,
          max: 2,
          display: '${(clip.volume * 100).round()}%',
          isDefault: clip.volume == 1,
          onReset: () => state.commitActiveClip((c) => c.copyWith(volume: 1)),
          onChangeStart: state.beginGesture,
          onChanged: (v) => state.updateActiveClip((c) => c.copyWith(volume: v)),
          onChangeEnd: state.endGesture,
        ),
        chrome.ParamSlider(
          label: 'Fade in',
          value: clip.fadeInUs.toDouble(),
          min: 0,
          max: 3000000,
          display: formatTimePrecise(clip.fadeInUs),
          isDefault: clip.fadeInUs == 0,
          onChangeStart: state.beginGesture,
          onChanged: (v) =>
              state.updateActiveClip((c) => c.copyWith(fadeInUs: v.round())),
          onChangeEnd: state.endGesture,
        ),
        chrome.ParamSlider(
          label: 'Fade out',
          value: clip.fadeOutUs.toDouble(),
          min: 0,
          max: 3000000,
          display: formatTimePrecise(clip.fadeOutUs),
          isDefault: clip.fadeOutUs == 0,
          onChangeStart: state.beginGesture,
          onChanged: (v) =>
              state.updateActiveClip((c) => c.copyWith(fadeOutUs: v.round())),
          onChangeEnd: state.endGesture,
        ),
      ],
    );
  }
}

class _TransitionSection extends StatelessWidget {
  const _TransitionSection({required this.state, required this.clip});

  final EditorState state;
  final TimelineClip clip;

  @override
  Widget build(BuildContext context) {
    final index = state.project.clips.indexWhere((c) => c.id == clip.id);
    if (index <= 0) {
      return const _SectionLabel('Transitions apply between two clips');
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _SectionLabel('Transition in'),
        SizedBox(
          height: 40,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              for (final type in TransitionType.values)
                chrome.Chip(
                  label: type.label,
                  selected: clip.transition.type == type,
                  onTap: () => state.commitActiveClip(
                    (c) => c.copyWith(
                      transition: c.transition.copyWith(type: type),
                    ),
                  ),
                ),
            ],
          ),
        ),
        if (clip.transition.isActive)
          chrome.ParamSlider(
            label: 'Duration',
            value: clip.transition.durationUs.toDouble(),
            min: Transition.minUs.toDouble(),
            max: Transition.maxUs.toDouble(),
            display: formatTimePrecise(clip.transition.durationUs),
            isDefault: false,
            onChangeStart: state.beginGesture,
            onChanged: (v) => state.updateActiveClip(
              (c) => c.copyWith(
                transition: c.transition.copyWith(durationUs: v.round()),
              ),
            ),
            onChangeEnd: state.endGesture,
          ),
      ],
    );
  }
}

/// Colour sliders, generated from the spec registry.
///
/// Nothing here lists the parameters by hand. Adding one means adding a single
/// AdjustSpec entry and a line in the shader, and the slider appears on its
/// own — which is what makes it impossible for a control to exist in the UI
/// that the engine does not read.
class AdjustPanel extends StatefulWidget {
  const AdjustPanel({super.key, required this.state});

  final EditorState state;

  @override
  State<AdjustPanel> createState() => _AdjustPanelState();
}

class _AdjustPanelState extends State<AdjustPanel> {
  AdjustGroup _group = AdjustGroup.light;

  @override
  Widget build(BuildContext context) {
    final state = widget.state;
    if (state.activeClip == null) {
      return const _PanelHint('Add a clip to adjust it');
    }

    final adjustments = state.activeAdjustments;
    final specs = AdjustSpec.inGroup(_group);

    return Column(
      children: [
        SizedBox(
          height: 44,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            children: [
              for (final group in AdjustGroup.values)
                chrome.Chip(
                  label: group.label,
                  selected: group == _group,
                  onTap: () => setState(() => _group = group),
                ),
            ],
          ),
        ),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.only(bottom: 16),
            itemCount: specs.length,
            itemBuilder: (context, i) {
              final spec = specs[i];
              final value = adjustments.get(spec.id);
              return chrome.ParamSlider(
                label: spec.label,
                value: value,
                min: spec.min,
                max: spec.max,
                display: spec.display(value),
                isDefault: value == spec.defaultValue,
                onReset: () => state.setAdjustment(
                  spec.id,
                  spec.defaultValue,
                  live: false,
                ),
                onChangeStart: state.beginGesture,
                onChanged: (v) => state.setAdjustment(spec.id, v),
                onChangeEnd: state.endGesture,
              );
            },
          ),
        ),
      ],
    );
  }
}

class FilterPanel extends StatelessWidget {
  const FilterPanel({super.key, required this.state});

  final EditorState state;

  @override
  Widget build(BuildContext context) {
    if (state.activeClip == null) {
      return const _PanelHint('Add a clip to filter it');
    }
    final adjustments = state.activeAdjustments;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.symmetric(vertical: 8),
            children: [
              for (final category in FilterCategory.values) ...[
                _SectionLabel(category.label),
                SizedBox(
                  height: 40,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    children: [
                      for (final preset in Filters.inCategory(category))
                        chrome.Chip(
                          label: preset.name,
                          selected: adjustments.filterId == preset.id,
                          onTap: () => state.commitActiveClip(
                            (c) => c.copyWith(
                              adjustments:
                                  c.adjustments.copyWith(filterId: preset.id),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
        if (adjustments.filterId != Filters.noneId)
          chrome.ParamSlider(
            label: 'Strength',
            value: adjustments.filterStrength,
            min: 0,
            max: 1,
            display: '${(adjustments.filterStrength * 100).round()}%',
            isDefault: adjustments.filterStrength == 1,
            onChangeStart: state.beginGesture,
            onChanged: (v) => state.setAdjustment('filterStrength', v),
            onChangeEnd: state.endGesture,
          ),
      ],
    );
  }
}

class TextPanel extends StatelessWidget {
  const TextPanel({super.key, required this.state});

  final EditorState state;

  @override
  Widget build(BuildContext context) {
    final selected = state.selectedOverlay;

    return ListView(
      padding: const EdgeInsets.all(12),
      children: [
        FilledButton.icon(
          onPressed: () => state.addOverlay(
            TextOverlay(
              startUs: state.positionUs,
              endUs: state.positionUs + 3000000,
            ),
          ),
          icon: const Icon(Icons.add_rounded),
          label: const Text('Add text'),
          style: FilledButton.styleFrom(
            backgroundColor: aqua,
            foregroundColor: Colors.black,
          ),
        ),
        const SizedBox(height: 12),
        if (selected is TextOverlay) ...[
          TextFormField(
            initialValue: selected.text,
            style: const TextStyle(color: Mist.m200),
            decoration: const InputDecoration(
              labelText: 'Text',
              labelStyle: TextStyle(color: Mist.m400),
            ),
            onChanged: (value) => state.commitSelectedOverlay(
              (o) => (o as TextOverlay).copyWith(text: value),
            ),
          ),
          const SizedBox(height: 8),
          chrome.ParamSlider(
            label: 'Size',
            value: selected.style.fontSize,
            min: 0.02,
            max: 0.25,
            display: '${(selected.style.fontSize * 100).toStringAsFixed(1)}%',
            isDefault: selected.style.fontSize == 0.07,
            onChanged: (v) => state.updateSelectedOverlay(
              (o) => (o as TextOverlay)
                  .copyWith(style: o.style.copyWith(fontSize: v)),
            ),
          ),
          TextButton.icon(
            onPressed: state.removeSelectedOverlay,
            icon: const Icon(Icons.delete_outline_rounded, color: rose),
            label: const Text('Remove', style: TextStyle(color: rose)),
          ),
        ] else
          const _PanelHint('Add text, then select it on the timeline'),
      ],
    );
  }
}

class StickerPanel extends StatelessWidget {
  const StickerPanel({super.key, required this.state});

  final EditorState state;

  static const List<String> _emoji = [
    '⭐', '🔥', '💯', '❤️', '😂', '😍', '🎉', '✨', '👀', '💀',
    '🙌', '👑', '⚡', '🌈', '🍿', '📸', '🎵', '💪', '🤯', '🥹',
  ];

  @override
  Widget build(BuildContext context) {
    return GridView.count(
      crossAxisCount: 6,
      padding: const EdgeInsets.all(12),
      children: [
        for (final emoji in _emoji)
          GestureDetector(
            onTap: () => state.addOverlay(
              StickerOverlay(
                emoji: emoji,
                startUs: state.positionUs,
                endUs: state.positionUs + 3000000,
              ),
            ),
            child: Container(
              margin: const EdgeInsets.all(3),
              decoration: BoxDecoration(
                color: Shade.i600,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Center(
                child: Text(emoji, style: const TextStyle(fontSize: 22)),
              ),
            ),
          ),
      ],
    );
  }
}

class CanvasPanel extends StatelessWidget {
  const CanvasPanel({super.key, required this.state});

  final EditorState state;

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 8),
      children: [
        const _SectionLabel('Aspect ratio'),
        SizedBox(
          height: 40,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              for (final ratio in CanvasRatio.values)
                chrome.Chip(
                  label: ratio.label,
                  selected: state.project.aspect == ratio,
                  onTap: () => state.setAspect(ratio),
                ),
            ],
          ),
        ),
        const _SectionLabel('Fit'),
        SizedBox(
          height: 40,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              for (final mode in FitMode.values)
                chrome.Chip(
                  label: mode.label,
                  selected: state.activeClip?.transform.fit == mode,
                  onTap: () => state.commitActiveClip(
                    (c) => c.copyWith(transform: c.transform.copyWith(fit: mode)),
                  ),
                ),
            ],
          ),
        ),
      ],
    );
  }
}

class _Action extends StatelessWidget {
  const _Action({required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(10),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 9),
          decoration: BoxDecoration(
            color: Shade.i600,
            borderRadius: BorderRadius.circular(10),
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(icon, size: 20, color: Mist.m200),
              const SizedBox(height: 4),
              Text(label,
                  style: const TextStyle(fontSize: 10, color: Mist.m400)),
            ],
          ),
        ),
      ),
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 4),
      child: Text(
        text,
        style: const TextStyle(
          fontSize: 12,
          color: Mist.m400,
          fontWeight: FontWeight.w500,
        ),
      ),
    );
  }
}

class _PanelHint extends StatelessWidget {
  const _PanelHint(this.text);

  final String text;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Text(
          text,
          textAlign: TextAlign.center,
          style: const TextStyle(color: Mist.m600, fontSize: 13),
        ),
      ),
    );
  }
}
