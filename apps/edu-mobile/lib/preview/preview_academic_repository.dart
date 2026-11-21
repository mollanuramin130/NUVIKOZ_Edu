import 'dart:math';
import '../../api_client.dart';
import '../academic/models/academic_session.dart';
import '../academic/models/school_class.dart';
import '../academic/models/class_offering.dart';
import '../academic/models/class_section.dart';
import '../academic/models/subject.dart';
import '../academic/models/subject_offering.dart';
import '../academic/repository/academic_repository.dart';
import 'preview_data.dart';
import 'preview_guard.dart';

/// In-memory fake repository providing deterministic offline catalog operations
/// and simulation toggles for interactive physical device walkthroughs.
class PreviewAcademicRepository implements AcademicRepository {
  PreviewAcademicRepository() {
    PreviewGuard.assertNotRelease();
    resetToDefaults();
  }

  final List<AcademicSession> _sessions = [];
  final List<SchoolClass> _classes = [];
  final List<ClassOffering> _offerings = [];
  final List<ClassSection> _sections = [];
  final List<Subject> _subjects = [];
  final List<SubjectOffering> _subjectOfferings = [];

  // Simulation controls
  bool simulateMfaRequired = false;
  bool simulateSuspended = false;
  bool simulateDemoExpired = false;
  bool simulateDemoLimit = false;
  bool simulateNetworkTimeout = false;

  String? lastIdempotencyKey;

  void resetToDefaults() {
    PreviewGuard.assertNotRelease();
    _sessions.clear();
    _sessions.addAll(PreviewData.initialSessions());

    _classes.clear();
    _classes.addAll(PreviewData.initialClasses());

    _offerings.clear();
    _offerings.addAll(PreviewData.initialOfferings());

    _sections.clear();
    _sections.addAll(PreviewData.initialSections());

    _subjects.clear();
    _subjects.addAll(PreviewData.initialSubjects());

    _subjectOfferings.clear();
    _subjectOfferings.addAll(PreviewData.initialSubjectOfferings());

    simulateMfaRequired = false;
    simulateSuspended = false;
    simulateDemoExpired = false;
    simulateDemoLimit = false;
    simulateNetworkTimeout = false;
  }

  void clearAll() {
    PreviewGuard.assertNotRelease();
    _sessions.clear();
    _classes.clear();
    _offerings.clear();
    _sections.clear();
    _subjects.clear();
    _subjectOfferings.clear();
  }

  void _checkSimulatedErrors() {
    if (simulateMfaRequired) {
      throw const ApiFailure(403, 'MFA_REQUIRED');
    }
    if (simulateSuspended) {
      throw const ApiFailure(403, 'ACCESS_SUSPENDED');
    }
    if (simulateDemoExpired) {
      throw const ApiFailure(403, 'DEMO_EXPIRED');
    }
    if (simulateDemoLimit) {
      throw const ApiFailure(403, 'DEMO_LIMIT_EXCEEDED');
    }
    if (simulateNetworkTimeout) {
      throw const ApiFailure(0, 'NETWORK_TIMEOUT');
    }
  }

  String _genId(String prefix) {
    final rand = Random().nextInt(999999).toString().padLeft(6, '0');
    return '01JPREVIEW${prefix.toUpperCase()}$rand';
  }

  @override
  Future<List<AcademicSession>> getSessions({String? cursor}) async {
    _checkSimulatedErrors();
    return List.unmodifiable(_sessions);
  }

  @override
  Future<List<SchoolClass>> getClasses({String? cursor}) async {
    _checkSimulatedErrors();
    final list = List<SchoolClass>.from(_classes)
      ..sort((a, b) => a.sortOrder.compareTo(b.sortOrder));
    return List.unmodifiable(list);
  }

  @override
  Future<List<ClassOffering>> getClassOfferings({String? sessionId, String? classId, String? cursor}) async {
    _checkSimulatedErrors();
    var list = _offerings;
    if (sessionId != null) {
      list = list.where((o) => o.sessionId == sessionId).toList();
    }
    if (classId != null) {
      list = list.where((o) => o.classId == classId).toList();
    }
    return List.unmodifiable(list);
  }

  @override
  Future<List<ClassSection>> getSections({String? classOfferingId, String? cursor}) async {
    _checkSimulatedErrors();
    var list = _sections;
    if (classOfferingId != null) {
      list = list.where((s) => s.classOfferingId == classOfferingId).toList();
    }
    return List.unmodifiable(list);
  }

  @override
  Future<List<Subject>> getSubjects({String? cursor}) async {
    _checkSimulatedErrors();
    return List.unmodifiable(_subjects);
  }

  @override
  Future<List<SubjectOffering>> getSubjectOfferings({String? classOfferingId, String? subjectId, String? cursor}) async {
    _checkSimulatedErrors();
    var list = _subjectOfferings;
    if (classOfferingId != null) {
      list = list.where((so) => so.classOfferingId == classOfferingId).toList();
    }
    if (subjectId != null) {
      list = list.where((so) => so.subjectId == subjectId).toList();
    }
    return List.unmodifiable(list);
  }

  @override
  Future<AcademicSession> createSession({
    required String name,
    required String startsOn,
    required String endsOn,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    _checkSimulatedErrors();
    final item = AcademicSession(
      id: _genId('SESS'),
      name: name,
      startsOn: startsOn,
      endsOn: endsOn,
      state: 'future',
      version: 1,
    );
    _sessions.insert(0, item);
    return item;
  }

  @override
  Future<SchoolClass> createClass({
    required String name,
    String? gradeLabel,
    required int sortOrder,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    _checkSimulatedErrors();
    final item = SchoolClass(
      id: _genId('CLS'),
      name: name,
      gradeLabel: gradeLabel,
      sortOrder: sortOrder,
      version: 1,
    );
    _classes.add(item);
    return item;
  }

  @override
  Future<ClassOffering> createClassOffering({
    required String sessionId,
    required String classId,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    _checkSimulatedErrors();
    final item = ClassOffering(
      id: _genId('OFF'),
      sessionId: sessionId,
      classId: classId,
      version: 1,
    );
    _offerings.insert(0, item);
    return item;
  }

  @override
  Future<ClassSection> createSection({
    required String classOfferingId,
    required String name,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    _checkSimulatedErrors();
    final item = ClassSection(
      id: _genId('SEC'),
      classOfferingId: classOfferingId,
      name: name,
      version: 1,
    );
    _sections.insert(0, item);
    return item;
  }

  @override
  Future<Subject> createSubject({
    required String code,
    required String name,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    _checkSimulatedErrors();
    final item = Subject(
      id: _genId('SUB'),
      code: code,
      name: name,
      version: 1,
    );
    _subjects.insert(0, item);
    return item;
  }

  @override
  Future<SubjectOffering> createSubjectOffering({
    required String classOfferingId,
    required String subjectId,
    required bool compulsory,
    String? idempotencyKey,
  }) async {
    lastIdempotencyKey = idempotencyKey;
    _checkSimulatedErrors();
    final item = SubjectOffering(
      id: _genId('SO'),
      classOfferingId: classOfferingId,
      subjectId: subjectId,
      compulsory: compulsory,
      version: 1,
    );
    _subjectOfferings.insert(0, item);
    return item;
  }
}
