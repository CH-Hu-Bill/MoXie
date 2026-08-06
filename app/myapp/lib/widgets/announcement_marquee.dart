import 'package:flutter/material.dart';

/// 公告无缝跑马灯。
///
/// 双副本（text + text）首尾相接循环滚动：
/// 第一份文本从右侧移入、左侧移出后，第二份已在同一位置无缝续接，
/// 不会出现"从开头重新播放"的跳变，也不会出现两份重叠。
///
/// [animation] 值域 0..1，0 时第一份从可用区左缘开始（第二份在右缘外），
/// 1 时第一份完全移出左侧、第二份正好补到第一份起始位置。
class BannerMarquee extends StatelessWidget {
  const BannerMarquee({
    super.key,
    required this.text,
    required this.style,
    required this.animation,
    required this.textWidth,
    this.gap = 40,
    this.height = 30,
    this.fadeEdges = true,
  });

  final String text;
  final TextStyle style;
  final Animation<double> animation;
  final double textWidth;
  final double gap;
  final double height;
  final bool fadeEdges;

  @override
  Widget build(BuildContext context) {
    final content = AnimatedBuilder(
      animation: animation,
      builder: (context, child) {
        final dx = -(animation.value * (textWidth + gap));
        return SizedBox(
          height: height,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Positioned(
                left: dx,
                top: 0,
                bottom: 0,
                child: Center(child: _copy()),
              ),
              Positioned(
                left: dx + textWidth + gap,
                top: 0,
                bottom: 0,
                child: Center(child: _copy()),
              ),
            ],
          ),
        );
      },
    );
    if (!fadeEdges) return ClipRect(child: content);
    return ClipRect(
      child: ShaderMask(
        shaderCallback: (bounds) => LinearGradient(
          begin: Alignment.centerLeft,
          end: Alignment.centerRight,
          colors: [
            Colors.transparent,
            Colors.black,
            Colors.black,
            Colors.transparent,
          ],
          stops: const [0.0, 0.06, 0.94, 1.0],
        ).createShader(bounds),
        blendMode: BlendMode.dstIn,
        child: content,
      ),
    );
  }

  Widget _copy() {
    return Text(
      text,
      style: style,
      maxLines: 1,
      softWrap: false,
      overflow: TextOverflow.visible,
    );
  }
}
