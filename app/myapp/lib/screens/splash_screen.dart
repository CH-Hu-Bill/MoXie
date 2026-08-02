import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../theme/app_theme.dart';
import '../widgets/hand_drawn.dart';
import 'auth/login_screen.dart';
import 'class_selection_screen.dart';
import 'home_screen.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with TickerProviderStateMixin {
  late AnimationController _bounceController;
  late Animation<double> _bounceAnimation;

  @override
  void initState() {
    super.initState();
    _bounceController = AnimationController(
      duration: const Duration(milliseconds: 1200),
      vsync: this,
    )..repeat(reverse: true);
    _bounceAnimation = Tween<double>(begin: -3, end: 3).animate(
      CurvedAnimation(parent: _bounceController, curve: Curves.elasticInOut),
    );
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    final auth = context.read<AuthProvider>();
    await auth.init();
    if (!mounted) return;

    await Future.delayed(const Duration(milliseconds: 800));

    if (!mounted) return;
    if (auth.authState == AuthState.authenticated) {
      if (auth.hasClass && auth.currentClassId!.isNotEmpty) {
        Navigator.pushReplacement(
          context,
          PageRouteBuilder(
            pageBuilder: (_, __, ___) => const HomeScreen(),
            transitionsBuilder: (_, anim, __, child) =>
                FadeTransition(opacity: anim, child: child),
          ),
        );
      } else {
        Navigator.pushReplacement(
          context,
          MaterialPageRoute(builder: (_) => const ClassSelectionScreen()),
        );
      }
    } else {
      Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
  }

  @override
  void dispose() {
    _bounceController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: PaperTexture(
        child: Center(
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              AnimatedBuilder(
                animation: _bounceAnimation,
                builder: (_, child) => Transform.rotate(
                  angle: _bounceAnimation.value * 0.017,
                  child: child,
                ),
                child: HandDrawnCard(
                  padding: const EdgeInsets.symmetric(
                      horizontal: 40, vertical: 32),
                  backgroundColor: AppColors.postItYellow,
                  shadows: AppTheme.hardShadowLg,
                  rotation: -1,
                  child: Column(
                    children: [
                      Icon(
                        Icons.edit_note,
                        size: 72,
                        color: AppColors.foreground,
                      ),
                      const SizedBox(height: 12),
                      Text(
                        '默写史记',
                        style: AppTheme.headingStyle.copyWith(fontSize: 36),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'ListenWrite',
                        style: AppTheme.bodyStyle.copyWith(
                          fontSize: 16,
                          color: AppColors.secondaryAccent,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 32),
              const SizedBox(
                width: 28,
                height: 28,
                child: CircularProgressIndicator(
                  color: AppColors.accent,
                  strokeWidth: 3,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
