import 'package:flutter/services.dart';

/// What the phone can handle, used to pick safe defaults on budget devices.
class DeviceProfile {
  const DeviceProfile({
    this.manufacturer = '',
    this.model = '',
    this.sdk = 0,
    this.release = '',
    this.totalRamMb = 0,
    this.lowRam = false,
  });

  final String manufacturer;
  final String model;
  final int sdk;
  final String release;
  final int totalRamMb;
  final bool lowRam;

  /// Phones with about 3 GB of RAM or less, or flagged low-RAM by Android.
  bool get isBudget => lowRam || (totalRamMb > 0 && totalRamMb < 3500);

  String describe() =>
      '$manufacturer $model, Android $release (API $sdk), ${totalRamMb > 0 ? '${(totalRamMb / 1024).toStringAsFixed(1)} Go de RAM' : 'RAM inconnue'}${lowRam ? ', mode faible mémoire' : ''}';
}

abstract final class SystemBridge {
  static const _ch = MethodChannel('ht.delicat.studio/system');
  static DeviceProfile device = const DeviceProfile();

  static Future<DeviceProfile> loadDevice() async {
    try {
      final m = await _ch.invokeMapMethod<String, Object?>('deviceInfo');
      if (m != null) {
        device = DeviceProfile(
          manufacturer: '${m['manufacturer'] ?? ''}',
          model: '${m['model'] ?? ''}',
          sdk: (m['sdk'] as num?)?.toInt() ?? 0,
          release: '${m['release'] ?? ''}',
          totalRamMb: (m['totalRamMb'] as num?)?.toInt() ?? 0,
          lowRam: m['lowRam'] == true,
        );
      }
    } catch (_) {
      // Not on Android (tests): keep defaults.
    }
    return device;
  }

  static Future<bool> hasWhatsApp() async {
    try {
      final list = await _ch.invokeListMethod<String>('installedApps');
      return list?.isNotEmpty ?? false;
    } catch (_) {
      return false;
    }
  }

  /// Opens WhatsApp (or WhatsApp Business) with the file ready to send or post as a status.
  /// Falls back to the system share sheet. Returns false if nothing could be opened.
  static Future<bool> shareToWhatsApp(String path, {required String mime, String? text}) async {
    try {
      await _ch.invokeMethod<String>('shareTo', {'path': path, 'mime': mime, 'package': 'com.whatsapp', 'text': text});
      return true;
    } catch (_) {
      return false;
    }
  }
}
