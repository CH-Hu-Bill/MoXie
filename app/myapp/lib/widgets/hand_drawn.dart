import 'dart:io';
import 'dart:math';
import 'package:flutter/material.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:path_provider/path_provider.dart';
import 'package:record/record.dart';
import 'package:just_audio/just_audio.dart';
import '../theme/app_theme.dart';
import '../services/tts_service.dart';
import '../services/storage_service.dart';
import '../services/api_service.dart';
import '../config/api_config.dart';

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
        transform:
            _pressed ? Matrix4.translationValues(3, 3, 0) : Matrix4.identity(),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 10),
          decoration: BoxDecoration(
            color: bgColor,
            borderRadius: AppTheme.wobblyRadius,
            border: Border.all(color: AppColors.pencil, width: 2),
            boxShadow:
                _pressed ? [] : (isDisabled ? null : AppTheme.hardShadowMd),
          ),
          child: Row(
            mainAxisSize:
                widget.fullWidth ? MainAxisSize.max : MainAxisSize.min,
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
    // 字体是异步加载的：冷缓存设备首次用 fallback 字体测量会偏窄，
    // 误判"不溢出"后渲染成普通文本，字体加载完也不会重建 → 滚动失效。
    // 延迟强制重建几次，字体就位后 TextPainter 重测即恢复滚动。
    for (final delay in const [300, 800, 1600]) {
      Future.delayed(Duration(milliseconds: delay), () {
        if (mounted) setState(() {});
      });
    }
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
        final style = widget.style ??
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
        // 切换到滚动分支后 controller 需要一帧才 attach；补一次检测启动滚动
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) _checkOverflow();
        });
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
  bool _hasOverflow = false;

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
      _hasOverflow = false;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_controller.hasClients) _controller.jumpTo(0);
        _checkOverflow();
      });
    }
  }

  void _checkOverflow() {
    if (!_controller.hasClients) return;
    final max = _controller.position.maxScrollExtent;
    if (max > 0.5 && !_scrolling && !_userInteracting) {
      if (!_hasOverflow) setState(() => _hasOverflow = true);
      _startScrolling();
    }
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
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                widget.text,
                textAlign: TextAlign.center,
                style: widget.style,
              ),
              // 底部缓冲：即使溢出很小时末行也能完整显示，不被裁切
              const SizedBox(height: 8),
            ],
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
                // 蒙版仅在确实有溢出时显示（避免短文本末行被渐变挡到）
                if (_hasOverflow) ...[
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

/// 全球发音按钮：
/// - 短按：拉取该单词的班级发音列表（底部弹窗），点选播放
/// - 长按：按住录音（AAC/M4A），松开自动上传（同一单词重复上传自动覆盖）
class GlobePronButton extends StatefulWidget {
  final String classId;
  final String wordId;
  final String word;
  const GlobePronButton({
    super.key,
    required this.classId,
    required this.wordId,
    required this.word,
  });

  @override
  State<GlobePronButton> createState() => _GlobePronButtonState();
}

class _GlobePronButtonState extends State<GlobePronButton> {
  final AudioRecorder _recorder = AudioRecorder();
  final AudioPlayer _player = AudioPlayer();
  bool _recording = false;
  bool _busy = false;

  void _toast(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
      content: Text(msg),
      duration: const Duration(seconds: 2),
      behavior: SnackBarBehavior.floating,
    ));
  }

  Future<void> _startRecording() async {
    if (_busy || _recording) return;
    try {
      if (!await _recorder.hasPermission()) {
        _toast('没有麦克风权限，请在系统设置中允许');
        return;
      }
      final dir = await getTemporaryDirectory();
      final path =
          '${dir.path}/pron_${DateTime.now().millisecondsSinceEpoch}.m4a';
      await _recorder.start(
        const RecordConfig(encoder: AudioEncoder.aacLc),
        path: path,
      );
      if (mounted) setState(() => _recording = true);
      _toast('正在录音，松开结束');
    } catch (e) {
      _toast('录音启动失败');
    }
  }

  Future<void> _stopAndUpload() async {
    if (!_recording) return;
    if (mounted) setState(() {
      _recording = false;
      _busy = true;
    });
    String? path;
    try {
      path = await _recorder.stop();
      if (path == null) {
        _toast('录音未保存');
        return;
      }
      final f = File(path);
      if (await f.length() < 2000) {
        _toast('录音太短，长按地球按钮重录');
        return;
      }
      await ApiService()
          .uploadPronunciation(widget.classId, widget.wordId, f, 'p.m4a');
      // 上传成功：清列表缓存，下次打开拉到最新
      await StorageService()
          .clearPronunciationCache(widget.classId, widget.wordId);
      _toast('发音已上传，同学们都能听到啦');
    } on ApiException catch (e) {
      _toast(e.message);
    } catch (e) {
      _toast('上传失败，请重试');
    } finally {
      if (path != null) {
        try { File(path).delete(); } catch (_) {}
      }
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _showList() async {
    if (_busy || _recording) return;
    // 立即弹出面板（内部自带加载态/缓存），不再让用户干等网络
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: AppColors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
        side: BorderSide(color: AppColors.pencil, width: 2),
      ),
      builder: (ctx) => _PronSheet(classId: widget.classId, wordId: widget.wordId, word: widget.word),
    );
  }

  @override
  void dispose() {
    _recorder.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final color = _recording ? AppColors.red : AppColors.blue;
    return GestureDetector(
      onTap: _showList,
      onLongPressStart: (_) => _startRecording(),
      onLongPressEnd: (_) => _stopAndUpload(),
      onLongPressCancel: _stopAndUpload,
      child: Container(
        padding: const EdgeInsets.all(5),
        decoration: BoxDecoration(
          color: _recording ? AppColors.red.withValues(alpha: 0.12) : AppColors.white,
          border: Border.all(color: color, width: 2),
          borderRadius: AppTheme.wobblySm,
          boxShadow: AppTheme.hardShadowSm,
        ),
        child: _busy
            ? const SizedBox(
                width: 16,
                height: 16,
                child: Padding(
                  padding: EdgeInsets.all(2),
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              )
            : Icon(Icons.public, size: 16, color: color),
      ),
    );
  }
}

/// 全球发音底部弹窗：
/// - 布局：顶部单词大字（过长自动缩字不撞边界），下方「全球发音」副标题
/// - 打开即显示加载态（不干等网络）；有缓存先展示缓存并静默刷新
/// - 列表缓存（StorageService，TTL 5 分钟）+ 音频文件缓存（flutter_cache_manager）
class _PronSheet extends StatefulWidget {
  final String classId;
  final String wordId;
  final String word;
  const _PronSheet({required this.classId, required this.wordId, required this.word});

  @override
  State<_PronSheet> createState() => _PronSheetState();
}

class _PronSheetState extends State<_PronSheet> {
  final AudioPlayer _player = AudioPlayer();
  List<dynamic>? _items;
  bool _loading = true;
  bool _refreshing = false; // 有缓存时的后台静默刷新
  bool _fromCache = false;
  String? _error;
  int _playingIndex = -1;

  @override
  void initState() {
    super.initState();
    final cached =
        StorageService().getPronunciations(widget.classId, widget.wordId);
    if (cached != null) {
      _items = cached;
      _loading = false;
      _fromCache = true;
      _refreshing = true; // 有缓存：先展示，后台拉最新
    }
    _fetch();
  }

  Future<void> _fetch() async {
    try {
      final items =
          await ApiService().getPronunciations(widget.classId, widget.wordId);
      await StorageService()
          .cachePronunciations(widget.classId, widget.wordId, items);
      if (!mounted) return;
      setState(() {
        _items = items;
        _loading = false;
        _refreshing = false;
        _fromCache = false;
        _error = null;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      if (_items != null) {
        // 已有缓存内容：静默失败，仅标记
        setState(() => _refreshing = false);
      } else {
        setState(() {
          _loading = false;
          _error = e.message;
        });
      }
    } catch (e) {
      if (!mounted) return;
      if (_items != null) {
        setState(() => _refreshing = false);
      } else {
        setState(() {
          _loading = false;
          _error = '网络异常，请重试';
        });
      }
    }
  }

  String _fullUrl(String url) => url.startsWith('http')
      ? url
      : '${ApiConfig.baseUrl}/${url.replaceFirst(RegExp(r'^/'), '')}';

  Future<void> _play(int i) async {
    final items = _items;
    if (items == null || i < 0 || i >= items.length) return;
    final url = _fullUrl((items[i]['url'] ?? '') as String);
    if (url.isEmpty || url.endsWith('/')) return;
    setState(() => _playingIndex = i);
    try {
      // 音频缓存：首次下载落盘，之后秒开
      final f = await DefaultCacheManager().getSingleFile(url);
      await _player.stop();
      await _player.setFilePath(f.path);
      await _player.play();
      if (mounted) setState(() => _playingIndex = -1);
    } catch (e) {
      if (mounted) setState(() => _playingIndex = -1);
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('播放失败'),
        duration: Duration(seconds: 2),
        behavior: SnackBarBehavior.floating,
      ));
    }
  }

  @override
  void dispose() {
    _player.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const SizedBox(height: 16),
          // 顶部：单词大字（过长自动缩字，不与边界冲突）
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: FittedBox(
              fit: BoxFit.scaleDown,
              child: ConstrainedBox(
                constraints: BoxConstraints(
                    maxWidth: MediaQuery.of(context).size.width - 32),
                child: Text(
                  widget.word,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    fontFamily: AppTheme.fontHeading,
                    fontSize: 28,
                    color: AppColors.pencil,
                  ),
                ),
              ),
            ),
          ),
          const SizedBox(height: 4),
          // 下方：副标题
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.public,
                  size: 13, color: AppColors.pencil.withValues(alpha: 0.5)),
              const SizedBox(width: 4),
              Text(
                _loading
                    ? '正在加载同学们的发音…'
                    : '全球发音 · ${_items?.length ?? 0} 条' +
                        (_refreshing ? ' · 更新中' : (_fromCache ? ' · 缓存' : '')),
                style: TextStyle(
                  fontSize: 12,
                  color: AppColors.pencil.withValues(alpha: 0.55),
                ),
              ),
            ],
          ),
          const SizedBox(height: 10),
          Flexible(child: _buildBody()),
        ],
      ),
    );
  }

  Widget _buildBody() {
    if (_loading) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 36),
        child: Column(
          children: [
            const CircularProgressIndicator(color: AppColors.blue),
            const SizedBox(height: 12),
            Text('正在加载发音…',
                style: TextStyle(
                    fontSize: 13,
                    color: AppColors.pencil.withValues(alpha: 0.6))),
          ],
        ),
      );
    }
    if (_error != null) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 28),
        child: Column(
          children: [
            Icon(Icons.cloud_off,
                size: 38, color: AppColors.pencil.withValues(alpha: 0.35)),
            const SizedBox(height: 8),
            Text(_error!,
                style: TextStyle(
                    fontSize: 13,
                    color: AppColors.pencil.withValues(alpha: 0.6))),
            const SizedBox(height: 12),
            TextButton.icon(
              onPressed: () {
                setState(() {
                  _loading = true;
                  _error = null;
                });
                _fetch();
              },
              icon: const Icon(Icons.refresh, size: 16),
              label: const Text('重试'),
            ),
          ],
        ),
      );
    }
    final items = _items ?? const [];
    if (items.isEmpty) {
      return Padding(
        padding: const EdgeInsets.symmetric(vertical: 28),
        child: Column(
          children: [
            Icon(Icons.public,
                size: 40, color: AppColors.pencil.withValues(alpha: 0.3)),
            const SizedBox(height: 8),
            Text(
              '还没有人录过这个词\n长按地球按钮，做第一个发音的人',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontSize: 13,
                color: AppColors.pencil.withValues(alpha: 0.6),
                height: 1.6,
              ),
            ),
          ],
        ),
      );
    }
    return ListView.builder(
      shrinkWrap: true,
      padding: const EdgeInsets.fromLTRB(14, 0, 14, 14),
      itemCount: items.length,
      itemBuilder: (ctx, i) => _buildItem(items[i], i),
    );
  }

  Widget _buildItem(dynamic p, int i) {
    final name = (p['name'] ?? '同学') as String;
    final duration = (p['duration'] ?? 0) as num;
    final uploadedAt = (p['uploaded_at'] ?? '') as String;
    final playing = _playingIndex == i;
    return Container(
      margin: const EdgeInsets.only(bottom: 8),
      decoration: BoxDecoration(
        color: playing ? AppColors.blue : AppColors.white,
        border: Border.all(
            color: playing ? AppColors.blue : AppColors.pencil, width: 2),
        borderRadius: AppTheme.wobblySm,
        boxShadow: AppTheme.hardShadowSm,
      ),
      child: ListTile(
        dense: true,
        leading: CircleAvatar(
          radius: 16,
          backgroundColor:
              playing ? AppColors.white : AppColors.blue,
          child: Text(
            name.isNotEmpty ? name.characters.first.toUpperCase() : '同',
            style: TextStyle(
                color: playing ? AppColors.blue : AppColors.white,
                fontSize: 13),
          ),
        ),
        title: Text(name,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
                fontSize: 14,
                color: playing ? AppColors.white : AppColors.pencil)),
        subtitle: Text(
          '$duration s  ·  ${uploadedAt.length >= 16 ? uploadedAt.substring(5, 16) : uploadedAt}',
          style: TextStyle(
              fontSize: 11,
              color: playing
                  ? AppColors.white.withValues(alpha: 0.8)
                  : AppColors.pencil.withValues(alpha: 0.5)),
        ),
        trailing: playing
            ? const SizedBox(
                width: 16,
                height: 16,
                child: Padding(
                  padding: EdgeInsets.all(2),
                  child: CircularProgressIndicator(strokeWidth: 2),
                ),
              )
            : Icon(Icons.play_arrow,
                color: playing ? AppColors.white : AppColors.blue),
        onTap: () => _play(i),
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

  /// 全球发音：班级 id + 单词 id 都传入时才显示地球按钮（默写场景不传即隐藏）
  final String? classId;
  final String? wordId;

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
    this.classId,
    this.wordId,
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
              if (wordId != null && classId != null) ...[
                const SizedBox(width: 4),
                GlobePronButton(
                  key: ValueKey('pron_${classId}_$wordId'),
                  classId: classId!,
                  wordId: wordId!,
                  word: word,
                ),
              ],
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
