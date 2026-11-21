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

  bool _disposed = false;

  AcademicTab activeTab = AcademicTab.sessions;
  bool isLoading = false;
  bool isCreating = false;
  String? errorMessage;
  String? successMessage;
  String? lastErrorCode;

  bool get isMfaRequired => lastErrorCode == 'MFA_REQUIRED' || lastErrorCode == 'MFA_CHALLENGE_REQUIRED';

  List<AcademicSession> sessions = [];
  List<SchoolClass> classes = [];
  List<ClassOffering> classOfferings = [];
  List<ClassSection> sections = [];
  List<Subject> subjects = [];
  List<SubjectOffering> subjectOfferings = [];

  AcademicSession? selectedSession;
  SchoolClass? selectedClass;
  ClassOffering? selectedClassOffering;

  @override
  void notifyListeners() {
    if (!_disposed) {
      super.notifyListeners();
    }
  }

  @override
  void dispose() {
    _disposed = true;
    super.dispose();
  }

  void reset() {
    activeTab = AcademicTab.sessions;
    isLoading = false;
    isCreating = false;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    sessions = [];
    classes = [];
    classOfferings = [];
    sections = [];
    subjects = [];
    subjectOfferings = [];
    selectedSession = null;
    selectedClass = null;
    selectedClassOffering = null;
    notifyListeners();
  }

  void setActiveTab(AcademicTab tab) {
    activeTab = tab;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
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
    String? idempotencyKey,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
    try {
      final created = await repository.createSession(
        name: name,
        startsOn: startsOn,
        endsOn: endsOn,
        idempotencyKey: idempotencyKey,
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
    String? idempotencyKey,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
    try {
      final created = await repository.createClass(
        name: name,
        gradeLabel: gradeLabel,
        sortOrder: sortOrder,
        idempotencyKey: idempotencyKey,
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
    String? idempotencyKey,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
    try {
      final created = await repository.createClassOffering(
        sessionId: sessionId,
        classId: classId,
        idempotencyKey: idempotencyKey,
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
    String? idempotencyKey,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
    try {
      final created = await repository.createSection(
        classOfferingId: classOfferingId,
        name: name,
        idempotencyKey: idempotencyKey,
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
    String? idempotencyKey,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
    try {
      final created = await repository.createSubject(
        code: code,
        name: name,
        idempotencyKey: idempotencyKey,
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
    String? idempotencyKey,
  }) async {
    isCreating = true;
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
    try {
      final created = await repository.createSubjectOffering(
        classOfferingId: classOfferingId,
        subjectId: subjectId,
        compulsory: compulsory,
        idempotencyKey: idempotencyKey,
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
      lastErrorCode = error.code;
      if (error.status == 401) {
        if (error.code == 'TOKEN_EXPIRED' || error.code == 'SESSION_CHANGED') {
          errorMessage = 'Session has expired or changed. Please sign in again.';
        } else {
          errorMessage = 'Authentication expired or invalid. Please sign in again.';
        }
      } else if (error.status == 403) {
        if (isMfaRequired) {
          errorMessage = 'Privileged MFA verification required to modify Academic resources.';
        } else if (error.code == 'ROLE_UNAUTHORIZED' || error.code == 'FORBIDDEN' || error.code == 'UNAUTHORIZED') {
          errorMessage = 'Academic management requires School Admin role.';
        } else if (error.code == 'ACCESS_SUSPENDED' || error.code == 'SCHOOL_SUSPENDED' || error.code == 'TENANT_SUSPENDED') {
          errorMessage = 'Institution access is currently suspended. Please contact platform administration.';
        } else if (error.code == 'ENTITLEMENT_REQUIRED' || error.code == 'ENTITLEMENT_EXPIRED') {
          errorMessage = 'School write entitlement has expired. Academic catalog is in read-only mode.';
        } else if (error.code == 'DEMO_LIMIT_EXCEEDED') {
          errorMessage = 'Evaluation limit reached for this demo institution.';
        } else if (error.code == 'DEMO_EXPIRED') {
          errorMessage = 'Evaluation period has expired for this demo institution.';
        } else if (error.code == 'CROSS_TENANT_DENIED' || error.code == 'TENANT_MISMATCH' || error.code == 'TOKEN_SCHOOL_MISMATCH' || error.code == 'TENANT_CONTEXT_REQUIRED') {
          errorMessage = 'Cross-tenant access violation: selected resource belongs to another institution.';
        } else {
          errorMessage = 'Academic operation not permitted. Please verify your permissions.';
        }
      } else if (error.status == 404) {
        errorMessage = 'The requested academic record or parent relationship was not found.';
      } else if (error.status == 409) {
        errorMessage = 'Conflict: a record with these details already exists or session conflict.';
      } else if (error.status == 422) {
        errorMessage = 'Validation error: please verify dates, names and required relationships.';
      } else if (error.status == 429) {
        errorMessage = 'Too many requests. Please wait a moment before trying again.';
      } else {
        errorMessage = error.toString();
      }
    } else {
      lastErrorCode = null;
      errorMessage = error.toString();
    }
  }

  void clearMessages() {
    errorMessage = null;
    successMessage = null;
    lastErrorCode = null;
    notifyListeners();
  }
}
