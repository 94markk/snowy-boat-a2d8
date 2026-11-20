import 'dart:convert';

import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';

enum ExportFormat { png, jpg }

/// Longest side photos are scaled to on import (keeps budget phones fast).
enum ImportQuality {
  standard(1440, 'Standard', 'Rapide, idéal pour les réseaux'),
  high(2048, 'Haute', 'Recommandé'),
  max(3072, 'Maximale', 'Plus lent sur les petits téléphones');

  const ImportQuality(this.maxSide, this.label, this.hint);
  final int maxSide;
  final String label;
  final String hint;
}

/// User preferences, persisted with shared_preferences.
class AppSettings extends ChangeNotifier {
  ThemeMode themeMode = ThemeMode.dark;
  bool lowPower = false;
  ExportFormat format = ExportFormat.png;
  int jpgQuality = 92;
  bool saveToGallery = true;
  ImportQuality importQuality = ImportQuality.high;

  /// How often each tool was opened (drives « Recommandé pour toi »).
  Map<String, int> toolUses = {};

  SharedPreferences? _prefs;

  Future<void> load() async {
    try {
      final p = await SharedPreferences.getInstance();
      _prefs = p;
      themeMode = ThemeMode.values[p.getInt('themeMode') ?? ThemeMode.dark.index];
      lowPower = p.getBool('lowPower') ?? false;
      format = ExportFormat.values[p.getInt('format') ?? 0];
      jpgQuality = p.getInt('jpgQuality') ?? 92;
      saveToGallery = p.getBool('saveToGallery') ?? true;
      importQuality = ImportQuality.values[p.getInt('importQuality') ?? ImportQuality.high.index];
      final raw = p.getString('toolUses');
      if (raw != null) toolUses = (jsonDecode(raw) as Map).map((k, v) => MapEntry(k as String, (v as num).toInt()));
    } catch (_) {
      // Preferences unavailable (tests, first boot hiccup): keep defaults.
    }
  }

  void _save(void Function(SharedPreferences p) write) {
    final p = _prefs;
    if (p != null) write(p);
    notifyListeners();
  }

  void setThemeMode(ThemeMode m) {
    themeMode = m;
    _save((p) => p.setInt('themeMode', m.index));
  }

  void setLowPower(bool v) {
    lowPower = v;
    _save((p) => p.setBool('lowPower', v));
  }

  void setFormat(ExportFormat f) {
    format = f;
    _save((p) => p.setInt('format', f.index));
  }

  void setJpgQuality(int q) {
    jpgQuality = q;
    _save((p) => p.setInt('jpgQuality', q));
  }

  void setSaveToGallery(bool v) {
    saveToGallery = v;
    _save((p) => p.setBool('saveToGallery', v));
  }

  void recordUse(String tool) {
    toolUses = {...toolUses, tool: (toolUses[tool] ?? 0) + 1};
    _save((p) => p.setString('toolUses', jsonEncode(toolUses)));
  }

  void setImportQuality(ImportQuality q) {
    importQuality = q;
    _save((p) => p.setInt('importQuality', q.index));
  }
}

final settings = AppSettings();

/// True when motion should be skipped (system setting or low-power mode).
bool reduceMotion(BuildContext context) =>
    settings.lowPower || (MediaQuery.maybeDisableAnimationsOf(context) ?? false);
