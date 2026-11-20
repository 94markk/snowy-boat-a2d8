import 'dart:ui' as ui;

import 'package:material_ui/material_ui.dart';

import '../../theme/tokens.dart';
import '../../widgets/common.dart';
import '../../widgets/kat_button.dart';
import '../../widgets/pressable.dart';
import 'editor_model.dart';

/// Creates or edits a text layer. Returns null when dismissed.
Future<EditorLayer?> showTextSheet(BuildContext context, EditorLayer initial, {required bool isNew}) {
  return showModalBottomSheet<EditorLayer>(
    context: context,
    isScrollControlled: true,
    builder: (_) => _TextSheet(initial: initial, isNew: isNew),
  );
}

class _TextSheet extends StatefulWidget {
  const _TextSheet({required this.initial, required this.isNew});
  final EditorLayer initial;
  final bool isNew;

  @override
  State<_TextSheet> createState() => _TextSheetState();
}

class _TextSheetState extends State<_TextSheet> {
  late EditorLayer _l = widget.initial;
  late final TextEditingController _text = TextEditingController(text: widget.initial.text);

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  void _done() {
    final t = _text.text.trim();
    if (t.isEmpty) {
      Navigator.pop(context);
      return;
    }
    Navigator.pop(context, _l.copyWith(text: t));
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final weight = _l.font == 'Pacifico' || _l.font == 'BebasNeue' ? FontWeight.w400 : FontWeight.w700;
    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(context).bottom),
      child: SafeArea(
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter),
                child: TextField(
                  controller: _text,
                  autofocus: true,
                  minLines: 1,
                  maxLines: 4,
                  textCapitalization: TextCapitalization.sentences,
                  textAlign: _l.align,
                  style: TextStyle(
                    fontFamily: _l.font,
                    fontSize: 24,
                    fontWeight: weight,
                    fontVariations: [ui.FontVariation('wght', weight.value.toDouble())],
                    color: k.ink,
                  ),
                  decoration: const InputDecoration(hintText: 'Écris ton texte'),
                ),
              ),
              const SizedBox(height: KatSpace.s8),
              SizedBox(
                height: 60,
                child: ListView.separated(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.symmetric(horizontal: KatSpace.gutter, vertical: 4),
                  itemCount: kTextFonts.length,
                  separatorBuilder: (_, _) => const SizedBox(width: KatSpace.s8),
                  itemBuilder: (context, i) {
                    final (family, label) = kTextFonts[i];
                    final sel = family == _l.font;
                    return Pressable(
                      onTap: () => setState(() => _l = _l.copyWith(font: family)),
                      selected: sel,
                      semanticLabel: 'Police $label',
                      child: AnimatedContainer(
                        duration: KatMotion.paint,
                        padding: const EdgeInsets.symmetric(horizontal: KatSpace.s16),
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          color: sel ? k.ink : k.sheet,
                          borderRadius: BorderRadius.circular(KatRadius.button),
                          border: Border.all(color: sel ? k.ink : k.line),
                        ),
                        child: Text(label, style: TextStyle(fontFamily: family, fontSize: 16, color: sel ? k.canvas : k.ink)),
                      ),
                    );
                  },
                ),
              ),
              ColorSwatches(value: _l.color, onChanged: (c) => setState(() => _l = _l.copyWith(color: c))),
              KatChoices<TextLook>(
                items: TextLook.values,
                value: _l.look,
                label: (v) => switch (v) {
                  TextLook.plain => 'Simple',
                  TextLook.label => 'Étiquette',
                  TextLook.outline => 'Contour',
                },
                onChanged: (v) => setState(() => _l = _l.copyWith(look: v)),
              ),
              KatChoices<TextAlign>(
                items: const [TextAlign.left, TextAlign.center, TextAlign.right],
                value: _l.align,
                icons: (a) => switch (a) {
                  TextAlign.left => Icons.format_align_left_rounded,
                  TextAlign.right => Icons.format_align_right_rounded,
                  _ => Icons.format_align_center_rounded,
                },
                label: (a) => switch (a) {
                  TextAlign.left => 'Gauche',
                  TextAlign.right => 'Droite',
                  _ => 'Centre',
                },
                onChanged: (a) => setState(() => _l = _l.copyWith(align: a)),
              ),
              Padding(
                padding: const EdgeInsets.fromLTRB(KatSpace.gutter, KatSpace.s12, KatSpace.gutter, KatSpace.s16),
                child: KatButton(label: widget.isNew ? 'Ajouter le texte' : 'Mettre à jour le texte', expand: true, onPressed: _done),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
