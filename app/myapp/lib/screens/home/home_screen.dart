import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/theme/app_theme.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/screens/study/study_screen.dart';
import 'package:listenwrite/screens/life/life_screen.dart';
import 'package:listenwrite/screens/search/search_screen.dart';
import 'package:listenwrite/screens/gallery/gallery_screen.dart';
import 'package:listenwrite/screens/profile/profile_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _currentIndex = 0;

  final _pages = const [
    StudyScreen(),
    LifeScreen(),
    SearchScreen(),
    GalleryScreen(),
    ProfileScreen(),
  ];

  final _titles = const ['学习', '生活', '搜索', '画廊', '我的'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _checkClasses());
  }

  Future<void> _checkClasses() async {
    final auth = context.read<AuthProvider>();
    final classIds = auth.user?.classIds ?? [];
    if (classIds.isEmpty) {
      if (mounted) {
        Navigator.of(context).pushReplacementNamed('/class_selection');
      }
      return;
    }

    for (final cid in classIds) {
      final ok = await auth.checkClass(cid);
      if (!ok && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Text('班级口令已变更，请重新进入'),
            backgroundColor: HandDrawnTheme.accent,
            action: SnackBarAction(
              label: '去选择',
              textColor: Colors.white,
              onPressed: () {
                Navigator.of(context).pushReplacementNamed('/class_selection');
              },
            ),
          ),
        );
        break;
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return HandDrawnScaffold(
      title: _titles[_currentIndex],
      showBackButton: false,
      body: _pages[_currentIndex],
      bottomNavigationBar: Container(
        decoration: const BoxDecoration(
          border: Border(
            top: BorderSide(color: HandDrawnTheme.pencil, width: 2),
          ),
        ),
        child: BottomNavigationBar(
          currentIndex: _currentIndex,
          onTap: (index) => setState(() => _currentIndex = index),
          backgroundColor: HandDrawnTheme.warmPaper,
          selectedItemColor: HandDrawnTheme.pencil,
          unselectedItemColor: HandDrawnTheme.muted,
          type: BottomNavigationBarType.fixed,
          elevation: 0,
          items: [
            _buildNavItem(Icons.school_outlined, Icons.school, '学习'),
            _buildNavItem(Icons.auto_stories_outlined, Icons.auto_stories, '生活'),
            _buildNavItem(Icons.search_outlined, Icons.search, '搜索'),
            _buildNavItem(Icons.photo_library_outlined, Icons.photo_library, '画廊'),
            _buildNavItem(Icons.person_outline, Icons.person, '我的'),
          ],
        ),
      ),
    );
  }

  BottomNavigationBarItem _buildNavItem(
    IconData outlined,
    IconData filled,
    String label,
  ) {
    return BottomNavigationBarItem(
      icon: Icon(outlined, size: 26),
      activeIcon: Icon(filled, size: 26),
      label: label,
    );
  }
}