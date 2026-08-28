import 'dart:convert';
import 'dart:io';
import 'dart:ui' show VoidCallback;
import 'package:http/http.dart' as http;
import '../config/api_config.dart';

class ApiException implements Exception {
  final String message;
  final String? code;
  final int statusCode;

  ApiException(this.message, {this.code, this.statusCode = 400});

  @override
  String toString() => message;
}

class ApiService {
  static final ApiService _instance = ApiService._internal();
  factory ApiService() => _instance;
  ApiService._internal();

  String? _token;
  VoidCallback? onClassAuthExpired;

  void setToken(String? token) => _token = token;
  String? get token => _token;

  String get _baseUrl => ApiConfig.baseUrl;

  Future<Map<String, dynamic>> _post(String action,
      {Map<String, String>? fields, Map<String, dynamic>? body}) async {
    final uri = Uri.parse(ApiConfig.apiEndpoint);
    final request = http.MultipartRequest('POST', uri);

    request.fields['action'] = action;
    if (fields != null) {
      fields.forEach((key, value) {
        request.fields[key] = value;
      });
    }
    if (body != null) {
      body.forEach((key, value) {
        if (value is List) {
          request.fields[key] = jsonEncode(value);
        } else {
          request.fields[key] = value.toString();
        }
      });
    }
    if (_token != null) {
      request.headers['Authorization'] = 'Bearer $_token';
    }

    final streamedResponse = await request.send().timeout(
          const Duration(seconds: 30),
          onTimeout: () => throw ApiException('请求超时，请检查网络连接'),
        );
    final response = await http.Response.fromStream(streamedResponse);
    return _handleResponse(response);
  }

  Future<Map<String, dynamic>> _postWithFile(
    String action,
    Map<String, String> fields,
    String fileField,
    File file,
    String filename,
  ) async {
    final uri = Uri.parse(ApiConfig.apiEndpoint);
    final request = http.MultipartRequest('POST', uri);

    request.fields['action'] = action;
    fields.forEach((key, value) {
      request.fields[key] = value;
    });
    if (_token != null) {
      request.headers['Authorization'] = 'Bearer $_token';
    }
    request.files.add(await http.MultipartFile.fromPath(fileField, file.path,
        filename: filename));

    final streamedResponse = await request.send().timeout(
          const Duration(seconds: 60),
          onTimeout: () => throw ApiException('上传超时，请重试'),
        );
    final response = await http.Response.fromStream(streamedResponse);
    return _handleResponse(response);
  }

  Map<String, dynamic> _handleResponse(http.Response response) {
    if (response.body.isEmpty) {
      throw ApiException('服务器返回空响应', statusCode: response.statusCode);
    }
    Map<String, dynamic> json;
    try {
      json = jsonDecode(response.body) as Map<String, dynamic>;
    } catch (_) {
      throw ApiException('服务器响应格式错误', statusCode: response.statusCode);
    }
    if (json['success'] != true) {
      final code = json['code']?.toString();
      if (code == 'CLASS_AUTH_EXPIRED' ||
          code == 'CLASS_NOT_BOUND' ||
          code == 'CLASS_DELETED') {
        onClassAuthExpired?.call();
      }
      throw ApiException(
        json['error']?.toString() ?? '请求失败',
        code: code,
        statusCode: response.statusCode,
      );
    }
    return json;
  }

  String resolveImageUrl(String relativeUrl) {
    if (relativeUrl.startsWith('http')) return relativeUrl;
    return '$_baseUrl/$relativeUrl';
  }

  // ── Auth ──

  Future<Map<String, dynamic>> login(String username, String password) {
    return _post('login', fields: {
      'username': username,
      'password': password,
    });
  }

  Future<Map<String, dynamic>> register(String username, String password) {
    return _post('register', fields: {
      'username': username,
      'password': password,
    });
  }

  Future<Map<String, dynamic>> autoLogin() {
    return _post('auto_login');
  }

  Future<void> logout() {
    return _post('logout');
  }

  Future<void> deleteAccount() {
    return _post('delete_account');
  }

  // ── Classes ──

  Future<List<dynamic>> getClasses() async {
    final res = await _post('get_classes');
    return res['data'] as List<dynamic>;
  }

  Future<Map<String, dynamic>> getMyClasses() {
    return _post('get_my_classes');
  }

  Future<Map<String, dynamic>> checkClass(String classId) {
    return _post('check_class', fields: {'class_id': classId});
  }

  Future<Map<String, dynamic>> bindClass(String classId, String password) {
    return _post('bind_class', fields: {
      'class_id': classId,
      'password': password,
    });
  }

  Future<void> unbindClass(String classId) {
    return _post('unbind_class', fields: {'class_id': classId});
  }

  // ── Profile ──

  Future<Map<String, dynamic>> getProfile() {
    return _post('get_profile');
  }

  Future<Map<String, dynamic>> setGlobalConsent(bool allow) {
    return _post('set_global_consent', fields: {
      'consent': allow ? '1' : '0',
    });
  }

  Future<Map<String, dynamic>> setConsent(String classId, bool allow) {
    return _post('set_consent', fields: {
      'class_id': classId,
      'consent': allow ? '1' : '0',
    });
  }

  Future<Map<String, dynamic>> getConsent(String classId) {
    return _post('get_consent', fields: {'class_id': classId});
  }

  // ── Version ──

  Future<Map<String, dynamic>> checkVersion() {
    return _post('check_version', fields: {
      'current_version': ApiConfig.appVersion,
    });
  }

  Future<Map<String, dynamic>> getAnnouncements(String classId,
      {String platform = 'app'}) {
    return _post('get_announcements', fields: {
      'class_id': classId,
      'platform': platform,
    });
  }

  // ── Words ──

  Future<Map<String, dynamic>> getWords(String classId,
      {int page = 1, int perPage = 20}) {
    return _post('get_words', fields: {
      'class_id': classId,
      'page': page.toString(),
      'per_page': perPage.toString(),
    });
  }

  Future<Map<String, dynamic>> searchWord(String classId, String query) {
    return _post('search_word', fields: {
      'class_id': classId,
      'query': query,
    });
  }

  Future<Map<String, dynamic>> addWord(
      String classId, String word, String meaning, String pos) {
    return _post('add_word', fields: {
      'class_id': classId,
      'word': word,
      'meaning': meaning,
      'pos': pos,
    });
  }

  Future<Map<String, dynamic>> markWrong(
      String classId, String wordId, bool wrong) {
    return _post('mark_wrong', fields: {
      'class_id': classId,
      'word_id': wordId,
      'wrong': wrong ? '1' : '0',
    });
  }

  Future<Map<String, dynamic>> unmarkWrong(String classId, String wordId) {
    return _post('unmark_wrong', fields: {
      'class_id': classId,
      'word_id': wordId,
    });
  }

  Future<Map<String, dynamic>> toggleFavorite(String classId, String wordId) {
    return _post('toggle_favorite', fields: {
      'class_id': classId,
      'word_id': wordId,
    });
  }

  Future<Map<String, dynamic>> getFavorites(String classId,
      {int page = 1, int perPage = 100}) {
    return _post('get_favorites', fields: {
      'class_id': classId,
      'page': page.toString(),
      'per_page': perPage.toString(),
    });
  }

  // ── Wrong Words ──

  Future<Map<String, dynamic>> getWrongWords(String classId,
      {int page = 1, int perPage = 100}) {
    return _post('get_wrong_words', fields: {
      'class_id': classId,
      'page': page.toString(),
      'per_page': perPage.toString(),
    });
  }

  Future<Map<String, dynamic>> exportWrongText(String classId) {
    return _post('export_wrong_text', fields: {'class_id': classId});
  }

  // ── Tasks ──

  Future<Map<String, dynamic>> getTasks(String classId, String type,
      {int page = 1, int perPage = 50}) {
    return _post('get_tasks', fields: {
      'class_id': classId,
      'type': type,
      'page': page.toString(),
      'per_page': perPage.toString(),
    });
  }

  Future<Map<String, dynamic>> getTaskDetail(String classId, String taskId) {
    return _post('get_task_detail', fields: {
      'class_id': classId,
      'task_id': taskId,
    });
  }

  Future<Map<String, dynamic>> completeTask(String classId, String taskId) {
    return _post('complete_task', fields: {
      'class_id': classId,
      'task_id': taskId,
    });
  }

  Future<Map<String, dynamic>> cancelTask(String classId, String taskId) {
    return _post('cancel_task', fields: {
      'class_id': classId,
      'task_id': taskId,
    });
  }

  Future<Map<String, dynamic>> exportTaskText(String classId, String taskId) {
    return _post('export_task_text', fields: {
      'class_id': classId,
      'task_id': taskId,
    });
  }

  // ── Search ──

  Future<Map<String, dynamic>> searchAll(String classId, String query) {
    return _post('search_all', fields: {
      'class_id': classId,
      'query': query,
    });
  }

  // ── Vlog / History ──

  Future<Map<String, dynamic>> getPersonalHistory(String classId,
      {String? month}) {
    return _post('get_personal_history', fields: {
      'class_id': classId,
      if (month != null) 'month': month,
    });
  }

  Future<Map<String, dynamic>> savePersonalHistory(
      String classId, Map<String, dynamic> entry) {
    final fields = <String, String>{
      'class_id': classId,
      'date': entry['date'] as String,
      'content': entry['content'] as String,
      'delta': entry['delta'] as String? ?? '',
      'title': entry['title'] as String? ?? '',
      'mood': entry['mood'] as String? ?? '😊',
      'weather': entry['weather'] as String? ?? '☀️',
      'location': entry['location'] as String? ?? '',
      'tags': jsonEncode(entry['tags'] ?? []),
    };
    return _post('save_personal_history', fields: fields);
  }

  Future<Map<String, dynamic>> getClassHistory(String classId,
      {String? month}) {
    return _post('get_class_history', fields: {
      'class_id': classId,
      if (month != null) 'month': month,
    });
  }

  Future<Map<String, dynamic>> getAuthorizedVlogs(String classId,
      {String? month}) {
    return _post('get_authorized_vlogs', fields: {
      'class_id': classId,
      if (month != null) 'month': month,
    });
  }

  Future<Map<String, dynamic>> uploadImage(
      String classId, File file, String filename) {
    return _postWithFile(
      'upload_image',
      {'class_id': classId},
      'file',
      file,
      filename,
    );
  }

  // ── Gallery ──

  Future<Map<String, dynamic>> getGallery(String classId,
      {int page = 1, int perPage = 50}) {
    return _post('get_gallery', fields: {
      'class_id': classId,
      'page': page.toString(),
      'per_page': perPage.toString(),
    });
  }

  Future<Map<String, dynamic>> saveGallery(
      String classId, File image, String description) {
    return _postWithFile(
      'save_gallery',
      {
        'class_id': classId,
        'description': description,
      },
      'image',
      image,
      image.uri.pathSegments.isNotEmpty
          ? image.uri.pathSegments.last
          : 'gallery_${DateTime.now().millisecondsSinceEpoch}.jpg',
    );
  }

  Future<void> deleteGallery(String classId, String id) {
    return _post('delete_gallery', fields: {
      'class_id': classId,
      'id': id,
    });
  }

  Future<void> updateGalleryDescription(
      String classId, String id, String description) {
    return _post('update_gallery', fields: {
      'class_id': classId,
      'id': id,
      'description': description,
    });
  }

  // ── 全球发音 ──

  /// 拉取某单词的全球发音列表
  Future<List<dynamic>> getPronunciations(String classId, String wordId) async {
    final res = await _post('get_pronunciations',
        fields: {'class_id': classId, 'word_id': wordId});
    return (res['data']?['items'] as List<dynamic>?) ?? [];
  }

  /// 上传自己的发音录音（M4A），同一单词重复上传自动覆盖
  Future<Map<String, dynamic>> uploadPronunciation(
      String classId, String wordId, File file, String filename) {
    return _postWithFile(
      'upload_pronunciation',
      {'class_id': classId, 'word_id': wordId},
      'audio',
      file,
      filename,
    );
  }

  /// 删除自己的发音
  Future<void> deletePronunciation(String classId, String wordId) {
    return _post('delete_pronunciation',
        fields: {'class_id': classId, 'word_id': wordId});
  }
}
