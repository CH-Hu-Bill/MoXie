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

  Future<void> saveUserId(String userId) => _p.setString('user_id', userId);
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

  // 缓存有效期：5 分钟内进入直接显示缓存（避免重复拉取），进入时仍会后台静默刷新兜底
  static const _cacheTtl = Duration(minutes: 5);

  /// 清理某个班级的全部列表缓存（单词/错题本/图集）。
  /// 删除单词/图片/错题后调用，避免下次进入仍显示已删除的项。
  Future<void> clearClassCaches(String classId) async {
    for (final key in [
      'cache_words_$classId',
      'cache_words_ts_$classId',
      'cache_wrong_words_$classId',
      'cache_wrong_words_ts_$classId',
      'cache_gallery_$classId',
      'cache_gallery_ts_$classId',
    ]) {
      await _p.remove(key);
    }
  }

  Future<void> cacheWords(
      String classId, List<Map<String, dynamic>> words) async {
    await _p.setString('cache_words_$classId', jsonEncode(words));
    await _p.setInt(
        'cache_words_ts_$classId', DateTime.now().millisecondsSinceEpoch);
  }

  List<Map<String, dynamic>>? getCachedWords(String classId) {
    final ts = _p.getInt('cache_words_ts_$classId');
    if (ts != null &&
        DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) <
            _cacheTtl) {
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
    await _p.setInt(
        'cache_wrong_words_ts_$classId', DateTime.now().millisecondsSinceEpoch);
  }

  List<Map<String, dynamic>>? getCachedWrongWords(String classId) {
    final ts = _p.getInt('cache_wrong_words_ts_$classId');
    if (ts != null &&
        DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) <
            _cacheTtl) {
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
    await _p.setInt(
        'cache_gallery_ts_$classId', DateTime.now().millisecondsSinceEpoch);
  }

  List<Map<String, dynamic>>? getCachedGallery(String classId) {
    final ts = _p.getInt('cache_gallery_ts_$classId');
    if (ts != null &&
        DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) <
            _cacheTtl) {
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

  Future<void> saveConsent(bool consent) => _p.setBool('consent', consent);
  bool getConsent() => _p.getBool('consent') ?? false;

  // ── 全球发音列表缓存 ──

  /// 缓存发音列表（TTL 内直接用，避免每次点按都等网络）
  Future<void> cachePronunciations(
      String classId, String wordId, List<dynamic> items) async {
    await _p.setString(
        'cache_pron_${classId}_$wordId', jsonEncode(items));
    await _p.setInt('cache_pron_ts_${classId}_$wordId',
        DateTime.now().millisecondsSinceEpoch);
  }

  /// 读发音列表缓存；过期/损坏返回 null
  List<dynamic>? getPronunciations(String classId, String wordId) {
    final ts = _p.getInt('cache_pron_ts_${classId}_$wordId');
    if (ts == null ||
        DateTime.now().difference(DateTime.fromMillisecondsSinceEpoch(ts)) >
            _cacheTtl) {
      return null;
    }
    final raw = _p.getString('cache_pron_${classId}_$wordId');
    if (raw == null) return null;
    try {
      return jsonDecode(raw) as List<dynamic>;
    } catch (_) {
      return null;
    }
  }

  /// 上传/删除发音后清对应缓存
  Future<void> clearPronunciationCache(String classId, String wordId) async {
    await _p.remove('cache_pron_${classId}_$wordId');
    await _p.remove('cache_pron_ts_${classId}_$wordId');
  }

  // ── Clear ──

  Future<void> clearAll() async {
    await _p.clear();
  }
}
