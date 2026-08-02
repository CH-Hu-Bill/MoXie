import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../models/class_info.dart';
import '../providers/auth_provider.dart';
import '../theme/app_theme.dart';
import '../widgets/hand_drawn.dart';
import 'home_screen.dart';

class ClassSelectionScreen extends StatefulWidget {
  final bool fromHome;

  const ClassSelectionScreen({super.key, this.fromHome = false});

  @override
  State<ClassSelectionScreen> createState() => _ClassSelectionScreenState();
}

class _ClassSelectionScreenState extends State<ClassSelectionScreen> {
  List<ClassInfo> _allClasses = [];
  bool _loading = true;
  String? _error;
  final _passwordController = TextEditingController();
  String? _selectedClassId;
  String? _selectedClassName;

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
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final auth = context.read<AuthProvider>();
      _allClasses = await auth.loadAllClasses();
    } catch (e) {
      _error = e.toString();
    }
    setState(() => _loading = false);
  }

  Future<void> _tryEnterClass(ClassInfo cls) async {
    final auth = context.read<AuthProvider>();

    final isBound = auth.myClasses.any((c) => c['class_id'] == cls.id);

    if (isBound) {
      final ok = await auth.checkClassAuth(cls.id);
      if (ok) {
        await auth.setCurrentClass(cls.id, cls.name);
        _navigateHome();
        return;
      }
    }

    if (!cls.hasPassword) {
      final success = await auth.bindClass(cls.id, '');
      if (success) {
        _navigateHome();
      } else {
        _showError(auth.error ?? '绑定失败');
      }
      return;
    }

    setState(() {
      _selectedClassId = cls.id;
      _selectedClassName = cls.name;
      _passwordController.clear();
    });

    if (mounted) {
      showDialog(
        context: context,
        barrierDismissible: false,
        builder: (_) => AlertDialog(
          backgroundColor: AppColors.background,
          shape: RoundedRectangleBorder(
            borderRadius: AppTheme.wobblyRadius,
            side: const BorderSide(color: AppColors.border, width: 2),
          ),
          title: Text('输入班级口令', style: AppTheme.headingStyle),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                cls.name,
                style: AppTheme.bodyStyle.copyWith(fontSize: 18),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _passwordController,
                obscureText: true,
                autofocus: true,
                decoration: const InputDecoration(hintText: '班级口令'),
                onSubmitted: (_) {
                  Navigator.pop(context);
                  _submitPassword();
                },
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text('取消', style: AppTheme.bodyStyle),
            ),
            TextButton(
              onPressed: () {
                Navigator.pop(context);
                _submitPassword();
              },
              child: Text('确定', style: AppTheme.bodyStyle),
            ),
          ],
        ),
      );
    }
  }

  Future<void> _submitPassword() async {
    if (_selectedClassId == null) return;
    final auth = context.read<AuthProvider>();
    final success =
        await auth.bindClass(_selectedClassId!, _passwordController.text);
    if (success) {
      _navigateHome();
    } else {
      _showError(auth.error ?? '口令错误');
    }
  }

  void _navigateHome() {
    if (widget.fromHome) {
      Navigator.pop(context);
    } else {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const HomeScreen()),
      );
    }
  }

  void _showError(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg),
        backgroundColor: AppColors.accent,
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      appBar: AppBar(
        title: const Text('选择班级'),
        leading: widget.fromHome
            ? IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: () => Navigator.pop(context),
              )
            : null,
      ),
      body: PaperTexture(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(
                    child: EmptyState(
                      message: _error!,
                      icon: Icons.error_outline,
                    ),
                  )
                : RefreshIndicator(
                    onRefresh: _loadClasses,
                    child: ListView(
                      padding: const EdgeInsets.all(16),
                      children: [
                        if (auth.myClasses.isNotEmpty) ...[
                          StickyNote(text: '已加入的班级'),
                          const SizedBox(height: 12),
                          ...auth.myClasses.map((c) => Padding(
                                padding: const EdgeInsets.only(bottom: 12),
                                child: HandDrawnCard(
                                  onTap: () => _tryEnterClass(ClassInfo(
                                    id: c['class_id']!,
                                    name: c['class_name']!,
                                    hasPassword: false,
                                  )),
                                  rotation: 0.5,
                                  child: Row(
                                    children: [
                                      Icon(Icons.class_,
                                          size: 32, color: AppColors.accent),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Text(
                                          c['class_name']!,
                                          style: AppTheme.bodyStyle.copyWith(
                                            fontSize: 20,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ),
                                      const Icon(Icons.chevron_right),
                                    ],
                                  ),
                                ),
                              )),
                          const SizedBox(height: 20),
                          const Divider(
                            color: AppColors.border,
                            thickness: 2,
                            indent: 20,
                            endIndent: 20,
                          ),
                          const SizedBox(height: 20),
                        ],
                        StickyNote(
                          text: '全部班级',
                          color: AppColors.postItYellow,
                          rotation: 1,
                        ),
                        const SizedBox(height: 12),
                        ..._allClasses.map((cls) {
                          final isBound = auth.myClasses
                              .any((c) => c['class_id'] == cls.id);
                          return Padding(
                            padding: const EdgeInsets.only(bottom: 12),
                            child: HandDrawnCard(
                              onTap: () => _tryEnterClass(cls),
                              backgroundColor: isBound
                                  ? AppColors.postItYellow.withValues(
                                      alpha: 0.3)
                                  : null,
                              child: Row(
                                children: [
                                  Icon(
                                    cls.hasPassword
                                        ? Icons.lock_outline
                                        : Icons.class_outlined,
                                    size: 32,
                                    color: AppColors.secondaryAccent,
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          cls.name,
                                          style: AppTheme.bodyStyle.copyWith(
                                            fontSize: 18,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                        if (isBound)
                                          Text(
                                            '已加入',
                                            style: AppTheme.bodyStyle.copyWith(
                                              fontSize: 14,
                                              color: AppColors.secondaryAccent,
                                            ),
                                          ),
                                      ],
                                    ),
                                  ),
                                  if (cls.hasPassword)
                                    const Icon(Icons.key,
                                        color: AppColors.accent, size: 20),
                                ],
                              ),
                            ),
                          );
                        }),
                      ],
                    ),
                  ),
      ),
    );
  }
}
