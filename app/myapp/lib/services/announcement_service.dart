import 'package:flutter/foundation.dart';
import '../models/announcement.dart';
import 'api_service.dart';

/// 公告共享服务：拉取并缓存当前生效的顶部横幅公告与超级霸屏公告。
///
/// - [banner]：顶部横幅公告（mode=banner）
/// - [fullscreen]：超级霸屏公告（mode=fullscreen）
/// - [triggerFullscreen]：触发一次全屏展示（切 tab / 进入子页面 / 启动时调用）
///
/// 首次 [refresh] 成功且有超级公告时会自动触发一次（App 进入首页）。
class AnnouncementService extends ChangeNotifier {
  AnnouncementService._();
  static final AnnouncementService instance = AnnouncementService._();

  Announcement? _banner;
  Announcement? _fullscreen;
  final ValueNotifier<int> _fsTrigger = ValueNotifier(0);
  String? _lastClassId;
  bool _triggeredOnLoad = false;

  Announcement? get banner => _banner;
  Announcement? get fullscreen => _fullscreen;
  ValueNotifier<int> get fsTrigger => _fsTrigger;

  Future<void> refresh(String classId) async {
    if (classId == _lastClassId) return;
    _lastClassId = classId;
    try {
      final res = await ApiService().getAnnouncements(classId);
      final data = res['data'] as List<dynamic>? ?? [];
      Announcement? banner;
      Announcement? fullscreen;
      for (final item in data) {
        final a = Announcement.fromJson(item as Map<String, dynamic>);
        if (a.mode == 'fullscreen') {
          fullscreen ??= a;
        } else {
          banner ??= a;
        }
      }
      _banner = banner;
      _fullscreen = fullscreen;
      notifyListeners();
      if (!_triggeredOnLoad && _fullscreen != null) {
        _triggeredOnLoad = true;
        _bumpTrigger();
      }
    } catch (_) {}
  }

  /// 手动触发一次全屏展示（有超级公告才生效）
  void triggerFullscreen() {
    if (_fullscreen == null) return;
    _bumpTrigger();
  }

  void _bumpTrigger() {
    _fsTrigger.value++;
  }

  @visibleForTesting
  void debugSet({Announcement? banner, Announcement? fullscreen}) {
    _banner = banner;
    _fullscreen = fullscreen;
    notifyListeners();
  }
}
