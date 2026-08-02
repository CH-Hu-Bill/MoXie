import 'package:flutter/material.dart';
import '../models/user.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';

class AuthProvider extends ChangeNotifier {
  final StorageService _storage = StorageService();
  ApiService? _api;
  User? _user;
  bool _loading = false;
  String? _error;

  User? get user => _user;
  bool get loading => _loading;
  String? get error => _error;
  bool get isLoggedIn => _user != null;
  String? get currentClassId => _user?.classIds.isNotEmpty == true
      ? _user!.classIds.first
      : null;

  ApiService get api {
    _api ??= ApiService(token: _user?.token);
    return _api!;
  }

  Future<void> init() async {
    _loading = true;
    notifyListeners();

    try {
      _user = await _storage.getUser();
      if (_user != null) {
        _api = ApiService(token: _user!.token);
        final result = await _api!.post('auto_login', {});
        if (result['success'] == true) {
          final data = result['data'] ?? {};
          _user = _user!.copyWith(
            classIds: List<String>.from(data['class_ids'] ?? []),
            consentMap: data['consent_map'] != null
                ? Map<String, bool>.from(data['consent_map'])
                : null,
          );
          await _storage.saveUser(_user!);
        } else {
          await _storage.clearUser();
          _user = null;
          _api = null;
        }
      }
    } catch (_) {
      _user = null;
    }

    _loading = false;
    notifyListeners();
  }

  Future<bool> login(String name, String password) async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final api = ApiService();
      final result = await api.post('login', {
        'name': name,
        'password': password,
      });

      if (result['success'] == true) {
        _user = User.fromJson(result['data'] ?? {});
        _api = ApiService(token: _user!.token);
        await _storage.saveUser(_user!);
        _loading = false;
        notifyListeners();
        return true;
      } else {
        _error = result['error'] ?? '登录失败';
        _loading = false;
        notifyListeners();
        return false;
      }
    } catch (e) {
      _error = '网络连接失败，请检查网络';
      _loading = false;
      notifyListeners();
      return false;
    }
  }

  Future<bool> register(String name, String password) async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final api = ApiService();
      final result = await api.post('register', {
        'name': name,
        'password': password,
      });

      if (result['success'] == true) {
        _user = User.fromJson(result['data'] ?? {});
        _api = ApiService(token: _user!.token);
        await _storage.saveUser(_user!);
        _loading = false;
        notifyListeners();
        return true;
      } else {
        _error = result['error'] ?? '注册失败';
        _loading = false;
        notifyListeners();
        return false;
      }
    } catch (e) {
      _error = '网络连接失败，请检查网络';
      _loading = false;
      notifyListeners();
      return false;
    }
  }

  Future<bool> bindClass(String classId, String password) async {
    _loading = true;
    _error = null;
    notifyListeners();

    try {
      final result = await api.post('bind_class', {
        'class_id': classId,
        'password': password,
      });

      if (result['success'] == true) {
        if (!_user!.classIds.contains(classId)) {
          _user!.classIds.add(classId);
          await _storage.saveUser(_user!);
        }
        _loading = false;
        notifyListeners();
        return true;
      } else {
        _error = result['error'] ?? '绑定失败';
        _loading = false;
        notifyListeners();
        return false;
      }
    } catch (e) {
      _error = '网络连接失败';
      _loading = false;
      notifyListeners();
      return false;
    }
  }

  Future<bool> checkClass(String classId) async {
    try {
      final result = await api.post('check_class', {
        'class_id': classId,
      });
      if (result['success'] == true) {
        final data = result['data'] ?? {};
        return data['ok'] == true;
      }
      return true;
    } catch (_) {
      return true;
    }
  }

  Future<void> logout() async {
    try {
      await api.post('logout', {});
    } catch (_) {}
    _user = null;
    _api = null;
    await _storage.clearUser();
    notifyListeners();
  }

  Future<void> deleteAccount() async {
    try {
      await api.post('delete_account', {});
    } catch (_) {}
    _user = null;
    _api = null;
    await _storage.clearUser();
    notifyListeners();
  }

  Future<void> updateUserClassIds(List<String> classIds) async {
    if (_user != null) {
      _user = _user!.copyWith(classIds: classIds);
      await _storage.saveUser(_user!);
      notifyListeners();
    }
  }

  Future<void> refreshProfile() async {
    try {
      final result = await api.post('get_profile', {});
      if (result['success'] == true) {
        final data = result['data'] ?? {};
        _user = _user!.copyWith(
          classIds: List<String>.from(data['class_ids'] ?? []),
          consentMap: data['consent_map'] != null
              ? Map<String, bool>.from(data['consent_map'])
              : null,
        );
        await _storage.saveUser(_user!);
        notifyListeners();
      }
    } catch (_) {}
  }
}