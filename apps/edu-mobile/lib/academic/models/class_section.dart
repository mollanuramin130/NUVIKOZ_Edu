class ClassSection {
  const ClassSection({
    required this.id,
    required this.classOfferingId,
    required this.name,
    this.classTeacherMembershipId,
    required this.version,
  });

  final String id;
  final String classOfferingId;
  final String name;
  final String? classTeacherMembershipId;
  final int version;

  factory ClassSection.fromJson(Map<String, dynamic> json) {
    return ClassSection(
      id: json['id'] as String? ?? '',
      classOfferingId: json['classOfferingId'] as String? ?? json['class_offering_id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      classTeacherMembershipId: json['classTeacherMembershipId'] as String? ?? json['class_teacher_membership_id'] as String?,
      version: json['version'] as int? ?? 1,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'class_offering_id': classOfferingId,
    'name': name,
    if (classTeacherMembershipId != null) 'class_teacher_membership_id': classTeacherMembershipId,
    'version': version,
  };
}
