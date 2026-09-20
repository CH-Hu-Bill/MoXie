/// API configuration template.
///
/// [baseUrl] 现在可在运行时由用户在「服务器端点」页修改（保存在 SharedPreferences），
/// 这里的值仅作为内置默认（CI 可用 `API_BASE_URL` Secret 覆盖）。
/// 因此不再是 const：`ApiConfig.baseUrl = ...`。
class ApiConfig {
  /// 内置默认端点（仅首次启动前的占位；用户首次启动会自定义并覆盖）。
  static String baseUrl = 'http://127.0.0.1:8000/web';

  static String get apiEndpoint => '$baseUrl/app_api.php';
  static const String appVersion = '1.0.18';
}
