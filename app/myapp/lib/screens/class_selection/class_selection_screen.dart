import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../models/class_info.dart';
import '../../widgets/hand_drawn_widgets.dart';
import '../../theme/app_theme.dart';

class ClassSelectionScreen extends StatefulWidget {
  const ClassSelectionScreen({super.key});

  @override
  State<ClassSelectionScreen> createState() => _ClassSelectionScreenState();
}

class _ClassSelectionScreenState extends State<ClassSelectionScreen> {
  List<ClassInfo> _allClasses = [];
  List<ClassInfo> _myClasses = [];
  bool _loading = true;
  String? _error;
  final _passwordController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadClasses();
  }

  @override
  void dispose() {
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _loadClasses() async {
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('get_classes', {});
      if (result['success'] == true) {
        final allList = (result['data'] as List)
            .map((j) => ClassInfo.fromJson(j))
            .toList();
        final myIds = auth.user?.classIds ?? [];
        setState(() {
          _allClasses = allList;
          _myClasses = allList.where((c) => myIds.contains(c.id)).toList();
          _loading = false;
        });
      } else {
        setState(() {
          _error = result['error'] ?? '加载失败';
          _loading = false;
        });
      }
    } catch (e) {
      setState(() {
        _error = '网络连接失败';
        _loading = false;
      });
    }
  }

  Future<void> _bindClass(ClassInfo classInfo) async {
    final auth = context.read<AuthProvider>();

    if (classInfo.hasPassword) {
      final password = await showDialog<String>(
        context: context,
        builder: (ctx) {
          final pwController = TextEditingController();
          return AlertDialog(
            backgroundColor: HandDrawnTheme.warmPaper,
            shape: RoundedRectangleBorder(
              borderRadius: HandDrawnTheme.wobblyRadiusMd,
              side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
            ),
            title: Text(
              '输入班级口令',
              style: TextStyle(
                fontFamily: 'Kalam',
                fontWeight: FontWeight.w700,
                color: HandDrawnTheme.pencil,
              ),
            ),
            content: HandDrawnInput(
              label: '口令',
              hint: '请输入${classInfo.name}的口令',
              controller: pwController,
              obscureText: true,
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: Text(
                  '取消',
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    color: HandDrawnTheme.pencil,
                  ),
                ),
              ),
              TextButton(
                onPressed: () => Navigator.pop(ctx, pwController.text),
                child: Text(
                  '确认',
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    color: HandDrawnTheme.blue,
                  ),
                ),
              ),
            ],
          );
        },
      );

      if (password == null) return;

      final success = await auth.bindClass(classInfo.id, password);
      if (!success && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(auth.error ?? '口令错误'),
            backgroundColor: HandDrawnTheme.accent,
          ),
        );
      }
      return;
    }

    final success = await auth.bindClass(classInfo.id, '');
    if (!success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(auth.error ?? '绑定失败'),
          backgroundColor: HandDrawnTheme.accent,
        ),
      );
    }
  }

  Future<void> _enterClass(ClassInfo classInfo) async {
    final auth = context.read<AuthProvider>();
    await auth.refreshProfile();
    if (mounted) {
      Navigator.of(context).pushReplacementNamed('/home');
    }
  }

  void _showConsentDialog(ClassInfo classInfo) {
    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          backgroundColor: HandDrawnTheme.warmPaper,
          shape: RoundedRectangleBorder(
            borderRadius: HandDrawnTheme.wobblyRadiusMd,
            side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
          ),
          title: Text(
            '授权设置',
            style: TextStyle(
              fontFamily: 'Kalam',
              fontWeight: FontWeight.w700,
              color: HandDrawnTheme.pencil,
            ),
          ),
          content: Text(
            '是否允许其他同学查看你在${classInfo.name}的Vlog？\n（后续可在"我的"中修改）',
            style: TextStyle(
              fontFamily: 'Patrick Hand',
              fontSize: 16,
              color: HandDrawnTheme.pencil,
            ),
          ),
          actions: [
            TextButton(
              onPressed: () {
                Navigator.pop(ctx);
                _setConsentAndEnter(classInfo.id, false);
              },
              child: Text(
                '不允许',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  color: HandDrawnTheme.pencil,
                ),
              ),
            ),
            TextButton(
              onPressed: () {
                Navigator.pop(ctx);
                _setConsentAndEnter(classInfo.id, true);
              },
              child: Text(
                '允许',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  color: HandDrawnTheme.blue,
                ),
              ),
            ),
          ],
        );
      },
    );
  }

  Future<void> _setConsentAndEnter(String classId, bool allow) async {
    final auth = context.read<AuthProvider>();
    try {
      await auth.api.post('set_consent', {
        'class_id': classId,
        'consent': allow ? '1' : '0',
      });
      await auth.refreshProfile();
    } catch (_) {}
    if (mounted) {
      Navigator.of(context).pushReplacementNamed('/home');
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    return HandDrawnScaffold(
      showBackButton: false,
      title: '选择班级',
      body: _loading
          ? const Center(
              child: CircularProgressIndicator(color: HandDrawnTheme.pencil),
            )
          : _error != null
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        _error!,
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 18,
                          color: HandDrawnTheme.pencil,
                        ),
                      ),
                      const SizedBox(height: 16),
                      HandDrawnButton(
                        text: '重试',
                        onPressed: () {
                          setState(() => _loading = true);
                          _loadClasses();
                        },
                      ),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _loadClasses,
                  child: ListView(
                    padding: const EdgeInsets.only(bottom: 32),
                    children: [
                      if (_myClasses.isNotEmpty) ...[
                        Padding(
                          padding: const EdgeInsets.fromLTRB(20, 24, 20, 12),
                          child: Text(
                            '已加入的班级',
                            style: TextStyle(
                              fontFamily: 'Kalam',
                              fontSize: 22,
                              fontWeight: FontWeight.w700,
                              color: HandDrawnTheme.pencil,
                            ),
                          ),
                        ),
                        ..._myClasses.map((c) => _buildClassCard(c, isMy: true)),
                        Padding(
                          padding: const EdgeInsets.fromLTRB(20, 24, 20, 12),
                          child: Text(
                            '全部班级',
                            style: TextStyle(
                              fontFamily: 'Kalam',
                              fontSize: 22,
                              fontWeight: FontWeight.w700,
                              color: HandDrawnTheme.pencil,
                            ),
                          ),
                        ),
                      ],
                      ..._allClasses
                          .where((c) =>
                              !_myClasses.any((mc) => mc.id == c.id))
                          .map((c) => _buildClassCard(c, isMy: false)),
                      if (_allClasses.isEmpty)
                        Padding(
                          padding: const EdgeInsets.all(40),
                          child: Center(
                            child: Text(
                              '暂无班级，请先创建班级\n（可通过网站端创建）',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                fontFamily: 'Patrick Hand',
                                fontSize: 16,
                                color: HandDrawnTheme.pencil
                                    .withValues(alpha: 0.6),
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
    );
  }

  Widget _buildClassCard(ClassInfo classInfo, {required bool isMy}) {
    return HandDrawnCard(
      onTap: isMy ? () => _enterClass(classInfo) : () => _bindClass(classInfo),
      decoration: isMy ? 'tape' : null,
      rotation: isMy ? 0 : (classInfo.id.hashCode % 3 - 1).toDouble(),
      child: Row(
        children: [
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: isMy ? HandDrawnTheme.postItYellow : HandDrawnTheme.muted,
              borderRadius: HandDrawnTheme.wobblyRadiusSm,
              border: Border.all(color: HandDrawnTheme.pencil, width: 2),
            ),
            child: Center(
              child: Text(
                classInfo.name.isNotEmpty ? classInfo.name[0] : '?',
                style: TextStyle(
                  fontFamily: 'Kalam',
                  fontSize: 24,
                  fontWeight: FontWeight.w700,
                  color: HandDrawnTheme.pencil,
                ),
              ),
            ),
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  classInfo.name,
                  style: TextStyle(
                    fontFamily: 'Kalam',
                    fontSize: 20,
                    fontWeight: FontWeight.w700,
                    color: HandDrawnTheme.pencil,
                  ),
                ),
                if (classInfo.hasPassword)
                  Text(
                    '需要口令',
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      fontSize: 14,
                      color: HandDrawnTheme.accent,
                    ),
                  ),
              ],
            ),
          ),
          Icon(
            isMy ? Icons.arrow_forward_ios : Icons.add_circle_outline,
            color: isMy ? HandDrawnTheme.blue : HandDrawnTheme.pencil,
            size: 24,
          ),
        ],
      ),
    );
  }
}