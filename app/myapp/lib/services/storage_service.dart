import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

class StorageService {
  static final StorageService _instance = StorageService._internal();
  factory StorageService() => _instance;
  StorageService._internal();

  SharedPreferences? _prefs;

  Future<void> init() async {
    _prefs = await SharedPreferences.getInstance();
  }

  SharedPreferences get _p {
    if (_prefs == null) {
      throw StateError('StorageService not initialized. Call init() first.');
    }
    return _prefs!;
  }

  // ── Auth ──

  Future<void> saveToken(String token) => _p.setString('auth_token', token);
  String? getToken() => _p.getString('auth_token');
  Future<void> clearToken() => _p.remove('auth_token');

  Future<void> saveUserId(String userId) =>
      _p.setString('user_id', userId);
  String? getUserId() => _p.getString('user_id');

  Future<void> saveUserName(String name) => _p.setString('user_name', name);
  String? getUserName() => _p.getString('user_name');

  // ── Current Class ──

  Future<void> saveCurrentClassId(String classId) =>
      _p.setString('current_class_id', classId);
  String? getCurrentClassId() => _p.getString('current_class_id');
  Future<void> clearCurrentClassId() => _p.remove('current_class_id');

  Future<void> saveCurrentClassName(String name) =>
      _p.setString('current_class_name', name);
  String? getCurrentClassName() => _p.getString('current_class_name');

  // ── Cache ──

  static const _cacheTtl = Duration(seconds: 30);

  Future<void> cacheWords(String classId, List<Map<String, dynamic>> words) async {
    await _p.setString('cache_words_$classId', jsonEncode(words));
    await _p.setInt('cache_words_ts_$classId', DateTime.now().millisecondsSinceEpoch);
  }

  List<Map<String, dynamic>>? getCachedWords(String classId) {
    final ts = _p.getInt('cache_words_ts_$classId');
    if (ts != null && DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) < _cacheTtl) {
      final raw = _p.getString('cache_words_$classId');
      if (raw == null) return null;
      try {
        return (jsonDecode(raw) as List)
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      } catch (_) {
        return null;
      }
    }
    return null;
  }

  Future<void> cacheWrongWords(
          String classId, List<Map<String, dynamic>> words) async {
    await _p.setString('cache_wrong_words_$classId', jsonEncode(words));
    await _p.setInt('cache_wrong_words_ts_$classId', DateTime.now().millisecondsSinceEpoch);
  }

  List<Map<String, dynamic>>? getCachedWrongWords(String classId) {
    final ts = _p.getInt('cache_wrong_words_ts_$classId');
    if (ts != null && DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) < _cacheTtl) {
      final raw = _p.getString('cache_wrong_words_$classId');
      if (raw == null) return null;
      try {
        return (jsonDecode(raw) as List)
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      } catch (_) {
        return null;
      }
    }
    return null;
  }

  Future<void> cacheGallery(
          String classId, List<Map<String, dynamic>> items) async {
    await _p.setString('cache_gallery_$classId', jsonEncode(items));
    await _p.setInt('cache_gallery_ts_$classId', DateTime.now().millisecondsSinceEpoch);
  }

  List<Map<String, dynamic>>? getCachedGallery(String classId) {
    final ts = _p.getInt('cache_gallery_ts_$classId');
    if (ts != null && DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) < _cacheTtl) {
      final raw = _p.getString('cache_gallery_$classId');
      if (raw == null) return null;
      try {
        return (jsonDecode(raw) as List)
            .map((e) => Map<String, dynamic>.from(e))
            .toList();
      } catch (_) {
        return null;
      }
    }
    return null;
  }

  // ── Consent ──

  Future<void> saveConsent(bool consent) =>
      _p.setBool('consent', consent);
  bool getConsent() => _p.getBool('consent') ?? false;

  // ── Clear ──

  Future<void> clearAll() async {
    await _p.clear();
  }
}
