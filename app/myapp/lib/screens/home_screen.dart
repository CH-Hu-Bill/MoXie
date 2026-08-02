import 'dart:async';
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
  bool _classExpiredHandled = false;
  Timer? _classCheckTimer;

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
    WidgetsBinding.instance.addPostFrameCallback((_) => _checkClassValidity());
    _classCheckTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) _checkClassValidity();
    });
  }

  @override
  void dispose() {
    _classCheckTimer?.cancel();
    super.dispose();
  }

  Future<void> _checkClassValidity() async {
    final auth = context.read<AuthProvider>();
    if (auth.currentClassId != null && auth.currentClassId!.isNotEmpty) {
      final ok = await auth.checkClassAuth(auth.currentClassId!);
      if (ok || !mounted) return;
      _showClassExpiredDialog();
    }
  }

  void _showClassExpiredDialog() {
    if (_classExpiredHandled) return;
    _classExpiredHandled = true;
    _classCheckTimer?.cancel();
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.paper,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.pencil, width: 2),
        ),
        title: Text('班级口令已失效', style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
        content: Text('班级口令已被修改或班级已删除，请重新选择班级',
            style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 16)),
        actions: [
          TextButton(
            onPressed: () {
              Navigator.pop(ctx);
              context.read<AuthProvider>().handleClassAuthExpired();
            },
            child: Text('去选择', style: TextStyle(fontFamily: AppTheme.fontBody)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();

    if (auth.currentClassId == null || auth.currentClassId!.isEmpty) {
      return const ClassSelectionScreen(fromHome: true);
    }

    return Scaffold(
      body: IndexedStack(
        index: _currentIndex,
        children: _screens,
      ),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(
          color: AppColors.white,
          border: const Border(top: BorderSide(color: AppColors.pencil, width: 2)),
          boxShadow: [
            BoxShadow(
              color: AppColors.pencil.withValues(alpha: 0.1),
              offset: const Offset(0, -2),
              blurRadius: 0,
            ),
          ],
        ),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildNavItem(0, Icons.menu_book),
                _buildNavItem(1, Icons.edit_note),
                _buildNavItem(2, Icons.search),
                _buildNavItem(3, Icons.photo_library),
                _buildNavItem(4, Icons.person),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildNavItem(int index, IconData icon) {
    final selected = _currentIndex == index;
    return GestureDetector(
      onTap: () => setState(() => _currentIndex = index),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 150),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        decoration: BoxDecoration(
          color: selected ? AppColors.postIt : Colors.transparent,
          borderRadius: AppTheme.wobblyRadius,
          border: selected ? Border.all(color: AppColors.pencil, width: 2) : null,
        ),
        child: Icon(
          icon,
          size: 28,
          color: selected ? AppColors.red : AppColors.pencil,
        ),
      ),
    );
  }
}