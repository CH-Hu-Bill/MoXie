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
    final media = MediaQuery.of(context);
    // 只占内容区：顶部为 状态栏+AppBar(+横幅)，底部为 底部 tab 栏
    final topInset = media.padding.top + kToolbarHeight +
        (AnnouncementService.instance.banner != null ? 30.0 : 0.0);
    final bottomInset = 60.0 + media.padding.bottom;

    return IgnorePointer(
      ignoring: !_visible,
      child: AnimatedOpacity(
        opacity: _visible ? 1 : 0,
        duration: const Duration(milliseconds: 250),
        child: GestureDetector(
          onTap: _hide,
          behavior: HitTestBehavior.opaque,
          child: Stack(
            children: [
              // 全屏透明拦截层：点击任意处（含顶部/底部栏）跳过
              Positioned.fill(child: Container(color: Colors.transparent)),
              // 内容区半透明背景
              Positioned(
                top: topInset,
                bottom: bottomInset,
                left: 0,
                right: 0,
                child: Container(color: AppColors.paper.withValues(alpha: 0.90)),
              ),
              // 公告卡（内容区居中）
              Positioned(
                top: topInset,
                bottom: bottomInset,
                left: 0,
                right: 0,
                child: Center(child: _buildCard(ann, color)),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildCard(Announcement ann, Color color) {
    // 超级霸屏大字展示：内容越长字号越小（自适应不溢出），并允许滚动兜底。
    final len = ann.content.characters.length;
    final base = len <= 24 ? 26.0 : (len <= 40 ? 22.0 : (len <= 60 ? 18.0 : 15.0));
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 24),
      padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 26),
      constraints: const BoxConstraints(maxHeight: 520),
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
          Flexible(
            child: SingleChildScrollView(
              child: Text(
                ann.content,
                textAlign: TextAlign.center,
                style: TextStyle(
                  fontFamily: AppTheme.fontHeading,
                  fontSize: base,
                  height: 1.5,
                  fontWeight: FontWeight.w700,
                  color: color,
                ),
              ),
            ),
          ),
          const SizedBox(height: 18),
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
