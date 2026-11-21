import 'dart:math';
import '../../api_client.dart';
import '../../auth/device_session.dart';
import '../models/academic_session.dart';
import '../models/school_class.dart';
import '../models/class_offering.dart';
import '../models/class_section.dart';
import '../models/subject.dart';
import '../models/subject_offering.dart';

abstract class AcademicRepository {
  Future<List<AcademicSession>> getSessions({String? cursor});
  Future<List<SchoolClass>> getClasses({String? cursor});
  Future<List<ClassOffering>> getClassOfferings({String? sessionId, String? classId, String? cursor});
  Future<List<ClassSection>> getSections({String? classOfferingId, String? cursor});
  Future<List<Subject>> getSubjects({String? cursor});
  Future<List<SubjectOffering>> getSubjectOfferings({String? classOfferingId, String? subjectId, String? cursor});

  Future<AcademicSession> createSession({
    required String name,
    required String startsOn,
    required String endsOn,
  });

  Future<SchoolClass> createClass({
    required String name,
    String? gradeLabel,
    required int sortOrder,
  });

  Future<ClassOffering> createClassOffering({
    required String sessionId,
    required String classId,
  });

  Future<ClassSection> createSection({
    required String classOfferingId,
    required String name,
  });

  Future<Subject> createSubject({
    required String code,
    required String name,
  });

  Future<SubjectOffering> createSubjectOffering({
    required String classOfferingId,
    required String subjectId,
    required bool compulsory,
  });
}

class HttpAcademicRepository implements AcademicRepository {
  HttpAcademicRepository({
    required this.api,
    required this.sessionProvider,
    this.endpointPrefix = '/api/v1/browser/academic',
  });

  final AcademicApiTransport api;
  final DeviceSession? Function() sessionProvider;
  final String endpointPrefix;

  DeviceSession get _session {
    final s = sessionProvider();
    if (s == null) throw const ApiFailure(401, 'NOT_AUTHENTICATED');
    return s;
  }

  String _generateIdempotencyKey() {
    final random = Random.secure();
    final values = List<int>.generate(24, (_) => random.nextInt(256));
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
    return List.generate(32, (i) => chars[values[i % values.length] % chars.length]).join();
  }

  Future<List<T>> _getList<T>(
    String kind,
    T Function(Map<String, dynamic>) fromJson, {
    Map<String, String>? queryParams,
  }) async {
    final s = _session;
    final res = await api.send(
      'GET',
      '$endpointPrefix/$kind',
      token: s.token,
      school: s.school,
      queryParams: queryParams,
    );
    final data = res['data'];
    if (data is! Map<String, dynamic>) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    final items = data['items'];
    if (items is! List) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    return items
        .whereType<Map<String, dynamic>>()
        .map((e) => fromJson(e))
        .toList();
  }

  Future<T> _create<T>(
    String kind,
    Map<String, dynamic> body,
    T Function(Map<String, dynamic>) fromJson,
  ) async {
    final s = _session;
    final res = await api.send(
      'POST',
      '$endpointPrefix/$kind',
      body: body,
      token: s.token,
      school: s.school,
      headers: {
        'Idempotency-Key': _generateIdempotencyKey(),
      },
    );
    final data = res['data'];
    if (data is! Map<String, dynamic>) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    final resource = data['resource'];
    if (resource is! Map<String, dynamic>) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    return fromJson(resource);
  }

  @override
  Future<List<AcademicSession>> getSessions({String? cursor}) =>
      _getList('sessions', AcademicSession.fromJson, queryParams: cursor != null ? {'cursor': cursor} : null);

  @override
  Future<List<SchoolClass>> getClasses({String? cursor}) =>
      _getList('classes', SchoolClass.fromJson, queryParams: cursor != null ? {'cursor': cursor} : null);

  @override
  Future<List<ClassOffering>> getClassOfferings({String? sessionId, String? classId, String? cursor}) {
    final q = <String, String>{};
    if (sessionId != null) q['session_id'] = sessionId;
    if (classId != null) q['class_id'] = classId;
    if (cursor != null) q['cursor'] = cursor;
    return _getList('class-offerings', ClassOffering.fromJson, queryParams: q.isNotEmpty ? q : null);
  }

  @override
  Future<List<ClassSection>> getSections({String? classOfferingId, String? cursor}) {
    final q = <String, String>{};
    if (classOfferingId != null) q['class_offering_id'] = classOfferingId;
    if (cursor != null) q['cursor'] = cursor;
    return _getList('sections', ClassSection.fromJson, queryParams: q.isNotEmpty ? q : null);
  }

  @override
  Future<List<Subject>> getSubjects({String? cursor}) =>
      _getList('subjects', Subject.fromJson, queryParams: cursor != null ? {'cursor': cursor} : null);

  @override
  Future<List<SubjectOffering>> getSubjectOfferings({String? classOfferingId, String? subjectId, String? cursor}) {
    final q = <String, String>{};
    if (classOfferingId != null) q['class_offering_id'] = classOfferingId;
    if (subjectId != null) q['subject_id'] = subjectId;
    if (cursor != null) q['cursor'] = cursor;
    return _getList('subject-offerings', SubjectOffering.fromJson, queryParams: q.isNotEmpty ? q : null);
  }

  @override
  Future<AcademicSession> createSession({
    required String name,
    required String startsOn,
    required String endsOn,
  }) =>
      _create('sessions', {
        'name': name,
        'starts_on': startsOn,
        'ends_on': endsOn,
      }, AcademicSession.fromJson);

  @override
  Future<SchoolClass> createClass({
    required String name,
    String? gradeLabel,
    required int sortOrder,
  }) =>
      _create('classes', {
        'name': name,
        if (gradeLabel != null && gradeLabel.isNotEmpty) 'grade_label': gradeLabel,
        'sort_order': sortOrder,
      }, SchoolClass.fromJson);

  @override
  Future<ClassOffering> createClassOffering({
    required String sessionId,
    required String classId,
  }) =>
      _create('class-offerings', {
        'session_id': sessionId,
        'class_id': classId,
      }, ClassOffering.fromJson);

  @override
  Future<ClassSection> createSection({
    required String classOfferingId,
    required String name,
  }) =>
      _create('sections', {
        'class_offering_id': classOfferingId,
        'name': name,
      }, ClassSection.fromJson);

  @override
  Future<Subject> createSubject({
    required String code,
    required String name,
  }) =>
      _create('subjects', {
        'code': code,
        'name': name,
      }, Subject.fromJson);

  @override
  Future<SubjectOffering> createSubjectOffering({
    required String classOfferingId,
    required String subjectId,
    required bool compulsory,
  }) =>
      _create('subject-offerings', {
        'class_offering_id': classOfferingId,
        'subject_id': subjectId,
        'compulsory': compulsory,
      }, SubjectOffering.fromJson);
}
