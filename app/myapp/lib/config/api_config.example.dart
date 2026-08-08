/// API configuration template.
///
/// Copy this file to `api_config.dart` and adjust [baseUrl] for local testing.
/// In CI, this file is used as fallback when the `API_BASE_URL` GitHub Secret
/// is not set. When the secret is set, CI generates `api_config.dart`
/// automatically — the secret value should be the server origin only
/// (e.g. `https://example.com`), without a trailing slash or `/app_api.php`.
class ApiConfig {
  /// Local development: run `php -S 0.0.0.0:8000 -t web` from the
  /// repository root, then use `http://127.0.0.1:8000/web` as [baseUrl].
  static const String baseUrl = 'http://127.0.0.1:8000/web';

  static const String apiEndpoint = '$baseUrl/app_api.php';
  static const String appVersion = '1.0.5';
}
