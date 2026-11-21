import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';
import 'package:edu_mobile/auth/secure_token_store.dart';

const _native = MethodChannel('nuvikoz.edu/secure-token-store');
const _control = MethodChannel('nuvikoz.edu/sec01-harness-control');
const _synthetic = 'SEC01_SYNTHETIC_CHANNEL_TOKEN_V1';

class _CheckFailed implements Exception {
  const _CheckFailed(this.label);
  final String label;
}

Future<void> main(List<String> arguments) async {
  WidgetsFlutterBinding.ensureInitialized();
  if (!kDebugMode || arguments.length != 2) {
    throw StateError('Explicit debug instrumentation launch required');
  }
  runApp(const Directionality(textDirection: TextDirection.ltr,
    child: Center(child: Text('SEC-01 synthetic channel verification'))));
  final phase = arguments[0];
  final checks = <String>[];
  String? failure;
  void check(bool ok, String label) {
    if (!ok) throw _CheckFailed(label);
    checks.add(label);
  }
  final store = PlatformSecureTokenStore();
  try {
    if (phase == 'contract') {
      await store.clear();
      check(await store.read() == null, 'initial_clear');
      await store.write(_synthetic);
      check(await store.read() == _synthetic, 'adapter_round_trip');
      check(await _native.invokeMethod<String>('read') == _synthetic, 'raw_channel_read');
      check(await _control.invokeMethod<bool>('assertCiphertextOnly') == true, 'ciphertext_only');
      await store.clear();
      check(await store.read() == null, 'read_after_clear');
      check(await _native.invokeMethod<bool>('write', {'value': _synthetic}) == true, 'raw_channel_write');
      check(await store.read() == _synthetic, 'adapter_reads_native_write');
      for (final value in ['', 'x' * 60001]) {
        var denied = false;
        try { await _native.invokeMethod<bool>('write', {'value': value}); }
        on PlatformException catch (error) {
          denied = error.code == 'INVALID_ARGUMENT' && error.message == 'Invalid token payload' && error.details == null;
        }
        check(denied, value.isEmpty ? 'empty_rejected' : 'oversize_rejected');
      }
      check(await store.read() == _synthetic, 'invalid_write_preserves_value');
      check(await _control.invokeMethod<bool>('deleteSyntheticKey') == true, 'delete_test_key');
      var nativeDenied = false;
      try { await _native.invokeMethod<String>('read'); }
      on PlatformException catch (error) {
        nativeDenied = error.code == 'SECURE_STORAGE_ERROR' && error.message == 'Secure device storage operation failed' && error.details == null;
      }
      check(nativeDenied, 'native_error_redacted');
      var adapterDenied = false;
      try { await store.read(); } on SecureStorageUnavailable { adapterDenied = true; }
      check(adapterDenied, 'adapter_fails_closed');
      check(await _native.invokeMethod<bool>('clear') == true, 'raw_channel_clear');
      check(await store.read() == null, 'final_clear');
    } else if (phase == 'persist-write') {
      await store.clear();
      await store.write(_synthetic);
      check(await store.read() == _synthetic, 'persist_write');
      check(await _control.invokeMethod<bool>('assertCiphertextOnly') == true, 'persist_ciphertext');
    } else if (phase == 'persist-read') {
      check(await store.read() == _synthetic, 'persist_read_new_engine');
      await store.clear();
      check(await store.read() == null, 'persist_cleanup');
    } else {
      throw const _CheckFailed('invalid_phase');
    }
  } catch (error) {
    failure = error is _CheckFailed ? error.label : 'unexpected_${error.runtimeType}';
    try { await store.clear(); } catch (_) { /* Report failure without fallback. */ }
  }
  await _control.invokeMethod<bool>('report', {
    'nonce': arguments[1], 'phase': phase, 'ok': failure == null,
    'checks': checks, 'failure': failure,
  });
}
