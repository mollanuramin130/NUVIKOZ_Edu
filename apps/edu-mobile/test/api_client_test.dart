import 'package:edu_mobile/api_client.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('rejects insecure remote origins, URL credentials and non-HTTP schemes', () {
    for (final url in [
      'http://school.example',
      'https://name:secret@school.example',
      'ftp://localhost',
    ]) {
      expect(() => EduApiClient(Uri.parse(url)), throwsArgumentError);
    }
  });

  test('accepts HTTPS and explicit local HTTP development origins', () {
    for (final url in ['https://school.example', 'http://127.0.0.1:8000']) {
      final client = EduApiClient(Uri.parse(url));
      client.close();
    }
  });
}
