import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/academic/controllers/academic_controller.dart';
import 'package:edu_mobile/academic/models/academic_session.dart';
import 'package:edu_mobile/academic/models/school_class.dart';
import 'package:edu_mobile/academic/models/class_offering.dart';
import 'package:edu_mobile/academic/models/class_section.dart';
import 'package:edu_mobile/academic/models/subject.dart';
import 'package:edu_mobile/academic/models/subject_offering.dart';
import 'package:edu_mobile/academic/repository/academic_repository.dart';
import 'package:edu_mobile/api_client.dart';

class FakeAcademicRepository implements AcademicRepository {
  List<AcademicSession> sessions = [];
  List<SchoolClass> classes = [];
  List<ClassOffering> offerings = [];
  List<ClassSection> sections = [];
  List<Subject> subjects = [];
  List<SubjectOffering> subjectOfferings = [];
  String? lastIdempotencyKey;
  Object? errorToThrow;

  @override
  Future<List<AcademicSession>> getSessions({String? cursor}) async {
    if (errorToThrow != null) throw errorToThrow!;
    return sessions;
  }

  @override
  Future<List<SchoolClass>> getClasses({String? cursor}) async {
    if (errorToThrow != null) throw errorToThrow!;
    return classes;
  }

  @override
  Future<List<ClassOffering>> getClassOfferings({String? sessionId, String? classId, String? cursor}) async {
    if (errorToThrow != null) throw errorToThrow!;
    return offerings;
  }

  @override
  Future<List<ClassSection>> getSections({String? classOfferingId, String? cursor}) async {
    if (errorToThrow != null) throw errorToThrow!;
    return sections;
  }

  @override
  Future<List<Subject>> getSubjects({String? cursor}) async {
    if (errorToThrow != null) throw errorToThrow!;
    return subjects;
  }

  @override
  Future<List<SubjectOffering>> getSubjectOfferings({String? classOfferingId, String? subjectId, String? cursor}) async {
    if (errorToThrow != null) throw errorToThrow!;
    return subjectOfferings;
  }

  @override
  Future<AcademicSession> createSession({
    required String name,
    required String startsOn,
    required String endsOn,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    if (errorToThrow != null) throw errorToThrow!;
    final s = AcademicSession(id: 'SESS_1', name: name, startsOn: startsOn, endsOn: endsOn, state: 'future', version: 1);
    sessions.add(s);
    return s;
  }

  @override
  Future<SchoolClass> createClass({
    required String name,
    String? gradeLabel,
    required int sortOrder,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    if (errorToThrow != null) throw errorToThrow!;
    final c = SchoolClass(id: 'CLS_1', name: name, gradeLabel: gradeLabel, sortOrder: sortOrder, version: 1);
    classes.add(c);
    return c;
  }

  @override
  Future<ClassOffering> createClassOffering({
    required String sessionId,
    required String classId,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    if (errorToThrow != null) throw errorToThrow!;
    final o = ClassOffering(id: 'OFF_1', sessionId: sessionId, classId: classId, version: 1);
    offerings.add(o);
    return o;
  }

  @override
  Future<ClassSection> createSection({
    required String classOfferingId,
    required String name,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    if (errorToThrow != null) throw errorToThrow!;
    final sec = ClassSection(id: 'SEC_1', classOfferingId: classOfferingId, name: name, version: 1);
    sections.add(sec);
    return sec;
  }

  @override
  Future<Subject> createSubject({
    required String code,
    required String name,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    if (errorToThrow != null) throw errorToThrow!;
    final sub = Subject(id: 'SUB_1', code: code, name: name, version: 1);
    subjects.add(sub);
    return sub;
  }

  @override
  Future<SubjectOffering> createSubjectOffering({
    required String classOfferingId,
    required String subjectId,
    required bool compulsory,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    if (errorToThrow != null) throw errorToThrow!;
    final so = SubjectOffering(id: 'SO_1', classOfferingId: classOfferingId, subjectId: subjectId, compulsory: compulsory, version: 1);
    subjectOfferings.add(so);
    return so;
  }
}

void main() {
  group('AcademicController', () {
    late FakeAcademicRepository repo;
    late AcademicController controller;

    setUp(() {
      repo = FakeAcademicRepository();
      controller = AcademicController(repo);
    });

    test('initial state and active tab switching', () {
      expect(controller.activeTab, AcademicTab.sessions);
      expect(controller.isLoading, isFalse);

      controller.setActiveTab(AcademicTab.classes);
      expect(controller.activeTab, AcademicTab.classes);
    });

    test('createSession updates sessions list, sets successMessage and forwards idempotencyKey', () async {
      final success = await controller.createSession(
        name: '2027–2028',
        startsOn: '2027-04-01',
        endsOn: '2028-03-31',
        idempotencyKey: 'test-idempotency-key-1234567890',
      );

      expect(success, isTrue);
      expect(controller.sessions.length, 1);
      expect(controller.sessions.first.name, '2027–2028');
      expect(controller.selectedSession?.name, '2027–2028');
      expect(controller.successMessage, contains('created successfully'));
      expect(repo.lastIdempotencyKey, 'test-idempotency-key-1234567890');
    });

    test('createClass adds to classes and sorts', () async {
      final success = await controller.createClass(
        name: 'Class 9',
        gradeLabel: '9',
        sortOrder: 9,
      );

      expect(success, isTrue);
      expect(controller.classes.length, 1);
      expect(controller.classes.first.name, 'Class 9');
    });

    test('selection flow updates selectedSession and selectedClassOffering', () {
      const session = AcademicSession(id: 'S1', name: '2027–2028', startsOn: '2027-04-01', endsOn: '2028-03-31', state: 'future', version: 1);
      controller.selectSession(session);
      expect(controller.selectedSession?.id, 'S1');

      const offering = ClassOffering(id: 'O1', sessionId: 'S1', classId: 'C1', version: 1);
      controller.selectClassOffering(offering);
      expect(controller.selectedClassOffering?.id, 'O1');
    });

    group('Error distinction contract', () {
      test('403 MFA_REQUIRED sets isMfaRequired true and specific privileged prompt', () async {
        repo.errorToThrow = const ApiFailure(403, 'MFA_REQUIRED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isTrue);
        expect(controller.lastErrorCode, 'MFA_REQUIRED');
        expect(controller.errorMessage, contains('Privileged MFA verification required'));
      });

      test('403 ROLE_UNAUTHORIZED sets isMfaRequired false and role denial message', () async {
        repo.errorToThrow = const ApiFailure(403, 'ROLE_UNAUTHORIZED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('School Admin'));
      });

      test('403 ENTITLEMENT_REQUIRED sets isMfaRequired false and read-only message', () async {
        repo.errorToThrow = const ApiFailure(403, 'ENTITLEMENT_REQUIRED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('entitlement has expired'));
      });

      test('403 DEMO_LIMIT_EXCEEDED sets isMfaRequired false and evaluation limit message', () async {
        repo.errorToThrow = const ApiFailure(403, 'DEMO_LIMIT_EXCEEDED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('Evaluation limit reached'));
      });

      test('403 CROSS_TENANT_DENIED sets isMfaRequired false and tenant violation message', () async {
        repo.errorToThrow = const ApiFailure(403, 'CROSS_TENANT_DENIED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('Cross-tenant access violation'));
      });

      test('403 ACCESS_SUSPENDED sets suspension message', () async {
        repo.errorToThrow = const ApiFailure(403, 'ACCESS_SUSPENDED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('Institution access is currently suspended'));
      });

      test('403 DEMO_EXPIRED sets evaluation expired message', () async {
        repo.errorToThrow = const ApiFailure(403, 'DEMO_EXPIRED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('Evaluation period has expired'));
      });

      test('403 TOKEN_SCHOOL_MISMATCH sets cross-tenant message', () async {
        repo.errorToThrow = const ApiFailure(403, 'TOKEN_SCHOOL_MISMATCH');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('Cross-tenant access violation'));
      });

      test('401 TOKEN_EXPIRED sets session expired message', () async {
        repo.errorToThrow = const ApiFailure(401, 'TOKEN_EXPIRED');

        final success = await controller.createSession(
          name: '2027–2028',
          startsOn: '2027-04-01',
          endsOn: '2028-03-31',
        );

        expect(success, isFalse);
        expect(controller.isMfaRequired, isFalse);
        expect(controller.errorMessage, contains('Session has expired or changed'));
      });
    });

    test('reset clears all catalog state and restores default tab', () {
      controller.sessions = [
        const AcademicSession(id: 'S1', name: '2027–2028', startsOn: '2027-04-01', endsOn: '2028-03-31', state: 'future', version: 1),
      ];
      controller.setActiveTab(AcademicTab.sections);
      controller.errorMessage = 'Some error';

      controller.reset();

      expect(controller.activeTab, AcademicTab.sessions);
      expect(controller.sessions, isEmpty);
      expect(controller.errorMessage, isNull);
    });

    test('dispose prevents notifyListeners from throwing after disposal', () {
      controller.dispose();
      // Should not throw
      controller.notifyListeners();
    });
  });
}
