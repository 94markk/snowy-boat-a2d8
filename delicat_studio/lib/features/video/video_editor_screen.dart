import 'dart:io';
import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:file_picker/file_picker.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:path_provider/path_provider.dart';
import 'package:video_player/video_player.dart';

import '../../core/history.dart';
import '../../core/image_io.dart';
import '../../core/settings.dart';
import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/pressable.dart';
import '../../widgets/style_strip.dart';
import '../editor/editor_model.dart';
import '../editor/editor_screen.dart';
import '../editor/text_sheet.dart';
import 'video_export.dart';
import 'video_model.dart';
import 'video_native.dart';
import 'video_playback.dart';
import 'video_timeline.dart';

const _videoExt = {'mp4', 'mov', 'm4v', '3gp', 'mkv', 'webm', 'avi'};
bool isVideoPath(String p) => _videoExt.contains(p.split('.').last.toLowerCase());

const _emojis = [
  '🔥', '✨', '💯', '🎉', '❤️', '😍', '😎', '🤩', '👀', '👍', '🙌', '💪', '⭐', '🌟', '⚡', '🎁', '🛍️', '💎', //
  '🎮', '📱', '💳', '🎬', '🎶', '📍', '⏰', '✅', '❗', '👉', '🇭🇹', '🌴', '☀️', '🌊',
];

/// Mobile video editor in the spirit of CapCut: preview, centred-playhead timeline,
/// clip tools, texts, stickers, music, logo and MP4 export.
class VideoEditorScreen extends StatefulWidget {
  const VideoEditorScreen({super.key, required this.paths, this.initialRatio = VRatio.original});
  final List<String> paths;
  final VRatio initialRatio;

  @override
  State<VideoEditorScreen> createState() => _VideoEditorScreenState();
}

class _VideoEditorScreenState extends State<VideoEditorScreen> with TickerProviderStateMixin {
  final History<VideoDoc> _h = History(const VideoDoc());
  late final VideoPlayback _pb = VideoPlayback(this);
  final Map<String, ClipThumbs> _thumbs = {};
  VSel _sel = const VSel.none();
  String? _panel;
  double _pxPerMs = 0.06;
  bool _loading = true;
  String _loadingText = 'Préparation des clips…';
  VideoDoc? _base;
  Adj _adj = Adj.brightness;
  ui.Image? _logoImage;
  String? _logoPath;

  // Text gestures on the preview.
  VText? _gText;
  Offset _gStart = Offset.zero;
  Size _previewSize = Size.zero;

  VideoDoc get _d => _h.value;

  @override
  void initState() {
    super.initState();
    _h.addListener(_onDoc);
    _pb.addListener(() {
      if (mounted) setState(() {});
    });
    _h.replace(VideoDoc(ratio: widget.initialRatio));
    _import(widget.paths).whenComplete(() {
      if (mounted) setState(() => _loading = false);
    });
  }

  void _onDoc() {
    _pb.setDoc(_d);
    _syncLogo();
    if (mounted) setState(() {});
  }

  Future<void> _syncLogo() async {
    final p = _d.logo?.path;
    if (p == _logoPath) return;
    _logoPath = p;
    _logoImage?.dispose();
    _logoImage = null;
    if (p != null) {
      final img = await ImageIO.decode(await File(p).readAsBytes(), maxSide: 512);
      if (mounted && _logoPath == p) setState(() => _logoImage = img);
    }
  }

  @override
  void dispose() {
    _pb.dispose();
    _h.dispose();
    _logoImage?.dispose();
    super.dispose();
  }

  String _id() => DateTime.now().microsecondsSinceEpoch.toString();

  // ---- import -------------------------------------------------------------

  Future<List<VClip>> _loadClips(List<String> paths) async {
    final out = <VClip>[];
    for (final p in paths) {
      try {
        if (isVideoPath(p)) {
          final info = await VideoNative.probe(p);
          if (info.durationMs <= 0 || info.width <= 0) continue;
          out.add(VClip(
            id: _id(),
            path: p,
            isImage: false,
            sourceMs: info.durationMs.toDouble(),
            width: info.width,
            height: info.height,
            inMs: 0,
            outMs: info.durationMs.toDouble(),
            hasAudio: info.hasAudio,
          ));
          if (!_thumbs.containsKey(p)) {
            final count = (info.durationMs / 1000).clamp(4, 30).round();
            final frames = await VideoNative.thumbnails(p, count: count, height: 96);
            final imgs = <ui.Image>[for (final f in frames) await ImageIO.decode(f)];
            _thumbs[p] = ClipThumbs(imgs, info.durationMs / math.max(1, count));
          }
        } else {
          final img = await ImageIO.decode(await File(p).readAsBytes(), maxSide: 240);
          final buffer = await ui.ImmutableBuffer.fromFilePath(p);
          final desc = await ui.ImageDescriptor.encoded(buffer);
          out.add(VClip(
            id: _id(),
            path: p,
            isImage: true,
            sourceMs: 60000,
            width: desc.width,
            height: desc.height,
            inMs: 0,
            outMs: 3000,
            hasAudio: false,
          ));
          desc.dispose();
          buffer.dispose();
          _thumbs[p] = ClipThumbs([img], 60000);
        }
      } catch (_) {
        // Unreadable file: skip it and keep the others.
      }
    }
    return out;
  }

  Future<void> _import(List<String> paths, {int? insertAt}) async {
    final clips = await _loadClips(paths);
    if (!mounted) return;
    if (clips.isEmpty) {
      if (_d.clips.isEmpty) {
        showKatSnack(context, 'Ces fichiers ne peuvent pas être lus. Choisis des vidéos MP4 ou des photos.');
        Navigator.pop(context);
      }
      return;
    }
    final list = [..._d.clips];
    list.insertAll(insertAt ?? list.length, clips);
    if (_d.clips.isEmpty) {
      _h.replace(_d.copyWith(clips: list));
    } else {
      _h.push(_d.copyWith(clips: list));
    }
  }

  Future<List<String>> _pickMedia() async {
    final max = settings.importQuality.maxSide.toDouble();
    final files = await ImagePicker().pickMultipleMedia(maxWidth: max, maxHeight: max, imageQuality: 92);
    return files.map((f) => f.path).toList();
  }

  Future<void> _addClips() async {
    _pb.pause();
    final paths = await _pickMedia();
    if (paths.isEmpty) return;
    setState(() {
      _loading = true;
      _loadingText = 'Ajout des clips…';
    });
    await _import(paths);
    if (mounted) setState(() => _loading = false);
  }

  // ---- editing helpers ------------------------------------------------------

  VClip? get _clip => _sel.kind == VSelKind.clip ? _d.clips.where((c) => c.id == _sel.id).firstOrNull : null;
  int get _clipIndex => _sel.kind == VSelKind.clip ? _d.clips.indexWhere((c) => c.id == _sel.id) : -1;
  VText? get _text => _sel.kind == VSelKind.text ? _d.texts.where((c) => c.id == _sel.id).firstOrNull : null;

  void _live(VideoDoc next) {
    _base ??= _d;
    _h.replace(next);
  }

  void _commit() {
    final b = _base;
    _base = null;
    if (b != null && !identical(b, _d)) _h.commitFrom(b);
  }

  void _select(VSel s) {
    setState(() {
      _sel = s;
      _panel = null;
    });
  }

  void _updateClip(VClip c, {bool live = false}) {
    final next = _d.replaceClip(c).clampTexts();
    live ? _live(next) : _h.push(next);
  }

  void _onTrim(String id, bool left, double deltaMs) {
    _base ??= _d;
    final base = _base!.clips.where((c) => c.id == id).firstOrNull;
    if (base == null) return;
    if (base.isImage) {
      final dur = (base.durationMs + (left ? -deltaMs : deltaMs)).clamp(500.0, 30000.0);
      _h.replace(_base!.replaceClip(base.copyWith(outMs: dur)).clampTexts());
      return;
    }
    final srcDelta = deltaMs * base.speed;
    if (left) {
      final nin = (base.inMs + srcDelta).clamp(0.0, base.outMs - 200 * base.speed);
      _h.replace(_base!.replaceClip(base.copyWith(inMs: nin)).clampTexts());
    } else {
      final nout = (base.outMs + srcDelta).clamp(base.inMs + 200 * base.speed, base.sourceMs);
      _h.replace(_base!.replaceClip(base.copyWith(outMs: nout)).clampTexts());
    }
  }

  void _onMoveText(String id, double start) {
    _base ??= _d;
    final v = _base!.texts.where((x) => x.id == id).firstOrNull;
    if (v == null) return;
    final len = v.endMs - v.startMs;
    final s = start.clamp(0.0, math.max(0.0, _d.durationMs - len)).toDouble();
    _h.replace(_base!.replaceText(v.copyWith(startMs: s, endMs: s + len)));
  }

  void _split() {
    final i = _clipIndex >= 0 ? _clipIndex : _d.indexAt(_pb.t);
    if (i < 0) return;
    if (_d.indexAt(_pb.t) != i) {
      showKatSnack(context, 'Place la tête de lecture sur le clip pour le diviser.');
      return;
    }
    final next = _d.splitAt(i, _pb.t, _id());
    if (next == null) {
      showKatSnack(context, 'Trop près du bord du clip pour diviser ici.');
      return;
    }
    _h.push(next);
    _select(VSel(VSelKind.clip, next.clips[i + 1].id));
  }

  void _deleteClip() {
    final i = _clipIndex;
    if (i < 0) return;
    if (_d.clips.length == 1) {
      showKatSnack(context, 'Un montage garde au moins un clip. Ajoute un autre clip avant de supprimer celui-ci.');
      return;
    }
    final list = [..._d.clips]..removeAt(i);
    _h.push(_d.copyWith(clips: list).clampTexts());
    _select(const VSel.none());
  }

  void _duplicateClip() {
    final i = _clipIndex;
    if (i < 0) return;
    final list = [..._d.clips]..insert(i + 1, _d.clips[i].copyWith(id: _id(), transitionIn: false));
    _h.push(_d.copyWith(clips: list));
  }

  void _moveClip(int dir) {
    final i = _clipIndex;
    final j = i + dir;
    if (i < 0 || j < 0 || j >= _d.clips.length) return;
    final list = [..._d.clips];
    final c = list.removeAt(i);
    list.insert(j, c);
    _h.push(_d.copyWith(clips: list));
    _pb.seek(_d.startOf(j) + 1);
  }

  Future<File> _frameFile(VClip c) async {
    final tmp = await getTemporaryDirectory();
    final local = _pb.t - _d.startOf(_clipIndex);
    final out = '${tmp.path}/ds_frame_${_id()}.jpg';
    await VideoNative.frame(c.path, c.sourceAt(local).round(), out);
    return File(out);
  }

  Future<void> _freeze() async {
    final c = _clip;
    final i = _clipIndex;
    if (c == null || c.isImage) return;
    if (_d.indexAt(_pb.t) != i) {
      showKatSnack(context, 'Place la tête de lecture sur le clip à figer.');
      return;
    }
    try {
      final f = await _frameFile(c);
      final docs = await getApplicationDocumentsDirectory();
      final kept = await f.copy('${docs.path}/ds_freeze_${_id()}.jpg');
      final still = (await _loadClips([kept.path])).first;
      final split = _d.splitAt(i, _pb.t, _id());
      final base = split ?? _d;
      final at = split == null ? i + 1 : i + 1;
      final list = [...base.clips]..insert(at, still.copyWith(rotation: c.rotation, flipH: c.flipH, style: c.style, styleAmount: c.styleAmount, adjust: c.adjust));
      _h.push(base.copyWith(clips: list));
      if (mounted) showKatSnack(context, 'Image figée ajoutée (3 s).');
    } catch (_) {
      if (mounted) showKatSnack(context, 'Impossible de figer cette image. Déplace un peu la tête de lecture et réessaie.');
    }
  }

  Future<void> _capture() async {
    final c = _clip;
    if (c == null || c.isImage || _d.indexAt(_pb.t) != _clipIndex) {
      showKatSnack(context, 'Place la tête de lecture sur une vidéo pour capturer une image.');
      return;
    }
    _pb.pause();
    try {
      final f = await _frameFile(c);
      final photo = await ImageIO.load(f.path);
      if (!mounted) return;
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => EditorScreen(photo: photo)));
    } catch (_) {
      if (mounted) showKatSnack(context, 'La capture a échoué. Réessaie un peu plus loin dans le clip.');
    }
  }

  Future<void> _addText({String? emoji}) async {
    if (_d.durationMs < 300) return;
    _pb.pause();
    final start = _pb.t >= _d.durationMs - 300 ? 0.0 : _pb.t;
    final end = math.min(start + 3000, _d.durationMs);
    EditorLayer? layer;
    if (emoji != null) {
      layer = EditorLayer(id: _id(), emoji: emoji, size: 0.2, pos: const Offset(0.5, 0.4));
    } else {
      layer = await showTextSheet(context, EditorLayer(id: _id(), pos: const Offset(0.5, 0.75), size: 0.07), isNew: true);
    }
    if (layer == null) return;
    final v = VText(layer: layer, startMs: start, endMs: end);
    _h.push(_d.copyWith(texts: [..._d.texts, v]));
    _select(VSel(VSelKind.text, v.id));
  }

  Future<void> _editText() async {
    final v = _text;
    if (v == null || v.layer.isSticker) return;
    final l = await showTextSheet(context, v.layer, isNew: false);
    if (l != null) _h.push(_d.replaceText(v.copyWith(layer: l)));
  }

  Future<void> _pickMusic() async {
    _pb.pause();
    try {
      final files = await FilePicker.pickFiles(type: FileType.audio);
      final path = files.firstOrNull?.path;
      if (path == null) return;
      final info = await VideoNative.probe(path);
      if (info.durationMs <= 0) throw const FormatException();
      final name = files.first.name.replaceAll(RegExp(r'\.[^.]+$'), '');
      _h.push(_d.copyWith(music: VMusic(path: path, name: name, sourceMs: info.durationMs.toDouble())));
      _select(const VSel(VSelKind.music, 'music'));
    } catch (_) {
      if (mounted) showKatSnack(context, 'Ce fichier audio ne peut pas être lu. Essaie un MP3 ou un M4A.');
    }
  }

  Future<void> _useVideoSound() async {
    _pb.pause();
    final f = await ImagePicker().pickVideo(source: ImageSource.gallery);
    if (f == null) return;
    try {
      final info = await VideoNative.probe(f.path);
      if (!info.hasAudio) throw const FormatException();
      _h.push(_d.copyWith(music: VMusic(path: f.path, name: 'Son de ${f.name}', sourceMs: info.durationMs.toDouble())));
      _select(const VSel(VSelKind.music, 'music'));
    } catch (_) {
      if (mounted) showKatSnack(context, 'Cette vidéo n’a pas de son à extraire.');
    }
  }

  Future<void> _pickLogo() async {
    try {
      final f = await ImagePicker().pickImage(source: ImageSource.gallery, maxWidth: 1024, maxHeight: 1024);
      if (f == null) return;
      final docs = await getApplicationDocumentsDirectory();
      final kept = await File(f.path).copy('${docs.path}/ds_logo_${_id()}.${f.path.split('.').last}');
      _h.push(_d.copyWith(logo: VLogo(path: kept.path)));
    } catch (_) {
      if (mounted) showKatSnack(context, 'Impossible d’ouvrir ce logo. Choisis une image PNG ou JPG.');
    }
  }

  Future<void> _close() async {
    _pb.pause();
    if (_h.canUndo) {
      final ok = await confirmKat(context, title: 'Quitter le montage ?', message: 'Ton montage non exporté sera perdu.', confirm: 'Quitter');
      if (!ok) return;
    }
    if (mounted) Navigator.pop(context);
  }

  Future<void> _export() async {
    _pb.pause();
    setState(() => _sel = const VSel.none());
    await showVideoExportSheet(context, _d);
  }

  // ---- preview gestures (texts) ------------------------------------------------

  VText? _hitText(Offset p) {
    final s = _previewSize;
    for (final v in _d.texts.reversed) {
      if (!v.visibleAt(_pb.t)) continue;
      final half = layerHalfSize(v.layer, s.width);
      final c = Offset(v.layer.pos.dx * s.width, v.layer.pos.dy * s.height);
      final d = p - c;
      final cos = math.cos(-v.layer.rotation);
      final sin = math.sin(-v.layer.rotation);
      final lx = d.dx * cos - d.dy * sin;
      final ly = d.dx * sin + d.dy * cos;
      if (lx.abs() < half.width + 16 && ly.abs() < half.height + 16) return v;
    }
    return null;
  }

  // ---- build ------------------------------------------------------------------

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    return PopScope(
      canPop: false,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _close();
      },
      child: Scaffold(
        backgroundColor: k.stage,
        body: Column(
          children: [
            ColoredBox(
              color: k.canvas,
              child: EditorTopBar(
                title: 'Montage',
                onClose: _close,
                primaryLabel: 'Exporter',
                onPrimary: _loading || _d.clips.isEmpty ? null : _export,
              ),
            ),
            Expanded(child: _previewArea(k)),
            _transport(k),
            ColoredBox(
              color: k.canvas,
              child: VideoTimeline(
                doc: _d,
                t: _pb.t,
                pxPerMs: _pxPerMs,
                sel: _sel,
                thumbs: _thumbs,
                onScrub: (t) {
                  _pb.pause();
                  _pb.seek(t);
                },
                onZoom: (p) => setState(() => _pxPerMs = p),
                onSelect: _select,
                onTrim: _onTrim,
                onMoveText: _onMoveText,
                onGestureEnd: _commit,
                onToggleTransition: (i) {
                  final c = _d.clips[i];
                  _h.push(_d.replaceClip(c.copyWith(transitionIn: !c.transitionIn)));
                  showKatSnack(context, c.transitionIn ? 'Transition retirée.' : 'Fondu ajouté entre les deux clips.');
                },
                onAddClip: _addClips,
                onAddMusic: () => setState(() {
                  _sel = const VSel.none();
                  _panel = 'audio';
                }),
                onAddText: () => _addText(),
              ),
            ),
            ToolPanel(
              child: AnimatedSize(
                duration: reduceMotion(context) ? Duration.zero : KatMotion.press,
                curve: KatMotion.curve,
                alignment: Alignment.topCenter,
                child: _panelWidget(k),
              ),
            ),
            IgnorePointer(
              ignoring: _loading,
              child: AnimatedOpacity(
                opacity: _loading ? 0.4 : 1,
                duration: KatMotion.paint,
                child: EditorToolBar(items: _rail(), selected: _panel, onSelect: _onTool),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _previewArea(KatColors k) {
    if (_loading) {
      return Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox.square(dimension: 28, child: CircularProgressIndicator(strokeWidth: 3, color: k.ink)),
            const SizedBox(height: KatSpace.s12),
            Text(_loadingText, style: KatType.ui(14, color: k.inkMuted)),
          ],
        ),
      );
    }
    return Padding(
      padding: const EdgeInsets.all(KatSpace.s12),
      child: Center(
        child: AspectRatio(
          aspectRatio: _d.aspect,
          child: LayoutBuilder(builder: (context, box) {
            _previewSize = box.biggest;
            return GestureDetector(
              onTap: () {
                if (_sel.kind == VSelKind.text) {
                  _select(const VSel.none());
                } else {
                  _pb.toggle();
                }
              },
              onScaleStart: (d) {
                final hit = _hitText(d.localFocalPoint);
                _gText = hit;
                _gStart = d.localFocalPoint;
                if (hit != null) {
                  _pb.pause();
                  _base = _d;
                  setState(() => _sel = VSel(VSelKind.text, hit.id));
                }
              },
              onScaleUpdate: (d) {
                final v = _gText;
                if (v == null) return;
                final delta = d.localFocalPoint - _gStart;
                final l = v.layer;
                _h.replace(_base!.replaceText(v.copyWith(
                  layer: l.copyWith(
                    pos: l.pos + Offset(delta.dx / _previewSize.width, delta.dy / _previewSize.height),
                    size: (l.size * d.scale).clamp(0.02, 1.0),
                    rotation: l.rotation + d.rotation,
                  ),
                )));
              },
              onScaleEnd: (_) {
                if (_gText != null) _commit();
                _gText = null;
              },
              child: ClipRect(child: _frame(k)),
            );
          }),
        ),
      ),
    );
  }

  Widget _frame(KatColors k) {
    final d = _d;
    final i = d.indexAt(_pb.t);
    if (i < 0) return const ColoredBox(color: Color(0xFF000000));
    final c = d.clips[i];
    Widget content;
    if (c.isImage) {
      content = Image.file(File(c.path), fit: BoxFit.fill, gaplessPlayback: true, cacheWidth: 1440);
    } else {
      final ctl = _pb.controllerFor(c);
      content = ctl == null ? const ColoredBox(color: Color(0xFF000000)) : VideoPlayer(ctl);
    }
    // Lay the clip out at its own aspect, then fit or fill the frame.
    final rawAspect = c.width / c.height;
    content = AspectRatio(aspectRatio: rawAspect, child: content);
    content = RotatedBox(quarterTurns: c.rotation ~/ 90, child: content);
    if (c.flipH) content = Transform.flip(flipX: true, child: content);
    if (c.hasColorWork) content = ColorFiltered(colorFilter: ColorFilter.matrix(c.matrix()), child: content);
    content = FittedBox(fit: d.fill ? BoxFit.cover : BoxFit.contain, child: SizedBox(width: c.aspect * 1000, height: 1000, child: content));
    final zoom = d.zoomAt(_pb.t);
    if (zoom != 1) content = Transform.scale(scale: zoom, child: content);
    final fade = d.fadeAt(_pb.t);
    final visibleTexts = d.texts.where((v) => v.visibleAt(_pb.t)).toList();
    final selected = _text;
    return Stack(
      fit: StackFit.expand,
      children: [
        const ColoredBox(color: Color(0xFF000000)),
        Opacity(opacity: fade, child: content),
        CustomPaint(
          painter: _OverlayPainter(
            texts: visibleTexts,
            logo: d.logo,
            logoImage: _logoImage,
            selected: selected != null && selected.visibleAt(_pb.t) ? selected : null,
            selection: const Color(0xFFFFFFFF),
          ),
        ),
      ],
    );
  }

  Widget _transport(KatColors k) {
    String fmt(double ms) {
      final s = (ms / 1000).floor();
      return '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';
    }

    return Container(
      color: k.canvas,
      padding: const EdgeInsets.symmetric(horizontal: KatSpace.s8),
      height: 52,
      child: Row(
        children: [
          const SizedBox(width: KatSpace.s8),
          Text(fmt(_pb.t), style: KatType.ui(14, weight: FontWeight.w600, tabular: true, color: k.ink)),
          Text('  /  ${fmt(_d.durationMs)}', style: KatType.ui(14, tabular: true, color: k.inkMuted)),
          const Spacer(),
          KatIconButton(
            icon: _pb.playing ? Icons.pause_rounded : Icons.play_arrow_rounded,
            tooltip: _pb.playing ? 'Pause' : 'Lecture',
            filled: true,
            onPressed: _d.clips.isEmpty ? null : _pb.toggle,
          ),
          const Spacer(),
          KatIconButton(icon: Icons.undo_rounded, tooltip: 'Annuler', onPressed: _h.canUndo ? _h.undo : null),
          KatIconButton(icon: Icons.redo_rounded, tooltip: 'Rétablir', onPressed: _h.canRedo ? _h.redo : null),
        ],
      ),
    );
  }

  // ---- tools --------------------------------------------------------------------

  List<ToolItem> _rail() {
    const back = ToolItem('back', Icons.chevron_left_rounded, 'Retour');
    switch (_sel.kind) {
      case VSelKind.clip:
        final c = _clip;
        if (c == null) return const [];
        return [
          back,
          const ToolItem('split', Icons.content_cut_rounded, 'Diviser'),
          ToolItem('speed', Icons.speed_rounded, c.isImage ? 'Durée' : 'Vitesse'),
          if (!c.isImage) const ToolItem('volume', Icons.volume_up_rounded, 'Volume'),
          const ToolItem('animation', Icons.animation_rounded, 'Animations'),
          const ToolItem('filters', Icons.palette_rounded, 'Filtres'),
          const ToolItem('adjust', Icons.tune_rounded, 'Réglages'),
          const ToolItem('rotate', Icons.rotate_90_degrees_cw_rounded, 'Pivoter'),
          const ToolItem('mirror', Icons.flip_rounded, 'Miroir'),
          if (!c.isImage) const ToolItem('freeze', Icons.ac_unit_rounded, 'Figer'),
          if (!c.isImage) const ToolItem('capture', Icons.photo_camera_rounded, 'Capture'),
          const ToolItem('move', Icons.swap_horiz_rounded, 'Déplacer'),
          const ToolItem('duplicate', Icons.copy_rounded, 'Dupliquer'),
          const ToolItem('delete', Icons.delete_outline_rounded, 'Supprimer'),
        ];
      case VSelKind.text:
        final v = _text;
        return [
          back,
          if (v != null && !v.layer.isSticker) const ToolItem('edit', Icons.edit_rounded, 'Modifier'),
          const ToolItem('start', Icons.first_page_rounded, 'Début ici'),
          const ToolItem('end', Icons.last_page_rounded, 'Fin ici'),
          const ToolItem('tduplicate', Icons.copy_rounded, 'Dupliquer'),
          const ToolItem('tdelete', Icons.delete_outline_rounded, 'Supprimer'),
        ];
      case VSelKind.music:
        return [
          back,
          const ToolItem('music', Icons.graphic_eq_rounded, 'Réglages'),
          const ToolItem('replace', Icons.library_music_rounded, 'Remplacer'),
          const ToolItem('mdelete', Icons.delete_outline_rounded, 'Supprimer'),
        ];
      case VSelKind.none:
        return const [
          ToolItem('add', Icons.add_photo_alternate_rounded, 'Ajouter'),
          ToolItem('audio', Icons.music_note_rounded, 'Audio'),
          ToolItem('text', Icons.title_rounded, 'Texte'),
          ToolItem('stickers', Icons.emoji_emotions_rounded, 'Stickers'),
          ToolItem('logo', Icons.branding_watermark_rounded, 'Logo'),
          ToolItem('format', Icons.aspect_ratio_rounded, 'Format'),
          ToolItem('allfilters', Icons.palette_rounded, 'Filtres'),
        ];
    }
  }

  void _onTool(String id) {
    if (_loading || _d.clips.isEmpty) return;
    final c = _clip;
    final v = _text;
    switch (id) {
      case 'back':
        _select(const VSel.none());
      case 'add':
        _addClips();
      case 'text':
        _addText();
      case 'split':
        _split();
      case 'rotate':
        if (c != null) _updateClip(c.copyWith(rotation: (c.rotation + 90) % 360));
      case 'mirror':
        if (c != null) _updateClip(c.copyWith(flipH: !c.flipH));
      case 'freeze':
        _freeze();
      case 'capture':
        _capture();
      case 'duplicate':
        _duplicateClip();
      case 'delete':
        _deleteClip();
      case 'edit':
        _editText();
      case 'start':
        if (v != null) {
          if (_pb.t < v.endMs - 200) {
            _h.push(_d.replaceText(v.copyWith(startMs: _pb.t)));
          } else {
            showKatSnack(context, 'Le début doit être avant la fin du texte.');
          }
        }
      case 'end':
        if (v != null) {
          if (_pb.t > v.startMs + 200) {
            _h.push(_d.replaceText(v.copyWith(endMs: _pb.t)));
          } else {
            showKatSnack(context, 'La fin doit être après le début du texte.');
          }
        }
      case 'tduplicate':
        if (v != null) {
          final copy = VText(layer: v.layer.copyWith(id: _id(), pos: v.layer.pos + const Offset(0.03, 0.03)), startMs: v.startMs, endMs: v.endMs);
          _h.push(_d.copyWith(texts: [..._d.texts, copy]));
          _select(VSel(VSelKind.text, copy.id));
        }
      case 'tdelete':
        if (v != null) {
          _h.push(_d.copyWith(texts: _d.texts.where((x) => x.id != v.id).toList()));
          _select(const VSel.none());
        }
      case 'replace':
        _pickMusic();
      case 'mdelete':
        _h.push(_d.copyWith(clearMusic: true));
        _select(const VSel.none());
      default:
        setState(() => _panel = _panel == id ? null : id);
    }
  }

  Widget _note(KatColors k, String t) => Padding(
        padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s4, KatSpace.gutter, KatSpace.s4),
        child: Text(t, style: KatType.ui(14, color: k.inkMuted)),
      );

  Widget _btn(String label, IconData icon, VoidCallback onTap) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: KatSpace.s4),
        child: KatButton(label: label, icon: icon, kind: KatButtonKind.secondary, compact: true, onPressed: onTap),
      );

  Widget _panelWidget(KatColors k) {
    final c = _clip;
    final d = _d;
    switch (_panel) {
      case 'speed':
        if (c == null) break;
        if (c.isImage) {
          return SliderRow(
            key: const ValueKey('duration'),
            label: 'Durée',
            value: c.durationMs / 1000,
            min: 0.5,
            max: 15,
            format: (v) => '${v.toStringAsFixed(1).replaceAll('.', ',')} s',
            onChanged: (v) => _updateClip(c.copyWith(outMs: v * 1000), live: true),
            onChangeEnd: (_) => _commit(),
          );
        }
        return Column(
          key: const ValueKey('speed'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<double>(
              items: const [0.25, 0.5, 1, 1.5, 2, 3, 4],
              value: c.speed,
              label: (s) => '${s.toString().replaceAll('.0', '').replaceAll('.', ',')}x',
              onChanged: (s) => _updateClip(c.copyWith(speed: s)),
            ),
            SliderRow(
              label: 'Vitesse',
              value: c.speed,
              min: 0.25,
              max: 4,
              format: (v) => '${v.toStringAsFixed(2).replaceAll('.', ',')}x',
              onChanged: (v) => _updateClip(c.copyWith(speed: (v * 20).round() / 20), live: true),
              onChangeEnd: (_) => _commit(),
            ),
            _note(k, 'Le son garde sa hauteur naturelle.'),
          ],
        );
      case 'volume':
        if (c == null) break;
        return Column(
          key: const ValueKey('volume'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SliderRow(
              label: 'Volume',
              value: c.volume * 100,
              min: 0,
              max: 100,
              format: (v) => '${v.round()} %',
              onChanged: (v) => _updateClip(c.copyWith(volume: v / 100), live: true),
              onChangeEnd: (_) => _commit(),
            ),
            KatChoices<bool>(
              items: const [false, true],
              value: c.mute,
              label: (m) => m ? 'Son coupé' : 'Son activé',
              icons: (m) => m ? Icons.volume_off_rounded : Icons.volume_up_rounded,
              onChanged: (m) => _updateClip(c.copyWith(mute: m)),
            ),
            if (!c.hasAudio) _note(k, 'Ce clip n’a pas de piste audio.'),
          ],
        );
      case 'animation':
        if (c == null) break;
        return Column(
          key: const ValueKey('animation'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<VMotion>(items: VMotion.values, value: c.motion, label: (m) => m.label, onChanged: (m) => _updateClip(c.copyWith(motion: m))),
            SliderRow(
              label: 'Fondu entrée',
              value: c.fadeInMs / 1000,
              min: 0,
              max: 2,
              format: (v) => '${v.toStringAsFixed(1).replaceAll('.', ',')} s',
              onChanged: (v) => _updateClip(c.copyWith(fadeInMs: math.min(v * 1000, c.durationMs / 2)), live: true),
              onChangeEnd: (_) => _commit(),
            ),
            SliderRow(
              label: 'Fondu sortie',
              value: c.fadeOutMs / 1000,
              min: 0,
              max: 2,
              format: (v) => '${v.toStringAsFixed(1).replaceAll('.', ',')} s',
              onChanged: (v) => _updateClip(c.copyWith(fadeOutMs: math.min(v * 1000, c.durationMs / 2)), live: true),
              onChangeEnd: (_) => _commit(),
            ),
          ],
        );
      case 'filters':
      case 'allfilters':
        final target = c ?? (d.clips.isEmpty ? null : d.clips.first);
        if (target == null) break;
        final thumb = _thumbs[target.path]?.images.firstOrNull;
        final all = c == null;
        void apply(VClip Function(VClip) f, {bool live = false}) {
          final next = all ? d.copyWith(clips: [for (final x in d.clips) f(x)]) : d.replaceClip(f(target));
          live ? _live(next) : _h.push(next);
        }
        return Column(
          key: ValueKey('filters$all'),
          mainAxisSize: MainAxisSize.min,
          children: [
            if (thumb != null) StyleStrip(image: thumb, selected: target.style, onSelect: (s) => apply((x) => x.copyWith(style: s, styleAmount: 1))),
            if (target.style != 'original')
              SliderRow(
                label: 'Intensité',
                value: target.styleAmount * 100,
                min: 0,
                max: 100,
                format: (v) => '${v.round()} %',
                onChanged: (v) => apply((x) => x.copyWith(styleAmount: v / 100), live: true),
                onChangeEnd: (_) => _commit(),
              ),
            if (all) _note(k, 'Le style s’applique à tous les clips du montage.'),
          ],
        );
      case 'adjust':
        if (c == null) break;
        final v = c.adjust[_adj] ?? 0;
        return Column(
          key: const ValueKey('adjust'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<Adj>(items: kVideoAdjustments, value: _adj, label: (a) => a.label, onChanged: (a) => setState(() => _adj = a)),
            SliderRow(
              label: _adj.label,
              value: v * 100,
              min: _adj.min * 100,
              max: _adj.max * 100,
              format: (x) => x.round() > 0 && _adj.min < 0 ? '+${x.round()}' : '${x.round()}',
              onChanged: (x) => _updateClip(c.copyWith(adjust: {...c.adjust, _adj: x / 100}), live: true),
              onChangeEnd: (_) => _commit(),
            ),
          ],
        );
      case 'move':
        if (c == null) break;
        final i = _clipIndex;
        return SizedBox(
          key: const ValueKey('move'),
          height: 52,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
            children: [
              if (i > 0) _btn('Plus tôt', Icons.arrow_back_rounded, () => _moveClip(-1)),
              if (i < d.clips.length - 1) _btn('Plus tard', Icons.arrow_forward_rounded, () => _moveClip(1)),
              if (d.clips.length == 1) _note(k, 'Ajoute d’autres clips pour changer l’ordre.'),
            ],
          ),
        );
      case 'audio':
        return Column(
          key: const ValueKey('audio'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(
              height: 52,
              child: ListView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
                children: [
                  _btn(d.music == null ? 'Choisir une musique' : 'Changer la musique', Icons.library_music_rounded, _pickMusic),
                  _btn('Son d’une vidéo', Icons.movie_filter_rounded, _useVideoSound),
                ],
              ),
            ),
            KatChoices<bool>(
              items: const [false, true],
              value: d.muteAll,
              label: (m) => m ? 'Son des clips coupé' : 'Son des clips activé',
              icons: (m) => m ? Icons.volume_off_rounded : Icons.volume_up_rounded,
              onChanged: (m) => _h.push(d.copyWith(muteAll: m)),
            ),
          ],
        );
      case 'music':
        final m = d.music;
        if (m == null) break;
        return Column(
          key: const ValueKey('music'),
          mainAxisSize: MainAxisSize.min,
          children: [
            SliderRow(
              label: 'Volume',
              value: m.volume * 100,
              min: 0,
              max: 100,
              format: (v) => '${v.round()} %',
              onChanged: (v) => _live(d.copyWith(music: m.copyWith(volume: v / 100))),
              onChangeEnd: (_) => _commit(),
            ),
            SliderRow(
              label: 'Début',
              value: m.inMs / 1000,
              min: 0,
              max: math.max(1, (m.sourceMs - 1000) / 1000),
              format: (v) => '${v.toStringAsFixed(0)} s',
              onChanged: (v) => _live(d.copyWith(music: m.copyWith(inMs: v * 1000))),
              onChangeEnd: (_) => _commit(),
            ),
            KatChoices<bool>(
              items: const [true, false],
              value: m.loop,
              label: (l) => l ? 'En boucle' : 'Une seule fois',
              icons: (l) => l ? Icons.repeat_rounded : Icons.trending_flat_rounded,
              onChanged: (l) => _h.push(d.copyWith(music: m.copyWith(loop: l))),
            ),
          ],
        );
      case 'stickers':
        return SizedBox(
          key: const ValueKey('stickers'),
          height: 112,
          child: GridView.builder(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: KatSpace.s12),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, mainAxisExtent: 52),
            itemCount: _emojis.length,
            itemBuilder: (context, i) => Pressable(
              onTap: () => _addText(emoji: _emojis[i]),
              semanticLabel: 'Sticker ${_emojis[i]}',
              child: Center(child: Text(_emojis[i], style: const TextStyle(fontSize: 28))),
            ),
          ),
        );
      case 'logo':
        final l = d.logo;
        if (l == null) {
          return Column(
            key: const ValueKey('logo-empty'),
            mainAxisSize: MainAxisSize.min,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: KatSpace.s4),
                child: KatButton(label: 'Choisir ton logo', icon: Icons.add_photo_alternate_rounded, kind: KatButtonKind.secondary, expand: true, onPressed: _pickLogo),
              ),
              _note(k, 'Ton logo reste dans un coin pendant toute la vidéo. Un PNG transparent rend le mieux.'),
            ],
          );
        }
        return Column(
          key: const ValueKey('logo'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<Alignment>(
              items: const [Alignment.topLeft, Alignment.topRight, Alignment.bottomLeft, Alignment.bottomRight],
              value: l.corner,
              icons: (a) => switch (a) {
                Alignment.topLeft => Icons.north_west_rounded,
                Alignment.topRight => Icons.north_east_rounded,
                Alignment.bottomLeft => Icons.south_west_rounded,
                _ => Icons.south_east_rounded,
              },
              label: (a) => switch (a) {
                Alignment.topLeft => 'Haut gauche',
                Alignment.topRight => 'Haut droite',
                Alignment.bottomLeft => 'Bas gauche',
                _ => 'Bas droite',
              },
              onChanged: (a) => _h.push(d.copyWith(logo: l.copyWith(corner: a))),
            ),
            SliderRow(
              label: 'Taille',
              value: l.size * 100,
              min: 8,
              max: 40,
              format: (v) => '${v.round()} %',
              onChanged: (v) => _live(d.copyWith(logo: l.copyWith(size: v / 100))),
              onChangeEnd: (_) => _commit(),
            ),
            SliderRow(
              label: 'Opacité',
              value: l.opacity * 100,
              min: 20,
              max: 100,
              format: (v) => '${v.round()} %',
              onChanged: (v) => _live(d.copyWith(logo: l.copyWith(opacity: v / 100))),
              onChangeEnd: (_) => _commit(),
            ),
            KatButton(label: 'Retirer le logo', icon: Icons.delete_outline_rounded, kind: KatButtonKind.ghost, compact: true, onPressed: () => _h.push(d.copyWith(clearLogo: true))),
          ],
        );
      case 'format':
        return Column(
          key: const ValueKey('format'),
          mainAxisSize: MainAxisSize.min,
          children: [
            KatChoices<VRatio>(items: VRatio.values, value: d.ratio, label: (r) => r.label, onChanged: (r) => _h.push(d.copyWith(ratio: r))),
            KatChoices<bool>(
              items: const [false, true],
              value: d.fill,
              label: (f) => f ? 'Remplir le cadre' : 'Ajuster avec bandes',
              icons: (f) => f ? Icons.crop_rounded : Icons.fit_screen_rounded,
              onChanged: (f) => _h.push(d.copyWith(fill: f)),
            ),
          ],
        );
    }
    return const SizedBox.shrink();
  }
}

class _OverlayPainter extends CustomPainter {
  _OverlayPainter({required this.texts, required this.logo, required this.logoImage, required this.selected, required this.selection});
  final List<VText> texts;
  final VLogo? logo;
  final ui.Image? logoImage;
  final VText? selected;
  final Color selection;

  @override
  void paint(Canvas canvas, Size size) {
    paintVideoOverlay(canvas, size, texts, logo, logoImage);
    final s = selected;
    if (s == null) return;
    final half = layerHalfSize(s.layer, size.width);
    canvas.save();
    canvas.translate(s.layer.pos.dx * size.width, s.layer.pos.dy * size.height);
    canvas.rotate(s.layer.rotation);
    final r = Rect.fromLTRB(-half.width - 8, -half.height - 8, half.width + 8, half.height + 8);
    canvas.drawRRect(RRect.fromRectAndRadius(r, const Radius.circular(8)), Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.5
      ..color = selection);
    canvas.restore();
  }

  @override
  bool shouldRepaint(_OverlayPainter old) => true;
}
