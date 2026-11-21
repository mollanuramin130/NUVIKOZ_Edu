import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/academic/controllers/academic_controller.dart';
import 'package:edu_mobile/academic/models/academic_session.dart';
import 'package:edu_mobile/academic/models/school_class.dart';
import 'package:edu_mobile/academic/models/class_offering.dart';
import 'package:edu_mobile/academic/models/class_section.dart';
import 'package:edu_mobile/academic/models/subject.dart';
import 'package:edu_mobile/academic/models/subject_offering.dart';
import 'package:edu_mobile/academic/repository/academic_repository.dart';

class FakeAcademicRepository implements AcademicRepository {
  List<AcademicSession> sessions = [];
  List<SchoolClass> classes = [];
  List<ClassOffering> offerings = [];
  List<ClassSection> sections = [];
  List<Subject> subjects = [];
  List<SubjectOffering> subjectOfferings = [];

  @override
  Future<List<AcademicSession>> getSessions({String? cursor}) async => sessions;

  @override
  Future<List<SchoolClass>> getClasses({String? cursor}) async => classes;

  @override
  Future<List<ClassOffering>> getClassOfferings({String? sessionId, String? classId, String? cursor}) async => offerings;

  @override
  Future<List<ClassSection>> getSections({String? classOfferingId, String? cursor}) async => sections;

  @override
  Future<List<Subject>> getSubjects({String? cursor}) async => subjects;

  @override
  Future<List<SubjectOffering>> getSubjectOfferings({String? classOfferingId, String? subjectId, String? cursor}) async => subjectOfferings;

  @override
  Future<AcademicSession> createSession({required String name, required String startsOn, required String endsOn}) async {
    final s = AcademicSession(id: 'SESS_1', name: name, startsOn: startsOn, endsOn: endsOn, state: 'future', version: 1);
    sessions.add(s);
    return s;
  }

  @override
  Future<SchoolClass> createClass({required String name, String? gradeLabel, required int sortOrder}) async {
    final c = SchoolClass(id: 'CLS_1', name: name, gradeLabel: gradeLabel, sortOrder: sortOrder, version: 1);
    classes.add(c);
    return c;
  }

  @override
  Future<ClassOffering> createClassOffering({required String sessionId, required String classId}) async {
    final o = ClassOffering(id: 'OFF_1', sessionId: sessionId, classId: classId, version: 1);
    offerings.add(o);
    return o;
  }

  @override
  Future<ClassSection> createSection({required String classOfferingId, required String name}) async {
    final sec = ClassSection(id: 'SEC_1', classOfferingId: classOfferingId, name: name, version: 1);
    sections.add(sec);
    return sec;
  }

  @override
  Future<Subject> createSubject({required String code, required String name}) async {
    final sub = Subject(id: 'SUB_1', code: code, name: name, version: 1);
    subjects.add(sub);
    return sub;
  }

  @override
  Future<SubjectOffering> createSubjectOffering({required String classOfferingId, required String subjectId, required bool compulsory}) async {
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

    test('createSession updates sessions list and sets successMessage', () async {
      final success = await controller.createSession(
        name: '2027–2028',
        startsOn: '2027-04-01',
        endsOn: '2028-03-31',
      );

      expect(success, isTrue);
      expect(controller.sessions.length, 1);
      expect(controller.sessions.first.name, '2027–2028');
      expect(controller.selectedSession?.name, '2027–2028');
      expect(controller.successMessage, contains('created successfully'));
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
  });
}
