class AcademicSession {
  const AcademicSession({
    required this.id,
    required this.name,
    required this.startsOn,
    required this.endsOn,
    required this.state,
    required this.version,
  });

  final String id;
  final String name;
  final String startsOn;
  final String endsOn;
  final String state;
  final int version;

  bool get isFuture => state == 'future';
  bool get isActive => state == 'active';

  factory AcademicSession.fromJson(Map<String, dynamic> json) {
    return AcademicSession(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      startsOn: json['startsOn'] as String? ?? json['starts_on'] as String? ?? '',
      endsOn: json['endsOn'] as String? ?? json['ends_on'] as String? ?? '',
      state: json['state'] as String? ?? 'future',
      version: json['version'] as int? ?? 1,
    );
  }

  Map<String, dynamic> toJson() => {
    'id': id,
    'name': name,
    'starts_on': startsOn,
    'ends_on': endsOn,
    'state': state,
    'version': version,
  };
}
