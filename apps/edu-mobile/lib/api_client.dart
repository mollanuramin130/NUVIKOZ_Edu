import 'dart:convert';
import 'dart:io';
import 'dart:async';

class ApiFailure implements Exception {
  const ApiFailure(this.status, this.code, {this.details});
  final int status;
  final String code;
  final dynamic details;

  @override
  String toString() => switch (status) {
    400 => 'Bad request. Please check submitted values.',
    401 => 'Sign in again. Credentials or session could not be verified.',
    403 => 'School context or operation is not authorized.',
    404 => 'The requested resource was not found.',
    409 => code == 'CONFLICT' || code == 'DUPLICATE'
        ? 'A record with these details already exists or conflict detected.'
        : 'Conflict detected. Please review and retry.',
    422 => 'Validation error. Please verify all required fields.',
    429 => 'Too many attempts. Please wait before trying again.',
    _ => 'The service is unavailable. Please retry.',
  };
}

abstract class ApiTransport {
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
  });
  void close();
}

abstract class AcademicApiTransport {
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
    Map<String, String>? queryParams,
    Map<String, String>? headers,
  });
}

class EduApiClient implements ApiTransport, AcademicApiTransport {
  EduApiClient(this.baseUri, {HttpClient? client, bool allowLocalHttp = false})
      : _client = client ?? HttpClient() {
    final local = ['localhost', '127.0.0.1', '10.0.2.2'].contains(baseUri.host);
    if (baseUri.host.isEmpty ||
        !['https', 'http'].contains(baseUri.scheme) ||
        baseUri.userInfo.isNotEmpty ||
        baseUri.hasQuery ||
        baseUri.hasFragment ||
        !['', '/'].contains(baseUri.path) ||
        (baseUri.scheme != 'https' && !(local && allowLocalHttp))) {
      _client.close();
      throw ArgumentError('Use an HTTPS API origin; local HTTP requires explicit test authorization.');
    }
    _client.connectionTimeout = const Duration(seconds: 10);
  }

  final Uri baseUri;
  final HttpClient _client;
  bool _closed = false;

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
    if (_closed) throw const ApiFailure(0, 'DISPOSED');
    if (!RegExp(r'^/api/v1/[A-Za-z0-9/_-]+$').hasMatch(path)) {
      throw ArgumentError('Use an API path without queries or credentials.');
    }
    try {
      var targetUri = baseUri.resolve(path);
      if (queryParams != null && queryParams.isNotEmpty) {
        targetUri = targetUri.replace(queryParameters: queryParams);
      }

      final request = await _client.openUrl(method, targetUri).timeout(const Duration(seconds: 10));
      request.followRedirects = false;
      request.headers.set(HttpHeaders.acceptHeader, 'application/json');

      if (token != null) request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $token');
      if (school != null) request.headers.set('X-School-ID', school);

      if (headers != null) {
        headers.forEach((k, v) {
          request.headers.set(k, v);
        });
      }

      if (body != null) {
        request.headers.contentType = ContentType.json;
        request.write(jsonEncode(body));
      }

      final response = await request.close().timeout(const Duration(seconds: 10));
      final bytes = <int>[];
      await for (final chunk in response.timeout(const Duration(seconds: 10))) {
        bytes.addAll(chunk);
        if (bytes.length > 1048576) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
      }

      final decoded = jsonDecode(utf8.decode(bytes));
      if (decoded is! Map<String, dynamic>) throw const ApiFailure(0, 'MALFORMED_RESPONSE');

      if (response.statusCode < 200 || response.statusCode >= 300) {
        final error = decoded['error'];
        final code = error is Map && error['code'] is String ? error['code'] as String : 'API_ERROR';
        final details = error is Map ? error['details'] : null;
        throw ApiFailure(response.statusCode, code, details: details);
      }

      if (decoded['data'] is! Map<String, dynamic>) throw const ApiFailure(0, 'MALFORMED_RESPONSE');
      return decoded;
    } on ApiFailure {
      rethrow;
    } on TimeoutException {
      throw const ApiFailure(0, 'NETWORK_TIMEOUT');
    } on IOException {
      throw const ApiFailure(0, 'NETWORK_UNAVAILABLE');
    } on FormatException {
      throw const ApiFailure(0, 'MALFORMED_RESPONSE');
    }
  }

  Future<Map<String, dynamic>> health() => send('GET', '/api/v1/health');

  @override
  void close() {
    _closed = true;
    _client.close(force: true);
  }
}
