import 'package:flutter/foundation.dart';
import '../../api_client.dart';
import '../models/academic_session.dart';
import '../models/school_class.dart';
import '../models/class_offering.dart';
import '../models/class_section.dart';
import '../models/subject.dart';
import '../models/subject_offering.dart';
import '../repository/academic_repository.dart';

enum AcademicTab { sessions, classes, classOfferings, sections, subjects, subjectOfferings }

class AcademicController extends ChangeNotifier {
  AcademicController(this.repository);

  final AcademicRepository repository;

  AcademicTab activeTab = AcademicTab.sessions;
  bool isLoading = false;
  bool isCreating = false;
  String? errorMessage;
  String? successMessage;

  List<AcademicSession> sessions = [];
  List<SchoolClass> classes = [];
  List<ClassOffering> classOfferings = [];
  List<ClassSection> sections = [];
  List<Subject> subjects = [];
  List<SubjectOffering> subjectOfferings = [];

  AcademicSession? selectedSession;
  SchoolClass? selectedClass;
  ClassOffering? selectedClassOffering;

  void setActiveTab(AcademicTab tab) {
    activeTab = tab;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    loadActiveTabData();
  }

  void selectSession(AcademicSession? session) {
    selectedSession = session;
    selectedClassOffering = null;
    notifyListeners();
    if (activeTab == AcademicTab.classOfferings || activeTab == AcademicTab.sections || activeTab == AcademicTab.subjectOfferings) {
      loadClassOfferings();
    }
  }

  void selectClass(SchoolClass? cls) {
    selectedClass = cls;
    selectedClassOffering = null;
    notifyListeners();
    if (activeTab == AcademicTab.classOfferings) {
      loadClassOfferings();
    }
  }

  void selectClassOffering(ClassOffering? offering) {
    selectedClassOffering = offering;
    notifyListeners();
    if (activeTab == AcademicTab.sections) {
      loadSections();
    } else if (activeTab == AcademicTab.subjectOfferings) {
      loadSubjectOfferings();
    }
  }

  Future<void> loadActiveTabData() async {
    switch (activeTab) {
      case AcademicTab.sessions:
        await loadSessions();
        break;
      case AcademicTab.classes:
        await loadClasses();
        break;
      case AcademicTab.classOfferings:
        await loadClassOfferings();
        break;
      case AcademicTab.sections:
        await loadSections();
        break;
      case AcademicTab.subjects:
        await loadSubjects();
        break;
      case AcademicTab.subjectOfferings:
        await loadSubjectOfferings();
        break;
    }
  }

  Future<void> loadAll() async {
    isLoading = true;
    errorMessage = null;
    notifyListeners();
    try {
      await Future.wait([
        loadSessions(silent: true),
        loadClasses(silent: true),
        loadSubjects(silent: true),
      ]);
      await loadClassOfferings(silent: true);
    } catch (e) {
      _handleError(e);
    } finally {
      isLoading = false;
      notifyListeners();
    }
  }

  Future<void> loadSessions({bool silent = false}) async {
    if (!silent) {
      isLoading = true;
      errorMessage = null;
      notifyListeners();
    }
    try {
      sessions = await repository.getSessions();
      if (selectedSession == null && sessions.isNotEmpty) {
        selectedSession = sessions.firstWhere(
          (s) => s.isFuture || s.isActive,
          orElse: () => sessions.first,
        );
      }
    } catch (e) {
      _handleError(e);
    } finally {
      if (!silent) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  Future<void> loadClasses({bool silent = false}) async {
    if (!silent) {
      isLoading = true;
      errorMessage = null;
      notifyListeners();
    }
    try {
      classes = await repository.getClasses();
    } catch (e) {
      _handleError(e);
    } finally {
      if (!silent) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  Future<void> loadClassOfferings({bool silent = false}) async {
    if (!silent) {
      isLoading = true;
      errorMessage = null;
      notifyListeners();
    }
    try {
      classOfferings = await repository.getClassOfferings(
        sessionId: selectedSession?.id,
        classId: selectedClass?.id,
      );
      if (selectedClassOffering == null && classOfferings.isNotEmpty) {
        selectedClassOffering = classOfferings.first;
      }
    } catch (e) {
      _handleError(e);
    } finally {
      if (!silent) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  Future<void> loadSections({bool silent = false}) async {
    if (!silent) {
      isLoading = true;
      errorMessage = null;
      notifyListeners();
    }
    try {
      sections = await repository.getSections(
        classOfferingId: selectedClassOffering?.id,
      );
    } catch (e) {
      _handleError(e);
    } finally {
      if (!silent) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  Future<void> loadSubjects({bool silent = false}) async {
    if (!silent) {
      isLoading = true;
      errorMessage = null;
      notifyListeners();
    }
    try {
      subjects = await repository.getSubjects();
    } catch (e) {
      _handleError(e);
    } finally {
      if (!silent) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  Future<void> loadSubjectOfferings({bool silent = false}) async {
    if (!silent) {
      isLoading = true;
      errorMessage = null;
      notifyListeners();
    }
    try {
      subjectOfferings = await repository.getSubjectOfferings(
        classOfferingId: selectedClassOffering?.id,
      );
    } catch (e) {
      _handleError(e);
    } finally {
      if (!silent) {
        isLoading = false;
        notifyListeners();
      }
    }
  }

  // --- Creation methods ---

  Future<bool> createSession({
    required String name,
    required String startsOn,
    required String endsOn,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    try {
      final created = await repository.createSession(
        name: name,
        startsOn: startsOn,
        endsOn: endsOn,
      );
      sessions.insert(0, created);
      selectedSession = created;
      successMessage = 'Academic Session "$name" created successfully.';
      return true;
    } catch (e) {
      _handleError(e);
      return false;
    } finally {
      isCreating = false;
      notifyListeners();
    }
  }

  Future<bool> createClass({
    required String name,
    String? gradeLabel,
    required int sortOrder,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    try {
      final created = await repository.createClass(
        name: name,
        gradeLabel: gradeLabel,
        sortOrder: sortOrder,
      );
      classes.add(created);
      classes.sort((a, b) => a.sortOrder.compareTo(b.sortOrder));
      successMessage = 'Class "$name" created successfully.';
      return true;
    } catch (e) {
      _handleError(e);
      return false;
    } finally {
      isCreating = false;
      notifyListeners();
    }
  }

  Future<bool> createClassOffering({
    required String sessionId,
    required String classId,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    try {
      final created = await repository.createClassOffering(
        sessionId: sessionId,
        classId: classId,
      );
      classOfferings.insert(0, created);
      selectedClassOffering = created;
      successMessage = 'Class offering linked successfully.';
      return true;
    } catch (e) {
      _handleError(e);
      return false;
    } finally {
      isCreating = false;
      notifyListeners();
    }
  }

  Future<bool> createSection({
    required String classOfferingId,
    required String name,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    try {
      final created = await repository.createSection(
        classOfferingId: classOfferingId,
        name: name,
      );
      sections.add(created);
      successMessage = 'Section "$name" created successfully.';
      return true;
    } catch (e) {
      _handleError(e);
      return false;
    } finally {
      isCreating = false;
      notifyListeners();
    }
  }

  Future<bool> createSubject({
    required String code,
    required String name,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    try {
      final created = await repository.createSubject(
        code: code,
        name: name,
      );
      subjects.add(created);
      successMessage = 'Subject "$name" ($code) created successfully.';
      return true;
    } catch (e) {
      _handleError(e);
      return false;
    } finally {
      isCreating = false;
      notifyListeners();
    }
  }

  Future<bool> createSubjectOffering({
    required String classOfferingId,
    required String subjectId,
    required bool compulsory,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    notifyListeners();
    try {
      final created = await repository.createSubjectOffering(
        classOfferingId: classOfferingId,
        subjectId: subjectId,
        compulsory: compulsory,
      );
      subjectOfferings.add(created);
      successMessage = 'Subject offering added successfully.';
      return true;
    } catch (e) {
      _handleError(e);
      return false;
    } finally {
      isCreating = false;
      notifyListeners();
    }
  }

  void _handleError(Object error) {
    if (error is ApiFailure) {
      if (error.status == 403) {
        errorMessage = 'Privileged operation is restricted. Browser MFA required for privileged mutations.';
      } else if (error.status == 409) {
        errorMessage = 'Conflict: a record with these details already exists or session conflict.';
      } else if (error.status == 422) {
        errorMessage = 'Validation error: please verify dates, names and required relationships.';
      } else {
        errorMessage = error.toString();
      }
    } else {
      errorMessage = error.toString();
    }
  }

  void clearMessages() {
    errorMessage = null;
    successMessage = null;
    notifyListeners();
  }
}
