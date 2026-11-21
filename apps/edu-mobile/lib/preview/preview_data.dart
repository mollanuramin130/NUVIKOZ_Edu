import '../academic/models/academic_session.dart';
import '../academic/models/school_class.dart';
import '../academic/models/class_offering.dart';
import '../academic/models/class_section.dart';
import '../academic/models/subject.dart';
import '../academic/models/subject_offering.dart';
import 'preview_guard.dart';

/// Pre-populated, realistic synthetic catalog data for offline interactive previews.
class PreviewData {
  const PreviewData._();

  static List<AcademicSession> initialSessions() {
    PreviewGuard.assertNotRelease();
    return [
      const AcademicSession(
        id: '01JPREVIEWSESS000000000001',
        name: '2026–2027 Academic Year',
        startsOn: '2026-04-01',
        endsOn: '2027-03-31',
        state: 'active',
        version: 1,
      ),
      const AcademicSession(
        id: '01JPREVIEWSESS000000000002',
        name: '2027–2028 Academic Year',
        startsOn: '2027-04-01',
        endsOn: '2028-03-31',
        state: 'future',
        version: 1,
      ),
      const AcademicSession(
        id: '01JPREVIEWSESS000000000003',
        name: '2025–2026 Academic Year',
        startsOn: '2025-04-01',
        endsOn: '2026-03-31',
        state: 'completed',
        version: 1,
      ),
    ];
  }

  static List<SchoolClass> initialClasses() {
    PreviewGuard.assertNotRelease();
    return [
      const SchoolClass(
        id: '01JPREVIEWCLS000000000001',
        name: 'Class 9',
        gradeLabel: 'Grade 9',
        sortOrder: 9,
        version: 1,
      ),
      const SchoolClass(
        id: '01JPREVIEWCLS000000000002',
        name: 'Class 10',
        gradeLabel: 'Grade 10',
        sortOrder: 10,
        version: 1,
      ),
      const SchoolClass(
        id: '01JPREVIEWCLS000000000003',
        name: 'Class 11',
        gradeLabel: 'Grade 11',
        sortOrder: 11,
        version: 1,
      ),
      const SchoolClass(
        id: '01JPREVIEWCLS000000000004',
        name: 'Class 12',
        gradeLabel: 'Grade 12',
        sortOrder: 12,
        version: 1,
      ),
    ];
  }

  static List<ClassOffering> initialOfferings() {
    PreviewGuard.assertNotRelease();
    return [
      const ClassOffering(
        id: '01JPREVIEWOFF000000000001',
        sessionId: '01JPREVIEWSESS000000000002', // 2027–2028
        classId: '01JPREVIEWCLS000000000001',   // Class 9
        version: 1,
      ),
      const ClassOffering(
        id: '01JPREVIEWOFF000000000002',
        sessionId: '01JPREVIEWSESS000000000002', // 2027–2028
        classId: '01JPREVIEWCLS000000000002',   // Class 10
        version: 1,
      ),
      const ClassOffering(
        id: '01JPREVIEWOFF000000000003',
        sessionId: '01JPREVIEWSESS000000000001', // 2026–2027
        classId: '01JPREVIEWCLS000000000001',   // Class 9
        version: 1,
      ),
    ];
  }

  static List<ClassSection> initialSections() {
    PreviewGuard.assertNotRelease();
    return [
      const ClassSection(
        id: '01JPREVIEWSEC000000000001',
        classOfferingId: '01JPREVIEWOFF000000000001', // Class 9 in 2027–2028
        name: 'Section A (Diamond)',
        version: 1,
      ),
      const ClassSection(
        id: '01JPREVIEWSEC000000000002',
        classOfferingId: '01JPREVIEWOFF000000000001', // Class 9 in 2027–2028
        name: 'Section B (Platinum)',
        version: 1,
      ),
      const ClassSection(
        id: '01JPREVIEWSEC000000000003',
        classOfferingId: '01JPREVIEWOFF000000000002', // Class 10 in 2027–2028
        name: 'Section Rose',
        version: 1,
      ),
    ];
  }

  static List<Subject> initialSubjects() {
    PreviewGuard.assertNotRelease();
    return [
      const Subject(
        id: '01JPREVIEWSUB000000000001',
        code: 'MATH',
        name: 'Mathematics & Statistics',
        version: 1,
      ),
      const Subject(
        id: '01JPREVIEWSUB000000000002',
        code: 'ENG',
        name: 'English Language & Lit',
        version: 1,
      ),
      const Subject(
        id: '01JPREVIEWSUB000000000003',
        code: 'PHY',
        name: 'Physics & Applied Sciences',
        version: 1,
      ),
      const Subject(
        id: '01JPREVIEWSUB000000000004',
        code: 'CHEM',
        name: 'Chemistry Laboratory',
        version: 1,
      ),
      const Subject(
        id: '01JPREVIEWSUB000000000005',
        code: 'CS',
        name: 'Computer Science & AI',
        version: 1,
      ),
    ];
  }

  static List<SubjectOffering> initialSubjectOfferings() {
    PreviewGuard.assertNotRelease();
    return [
      const SubjectOffering(
        id: '01JPREVIEWSO000000000001',
        classOfferingId: '01JPREVIEWOFF000000000001', // Class 9 in 2027–2028
        subjectId: '01JPREVIEWSUB000000000001',       // MATH
        compulsory: true,
        version: 1,
      ),
      const SubjectOffering(
        id: '01JPREVIEWSO000000000002',
        classOfferingId: '01JPREVIEWOFF000000000001', // Class 9 in 2027–2028
        subjectId: '01JPREVIEWSUB000000000002',       // ENG
        compulsory: true,
        version: 1,
      ),
      const SubjectOffering(
        id: '01JPREVIEWSO000000000003',
        classOfferingId: '01JPREVIEWOFF000000000001', // Class 9 in 2027–2028
        subjectId: '01JPREVIEWSUB000000000005',       // CS
        compulsory: false,
        version: 1,
      ),
      const SubjectOffering(
        id: '01JPREVIEWSO000000000004',
        classOfferingId: '01JPREVIEWOFF000000000002', // Class 10 in 2027–2028
        subjectId: '01JPREVIEWSUB000000000003',       // PHY
        compulsory: true,
        version: 1,
      ),
    ];
  }
}
