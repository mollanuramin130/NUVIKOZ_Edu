import 'package:flutter/foundation.dart';

/// Build-time and runtime guard ensuring preview mode is completely excluded
/// and strictly unreachable in production release builds.
class PreviewGuard {
  const PreviewGuard._();

  /// Returns true only in debug and profile modes; false in release mode.
  static bool get isPreviewSupported => !kReleaseMode;

  /// Throws [StateError] if called within a release build.
  static void assertNotRelease() {
    if (kReleaseMode) {
      throw StateError('Preview mode is strictly forbidden in release builds.');
    }
  }
}
