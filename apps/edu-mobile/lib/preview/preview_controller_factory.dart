import '../auth/auth_controller.dart';
import '../auth/secure_token_store.dart';
import 'preview_academic_repository.dart';
import 'preview_api_transport.dart';
import 'preview_guard.dart';

/// In-memory token store for preview sessions that never persists credentials to disk.
class PreviewMemoryStore implements SecureTokenStore {
  String? _value;

  @override
  Future<String?> read() async => _value;

  @override
  Future<void> write(String next) async => _value = next;

  @override
  Future<void> clear() async => _value = null;
}

/// Development-only harness bundling fake auth and academic repositories.
class PreviewHarness {
  PreviewHarness({
    required this.authController,
    required this.academicRepository,
  });

  final AuthController authController;
  final PreviewAcademicRepository academicRepository;

  static Future<PreviewHarness> create() async {
    PreviewGuard.assertNotRelease();
    final transport = PreviewApiTransport();
    final store = PreviewMemoryStore();
    final auth = AuthController(transport, store);
    await auth.login('DEMO', 'ADMIN', 'preview-development-offline');
    final academicRepo = PreviewAcademicRepository();
    return PreviewHarness(
      authController: auth,
      academicRepository: academicRepo,
    );
  }
}
