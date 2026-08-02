import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/config/api_config.dart';
import 'package:listenwrite/theme/app_theme.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final auth = context.read<AuthProvider>();
    await auth.init();

    if (!mounted) return;

    if (auth.isLoggedIn) {
      final classIds = auth.user?.classIds ?? [];
      if (classIds.isEmpty) {
        Navigator.of(context).pushReplacementNamed('/class_selection');
      } else {
        await _checkVersion(auth);
        if (!mounted) return;
        Navigator.of(context).pushReplacementNamed('/home');
      }
    } else {
      Navigator.of(context).pushReplacementNamed('/login');
    }
  }

  Future<void> _checkVersion(AuthProvider auth) async {
    try {
      final result = await auth.api.post('check_version', {
        'current_version': '1.0.0',
      });
      if (result['success'] == true && mounted) {
        final data = result['data'] ?? {};
        if (data['has_update'] == true) {
          _showUpdateDialog(
            data['latest'] ?? '',
            data['notes'] ?? '',
          );
        }
      }
    } catch (_) {}
  }

  void _showUpdateDialog(String version, String notes) {
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
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '最新版本: $version',
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                fontSize: 16,
                color: HandDrawnTheme.pencil,
              ),
            ),
            if (notes.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(
                notes,
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  fontSize: 14,
                  color: HandDrawnTheme.pencil.withValues(alpha: 0.7),
                ),
              ),
            ],
          ],
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
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HandDrawnTheme.warmPaper,
      body: Center(
        child: HandDrawnCard(
          decoration: 'tape',
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                'ListenWrite',
                style: TextStyle(
                  fontFamily: 'Kalam',
                  fontSize: 40,
                  fontWeight: FontWeight.w700,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              const SizedBox(height: 16),
              const SizedBox(
                width: 32,
                height: 32,
                child: CircularProgressIndicator(
                  color: HandDrawnTheme.pencil,
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