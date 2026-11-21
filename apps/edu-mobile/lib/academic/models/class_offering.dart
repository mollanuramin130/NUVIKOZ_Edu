class ClassOffering {
  const ClassOffering({
    required this.id,
    required this.sessionId,
    required this.classId,
    required this.version,
  });

  final String id;
  final String sessionId;
  final String classId;
  final int version;

  factory ClassOffering.fromJson(Map<String, dynamic> json) {
    return ClassOffering(
      id: json['id'] as String? ?? '',
      sessionId: json['sessionId'] as String? ?? json['session_id'] as String? ?? '',
      classId: json['classId'] as String? ?? json['class_id'] as String? ?? '',
      version: json['version'] as int? ?? 1,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'session_id': sessionId,
    'class_id': classId,
    'version': version,
  };
}
