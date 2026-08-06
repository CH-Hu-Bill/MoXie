import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/announcement.dart';
import '../providers/auth_provider.dart';
import '../services/announcement_service.dart';
import '../theme/app_theme.dart';
import 'announcement_marquee.dart';

class AnnouncementBanner extends StatefulWidget {
  const AnnouncementBanner({super.key});

  @override
  State<AnnouncementBanner> createState() => _AnnouncementBannerState();
}

class _AnnouncementBannerState extends State<AnnouncementBanner>
    with SingleTickerProviderStateMixin {
  static const double _gap = 40;

  Announcement? _announcement;
  late AnimationController _scrollController;
  bool _dismissed = false;
  bool _animating = false;
  String? _lastShownId;

  @override
  void initState() {
    super.initState();
    _scrollController = AnimationController(vsync: this);
    AnnouncementService.instance.addListener(_onServiceChanged);
    _fetchAnnouncement();
  }

  @override
  void dispose() {
    AnnouncementService.instance.removeListener(_onServiceChanged);
    _scrollController.dispose();
    super.dispose();
  }

  void _onServiceChanged() {
    _applyBanner(AnnouncementService.instance.banner);
  }

  Future<void> _fetchAnnouncement() async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;
    await AnnouncementService.instance.refresh(classId);
    _applyBanner(AnnouncementService.instance.banner);
  }

  Future<void> _applyBanner(Announcement? ann) async {
    if (!mounted) return;
    if (ann == null) {
      setState(() {
        _announcement = null;
        _dismissed = false;
      });
      _stopAnimating();
      return;
    }
    if (ann.id == _lastShownId && _announcement?.id == ann.id) return;
    _lastShownId = ann.id;
    final prefs = await SharedPreferences.getInstance();
    final dismissed = prefs.getString('ann_dismissed_${ann.id}');
    if (dismissed != null) {
      setState(() {
        _dismissed = true;
        _announcement = null;
      });
      _stopAnimating();
      return;
    }
    setState(() {
      _announcement = ann;
      _dismissed = false;
    });
  }

  void _startAnimating(double travel) {
    if (_animating || _announcement == null) return;
    final ms = (travel / 30 * 1000).round().clamp(4000, 40000);
    _scrollController.duration = Duration(milliseconds: ms);
    _scrollController.repeat();
    _animating = true;
  }

  void _stopAnimating() {
    if (_animating) {
      _scrollController.stop();
      _animating = false;
    }
  }

  void _dismiss() async {
    if (_announcement == null) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString('ann_dismissed_${_announcement!.id}', '1');
    setState(() {
      _dismissed = true;
      _announcement = null;
    });
    _stopAnimating();
  }

  Color _parseColor(String hex) {
    hex = hex.replaceFirst('#', '');
    if (hex.length == 3) {
      hex = hex.split('').map((c) => '$c$c').join();
    }
    if (hex.length == 6) hex = 'FF$hex';
    return Color(int.parse(hex, radix: 16));
  }

  @override
  Widget build(BuildContext context) {
    if (_announcement == null || _dismissed) return const SizedBox.shrink();

    final ann = _announcement!;
    final color = _parseColor(ann.color);
    final bgColor = color.withValues(alpha: 0.12);

    return Container(
      height: 30,
      decoration: BoxDecoration(
        color: bgColor,
        border: Border(
          bottom: BorderSide(color: color.withValues(alpha: 0.3), width: 1),
        ),
      ),
      child: Row(
        children: [
          const SizedBox(width: 12),
          Expanded(
            child: LayoutBuilder(
              builder: (context, constraints) {
                final availableWidth = constraints.maxWidth;
                final textWidth = _textWidth(ann.content, context);
                final overflow = textWidth > availableWidth;
                final textStyle = TextStyle(
                  color: color,
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  fontFamily: AppTheme.fontBody,
                );
                if (overflow) {
                  WidgetsBinding.instance.addPostFrameCallback((_) {
                    if (mounted) _startAnimating(textWidth + _gap);
                  });
                } else {
                  _stopAnimating();
                }

                if (!overflow) {
                  return Align(
                    alignment: Alignment.center,
                    child: Text(
                      ann.content,
                      style: textStyle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  );
                }

                return BannerMarquee(
                  text: ann.content,
                  style: textStyle,
                  animation: _scrollController,
                  textWidth: textWidth,
                );
              },
            ),
          ),
          if (ann.allowClose)
            GestureDetector(
              onTap: _dismiss,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Icon(Icons.close, size: 16, color: color.withValues(alpha: 0.6)),
              ),
            ),
        ],
      ),
    );
  }

  double _textWidth(String text, BuildContext context) {
    final tp = TextPainter(
      text: TextSpan(
        text: text,
        style: const TextStyle(
          fontSize: 13,
          fontWeight: FontWeight.w600,
          fontFamily: AppTheme.fontBody,
        ),
      ),
      maxLines: 1,
      textDirection: TextDirection.ltr,
      textScaler: MediaQuery.textScalerOf(context),
    )..layout();
    return tp.width;
  }
}
