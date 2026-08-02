import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../theme/app_theme.dart';
import 'study/study_screen.dart';
import 'life/life_screen.dart';
import 'search/search_screen.dart';
import 'gallery/gallery_screen.dart';
import 'profile/profile_screen.dart';
import 'class_selection_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _currentIndex = 0;

  final _screens = const [
    StudyScreen(),
    LifeScreen(),
    SearchScreen(),
    GalleryScreen(),
    ProfileScreen(),
  ];

  @override
  void initState() {
    super.initState();
    _checkClassValidity();
  }

  Future<void> _checkClassValidity() async {
    final auth = context.read<AuthProvider>();
    if (auth.currentClassId != null &&
        auth.currentClassId!.isNotEmpty) {
      final ok = await auth.checkClassAuth(auth.currentClassId!);
      if (ok && mounted) return;
      if (!ok && mounted) {
        _showClassExpiredDialog();
      }
    }
  }

  void _showClassExpiredDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => AlertDialog(
        backgroundColor: AppColors.background,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.border, width: 2),
        ),
        title: Text('班级口令已变更', style: AppTheme.headingStyle),
        content: Text(
          '请重新选择班级并输入口令',
          style: AppTheme.bodyStyle.copyWith(fontSize: 16),
        ),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(context);
              Navigator.pushReplacement(
                context,
                MaterialPageRoute(
                    builder: (_) => const ClassSelectionScreen()),
              );
            },
            child: Text('去选择', style: AppTheme.bodyStyle),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: AppColors.cardWhite,
          border: const Border(
            top: BorderSide(color: AppColors.border, width: 2),
          ),
          boxShadow: [
            BoxShadow(
              color: AppColors.foreground.withValues(alpha: 0.1),
              offset: const Offset(0, -2),
              blurRadius: 0,
            ),
          ],
        ),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildNavItem(0, Icons.menu_book, '学习'),
                _buildNavItem(1, Icons.edit_note, '生活'),
                _buildNavItem(2, Icons.search, '搜索'),
                _buildNavItem(3, Icons.photo_library, '画廊'),
                _buildNavItem(4, Icons.person, '我的'),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem(int index, IconData icon, String label) {
    final selected = _currentIndex == index;
    return GestureDetector(
      onTap: () => setState(() => _currentIndex = index),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: selected ? AppColors.postItYellow : Colors.transparent,
          borderRadius: AppTheme.wobblyRadius,
          border: selected
              ? Border.all(color: AppColors.border, width: 2)
              : null,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              icon,
              size: 26,
              color: selected ? AppColors.accent : AppColors.foreground,
            ),
            const SizedBox(height: 2),
            Text(
              label,
              style: AppTheme.bodyStyle.copyWith(
                fontSize: 12,
                fontWeight: selected ? FontWeight.bold : FontWeight.normal,
                color: selected ? AppColors.accent : AppColors.foreground,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
