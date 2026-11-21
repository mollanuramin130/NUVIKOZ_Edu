import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/academic/repository/academic_repository.dart';
import 'package:edu_mobile/api_client.dart';
import 'package:edu_mobile/auth/device_session.dart';

class MockApiTransport implements AcademicApiTransport {
  String? lastMethod;
  String? lastPath;
  Map<String, dynamic>? lastBody;
  String? lastToken;
  String? lastSchool;
  Map<String, String>? lastQueryParams;
  Map<String, String>? lastHeaders;
  Map<String, dynamic> responseData = {'data': {}};

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
    Map<String, String>? queryParams,
    Map<String, String>? headers,
  }) async {
    lastMethod = method;
    lastPath = path;
    lastBody = body;
    lastToken = token;
    lastSchool = school;
    lastQueryParams = queryParams;
    lastHeaders = headers;
    return responseData;
  }

  void close() {}
}

void main() {
  group('HttpAcademicRepository', () {
    late MockApiTransport mockApi;
    late HttpAcademicRepository repo;

    final session = DeviceSession(
      token: '1|syntheticToken1234567890abcdef1234567890abcdef',
      school: '01HXYZ00000000000000000001',
      membership: '01HXYZ00000000000000000002',
      user: '01HXYZ00000000000000000003',
      role: 'school_admin',
      expires: DateTime.now().add(const Duration(hours: 1)),
    );

    setUp(() {
      mockApi = MockApiTransport();
      repo = HttpAcademicRepository(
        api: mockApi,
        sessionProvider: () => session,
      );
    });

    test('getSessions requests /api/v1/academic/sessions with bearer token', () async {
      mockApi.responseData = {
        'data': {
          'items': [
            {
              'id': '01HXYZ00000000000000000010',
              'name': '2027–2028',
              'starts_on': '2027-04-01',
              'ends_on': '2028-03-31',
              'state': 'future',
              'version': 1,
            }
          ]
        }
      };

      final items = await repo.getSessions();
      expect(items.length, 1);
      expect(items.first.name, '2027–2028');
      expect(mockApi.lastMethod, 'GET');
      expect(mockApi.lastPath, '/api/v1/academic/sessions');
      expect(mockApi.lastToken, session.token);
      expect(mockApi.lastSchool, session.school);
    });

    test('createSession sends POST with Idempotency-Key header', () async {
      mockApi.responseData = {
        'data': {
          'resource': {
            'id': '01HXYZ00000000000000000011',
            'name': '2028–2029',
            'starts_on': '2028-04-01',
            'ends_on': '2029-03-31',
            'state': 'future',
            'version': 1,
          }
        }
      };

      final created = await repo.createSession(
        name: '2028–2029',
        startsOn: '2028-04-01',
        endsOn: '2029-03-31',
      );

      expect(created.name, '2028–2029');
      expect(mockApi.lastMethod, 'POST');
      expect(mockApi.lastPath, '/api/v1/academic/sessions');
      expect(mockApi.lastHeaders?['Idempotency-Key'], isNotNull);
      expect(mockApi.lastHeaders!['Idempotency-Key']!.length, 32);
      expect(mockApi.lastBody?['name'], '2028–2029');
    });

    test('getClasses fetches classes list', () async {
      mockApi.responseData = {
        'data': {
          'items': [
            {
              'id': '01HXYZ00000000000000000020',
              'name': 'Class 10',
              'grade_label': '10',
              'sort_order': 10,
              'version': 1,
            }
          ]
        }
      };

      final classes = await repo.getClasses();
      expect(classes.length, 1);
      expect(classes.first.name, 'Class 10');
      expect(classes.first.sortOrder, 10);
    });
  });
}
