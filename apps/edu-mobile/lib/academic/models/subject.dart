class Subject {
  const Subject({
    required this.id,
    required this.code,
    required this.name,
    required this.version,
  });

  final String id;
  final String code;
  final String name;
  final int version;

  factory Subject.fromJson(Map<String, dynamic> json) {
    return Subject(
      id: json['id'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      version: json['version'] as int? ?? 1,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'code': code,
    'name': name,
    'version': version,
  };
}
