import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import '../config/api_config.dart';
import '../models/vlog_entry.dart';
import '../providers/auth_provider.dart';
import '../services/announcement_service.dart';
import '../theme/app_theme.dart';
import 'study/study_screen.dart';
import 'life/life_screen.dart';
import 'search/search_screen.dart';
import 'gallery/gallery_screen.dart';
import 'profile/profile_screen.dart';
import 'class_selection_screen.dart';
import '../widgets/announcement_banner.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _currentIndex = 0;
  bool _classExpiredHandled = false;
  Timer? _classCheckTimer;
  final _studyKey = GlobalKey<StudyScreenState>();
  final _lifeKey = GlobalKey<LifeScreenState>();

  void _onWordFound(WordMatch word) {
    setState(() => _currentIndex = 0);
    // 等 tab 切换完成渲染后再定位，避免 IndexedStack 中滚动上下文未就绪
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _studyKey.currentState?.locateWord(word.type, word.id, word.word);
    });
  }

  void _onTabChanged(int index) {
    if (_currentIndex == 1 && index != 1) {
      _lifeKey.currentState?.resetToInitial();
    }
    setState(() => _currentIndex = index);
    // 每次切换底部 tab 触发超级霸屏
    AnnouncementService.instance.triggerFullscreen();
  }

  late final _screens = [
    StudyScreen(key: _studyKey),
    LifeScreen(key: _lifeKey),
    SearchScreen(onWordFound: _onWordFound),
    const GalleryScreen(),
    const ProfileScreen(),
  ];

  static const _tabTitles = ['学习', '生活', '搜索', '画廊', '我的'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _checkClassValidity();
      _checkVersionGate();
    });
    _classCheckTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) _checkClassValidity();
    });
  }

  bool _versionLessThan(String a, String b) {
    List<int> parse(String v) {
      return v.split('.').map((p) {
        final digits = RegExp(r'^\d+').firstMatch(p.trim())?.group(0) ?? '0';
        return int.tryParse(digits) ?? 0;
      }).toList();
    }
    final pa = parse(a);
    final pb = parse(b);
    for (var i = 0; i < 3; i++) {
      final x = i < pa.length ? pa[i] : 0;
      final y = i < pb.length ? pb[i] : 0;
      if (x != y) return x < y;
    }
    return false;
  }

  Future<void> _checkVersionGate() async {
    final auth = context.read<AuthProvider>();
    final info = await auth.checkVersion();
    if (!mounted || info == null) return;
    final webVersion = (info['web_version'] ?? '').toString();
    final minWeb = (info['min_web_version'] ?? ApiConfig.minWebVersion).toString();
    if (webVersion.isNotEmpty && _versionLessThan(webVersion, minWeb)) {
      _showVersionBlock(
        '服务器版本过旧',
        '当前服务器 $webVersion 低于 APP 要求的 $minWeb。请联系管理员更新网页端后再使用。',
        null,
      );
      return;
    }
    if (info['force_update'] == true) {
      final latest = (info['latest'] ?? '').toString();
      _showVersionBlock(
        '需要更新 APP',
        '当前版本 ${ApiConfig.appVersion} 低于最低支持版本，请更新到 $latest 后继续使用。',
        '${ApiConfig.baseUrl}/download_app.php',
      );
    }
  }

  void _showVersionBlock(String title, String message, String? downloadUrl) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => PopScope(
        canPop: false,
        child: AlertDialog(
          backgroundColor: AppColors.background,
          shape: RoundedRectangleBorder(
            borderRadius: AppTheme.wobblyRadius,
            side: const BorderSide(color: AppColors.border, width: 2),
          ),
          title: Text(title, style: AppTheme.headingStyle),
          content: Text(message, style: AppTheme.bodyStyle),
          actions: [
            if (downloadUrl != null)
              TextButton(
                onPressed: () {
                  Clipboard.setData(ClipboardData(text: downloadUrl));
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('下载地址已复制，请在浏览器打开')),
                  );
                },
                child: Text('复制下载地址', style: AppTheme.bodyStyle),
              )
            else
              TextButton(
                onPressed: () => SystemNavigator.pop(),
                child: Text('退出 APP', style: AppTheme.bodyStyle),
              ),
          ],
        ),
      ),
    );
  }

  @override
  void dispose() {
    _classCheckTimer?.cancel();
    super.dispose();
  }

  Future<void> _checkClassValidity() async {
    final auth = context.read<AuthProvider>();
    if (auth.currentClassId != null && auth.currentClassId!.isNotEmpty) {
      final ok = await auth.checkClassAuth(auth.currentClassId!);
      if (ok || !mounted) return;
      _showClassExpiredDialog();
    }
  }

  void _showClassExpiredDialog() {
    if (_classExpiredHandled) return;
    _classExpiredHandled = true;
    _classCheckTimer?.cancel();
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        scrollable: true,
        backgroundColor: AppColors.paper,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.pencil, width: 2),
        ),
        title: Text('班级口令已失效', style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
        content: Text('班级口令已被修改或班级已删除，请重新选择班级',
            style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 16)),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              context.read<AuthProvider>().handleClassAuthExpired();
            },
            child: Text('去选择', style: TextStyle(fontFamily: AppTheme.fontBody)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    if (auth.currentClassId == null || auth.currentClassId!.isEmpty) {
      return const ClassSelectionScreen(fromHome: true);
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(_currentIndex == 4
            ? '我的'
            : (auth.currentClassName ?? _tabTitles[_currentIndex])),
        backgroundColor: AppColors.white,
        shape: const Border(bottom: BorderSide(color: AppColors.pencil, width: 3)),
      ),
      body: Column(
        children: [
          const AnnouncementBanner(),
          Expanded(
            child: IndexedStack(
              index: _currentIndex,
              children: _screens,
            ),
          ),
        ],
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: AppColors.white,
          border: const Border(top: BorderSide(color: AppColors.pencil, width: 2)),
          boxShadow: [
            BoxShadow(
              color: AppColors.pencil.withValues(alpha: 0.1),
              offset: const Offset(0, -2),
              blurRadius: 0,
            ),
          ],
        ),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildNavItem(0, Icons.menu_book),
                _buildNavItem(1, Icons.edit_note),
                _buildNavItem(2, Icons.search),
                _buildNavItem(3, Icons.photo_library),
                _buildNavItem(4, Icons.person),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem(int index, IconData icon) {
    final selected = _currentIndex == index;
    return GestureDetector(
      onTap: () => _onTabChanged(index),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? AppColors.postIt : Colors.transparent,
          borderRadius: AppTheme.wobblyRadius,
          border: selected ? Border.all(color: AppColors.pencil, width: 2) : null,
        ),
        child: Icon(
          icon,
          size: 28,
          color: selected ? AppColors.red : AppColors.pencil,
        ),
      ),
    );
  }
}