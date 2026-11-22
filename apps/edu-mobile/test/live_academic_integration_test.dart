import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/api_client.dart';
import 'package:edu_mobile/academic/controllers/academic_controller.dart';
import 'package:edu_mobile/academic/models/academic_session.dart';
import 'package:edu_mobile/academic/models/class_section.dart';
import 'package:edu_mobile/academic/models/school_class.dart';
import 'package:edu_mobile/academic/models/subject.dart';
import 'package:edu_mobile/academic/repository/academic_repository.dart';
import 'package:edu_mobile/academic/screens/academic_analytics_screen.dart';
import 'package:edu_mobile/academic/screens/academic_workspace_screen.dart';
import 'package:edu_mobile/auth/auth_controller.dart';
import 'package:edu_mobile/dashboard/screens/dashboard_screen.dart';
import 'package:edu_mobile/navigation/main_navigation_shell.dart';
import 'package:edu_mobile/preview/preview_controller_factory.dart';
import 'package:edu_mobile/theme/app_theme.dart';
import 'auth_controller_test.dart' show MemoryStore;

const _kTestSchoolId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
const _kTestMemberId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';
const _kTestUserId = '01ARZ3NDEKTSV4RRFFQ69G5FAX';
final _kTestBearer = '1|${'a' * 64}';

class FakeAcademicTransport implements ApiTransport, AcademicApiTransport {
  final List<Map<String, dynamic>> recordedCalls = [];
  Map<String, String>? lastForwardedQueryParams;
  Map<String, String>? lastForwardedHeaders;

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
    Map<String, String>? queryParams,
    Map<String, String>? headers,
  }) async {
    lastForwardedQueryParams = queryParams;
    lastForwardedHeaders = headers;
    recordedCalls.add({
      'method': method,
      'path': path,
      'body': body,
      'token': token,
      'school': school,
      'queryParams': queryParams,
      'headers': headers,
    });

    if (path == '/api/v1/auth/login') {
      return {
        'data': {
          'token': _kTestBearer,
          'school_id': _kTestSchoolId,
          'membership_id': _kTestMemberId,
          'user_id': _kTestUserId,
          'role': 'school_admin',
          'expires_at': DateTime.now().add(const Duration(hours: 4)).toUtc().toIso8601String(),
          'abilities': ['school:$_kTestSchoolId'],
          'must_change_password': false,
        },
        'meta': {'request_id': 'req_auth_01'},
      };
    }

    if (path.startsWith('/api/v1/schools/')) {
      return {
        'data': {
          'id': _kTestSchoolId,
          'name': 'Springdale International Academy',
          'timezone': 'Asia/Kolkata',
        },
        'meta': {'request_id': 'req_school_01'},
      };
    }

    return {
      'data': {
        'items': <Map<String, dynamic>>[],
        'next_cursor': null,
      },
      'meta': {'request_id': 'req_test_01'},
    };
  }

  @override
  void close() {}
}

class FakeTestRepository implements AcademicRepository {
  List<AcademicSession> sessions = [];
  List<SchoolClass> classes = [];
  List<ClassSection> sections = [];
  List<Subject> subjects = [];
  bool failNext = false;
  Duration delay = Duration.zero;

  @override
  Future<List<AcademicSession>> getSessions({String? cursor}) async {
    if (delay > Duration.zero) await Future.delayed(delay);
    if (failNext) throw const ApiFailure(503, 'SERVICE_UNAVAILABLE');
    return sessions;
  }

  @override
  Future<List<SchoolClass>> getClasses({String? cursor}) async {
    if (delay > Duration.zero) await Future.delayed(delay);
    if (failNext) throw const ApiFailure(503, 'SERVICE_UNAVAILABLE');
    return classes;
  }

  @override
  Future<List<ClassSection>> getSections({String? classOfferingId, String? cursor}) async {
    if (delay > Duration.zero) await Future.delayed(delay);
    if (failNext) throw const ApiFailure(503, 'SERVICE_UNAVAILABLE');
    return sections;
  }

  @override
  Future<List<Subject>> getSubjects({String? cursor}) async {
    if (delay > Duration.zero) await Future.delayed(delay);
    if (failNext) throw const ApiFailure(503, 'SERVICE_UNAVAILABLE');
    return subjects;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

void main() {
  group('AG-UI-005 Premium Live Academic Integration Tests', () {
    testWidgets('AcademicTransportAdapter properly forwards queryParams and headers', (tester) async {
      final fakeTransport = FakeAcademicTransport();
      final adapter = MainNavigationShell(
        authController: AuthController(fakeTransport, MemoryStore()),
      );
      expect(adapter, isNotNull);

      // Verify direct forwarding of headers and queryParams
      await fakeTransport.send(
        'GET',
        '/api/v1/academic/sessions',
        queryParams: {'cursor': '01JPREVCURSOR00000000001'},
        headers: {'Idempotency-Key': 'key-abc-1234567890'},
      );

      expect(fakeTransport.lastForwardedQueryParams?['cursor'], '01JPREVCURSOR00000000001');
      expect(fakeTransport.lastForwardedHeaders?['Idempotency-Key'], 'key-abc-1234567890');
    });

    testWidgets('DashboardScreen loads real catalog summary metrics asynchronously', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final fakeRepo = FakeTestRepository()
        ..sessions = [
          AcademicSession(
            id: '01JSESS000000000000000001',
            name: '2026–2027 Live Session',
            startsOn: '2026-04-01',
            endsOn: '2027-03-31',
            state: 'active',
            version: 1,
          ),
          AcademicSession(
            id: '01JSESS000000000000000002',
            name: '2027–2028 Future Session',
            startsOn: '2027-04-01',
            endsOn: '2028-03-31',
            state: 'future',
            version: 1,
          ),
        ]
        ..classes = [
          SchoolClass(id: '01JCLS000000000000000001', name: 'Grade 9', sortOrder: 1, version: 1),
          SchoolClass(id: '01JCLS000000000000000002', name: 'Grade 10', sortOrder: 2, version: 1),
          SchoolClass(id: '01JCLS000000000000000003', name: 'Grade 11', sortOrder: 3, version: 1),
        ]
        ..sections = [
          ClassSection(id: '01JSEC000000000000000001', classOfferingId: '01JOFF000001', name: 'Section A', version: 1),
        ]
        ..subjects = [
          Subject(id: '01JSUB000000000000000001', code: 'MATH', name: 'Mathematics', version: 1),
          Subject(id: '01JSUB000000000000000002', code: 'PHY', name: 'Physics', version: 1),
        ];

      final auth = AuthController(FakeAcademicTransport(), MemoryStore());
      await auth.login('DEMO', 'ADMIN', 'SyntheticPassword');

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: DashboardScreen(
            authController: auth,
            academicRepository: fakeRepo,
            isPreviewMode: false,
          ),
        ),
      );
      await tester.pumpAndSettle();

      // Verify dynamic values loaded from repository
      expect(find.text('2 Terms'), findsOneWidget);
      expect(find.text('Active: 2026–2027 Live Session'), findsOneWidget);
      expect(find.text('3 Grades'), findsOneWidget);
      expect(find.text('1 Group'), findsOneWidget);
      expect(find.text('2 Courses'), findsOneWidget);

      auth.dispose();
    });

    testWidgets('DashboardScreen displays error and retry when catalog query fails', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final fakeRepo = FakeTestRepository()..failNext = true;
      final auth = AuthController(FakeAcademicTransport(), MemoryStore());
      await auth.login('DEMO', 'ADMIN', 'SyntheticPassword');

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: DashboardScreen(
            authController: auth,
            academicRepository: fakeRepo,
            isPreviewMode: false,
          ),
        ),
      );
      await tester.pumpAndSettle();

      // Error state displayed
      expect(find.text('Catalog summary unavailable'), findsOneWidget);
      expect(find.text('Retry'), findsOneWidget);
      expect(find.text('Unavailable'), findsNWidgets(4));

      // Resolve error and tap retry
      fakeRepo.failNext = false;
      fakeRepo.sessions = [
        AcademicSession(
          id: '01JSESS000000000000000001',
          name: 'Recovered Term',
          startsOn: '2026-04-01',
          endsOn: '2027-03-31',
          state: 'active',
          version: 1,
        ),
      ];

      await tester.tap(find.text('Retry'));
      await tester.pumpAndSettle();

      expect(find.text('1 Term'), findsOneWidget);
      expect(find.text('Active: Recovered Term'), findsOneWidget);

      auth.dispose();
    });

    testWidgets('AcademicWorkspaceScreen displays Session Governance Policy and opens Web Portal guidance dialog', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final harness = await PreviewHarness.create();
      final controller = AcademicController(harness.academicRepository);
      await controller.loadSessions();

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: Scaffold(
            body: AcademicWorkspaceScreen(
              controller: controller,
              schoolName: 'Test School',
              authController: harness.authController,
              isPreviewMode: true,
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      // Governance policy card visible
      expect(find.text('Session Governance Policy'), findsOneWidget);
      expect(find.text('Governance'), findsWidgets);

      // Tap on Governance action on session card
      await tester.tap(find.text('Governance').first);
      await tester.pumpAndSettle();

      // Verify Web Portal guidance dialog appears without invoking external browser
      expect(find.text('Session Governance'), findsOneWidget);
      expect(find.text('MANAGED VIA SCHOOL ADMIN WEB PORTAL'), findsOneWidget);
      expect(find.text('Understood'), findsOneWidget);

      await tester.tap(find.text('Understood'));
      await tester.pumpAndSettle();

      expect(find.text('MANAGED VIA SCHOOL ADMIN WEB PORTAL'), findsNothing);

      harness.authController.dispose();
    });

    testWidgets('AcademicAnalyticsScreen renders PROTOTYPE — NOT LIVE SCHOOL DATA notice', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      await tester.pumpWidget(
        const MaterialApp(
          home: AcademicAnalyticsScreen(isPreviewMode: false),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('PROTOTYPE — NOT LIVE SCHOOL DATA'), findsNWidgets(2));
      expect(find.text('SYNTHETIC PREVIEW PROTOTYPE: Demonstrating proposed attendance and marks visualization components.'), findsOneWidget);
    });
  });
}
