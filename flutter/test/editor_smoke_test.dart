import 'package:delicat_studio/core/model/project.dart';
import 'package:delicat_studio/engine/thumbnails.dart';
import 'package:delicat_studio/ui/editor/filmstrip.dart';
import 'package:delicat_studio/ui/editor/editor_screen.dart';
import 'package:delicat_studio/ui/editor/editor_state.dart';
import 'package:delicat_studio/ui/editor/panels.dart';
import 'package:delicat_studio/ui/home/home_screen.dart';
import 'package:delicat_studio/ui/theme/theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Screens are built here without a device, which is the gap that let a crash
/// reach a release: everything up to now was checked by the compiler and by
/// tests of pure functions, and neither of those can notice a widget that
/// throws while building or one that restarts an expensive job on every frame.
///
/// Plugins are absent in this environment, so every platform call fails. That
/// is the point rather than a limitation — a screen that only survives when
/// the picker, the player and FFmpeg all answer is a screen that will fall
/// over on a real phone the first time one of them does not.
void main() {
  Widget wrap(Widget child) => MaterialApp(
        theme: delicatTheme(),
        home: child,
      );

  testWidgets('home builds', (tester) async {
    await tester.pumpWidget(wrap(const HomeScreen()));
    expect(find.text('Delicat Studio'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('editor builds with an empty timeline', (tester) async {
    await tester.pumpWidget(wrap(const EditorScreen()));
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Export'), findsOneWidget);
    expect(find.text('Nothing on the timeline yet'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the editor survives its own ticker running', (tester) async {
    await tester.pumpWidget(wrap(const EditorScreen()));

    // The playback ticker fires every 60ms for the life of the screen. Letting
    // it run for a while is what surfaces anything that accumulates per tick.
    for (var i = 0; i < 40; i++) {
      await tester.pump(const Duration(milliseconds: 60));
      expect(tester.takeException(), isNull, reason: 'tick $i threw');
    }
  });

  testWidgets('every tool panel builds without a clip', (tester) async {
    // A panel opened on an empty project must say so rather than dereference
    // a clip that is not there.
    for (final tool in editorTools) {
      final state = EditorState();
      addTearDown(state.dispose);

      await tester.pumpWidget(
        wrap(
          Scaffold(
            body: buildToolPanel(
              id: tool.id,
              state: state,
              onMessage: (_) {},
              onSeek: () {},
            ),
          ),
        ),
      );
      await tester.pump();
      expect(tester.takeException(), isNull, reason: '${tool.id} threw');
    }
  });

  testWidgets('every tool panel builds with a clip', (tester) async {
    for (final tool in editorTools) {
      final state = EditorState()
        ..addClips([
          TimelineClip(
            uri: 'file:///tmp/a.mp4',
            kind: MediaKind.video,
            sourceDurationUs: 5000000,
            sourceWidth: 1920,
            sourceHeight: 1080,
          ),
        ]);
      addTearDown(state.dispose);

      await tester.pumpWidget(
        wrap(
          Scaffold(
            body: buildToolPanel(
              id: tool.id,
              state: state,
              onMessage: (_) {},
              onSeek: () {},
            ),
          ),
        ),
      );
      await tester.pump();
      expect(tester.takeException(), isNull, reason: '${tool.id} threw');
    }
  });

  testWidgets('the filmstrip extracts frames once, not once per frame',
      (tester) async {
    // This is the regression that crashed the app. The strip re-ran extraction
    // whenever its frame count did not match the number of cells, which is
    // true before the first load and stays true whenever FFmpeg returns a
    // different count. The timeline rebuilds about sixteen times a second
    // during playback, so that became sixteen FFmpeg processes a second, per
    // clip, until the system killed the app.
    Thumbnailer.invocations = 0;

    final project = Project(
      clips: [
        TimelineClip(
          uri: 'file:///tmp/a.mp4',
          kind: MediaKind.video,
          sourceDurationUs: 8000000,
          sourceWidth: 1920,
          sourceHeight: 1080,
        ),
      ],
    );

    // The parent has to actually rebuild, because that is what calls
    // didUpdateWidget. Pumping time alone leaves the subtree untouched, which
    // is why an earlier version of this test passed against the broken code.
    await tester.pumpWidget(
      wrap(
        Scaffold(
          body: _Rebuilder(
            builder: (positionUs) => Filmstrip(
              project: project,
              positionUs: positionUs,
              selectedClipId: project.clips.first.id,
              onScrub: (_) {},
              onScrubFinished: () {},
              onSelectClip: (_) {},
              onAddMedia: () {},
              onAddAudio: () {},
              onAddText: () {},
            ),
          ),
        ),
      ),
    );

    final rebuilder = tester.state<_RebuilderState>(find.byType(_Rebuilder));
    for (var i = 0; i < 30; i++) {
      rebuilder.advance(60000);
      await tester.pump(const Duration(milliseconds: 60));
    }

    expect(tester.takeException(), isNull);
    expect(
      Thumbnailer.invocations,
      lessThanOrEqualTo(2),
      reason: 'extraction re-ran on rebuild',
    );
  });

  testWidgets('adjust sliders move the model', (tester) async {
    // The complaint this app began with was controls that did nothing. A
    // slider that is wired to nothing looks identical to one that is wired
    // wrongly, so the panel is driven and the model is read back.
    final state = EditorState()
      ..addClips([
        TimelineClip(
          uri: 'file:///tmp/a.mp4',
          kind: MediaKind.video,
          sourceDurationUs: 5000000,
          sourceWidth: 1080,
          sourceHeight: 1920,
        ),
      ]);
    addTearDown(state.dispose);

    await tester.pumpWidget(wrap(Scaffold(body: AdjustPanel(state: state))));
    await tester.pump();

    final slider = find.byType(Slider).first;
    expect(slider, findsOneWidget);

    final before = state.activeAdjustments.exposure;
    await tester.drag(slider, const Offset(60, 0));
    await tester.pump();

    expect(state.activeAdjustments.exposure, isNot(before));
    expect(tester.takeException(), isNull);
  });
}


/// Rebuilds its child with an advancing playhead, the way the editor's
/// playback ticker does. Without this the subtree is never asked to update and
/// nothing that goes wrong per rebuild can show itself.
class _Rebuilder extends StatefulWidget {
  const _Rebuilder({required this.builder});

  final Widget Function(int positionUs) builder;

  @override
  State<_Rebuilder> createState() => _RebuilderState();
}

class _RebuilderState extends State<_Rebuilder> {
  int positionUs = 0;

  void advance(int byUs) => setState(() => positionUs += byUs);

  @override
  Widget build(BuildContext context) => widget.builder(positionUs);
}
