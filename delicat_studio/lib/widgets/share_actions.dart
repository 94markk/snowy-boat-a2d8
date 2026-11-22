import 'package:material_ui/material_ui.dart';

import '../core/brand.dart';
import '../core/image_io.dart';
import '../core/system.dart';
import '../theme/tokens.dart';
import 'common.dart';
import 'kat_button.dart';

/// WhatsApp first (statuses and chats are where the shop sells), then the system share sheet.
class ShareActions extends StatefulWidget {
  const ShareActions({super.key, required this.path, required this.isVideo, this.durationMs, this.onKat = false});
  final String path;
  final bool isVideo;
  final int? durationMs;

  /// Placed on the obsidian voucher.
  final bool onKat;

  static Future<bool>? _hasWhatsApp;

  @override
  State<ShareActions> createState() => _ShareActionsState();
}

class _ShareActionsState extends State<ShareActions> {
  late final Future<bool> _wa = ShareActions._hasWhatsApp ??= SystemBridge.hasWhatsApp();
  bool _busy = false;

  String? get _caption {
    final link = brand.kit.link.trim();
    return link.isEmpty ? null : link;
  }

  Future<void> _whatsApp() async {
    setState(() => _busy = true);
    final ok = await SystemBridge.shareToWhatsApp(widget.path, mime: widget.isVideo ? 'video/mp4' : _imageMime, text: _caption);
    if (!mounted) return;
    setState(() => _busy = false);
    if (!ok) showKatSnack(context, 'WhatsApp n’a pas pu s’ouvrir. Utilise Partager.');
  }

  String get _imageMime => widget.path.toLowerCase().endsWith('.png') ? 'image/png' : 'image/jpeg';

  Future<void> _share() async {
    try {
      await ImageIO.shareFile(widget.path, text: _caption);
    } catch (_) {
      if (mounted) showKatSnack(context, 'Le partage n’a pas pu s’ouvrir. Réessaie.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final k = KatColors.of(context);
    final long = widget.isVideo && (widget.durationMs ?? 0) > 60000;
    return FutureBuilder<bool>(
      future: _wa,
      builder: (context, snap) {
        final wa = snap.data == true;
        final shareKind = wa ? (widget.onKat ? KatButtonKind.onKat : KatButtonKind.secondary) : KatButtonKind.primary;
        return Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                if (wa) ...[
                  Expanded(
                    child: KatButton(label: 'WhatsApp', icon: Icons.chat_rounded, expand: true, loading: _busy, onPressed: _whatsApp),
                  ),
                  const SizedBox(width: KatSpace.s8),
                ],
                Expanded(child: KatButton(label: 'Partager', icon: Icons.share_rounded, kind: shareKind, expand: true, onPressed: _share)),
              ],
            ),
            if (wa && long)
              Padding(
                padding: const EdgeInsets.only(top: KatSpace.s8),
                child: Text(
                  'Pour un statut WhatsApp, une vidéo d’une minute ou moins passe le mieux.',
                  style: KatType.ui(14, color: widget.onKat ? k.onKatMuted : k.inkMuted),
                ),
              ),
          ],
        );
      },
    );
  }
}
