class SubjectOffering {
  const SubjectOffering({
    required this.id,
    required this.classOfferingId,
    required this.subjectId,
    required this.compulsory,
    required this.version,
  });

  final String id;
  final String classOfferingId;
  final String subjectId;
  final bool compulsory;
  final int version;

  factory SubjectOffering.fromJson(Map<String, dynamic> json) {
    return SubjectOffering(
      id: json['id'] as String? ?? '',
      classOfferingId: json['classOfferingId'] as String? ?? json['class_offering_id'] as String? ?? '',
      subjectId: json['subjectId'] as String? ?? json['subject_id'] as String? ?? '',
      compulsory: (json['compulsory'] as bool?) ?? false,
      version: json['version'] as int? ?? 1,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'class_offering_id': classOfferingId,
    'subject_id': subjectId,
    'compulsory': compulsory,
    'version': version,
  };
}
