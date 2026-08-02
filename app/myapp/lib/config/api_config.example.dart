class ApiConfig {
  static const String baseUrl =
      'https://your-domain.com/web/app_api.php';

  static const String downloadBaseUrl =
      'https://your-domain.com/web/';

  static const String uploadBaseUrl =
      'https://your-domain.com/web/';

  static const Duration requestTimeout = Duration(seconds: 30);
  static const int tokenExpiryDays = 30;
}