class SchoolClass {
  const SchoolClass({
    required this.id,
    required this.name,
    this.gradeLabel,
    required this.sortOrder,
    required this.version,
  });

  final String id;
  final String name;
  final String? gradeLabel;
  final int sortOrder;
  final int version;

  factory SchoolClass.fromJson(Map<String, dynamic> json) {
    return SchoolClass(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      gradeLabel: json['gradeLabel'] as String? ?? json['grade_label'] as String?,
      sortOrder: (json['sortOrder'] ?? json['sort_order'] ?? 0) as int,
      version: json['version'] as int? ?? 1,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    if (gradeLabel != null) 'grade_label': gradeLabel,
    'sort_order': sortOrder,
    'version': version,
  };
}
