import 'dart:math';
import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../services/tts_service.dart';

class HandDrawnCard extends StatelessWidget {
  final Widget child;
  final EdgeInsets padding;
  final Color? backgroundColor;
  final BorderRadius? borderRadius;
  final List<BoxShadow>? shadows;
  final double? rotation;
  final double borderWidth;
  final Color? borderColor;
  final VoidCallback? onTap;

  const HandDrawnCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.backgroundColor,
    this.borderRadius,
    this.shadows,
    this.rotation,
    this.borderWidth = 2,
    this.borderColor,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final card = Container(
      padding: padding,
      decoration: BoxDecoration(
        color: backgroundColor ?? AppColors.white,
        borderRadius: borderRadius ?? AppTheme.wobblyRadius,
        border: Border.all(
          color: borderColor ?? AppColors.pencil,
          width: borderWidth,
        ),
        boxShadow: shadows ?? AppTheme.softShadow,
      ),
      child: child,
    );

    final rotated = rotation != null
        ? Transform.rotate(angle: rotation! * pi / 180, child: card)
        : card;

    if (onTap != null) {
      return InkWell(
        onTap: onTap,
        borderRadius: borderRadius ?? AppTheme.wobblyRadius,
        child: rotated,
      );
    }
    return rotated;
  }
}

class HandDrawnButton extends StatefulWidget {
  final String label;
  final VoidCallback? onPressed;
  final Color? backgroundColor;
  final Color? textColor;
  final IconData? icon;
  final bool isSecondary;
  final bool fullWidth;
  final double fontSize;

  const HandDrawnButton({
    super.key,
    required this.label,
    this.onPressed,
    this.backgroundColor,
    this.textColor,
    this.icon,
    this.isSecondary = false,
    this.fullWidth = false,
    this.fontSize = 18,
  });

  @override
  State<HandDrawnButton> createState() => _HandDrawnButtonState();
}

class _HandDrawnButtonState extends State<HandDrawnButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final isDisabled = widget.onPressed == null;
    final bgColor = isDisabled
        ? AppColors.oldPaper
        : (widget.backgroundColor ??
              (widget.isSecondary ? AppColors.oldPaper : AppColors.white));
    final fgColor = isDisabled
        ? AppColors.pencil.withValues(alpha: 0.4)
        : (widget.textColor ?? AppColors.pencil);

    return GestureDetector(
      onTapDown: (_) => setState(() => _pressed = true),
      onTapUp: (_) {
        setState(() => _pressed = false);
        if (widget.onPressed != null) widget.onPressed!();
      },
      onTapCancel: () => setState(() => _pressed = false),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 80),
        transform: _pressed
            ? Matrix4.translationValues(3, 3, 0)
            : Matrix4.identity(),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
          decoration: BoxDecoration(
            color: bgColor,
            borderRadius: AppTheme.wobblyRadius,
            border: Border.all(color: AppColors.pencil, width: 2),
            boxShadow: _pressed
                ? []
                : (isDisabled ? null : AppTheme.hardShadowMd),
          ),
          child: Row(
            mainAxisSize: widget.fullWidth
                ? MainAxisSize.max
                : MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (widget.icon != null) ...[
                Icon(widget.icon, size: widget.fontSize + 2, color: fgColor),
                const SizedBox(width: 8),
              ],
              Flexible(
                child: Text(
                  widget.label,
                  style: TextStyle(
                    fontFamily: AppTheme.fontHeading,
                    fontSize: widget.fontSize,
                    color: fgColor,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class HandDrawnInput extends StatelessWidget {
  final String? label;
  final String? hint;
  final TextEditingController? controller;
  final bool obscureText;
  final TextInputType keyboardType;
  final int maxLines;
  final Widget? suffix;
  final String? Function(String?)? validator;
  final void Function(String)? onChanged;

  const HandDrawnInput({
    super.key,
    this.label,
    this.hint,
    this.controller,
    this.obscureText = false,
    this.keyboardType = TextInputType.text,
    this.maxLines = 1,
    this.suffix,
    this.validator,
    this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (label != null) ...[
          Text(
            label!,
            style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 15),
          ),
          const SizedBox(height: 6),
        ],
        TextFormField(
          controller: controller,
          obscureText: obscureText,
          keyboardType: keyboardType,
          maxLines: maxLines,
          validator: validator,
          onChanged: onChanged,
          style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 15),
          decoration: InputDecoration(hintText: hint, suffixIcon: suffix),
        ),
      ],
    );
  }
}

class StickyNote extends StatelessWidget {
  final String text;
  final Color? color;
  final double rotation;

  const StickyNote({
    super.key,
    required this.text,
    this.color,
    this.rotation = -1,
  });

  @override
  Widget build(BuildContext context) {
    return Transform.rotate(
      angle: rotation * pi / 180,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: color ?? AppColors.postIt,
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(4),
            topRight: Radius.circular(4),
            bottomLeft: Radius.circular(2),
            bottomRight: Radius.circular(8),
          ),
          boxShadow: [
            BoxShadow(
              color: AppColors.pencil.withValues(alpha: 0.15),
              offset: const Offset(3, 3),
              blurRadius: 0,
            ),
          ],
        ),
        child: Text(
          text,
          style: TextStyle(
            fontFamily: AppTheme.fontHeading,
            fontSize: 16,
            color: AppColors.pencil,
          ),
        ),
      ),
    );
  }
}

class LoadingOverlay extends StatelessWidget {
  final String? message;

  const LoadingOverlay({super.key, this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.paper.withValues(alpha: 0.8),
      child: Center(
        child: HandDrawnCard(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(
                width: 32,
                height: 32,
                child: CircularProgressIndicator(
                  color: AppColors.red,
                  strokeWidth: 3,
                ),
              ),
              if (message != null) ...[
                const SizedBox(height: 16),
                Text(
                  message!,
                  style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 16),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  final String message;
  final IconData icon;

  const EmptyState({
    super.key,
    required this.message,
    this.icon = Icons.draw_outlined,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: AppColors.oldPaper),
            const SizedBox(height: 16),
            Text(
              message,
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: AppTheme.fontBody,
                fontSize: 18,
                color: AppColors.pencil.withValues(alpha: 0.5),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class WobblyTabBar extends StatelessWidget {
  final List<String> tabs;
  final int selectedIndex;
  final ValueChanged<int> onTap;

  const WobblyTabBar({
    super.key,
    required this.tabs,
    required this.selectedIndex,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.oldPaper.withValues(alpha: 0.5),
        borderRadius: AppTheme.wobblyRadius,
        border: Border.all(color: AppColors.pencil, width: 2),
      ),
      child: Row(
        children: List.generate(tabs.length, (i) {
          final selected = i == selectedIndex;
          return Expanded(
            child: GestureDetector(
              onTap: () => onTap(i),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 150),
                margin: const EdgeInsets.all(2),
                padding: const EdgeInsets.symmetric(
                  vertical: 10,
                  horizontal: 4,
                ),
                decoration: BoxDecoration(
                  color: selected ? AppColors.white : Colors.transparent,
                  borderRadius: AppTheme.wobblyRadius,
                  border: selected
                      ? Border.all(color: AppColors.pencil, width: 2)
                      : null,
                  boxShadow: selected ? AppTheme.hardShadowSm : null,
                ),
                child: Text(
                  tabs[i],
                  textAlign: TextAlign.center,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    fontFamily: AppTheme.fontHeading,
                    fontSize: 14,
                    color: selected
                        ? AppColors.red
                        : AppColors.pencil.withValues(alpha: 0.6),
                  ),
                ),
              ),
            ),
          );
        }),
      ),
    );
  }
}

class MarqueeText extends StatefulWidget {
  final String text;
  final double fontSize;
  final TextStyle? style;

  const MarqueeText({
    super.key,
    required this.text,
    this.fontSize = 32,
    this.style,
  });

  @override
  State<MarqueeText> createState() => _MarqueeTextState();
}

class _MarqueeTextState extends State<MarqueeText>
    with TickerProviderStateMixin {
  late ScrollController _controller;
  bool _scrolling = false;
  bool _userInteracting = false;

  @override
  void initState() {
    super.initState();
    _controller = ScrollController();
    WidgetsBinding.instance.addPostFrameCallback((_) => _checkOverflow());
  }

  void _checkOverflow() {
    if (!_controller.hasClients) return;
    final maxScroll = _controller.position.maxScrollExtent;
    if (maxScroll > 4 && !_scrolling && !_userInteracting) {
      _startScrolling();
    }
  }

  void _startScrolling() {
    if (!_controller.hasClients) return;
    setState(() => _scrolling = true);
    final maxScroll = _controller.position.maxScrollExtent;
    _controller
        .animateTo(
          maxScroll,
          duration: Duration(
            milliseconds: (maxScroll * 35).round().clamp(2000, 8000),
          ),
          curve: Curves.easeInOut,
        )
        .then((_) {
          if (!_userInteracting && mounted) {
            Future.delayed(const Duration(milliseconds: 800), () {
              if (!_userInteracting && _controller.hasClients && mounted) {
                _controller.animateTo(
                  0,
                  duration: Duration(
                    milliseconds: (maxScroll * 35).round().clamp(2000, 8000),
                  ),
                  curve: Curves.easeInOut,
                );
              }
            });
          }
        });
  }

  @override
  void didUpdateWidget(MarqueeText oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.text != widget.text) {
      _scrolling = false;
      _userInteracting = false;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_controller.hasClients) _controller.jumpTo(0);
        _checkOverflow();
      });
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // 仅在文本溢出时启用跑马灯（ShaderMask 开销大，短词用普通文本即可）
    return LayoutBuilder(
      builder: (context, constraints) {
        final style =
            widget.style ??
            TextStyle(
              fontFamily: AppTheme.fontHeading,
              fontSize: widget.fontSize,
              color: AppColors.pencil,
            );
        final painter = TextPainter(
          text: TextSpan(text: widget.text, style: style),
          maxLines: 1,
          textDirection: TextDirection.ltr,
        )..layout(maxWidth: constraints.maxWidth);
        final overflows = painter.didExceedMaxLines;
        painter.dispose();
        if (!overflows) {
          return Text(
            widget.text,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: style,
          );
        }
        return GestureDetector(
          onHorizontalDragStart: (_) {
            _userInteracting = true;
            setState(() => _scrolling = false);
          },
          onHorizontalDragEnd: (_) {
            _userInteracting = false;
            Future.delayed(const Duration(seconds: 2), () {
              if (!_userInteracting && mounted) _checkOverflow();
            });
          },
          child: ShaderMask(
            shaderCallback: (bounds) {
              return LinearGradient(
                colors: [
                  Colors.transparent,
                  Colors.black,
                  Colors.black,
                  Colors.transparent,
                ],
                stops: const [0.0, 0.08, 0.92, 1.0],
              ).createShader(bounds);
            },
            blendMode: BlendMode.srcIn,
            child: SingleChildScrollView(
              controller: _controller,
              scrollDirection: Axis.horizontal,
              child: Text(widget.text, style: style),
            ),
          ),
        );
      },
    );
  }
}

/// 纵向自动滚动文本（图集描述等）。
///
/// 内容超出 [height] 时：缓慢滚动到底 → 停留 → 滚回顶部，循环。
/// [userInterruptible] 为 true 时，用户按住/拖动可暂停，松手 2 秒后自动恢复
/// （与单词跑马灯 MarqueeText 一致）；为 false 则纯自动（展示大屏壁纸场景）。
/// 溢出时上下边缘渐隐到 [fadeColor]。
class AutoScrollText extends StatefulWidget {
  final String text;
  final TextStyle style;
  final double height;
  final EdgeInsets padding;
  final bool userInterruptible;
  final Color fadeColor;

  const AutoScrollText({
    super.key,
    required this.text,
    required this.style,
    this.height = 68,
    this.padding = const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
    this.userInterruptible = true,
    this.fadeColor = Colors.black,
  });

  @override
  State<AutoScrollText> createState() => _AutoScrollTextState();
}

class _AutoScrollTextState extends State<AutoScrollText> {
  final ScrollController _controller = ScrollController();
  bool _scrolling = false;
  bool _userInteracting = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _checkOverflow());
  }

  @override
  void didUpdateWidget(AutoScrollText oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.text != widget.text) {
      _scrolling = false;
      _userInteracting = false;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_controller.hasClients) _controller.jumpTo(0);
        _checkOverflow();
      });
    }
  }

  void _checkOverflow() {
    if (!_controller.hasClients) return;
    final max = _controller.position.maxScrollExtent;
    if (max > 4 && !_scrolling && !_userInteracting) _startScrolling();
  }

  Future<void> _startScrolling() async {
    if (!_controller.hasClients) return;
    setState(() => _scrolling = true);
    final max = _controller.position.maxScrollExtent;
    final dur = Duration(milliseconds: (max * 35).round().clamp(2000, 9000));
    await _controller.animateTo(max, duration: dur, curve: Curves.easeInOut);
    if (!mounted || _userInteracting) return;
    await Future.delayed(const Duration(milliseconds: 900));
    if (!mounted || _userInteracting || !_controller.hasClients) return;
    await _controller.animateTo(0, duration: dur, curve: Curves.easeInOut);
    if (!mounted || _userInteracting) return;
    setState(() => _scrolling = false);
    Future.delayed(const Duration(milliseconds: 300), () {
      if (mounted && !_userInteracting) _checkOverflow();
    });
  }

  void _onDragStart(DragStartDetails _) {
    _userInteracting = true;
    if (_scrolling) setState(() => _scrolling = false);
  }

  void _onDragEnd(DragEndDetails _) {
    _userInteracting = false;
    Future.delayed(const Duration(seconds: 2), () {
      if (mounted && !_userInteracting) _checkOverflow();
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final maxW = constraints.maxWidth;
        final painter = TextPainter(
          text: TextSpan(text: widget.text, style: widget.style),
          textDirection: TextDirection.ltr,
          textAlign: TextAlign.center,
        )..layout(maxWidth: maxW);
        final overflowH =
            painter.height + widget.padding.vertical > widget.height;
        painter.dispose();
        if (!overflowH) {
          return Container(
            height: widget.height,
            alignment: Alignment.center,
            padding: widget.padding,
            child: Text(
              widget.text,
              textAlign: TextAlign.center,
              style: widget.style,
            ),
          );
        }
        Widget scroll = SingleChildScrollView(
          controller: _controller,
          padding: widget.padding,
          child: Text(
            widget.text,
            textAlign: TextAlign.center,
            style: widget.style,
          ),
        );
        if (widget.userInterruptible) {
          scroll = GestureDetector(
            onVerticalDragStart: _onDragStart,
            onVerticalDragEnd: _onDragEnd,
            child: scroll,
          );
        }
        return SizedBox(
          height: widget.height,
          child: ClipRect(
            child: Stack(
              children: [
                Positioned.fill(child: scroll),
                Positioned(
                  top: 0,
                  left: 0,
                  right: 0,
                  height: 14,
                  child: _fade(true),
                ),
                Positioned(
                  bottom: 0,
                  left: 0,
                  right: 0,
                  height: 14,
                  child: _fade(false),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _fade(bool fromTop) {
    return IgnorePointer(
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: fromTop ? Alignment.topCenter : Alignment.bottomCenter,
            end: fromTop ? Alignment.bottomCenter : Alignment.topCenter,
            colors: [widget.fadeColor, widget.fadeColor.withValues(alpha: 0)],
          ),
        ),
      ),
    );
  }
}

class WordCard extends StatelessWidget {
  final String word;
  final String meaning;
  final String pos;
  final bool isWrong;
  final bool showRemoveButton;
  final VoidCallback? onToggleWrong;
  final VoidCallback? onTap;
  final bool highlight;

  const WordCard({
    super.key,
    required this.word,
    required this.meaning,
    this.pos = '',
    this.isWrong = false,
    this.showRemoveButton = false,
    this.onToggleWrong,
    this.onTap,
    this.highlight = false,
  });

  double _wordFontSize(String text) {
    final len = text.length;
    if (len > 16) return 18;
    if (len > 12) return 22;
    if (len > 9) return 26;
    if (len > 7) return 30;
    return 34;
  }

  @override
  Widget build(BuildContext context) {
    final card = Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(
        color: highlight ? AppColors.postIt : AppColors.white,
        borderRadius: AppTheme.wobblyRadius,
        border: Border.all(
          color: isWrong ? AppColors.red : AppColors.pencil,
          width: isWrong ? 3 : 2,
        ),
        boxShadow: [
          BoxShadow(
            color: isWrong ? AppColors.red : AppColors.pencil,
            offset: const Offset(3, 3),
            blurRadius: 0,
          ),
        ],
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Expanded(
                child: MarqueeText(text: word, fontSize: _wordFontSize(word)),
              ),
              if (pos.isNotEmpty) ...[
                const SizedBox(width: 6),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 6,
                    vertical: 2,
                  ),
                  decoration: BoxDecoration(
                    color: AppColors.blue.withValues(alpha: 0.1),
                    borderRadius: AppTheme.wobblyRadius,
                  ),
                  child: Text(
                    pos,
                    style: TextStyle(
                      fontFamily: AppTheme.fontHeading,
                      fontSize: 13,
                      color: AppColors.blue,
                    ),
                  ),
                ),
              ],
              const SizedBox(width: 4),
              _buildSpeaker(context),
              if (onToggleWrong != null || showRemoveButton) ...[
                const SizedBox(width: 4),
                _buildWrongButton(),
              ],
            ],
          ),
          const SizedBox(height: 4),
          Text(
            meaning,
            style: TextStyle(
              fontFamily: AppTheme.fontBody,
              fontSize: 16,
              color: AppColors.pencil.withValues(alpha: 0.7),
            ),
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );
    if (onTap != null) {
      return GestureDetector(onTap: onTap, child: card);
    }
    return card;
  }

  Widget _buildSpeaker(BuildContext context) {
    return GestureDetector(
      onTap: () async {
        final tts = TTSService();
        final ok = await tts.speak(word);
        if (!ok && context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(tts.lastError ?? '发音不可用'),
              duration: const Duration(seconds: 2),
              behavior: SnackBarBehavior.floating,
            ),
          );
        }
      },
      child: Container(
        padding: const EdgeInsets.all(5),
        decoration: BoxDecoration(
          color: AppColors.white,
          border: Border.all(color: AppColors.pencil, width: 2),
          borderRadius: AppTheme.wobblySm,
          boxShadow: AppTheme.hardShadowSm,
        ),
        child: const Icon(Icons.volume_up, size: 16, color: AppColors.blue),
      ),
    );
  }

  Widget _buildWrongButton() {
    if (showRemoveButton) {
      return GestureDetector(
        onTap: onToggleWrong,
        child: Container(
          padding: const EdgeInsets.all(5),
          decoration: BoxDecoration(
            color: AppColors.red,
            border: Border.all(color: AppColors.pencil, width: 2),
            borderRadius: AppTheme.wobblySm,
            boxShadow: AppTheme.hardShadowSm,
          ),
          child: const Icon(Icons.remove, size: 16, color: AppColors.white),
        ),
      );
    }
    return GestureDetector(
      onTap: onToggleWrong,
      child: Container(
        padding: const EdgeInsets.all(5),
        decoration: BoxDecoration(
          color: isWrong ? AppColors.red : AppColors.white,
          border: Border.all(color: AppColors.pencil, width: 2),
          borderRadius: AppTheme.wobblySm,
          boxShadow: AppTheme.hardShadowSm,
        ),
        child: Icon(
          isWrong ? Icons.check : Icons.add,
          size: 16,
          color: isWrong ? AppColors.white : AppColors.pencil,
        ),
      ),
    );
  }
}
