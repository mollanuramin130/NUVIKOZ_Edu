import 'dart:convert';
import 'dart:io';
class EduApiClient {
  EduApiClient(this.baseUri, {HttpClient? client}) : _client = client ?? HttpClient() {
    if (baseUri.scheme != 'https' && !['localhost', '127.0.0.1', '10.0.2.2'].contains(baseUri.host)) throw ArgumentError('Use HTTPS outside local development.');
    _client.connectionTimeout = const Duration(seconds: 10);
  }
  final Uri baseUri;
  final HttpClient _client;
  Future<Map<String, dynamic>> health() async {
    final request = await _client.getUrl(baseUri.resolve('/api/v1/health'));
    request.headers.set(HttpHeaders.acceptHeader, 'application/json');
    final response = await request.close();
    if (response.statusCode != 200) throw HttpException('API unavailable', uri: baseUri);
    return jsonDecode(await response.transform(utf8.decoder).join()) as Map<String, dynamic>;
  }
  void close() => _client.close();
}
