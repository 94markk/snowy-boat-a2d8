import 'dart:io';

import 'package:ffmpeg_kit_flutter_new_min_gpl/ffmpeg_kit.dart';
import 'package:ffmpeg_kit_flutter_new_min_gpl/return_code.dart';
import 'package:flutter/material.dart';
import 'package:gal/gal.dart';
import 'package:path_provider/path_provider.dart';

import '../../engine/export/filter_graph.dart';
import '../../engine/lut.dart';
import '../common/tool_rail.dart' as chrome;
import '../theme/theme.dart';
import 'editor_state.dart';

class ExportPanel extends StatefulWidget {
  const ExportPanel({
    super.key,
    required this.state,
    required this.onMessage,
  });

  final EditorState state;
  final void Function(String) onMessage;

  @override
  State<ExportPanel> createState() => _ExportPanelState();
}

class _ExportPanelState extends State<ExportPanel> {
  @override
  Widget build(BuildContext context) {
    final state = widget.state;

    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 10),
      children: [
        const _Label('Resolution'),
        SizedBox(
          height: 40,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              for (final value in [720, 1080, 1440, 2160])
                chrome.Chip(
                  label: '${value}p',
                  selected: state.exportShortEdge == value,
                  onTap: () => setState(() => state.exportShortEdge = value),
                ),
            ],
          ),
        ),
        const _Label('Frame rate'),
        SizedBox(
          height: 40,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              for (final value in [24, 30, 60])
                chrome.Chip(
                  label: '$value fps',
                  selected: state.exportFps == value,
                  onTap: () => setState(() => state.exportFps = value),
                ),
            ],
          ),
        ),
        const SizedBox(height: 14),
        if (state.exporting)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                LinearProgressIndicator(
                  value: state.exportProgress > 0 ? state.exportProgress : null,
                  backgroundColor: Shade.i500,
                  color: aqua,
                ),
                const SizedBox(height: 6),
                Text(
                  state.exportProgress > 0
                      ? 'Exporting ${(state.exportProgress * 100).round()}%'
                      : 'Preparing…',
                  style: const TextStyle(color: Mist.m400, fontSize: 12),
                ),
              ],
            ),
          )
        else
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14),
            child: FilledButton.icon(
              onPressed: state.project.clips.isEmpty ? null : _export,
              icon: const Icon(Icons.file_download_rounded),
              label: const Text('Export to gallery'),
              style: FilledButton.styleFrom(
                backgroundColor: aqua,
                foregroundColor: Colors.black,
                minimumSize: const Size.fromHeight(44),
              ),
            ),
          ),
      ],
    );
  }

  Future<void> _export() async {
    final state = widget.state;
    state.setExporting(true);

    try {
      final temp = await getTemporaryDirectory();
      final workDir = Directory(
        '${temp.path}/export_${DateTime.now().millisecondsSinceEpoch}',
      );
      await workDir.create(recursive: true);

      // One .cube per clip that is actually graded. A neutral clip gets no
      // LUT at all rather than an identity one: lut3d is not free, and running
      // it to produce the input unchanged costs time on every frame.
      final lutPaths = <String, String>{};
      for (final clip in state.project.clips) {
        if (clip.adjustments.isNeutral) continue;
        final file = File('${workDir.path}/${clip.id}.cube');
        await file.writeAsString(LutBaker.bakeCube(clip.adjustments));
        lutPaths[clip.id] = file.path;
      }

      final output = '${workDir.path}/delicat_export.mp4';
      final args = FilterGraph.build(
        project: state.project,
        settings: ExportSettings(
          shortEdge: state.exportShortEdge,
          fps: state.exportFps,
        ),
        lutPaths: lutPaths,
        overlays: const [],
        outputPath: output,
      );

      final totalUs = state.project.durationUs;
      final session = await FFmpegKit.executeWithArgumentsAsync(
        args,
        (session) async {
          final code = await session.getReturnCode();
          if (!mounted) return;

          if (ReturnCode.isSuccess(code)) {
            try {
              await Gal.putVideo(output, album: 'Delicat Studio');
              widget.onMessage('Saved to your gallery');
            } catch (_) {
              // The file exists either way; only the gallery write failed, so
              // say where it is rather than reporting the export as lost.
              widget.onMessage('Exported, but could not add it to the gallery');
            }
          } else {
            final log = await session.getFailStackTrace();
            widget.onMessage(
              log == null || log.isEmpty
                  ? 'Export failed'
                  : 'Export failed. FFmpeg reported an error.',
            );
          }
          state.setExporting(false);
        },
        null,
        (statistics) {
          // FFmpeg reports progress in milliseconds of output written, which
          // is the only honest progress signal available: it is proportional
          // to the work actually done, unlike a frame count that says nothing
          // about how long the remaining frames will take.
          if (totalUs <= 0) return;
          final doneUs = statistics.getTime() * 1000;
          state.setExportProgress(doneUs / totalUs);
        },
      );

      // Keep a reference so the session is not collected mid-export.
      assert(session.getSessionId() != 0);
    } catch (error) {
      if (mounted) {
        state.setExporting(false);
        widget.onMessage('Export could not start');
      }
    }
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);

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
