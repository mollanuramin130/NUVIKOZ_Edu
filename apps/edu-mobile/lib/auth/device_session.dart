import '../api_client.dart';

class DeviceSession {
  const DeviceSession({required this.token, required this.school, required this.membership,
    required this.user, required this.role, required this.expires});
  final String token, school, membership, user, role;
  final DateTime expires;
  bool get privileged => role == 'school_admin';
  bool expired(DateTime now) => !expires.isAfter(now);
  factory DeviceSession.parse(Map<String, dynamic> value) {
    String text(String key) {
      final result = value[key];
      if (result is! String || result.isEmpty || result.length > 512) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
      return result;
    }
    final school = text('school_id'); final membership = text('membership_id'); final user = text('user_id');
    for (final id in [school,membership,user]) {
      if (!RegExp(r'^[0-9A-HJKMNP-TV-Z]{26}$').hasMatch(id)) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    }
    final role = text('role');
    if (!['school_admin','principal','teacher','student','parent'].contains(role)) throw const ApiFailure(403, 'PRIVILEGED_UNAVAILABLE');
    final abilities = value['abilities'];
    if (abilities is! List || abilities.length != 1 || abilities[0] != 'school:$school') throw const ApiFailure(403, 'TOKEN_SCHOOL_MISMATCH');
    final expires = DateTime.tryParse(text('expires_at'));
    if (expires == null) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    final token=text('token');
    if(!RegExp(r'^[0-9]+[|][A-Za-z0-9_-]{40,128}$').hasMatch(token))throw const ApiFailure(0,'MALFORMED_RESPONSE');
    return DeviceSession(token:token,school:school,membership:membership,user:user,role:role,expires:expires.toUtc());
  }
  Map<String,dynamic> toJson() => {'token':token,'school_id':school,'membership_id':membership,
    'user_id':user,'role':role,'expires_at':expires.toIso8601String(),'abilities':['school:$school']};
  @override
  String toString() => 'DeviceSession(redacted)';
}
