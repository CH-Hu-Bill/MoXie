import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_quill/flutter_quill.dart';
import 'package:provider/provider.dart';
import 'providers/auth_provider.dart';
import 'services/announcement_service.dart';
import 'services/storage_service.dart';
import 'config/api_config.dart';
import 'theme/app_theme.dart';
import 'widgets/fullscreen_announcement_overlay.dart';
import 'screens/splash_screen.dart';
import 'screens/endpoint_setup_screen.dart';
import 'screens/auth/login_screen.dart';
import 'screens/class_selection_screen.dart';
import 'screens/home_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await StorageService().init();
  // 运行时端点：优先使用用户上次保存的地址
  final saved = StorageService().getServerBaseUrl();
  if (saved != null && saved.isNotEmpty) {
    ApiConfig.baseUrl = saved;
  }
  runApp(const MyApp());
}

/// 进入子页面（Navigator.push）时触发超级霸屏
class FsNavigatorObserver extends NavigatorObserver {
  @override
  void didPush(Route<dynamic> route, Route<dynamic>? previousRoute) {
    AnnouncementService.instance.triggerFullscreen();
  }
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => AuthProvider(),
      child: MaterialApp(
        title: 'ListenWrite',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.theme,
        locale: const Locale('zh'),
        supportedLocales: const [
          Locale('zh'),
          Locale('en'),
        ],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          FlutterQuillLocalizations.delegate,
        ],
        navigatorObservers: [FsNavigatorObserver()],
        builder: (context, child) {
          return Stack(
            children: [
              if (child != null) child,
              const FullscreenAnnouncementOverlay(),
            ],
          );
        },
        home: const RootGate(),
      ),
    );
  }
}

/// 先判断是否已配置服务器端点：未配置则显示端点设置页，配置后进入正常流程。
class RootGate extends StatefulWidget {
  const RootGate({super.key});

  @override
  State<RootGate> createState() => _RootGateState();
}

class _RootGateState extends State<RootGate> {
  late bool _configured;

  @override
  void initState() {
    super.initState();
    final saved = StorageService().getServerBaseUrl();
    _configured = saved != null && saved.isNotEmpty;
    if (_configured) {
      ApiConfig.baseUrl = saved!;
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_configured) {
      return EndpointSetupScreen(
        onConfigured: () => setState(() => _configured = true),
      );
    }
    return Consumer<AuthProvider>(
      builder: (_, auth, __) {
        switch (auth.authState) {
          case AuthState.authenticated:
            if (auth.hasClass && auth.currentClassId!.isNotEmpty) {
              return const HomeScreen();
            }
            return const ClassSelectionScreen();
          case AuthState.unauthenticated:
            return const LoginScreen();
          case AuthState.initial:
          case AuthState.loading:
            return const SplashScreen();
        }
      },
    );
  }
}
