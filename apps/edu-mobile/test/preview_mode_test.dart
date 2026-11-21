import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/api_client.dart';
import 'package:edu_mobile/auth/auth_controller.dart';
import 'package:edu_mobile/auth/screens/login_screen.dart';
import 'package:edu_mobile/preview/preview_academic_repository.dart';
import 'package:edu_mobile/preview/preview_controller_factory.dart';
import 'package:edu_mobile/preview/preview_guard.dart';

void main() {
  group('Preview Mode Architecture & Release Build Protection', () {
    test('PreviewGuard allows preview in debug/test mode and asserts release boundary', () {
      expect(PreviewGuard.isPreviewSupported, isTrue);
      // In test environment, assertNotRelease should not throw
      expect(() => PreviewGuard.assertNotRelease(), returnsNormally);
    });

    test('PreviewAcademicRepository initializes full 6-module synthetic catalog', () async {
      final repo = PreviewAcademicRepository();

      final sessions = await repo.getSessions();
      expect(sessions.length, 3);
      expect(sessions.any((s) => s.state == 'active'), isTrue);
      expect(sessions.any((s) => s.state == 'future'), isTrue);

      final classes = await repo.getClasses();
      expect(classes.length, 4);
      expect(classes.first.name, 'Class 9');

      final offerings = await repo.getClassOfferings();
      expect(offerings.length, 3);

      final sections = await repo.getSections();
      expect(sections.length, 3);

      final subjects = await repo.getSubjects();
      expect(subjects.length, 5);

      final subjectOfferings = await repo.getSubjectOfferings();
      expect(subjectOfferings.length, 4);
    });

    test('PreviewAcademicRepository in-memory mutations are deterministic', () async {
      final repo = PreviewAcademicRepository();

      final newSession = await repo.createSession(
        name: '2029–2030 Custom Year',
        startsOn: '2029-04-01',
        endsOn: '2030-03-31',
        idempotencyKey: 'preview-key-001',
      );
      expect(newSession.name, '2029–2030 Custom Year');
      expect(repo.lastIdempotencyKey, 'preview-key-001');

      final updatedSessions = await repo.getSessions();
      expect(updatedSessions.length, 4);
      expect(updatedSessions.first.name, '2029–2030 Custom Year');

      final newSection = await repo.createSection(
        classOfferingId: '01JPREVIEWOFF000000000001',
        name: 'Section Emerald',
      );
      expect(newSection.name, 'Section Emerald');

      final updatedSections = await repo.getSections(classOfferingId: '01JPREVIEWOFF000000000001');
      expect(updatedSections.any((s) => s.name == 'Section Emerald'), isTrue);
    });

    test('Simulation controls trigger fault injection deterministically', () async {
      final repo = PreviewAcademicRepository();

      repo.simulateMfaRequired = true;
      expect(
        () => repo.createSession(name: 'Test', startsOn: '2028-04-01', endsOn: '2029-03-31'),
        throwsA(isA<ApiFailure>().having((e) => e.code, 'code', 'MFA_REQUIRED')),
      );

      repo.simulateMfaRequired = false;
      repo.simulateSuspended = true;
      expect(
        () => repo.getSessions(),
        throwsA(isA<ApiFailure>().having((e) => e.code, 'code', 'ACCESS_SUSPENDED')),
      );

      repo.simulateSuspended = false;
      repo.simulateDemoLimit = true;
      expect(
        () => repo.createClass(name: 'Class 8', sortOrder: 8),
        throwsA(isA<ApiFailure>().having((e) => e.code, 'code', 'DEMO_LIMIT_EXCEEDED')),
      );

      repo.simulateDemoLimit = false;
      repo.clearAll();
      expect(await repo.getSessions(), isEmpty);
      expect(await repo.getClasses(), isEmpty);

      repo.resetToDefaults();
      expect((await repo.getSessions()).length, 3);
      expect((await repo.getClasses()).length, 4);
    });

    test('PreviewHarness establishes authenticated offline session with zero real network calls', () async {
      final harness = await PreviewHarness.create();

      expect(harness.authController.state, AuthState.authenticated);
      expect(harness.authController.session, isNotNull);
      expect(harness.authController.session!.role, 'school_admin');
      expect(harness.authController.session!.privileged, isTrue);
      expect(harness.authController.session!.school, '01JPREV1EW0000000000000001');

      // Logout clears session
      await harness.authController.logout();
      expect(harness.authController.state, AuthState.signedOut);
      expect(harness.authController.session, isNull);
    });

    testWidgets('LoginScreen renders Preview App entry button and enters Dashboard in preview mode', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final fakeApi = FakePreviewApi();
      final store = PreviewMemoryStore();
      final auth = AuthController(fakeApi, store);

      await tester.pumpWidget(
        MaterialApp(
          home: LoginScreen(controller: auth),
        ),
      );

      final previewButton = find.byKey(const Key('preview_app_button'));
      expect(previewButton, findsOneWidget);
      expect(find.text('Preview App — Development Only'), findsOneWidget);

      await tester.ensureVisible(previewButton);
      await tester.tap(previewButton);
      await tester.pumpAndSettle();

      // Dashboard screen reached in preview mode
      expect(find.text('DEMO DATA — NO REAL BACKEND (PREVIEW MODE)'), findsWidgets);
      expect(find.text('Academic Catalog'), findsWidgets);
      expect(find.text('Device Security'), findsWidgets);

      // Exit preview mode and return to login screen
      final exitButton = find.text('Exit Preview').first;
      await tester.ensureVisible(exitButton);
      await tester.tap(exitButton);
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('preview_app_button')), findsOneWidget);
    });
  });
}

class FakePreviewApi implements ApiTransport {
  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
  }) async {
    return {'data': {}};
  }

  @override
  void close() {}
}
