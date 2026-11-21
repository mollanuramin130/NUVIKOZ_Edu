import '../auth/device_session.dart';
import 'preview_guard.dart';

/// Creates a safe in-memory synthetic session for development preview.
DeviceSession createPreviewSession() {
  PreviewGuard.assertNotRelease();
  return DeviceSession(
    token: '1|preview_synthetic_token_${'0'*64}',
    school: '01JPREV1EW0000000000000001',
    membership: '01JPREV1EW0000000000000002',
    user: '01JPREV1EW0000000000000003',
    role: 'school_admin',
    expires: DateTime.now().add(const Duration(days: 7)),
  );
}
