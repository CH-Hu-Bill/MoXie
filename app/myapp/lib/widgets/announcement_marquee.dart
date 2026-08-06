import 'package:flutter/material.dart';

/// 公告无缝跑马灯。
///
/// 双副本（text + text）首尾相接循环滚动：第一份从左侧移出后，
/// 第二份已精确补位到第一份的起始位置，无跳变、无重叠。
///
/// 关键：副本间距使用**实际渲染宽度**（GlobalKey 测量），而不是
/// TextPainter 估算值——字体缩放/度量差异会导致第二份偏移不精确
/// 从而在拼接处出现短暂重叠。
class BannerMarquee extends StatefulWidget {
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
  State<BannerMarquee> createState() => _BannerMarqueeState();
}

class _BannerMarqueeState extends State<BannerMarquee> {
  final GlobalKey _firstKey = GlobalKey();
  double _realWidth = 0;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _measure());
  }

  void _measure() {
    if (!mounted) return;
    final ctx = _firstKey.currentContext;
    if (ctx == null) return;
    final w = ctx.size?.width ?? 0;
    if (w > 0 && (w - _realWidth).abs() > 0.5) {
      setState(() => _realWidth = w);
    }
  }

  @override
  Widget build(BuildContext context) {
    // 有真实渲染宽度优先用真实值，保证拼接精确；否则退回估算值
    final copyWidth = _realWidth > 0 ? _realWidth : widget.textWidth;
    WidgetsBinding.instance.addPostFrameCallback((_) => _measure());

    final content = AnimatedBuilder(
      animation: widget.animation,
      builder: (context, child) {
        final dx = -(widget.animation.value * (copyWidth + widget.gap));
        return SizedBox(
          height: widget.height,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Positioned(
                left: dx,
                top: 0,
                bottom: 0,
                child: Center(child: _copy(key: _firstKey)),
              ),
              Positioned(
                left: dx + copyWidth + widget.gap,
                top: 0,
                bottom: 0,
                child: Center(child: _copy()),
              ),
            ],
          ),
        );
      },
    );

    if (!widget.fadeEdges) return ClipRect(child: content);
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
          stops: const [0.0, 0.03, 0.97, 1.0],
        ).createShader(bounds),
        blendMode: BlendMode.dstIn,
        child: content,
      ),
    );
  }

  Widget _copy({Key? key}) {
    return Text(
      widget.text,
      key: key,
      style: widget.style,
      maxLines: 1,
      softWrap: false,
      overflow: TextOverflow.visible,
    );
  }
}
