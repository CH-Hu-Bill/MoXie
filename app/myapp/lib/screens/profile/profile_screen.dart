import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/theme/app_theme.dart';
import 'package:listenwrite/config/api_config.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  String? _currentClassId;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final auth = context.read<AuthProvider>();
      setState(() {
        _currentClassId = auth.user?.classIds.isNotEmpty == true
            ? auth.user!.classIds.first
            : null;
      });
    });
  }

  Future<void> _checkUpdate() async {
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('check_version', {
        'current_version': '1.0.0',
      });
      if (result['success'] == true && mounted) {
        final data = result['data'] ?? {};
        if (data['has_update'] == true) {
          showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              backgroundColor: HandDrawnTheme.warmPaper,
              shape: RoundedRectangleBorder(
                borderRadius: HandDrawnTheme.wobblyRadiusMd,
                side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
              ),
              title: Text(
                '发现新版本',
                style: TextStyle(
                  fontFamily: 'Kalam',
                  fontWeight: FontWeight.w700,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              content: Text(
                '最新版本: ${data['latest']}\n${data['notes'] ?? ''}',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  fontSize: 16,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: Text(
                    '知道了',
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      color: HandDrawnTheme.blue,
                    ),
                  ),
                ),
              ],
            ),
          );
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: const Text('已是最新版本'),
              backgroundColor: HandDrawnTheme.blue,
            ),
          );
        }
      }
    } catch (_) {}
  }

  Future<void> _toggleConsent() async {
    if (_currentClassId == null) return;
    final auth = context.read<AuthProvider>();
    final consentMap = auth.user?.consentMap ?? {};
    final current = consentMap[_currentClassId!] ?? false;

    final allow = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: HandDrawnTheme.warmPaper,
        shape: RoundedRectangleBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusMd,
          side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
        ),
        title: Text(
          'Vlog授权',
          style: TextStyle(
            fontFamily: 'Kalam',
            fontWeight: FontWeight.w700,
            color: HandDrawnTheme.pencil,
          ),
        ),
        content: Text(
          current ? '确定要取消Vlog公开授权吗？' : '确定要允许其他同学查看你的Vlog吗？',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              '取消',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.pencil,
              ),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              '确认',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.blue,
              ),
            ),
          ),
        ],
      ),
    );

    if (allow == true) {
      try {
        await auth.api.post('set_consent', {
          'class_id': _currentClassId!,
          'consent': current ? '0' : '1',
        });
        await auth.refreshProfile();
      } catch (_) {}
    }
  }

  Future<void> _logout() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: HandDrawnTheme.warmPaper,
        shape: RoundedRectangleBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusMd,
          side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
        ),
        title: Text(
          '退出登录',
          style: TextStyle(
            fontFamily: 'Kalam',
            fontWeight: FontWeight.w700,
            color: HandDrawnTheme.pencil,
          ),
        ),
        content: Text(
          '确定要退出登录吗？',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              '取消',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.pencil,
              ),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              '退出',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.accent,
              ),
            ),
          ),
        ],
      ),
    );

    if (confirm == true) {
      final auth = context.read<AuthProvider>();
      await auth.logout();
      if (mounted) {
        Navigator.of(context).pushReplacementNamed('/login');
      }
    }
  }

  Future<void> _deleteAccount() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: HandDrawnTheme.warmPaper,
        shape: RoundedRectangleBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusMd,
          side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
        ),
        title: Text(
          '注销账号',
          style: TextStyle(
            fontFamily: 'Kalam',
            fontWeight: FontWeight.w700,
            color: HandDrawnTheme.accent,
          ),
        ),
        content: Text(
          '此操作不可撤销，确定要注销账号吗？',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil,
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text(
              '取消',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.pencil,
              ),
            ),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              '确认注销',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.accent,
              ),
            ),
          ),
        ],
      ),
    );

    if (confirm == true) {
      final auth = context.read<AuthProvider>();
      await auth.deleteAccount();
      if (mounted) {
        Navigator.of(context).pushReplacementNamed('/login');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = auth.user;
    final consentMap = user?.consentMap ?? {};
    final currentConsent = _currentClassId != null
        ? (consentMap[_currentClassId!] ?? false)
        : false;

    if (user == null) {
      return const Center(child: CircularProgressIndicator());
    }

    return ListView(
      padding: const EdgeInsets.only(bottom: 32),
      children: [
        const SizedBox(height: 24),
        HandDrawnCard(
          decoration: 'tack',
          child: Column(
            children: [
              Container(
                width: 80,
                height: 80,
                decoration: BoxDecoration(
                  color: HandDrawnTheme.postItYellow,
                  borderRadius: HandDrawnTheme.wobblyRadius,
                  border: Border.all(
                      color: HandDrawnTheme.pencil, width: 3),
                ),
                child: Center(
                  child: Text(
                    user.name.isNotEmpty ? user.name[0].toUpperCase() : '?',
                    style: TextStyle(
                      fontFamily: 'Kalam',
                      fontSize: 40,
                      fontWeight: FontWeight.w700,
                      color: HandDrawnTheme.pencil,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              Text(
                user.name,
                style: TextStyle(
                  fontFamily: 'Kalam',
                  fontSize: 24,
                  fontWeight: FontWeight.w700,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              Text(
                '已绑定 ${user.classIds.length} 个班级',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  fontSize: 14,
                  color: HandDrawnTheme.pencil.withValues(alpha: 0.6),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: 24),
        _buildMenuItem(
          icon: Icons.swap_horiz,
          title: '切换班级',
          onTap: () {
            Navigator.of(context).pushReplacementNamed('/class_selection');
          },
        ),
        _buildMenuItem(
          icon: Icons.visibility,
          title: 'Vlog权限',
          subtitle: currentConsent ? '已公开' : '未公开',
          onTap: _toggleConsent,
        ),
        _buildMenuItem(
          icon: Icons.system_update,
          title: '检查更新',
          onTap: _checkUpdate,
        ),
        _buildMenuItem(
          icon: Icons.logout,
          title: '退出登录',
          textColor: HandDrawnTheme.accent,
          onTap: _logout,
        ),
        _buildMenuItem(
          icon: Icons.delete_forever,
          title: '注销账号',
          textColor: HandDrawnTheme.accent,
          onTap: _deleteAccount,
        ),
      ],
    );
  }

  Widget _buildMenuItem({
    required IconData icon,
    required String title,
    String? subtitle,
    Color? textColor,
    required VoidCallback onTap,
  }) {
    return HandDrawnCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          Icon(icon, color: textColor ?? HandDrawnTheme.pencil, size: 24),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    fontSize: 18,
                    fontWeight: FontWeight.w700,
                    color: textColor ?? HandDrawnTheme.pencil,
                  ),
                ),
                if (subtitle != null)
                  Text(
                    subtitle,
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      fontSize: 14,
                      color: HandDrawnTheme.pencil.withValues(alpha: 0.5),
                    ),
                  ),
              ],
            ),
          ),
          Icon(Icons.chevron_right, color: HandDrawnTheme.pencil),
        ],
      ),
    );
  }
}