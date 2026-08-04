import 'package:flutter/material.dart';
import '../models/user.dart';
import '../models/class_info.dart';
import '../services/api_service.dart';
import '../services/storage_service.dart';

enum AuthState { initial, authenticated, unauthenticated, loading }

class ClassAuthExpiredException implements Exception {
  final String message;
  ClassAuthExpiredException(this.message);
}

class AuthProvider extends ChangeNotifier {
  final ApiService _api = ApiService();
  final StorageService _storage = StorageService();

  AuthState _authState = AuthState.initial;
  User? _user;
  String? _currentClassId;
  String? _currentClassName;
  List<ClassInfo> _allClasses = [];
  List<Map<String, String>> _myClasses = [];
  String? _error;
  bool _isNewlyBound = false;

  AuthProvider() {
    _api.onClassAuthExpired = () {
      if (_currentClassId != null) {
        _currentClassId = null;
        _currentClassName = null;
        _storage.clearCurrentClassId();
        notifyListeners();
      }
    };
  }

  AuthState get authState => _authState;
  User? get user => _user;
  String? get currentClassId => _currentClassId;
  String? get currentClassName => _currentClassName;
  List<ClassInfo> get allClasses => _allClasses;
  List<Map<String, String>> get myClasses => _myClasses;
  String? get error => _error;
  bool get hasClass => _currentClassId != null && _currentClassId!.isNotEmpty;
  bool get isNewlyBound => _isNewlyBound;

  void _setError(String? e) {
    _error = e;
    notifyListeners();
  }

  void clearNewlyBound() {
    _isNewlyBound = false;
    notifyListeners();
  }

  Future<void> init() async {
    final token = _storage.getToken();
    if (token == null) {
      _authState = AuthState.unauthenticated;
      notifyListeners();
      return;
    }
    _api.setToken(token);
    try {
      _currentClassId = _storage.getCurrentClassId();
      _currentClassName = _storage.getCurrentClassName();
      await _autoLogin();
      _authState = AuthState.authenticated;
    } catch (e) {
      await _storage.clearToken();
      _api.setToken(null);
      _authState = AuthState.unauthenticated;
    }
    notifyListeners();
  }

  Future<void> _autoLogin() async {
    final res = await _api.autoLogin();
    final data = res['data'] as Map<String, dynamic>;
    if (!data.containsKey('consent')) {
      data['consent'] = _storage.getConsent();
    }
    _user = User.fromJson(data);
    await _storage.saveUserId(_user!.id);
    await _storage.saveUserName(_user!.name);
    await _loadMyClasses();
  }

  Future<void> _loadMyClasses() async {
    if (_user == null) return;
    try {
      final res = await _api.getMyClasses();
      final classes = (res['data']['classes'] as List<dynamic>)
          .map((c) => Map<String, String>.from(c))
          .toList();
      _myClasses = classes;
      if (_currentClassId == null && classes.isNotEmpty) {
        await setCurrentClass(
          classes.first['class_id']!,
          classes.first['class_name']!,
        );
      }
    } catch (_) {}
  }

  Future<void> refreshMyClasses() async {
    await _loadMyClasses();
  }

  Future<bool> login(String username, String password) async {
    _setError(null);
    notifyListeners();
    try {
      final res = await _api.login(username, password);
      final data = res['data'] as Map<String, dynamic>;
      if (!data.containsKey('consent')) {
        data['consent'] = _storage.getConsent();
      }
      _user = User.fromJson(data);
      _api.setToken(_user!.token);
      await _storage.saveToken(_user!.token!);
      await _storage.saveUserId(_user!.id);
      await _storage.saveUserName(_user!.name);
      _currentClassId = _storage.getCurrentClassId();
      _currentClassName = _storage.getCurrentClassName();
      await _loadMyClasses();
      _authState = AuthState.authenticated;
      notifyListeners();
      return true;
    } catch (e) {
      _setError(e.toString());
      _authState = AuthState.unauthenticated;
      notifyListeners();
      return false;
    }
  }

  Future<void> logout() async {
    try {
      await _api.logout();
    } catch (_) {}
    await _storage.clearToken();
    await _storage.clearCurrentClassId();
    _api.setToken(null);
    _user = null;
    _currentClassId = null;
    _currentClassName = null;
    _myClasses = [];
    _authState = AuthState.unauthenticated;
    notifyListeners();
  }

  Future<void> deleteAccount() async {
    try {
      await _api.deleteAccount();
    } catch (_) {}
    await _storage.clearAll();
    _api.setToken(null);
    _user = null;
    _currentClassId = null;
    _currentClassName = null;
    _myClasses = [];
    _authState = AuthState.unauthenticated;
    notifyListeners();
  }

  Future<List<ClassInfo>> loadAllClasses() async {
    final list = await _api.getClasses();
    _allClasses = list
        .map((c) => ClassInfo.fromJson(c as Map<String, dynamic>))
        .toList();
    notifyListeners();
    return _allClasses;
  }

  Future<bool> bindClass(String classId, String password) async {
    try {
      final res = await _api.bindClass(classId, password);
      final data = res['data'] as Map<String, dynamic>;
      await setCurrentClass(
        data['class_id'] as String,
        data['class_name'] as String,
      );
      await _loadMyClasses();
      _isNewlyBound = true;
      notifyListeners();
      return true;
    } catch (e) {
      _setError(e.toString());
      return false;
    }
  }

  Future<void> unbindClass(String classId) async {
    await _api.unbindClass(classId);
    if (_currentClassId == classId) {
      await setCurrentClass(
        _myClasses.isNotEmpty ? _myClasses.first['class_id']! : '',
        _myClasses.isNotEmpty ? _myClasses.first['class_name']! : '',
      );
    }
    await _loadMyClasses();
  }

  Future<void> setCurrentClass(String classId, String className) async {
    _currentClassId = classId;
    _currentClassName = className;
    await _storage.saveCurrentClassId(classId);
    await _storage.saveCurrentClassName(className);
    notifyListeners();
  }

  Future<bool> checkClassAuth(String classId) async {
    try {
      final res = await _api.checkClass(classId);
      final data = res['data'] as Map<String, dynamic>;
      return data['ok'] == true;
    } catch (_) {
      return false;
    }
  }

  /// Called when an API returns CLASS_AUTH_EXPIRED or CLASS_NOT_BOUND.
  /// Clears the current class and triggers navigation to class selection.
  Future<void> handleClassAuthExpired() async {
    _currentClassId = null;
    _currentClassName = null;
    await _storage.clearCurrentClassId();
    notifyListeners();
  }

  Future<bool> setGlobalConsent(bool allow) async {
    try {
      await _api.setGlobalConsent(allow);
      _user = _user!.copyWith(consent: allow);
      await _storage.saveConsent(allow);
      notifyListeners();
      return true;
    } catch (_) {
      return false;
    }
  }

  Future<Map<String, dynamic>?> checkVersion() async {
    try {
      final res = await _api.checkVersion();
      return res['data'] as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  void clearError() {
    _error = null;
    notifyListeners();
  }
}
