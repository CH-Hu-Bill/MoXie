import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/user.dart';

class StorageService {
  static const _keyUser = 'user_data';
  static const _keyCurrentClassId = 'current_class_id';
  static const _keyCurrentClassName = 'current_class_name';

  Future<SharedPreferences> get _prefs => SharedPreferences.getInstance();

  Future<void> saveUser(User user) async {
    final prefs = await _prefs;
    await prefs.setString(_keyUser, json.encode(user.toJson()));
  }

  Future<User?> getUser() async {
    final prefs = await _prefs;
    final raw = prefs.getString(_keyUser);
    if (raw == null) return null;
    try {
      return User.fromJson(json.decode(raw));
    } catch (_) {
      return null;
    }
  }

  Future<void> clearUser() async {
    final prefs = await _prefs;
    await prefs.remove(_keyUser);
  }

  Future<String?> getToken() async {
    final user = await getUser();
    return user?.token;
  }

  Future<void> saveCurrentClass(String classId, String className) async {
    final prefs = await _prefs;
    await prefs.setString(_keyCurrentClassId, classId);
    await prefs.setString(_keyCurrentClassName, className);
  }

  Future<String?> getCurrentClassId() async {
    final prefs = await _prefs;
    return prefs.getString(_keyCurrentClassId);
  }

  Future<String?> getCurrentClassName() async {
    final prefs = await _prefs;
    return prefs.getString(_keyCurrentClassName);
  }

  Future<void> clearCurrentClass() async {
    final prefs = await _prefs;
    await prefs.remove(_keyCurrentClassId);
    await prefs.remove(_keyCurrentClassName);
  }
}