import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';
import '../class_selection_screen.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  bool _checkingUpdate = false;
  Map<String, dynamic>? _versionInfo;

  @override
  void initState() {
    super.initState();
    _checkVersion();
  }

  Future<void> _checkVersion() async {
    setState(() => _checkingUpdate = true);
    final auth = context.read<AuthProvider>();
    final info = await auth.checkVersion();
    setState(() {
      _versionInfo = info;
      _checkingUpdate = false;
    });
    if (info != null && info['has_update'] == true && mounted) {
      showDialog(
        context: context,
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.background,
          shape: RoundedRectangleBorder(
            borderRadius: AppTheme.wobblyRadius,
            side: const BorderSide(color: AppColors.border, width: 2),
          ),
          title: Text('发现新版本', style: AppTheme.headingStyle),
          content: Text(
            '新版本 ${info['latest']} 可用\n\n请前往 GitHub 下载更新。',
            style: AppTheme.bodyStyle.copyWith(fontSize: 16),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text('知道了', style: AppTheme.bodyStyle),
            ),
          ],
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final user = auth.user;
    return Scaffold(
      appBar: AppBar(title: const Text('我的')),
      body: PaperTexture(
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            HandDrawnCard(
              backgroundColor: AppColors.postItYellow,
              child: Row(
                children: [
                  Container(
                    width: 56,
                    height: 56,
                    decoration: BoxDecoration(
                      color: AppColors.cardWhite,
                      borderRadius: AppTheme.wobblyRadius,
                      border: Border.all(color: AppColors.border, width: 2),
                    ),
                    child: const Icon(Icons.person, size: 36),
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user?.name ?? '未知用户',
                          style: AppTheme.headingStyle.copyWith(fontSize: 24),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          '当前班级：${auth.currentClassName ?? '未选择'}',
                          style: AppTheme.bodyStyle.copyWith(fontSize: 15),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            _buildSection('班级管理', [
              _buildItem(
                icon: Icons.swap_horiz,
                title: '切换班级',
                subtitle: auth.currentClassName ?? '未选择',
                onTap: () {
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) =>
                          const ClassSelectionScreen(fromHome: true),
                    ),
                  );
                },
              ),
              ...auth.myClasses.map((c) {
                    final isCurrent = c['class_id'] == auth.currentClassId;
                    return _buildItem(
                      icon: Icons.class_,
                      title: c['class_name']!,
                      subtitle: isCurrent ? '当前班级' : '点击切换',
                      onTap: isCurrent
                          ? null
                          : () => auth.setCurrentClass(
                              c['class_id']!, c['class_name']!),
                    );
                  }),
            ]),
            const SizedBox(height: 16),
            _buildSection('Vlog 授权', [
              _buildToggleItem(
                icon: Icons.visibility,
                title: '公开我的 Vlog',
                subtitle: '授权后其他用户和班级成员可以查看你的个人史记',
                value: user?.consent ?? false,
                onChanged: (val) => auth.setGlobalConsent(val),
              ),
            ]),
            const SizedBox(height: 16),
            _buildSection('关于', [
              _buildItem(
                icon: Icons.system_update,
                title: '检查更新',
                subtitle: _checkingUpdate
                    ? '检查中...'
                    : _versionInfo != null
                        ? (_versionInfo!['has_update'] == true
                            ? '发现新版本 ${_versionInfo!['latest']}'
                            : '已是最新版本')
                        : '点击检查',
                onTap: _checkVersion,
              ),
              _buildItem(
                icon: Icons.info_outline,
                title: '版本',
                subtitle: '1.0.0',
              ),
            ]),
            const SizedBox(height: 16),
            _buildSection('账号', [
              _buildItem(
                icon: Icons.logout,
                title: '退出登录',
                titleColor: AppColors.accent,
                onTap: () => _confirmLogout(),
              ),
              _buildItem(
                icon: Icons.delete_forever,
                title: '注销账号',
                titleColor: AppColors.accent,
                onTap: () => _confirmDelete(),
              ),
            ]),
            const SizedBox(height: 32),
            Center(
              child: Text(
                '默写史记 ListenWrite',
                style: AppTheme.bodyStyle.copyWith(
                  fontSize: 14,
                  color: AppColors.foreground.withValues(alpha: 0.3),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSection(String title, List<Widget> children) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        StickyNote(text: title),
        const SizedBox(height: 8),
        HandDrawnCard(
          padding: const EdgeInsets.symmetric(vertical: 4),
          child: Column(children: children),
        ),
      ],
    );
  }

  Widget _buildItem({
    required IconData icon,
    required String title,
    String? subtitle,
    Color? titleColor,
    VoidCallback? onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: AppTheme.wobblyRadius,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
        child: Row(
          children: [
            Icon(icon, size: 24, color: titleColor ?? AppColors.foreground),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    title,
                    style: AppTheme.bodyStyle.copyWith(
                      fontSize: 17,
                      fontWeight: FontWeight.bold,
                      color: titleColor ?? AppColors.foreground,
                    ),
                  ),
                  if (subtitle != null)
                    Text(
                      subtitle,
                      style: AppTheme.bodyStyle.copyWith(
                        fontSize: 14,
                        color: AppColors.foreground.withValues(alpha: 0.5),
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                ],
              ),
            ),
            if (onTap != null)
              const Icon(Icons.chevron_right,
                  color: AppColors.foreground, size: 20),
          ],
        ),
      ),
    );
  }

  Widget _buildToggleItem({
    required IconData icon,
    required String title,
    required String subtitle,
    required bool value,
    required ValueChanged<bool> onChanged,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 14),
      child: Row(
        children: [
          Icon(icon, size: 24),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(title,
                    style: AppTheme.bodyStyle.copyWith(
                        fontSize: 17, fontWeight: FontWeight.bold)),
                Text(subtitle,
                    style: AppTheme.bodyStyle.copyWith(
                        fontSize: 14,
                        color: AppColors.foreground.withValues(alpha: 0.5))),
              ],
            ),
          ),
          Switch(
            value: value,
            onChanged: onChanged,
            activeColor: AppColors.accent,
          ),
        ],
      ),
    );
  }

  void _confirmLogout() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.background,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.border, width: 2),
        ),
        title: Text('退出登录', style: AppTheme.headingStyle),
        content: Text('确定要退出登录吗？',
            style: AppTheme.bodyStyle.copyWith(fontSize: 16)),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('取消', style: AppTheme.bodyStyle),
          ),
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              context.read<AuthProvider>().logout();
            },
            child: Text('退出', style: AppTheme.bodyStyle),
          ),
        ],
      ),
    );
  }

  void _confirmDelete() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.background,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.border, width: 2),
        ),
        title: Text('注销账号',
            style: AppTheme.headingStyle.copyWith(color: AppColors.accent)),
        content: Text(
          '注销后账号数据将永久删除，无法恢复。确定要注销吗？',
          style: AppTheme.bodyStyle.copyWith(fontSize: 16),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('取消', style: AppTheme.bodyStyle),
          ),
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              context.read<AuthProvider>().deleteAccount();
            },
            child: Text('确定注销',
                style: AppTheme.bodyStyle.copyWith(color: AppColors.accent)),
          ),
        ],
      ),
    );
  }
}
