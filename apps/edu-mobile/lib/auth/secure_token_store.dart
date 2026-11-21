import 'package:flutter/services.dart';

class SecureStorageUnavailable implements Exception {
  @override
  String toString() => 'Secure device storage is unavailable. Sign-in is disabled on this platform.';
}
abstract class SecureTokenStore {
  Future<String?> read();
  Future<void> write(String value);
  Future<void> clear();
}
// Android supplies a Keystore-backed handler. Other platforms still fail closed
// without an approved native handler. No preferences/file fallback.
class PlatformSecureTokenStore implements SecureTokenStore {
  static const _channel = MethodChannel('nuvikoz.edu/secure-token-store');
  @override
  Future<String?> read() async {
    try { return await _channel.invokeMethod<String>('read'); }
    on PlatformException { throw SecureStorageUnavailable(); }
    on MissingPluginException { throw SecureStorageUnavailable(); }
  }
  @override
  Future<void> write(String value) async {
    try {
      final confirmed = await _channel.invokeMethod<bool>('write', {'value': value});
      if (confirmed != true) throw SecureStorageUnavailable();
    } on PlatformException { throw SecureStorageUnavailable(); }
    on MissingPluginException { throw SecureStorageUnavailable(); }
  }
  @override
  Future<void> clear() async {
    try {
      final confirmed = await _channel.invokeMethod<bool>('clear');
      if (confirmed != true) throw SecureStorageUnavailable();
    } on PlatformException { throw SecureStorageUnavailable(); }
    on MissingPluginException { throw SecureStorageUnavailable(); }
  }
}
