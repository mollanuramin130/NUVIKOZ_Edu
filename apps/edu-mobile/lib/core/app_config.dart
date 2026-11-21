class AppConfig {
  AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'EDU_API_BASE_URL',
    defaultValue: 'https://edu-api-staging.nuvikoz.com',
  );

  static const String websiteUrl = String.fromEnvironment(
    'EDU_WEB_BASE_URL',
    defaultValue: 'https://edu-web-staging.nuvikoz.com',
  );

  static const String appVersion = '1.0.0';
  static const String appName = 'NUVIKOZ Edu';
}
