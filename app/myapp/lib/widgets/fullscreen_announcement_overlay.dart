import 'dart:async';
import 'package:flutter/material.dart';
import '../models/announcement.dart';
import '../services/announcement_service.dart';
import '../theme/app_theme.dart';

/// 超级霸屏全屏公告层。
///
/// 常驻于 MaterialApp builder（覆盖所有页面），监听 [AnnouncementService.fsTrigger]：
/// - 有生效中的超级公告时，全屏展示其内容 ≥ [Announcement.fullscreenSeconds] 秒
/// - 点击任意处可立即跳过
/// - 半透明暖纸底，公告卡居中且避开顶部横幅/AppBar 区域
class FullscreenAnnouncementOverlay extends StatefulWidget {
  const FullscreenAnnouncementOverlay({super.key});

  @override
  State<FullscreenAnnouncementOverlay> createState() =>
      _FullscreenAnnouncementOverlayState();
}

class _FullscreenAnnouncementOverlayState
    extends State<FullscreenAnnouncementOverlay> {
  Announcement? _showing;
  Timer? _timer;
  bool _visible = false;

  @override
  void initState() {
    super.initState();
    AnnouncementService.instance.fsTrigger.addListener(_onTrigger);
  }

  @override
  void dispose() {
    AnnouncementService.instance.fsTrigger.removeListener(_onTrigger);
    _timer?.cancel();
    super.dispose();
  }

  void _onTrigger() {
    final ann = AnnouncementService.instance.fullscreen;
    if (ann == null || _showing != null) return;
    _timer?.cancel();
    setState(() {
      _showing = ann;
      _visible = false;
    });
    // 下一帧再淡入，确保动画生效
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || _showing == null) return;
      setState(() => _visible = true);
    });
    final seconds = ann.fullscreenSeconds.clamp(1, 5);
    _timer = Timer(Duration(seconds: seconds), _hide);
  }

  void _hide() {
    _timer?.cancel();
    if (!mounted) return;
    setState(() => _visible = false);
    Future.delayed(const Duration(milliseconds: 300), () {
      if (mounted && !_visible) setState(() => _showing = null);
    });
  }

  @override
  Widget build(BuildContext context) {
    final ann = _showing;
    if (ann == null) return const SizedBox.shrink();

    final color = _parseColor(ann.color);

    return IgnorePointer(
      ignoring: !_visible,
      child: AnimatedOpacity(
        opacity: _visible ? 1 : 0,
        duration: const Duration(milliseconds: 250),
        child: GestureDetector(
          onTap: _hide,
          behavior: HitTestBehavior.opaque,
          child: Container(
            color: AppColors.paper.withValues(alpha: 0.90),
            alignment: Alignment.center,
            padding: const EdgeInsets.only(top: 96),
            child: Container(
              margin: const EdgeInsets.symmetric(horizontal: 32),
              padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 34),
              decoration: BoxDecoration(
                color: AppColors.white,
                borderRadius: BorderRadius.only(
                  topLeft: const Radius.circular(255),
                  topRight: const Radius.circular(15),
                  bottomLeft: const Radius.circular(225),
                  bottomRight: const Radius.circular(15),
                ),
                border: Border.all(color: color, width: 4),
                boxShadow: const [
                  BoxShadow(
                    color: Color(0x8C2D2D2D),
                    offset: Offset(8, 8),
                    blurRadius: 0,
                  ),
                ],
              ),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    ann.content,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontFamily: AppTheme.fontHeading,
                      fontSize: 26,
                      height: 1.5,
                      fontWeight: FontWeight.w700,
                      color: color,
                    ),
                  ),
                  const SizedBox(height: 22),
                  Text(
                    '点击任意处进入',
                    style: TextStyle(
                      fontFamily: AppTheme.fontBody,
                      fontSize: 13,
                      color: AppColors.pencil.withValues(alpha: 0.55),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Color _parseColor(String hex) {
    hex = hex.replaceFirst('#', '');
    if (hex.length == 3) {
      hex = hex.split('').map((c) => '$c$c').join();
    }
    if (hex.length == 6) hex = 'FF$hex';
    return Color(int.parse(hex, radix: 16));
  }
}
