import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'providers/auth_provider.dart';
import 'services/storage_service.dart';
import 'theme/app_theme.dart';
import 'screens/splash_screen.dart';
import 'screens/auth/login_screen.dart';
import 'screens/class_selection_screen.dart';
import 'screens/home_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await StorageService().init();
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => AuthProvider(),
      child: MaterialApp(
        title: '默写史记',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.theme,
        home: Consumer<AuthProvider>(
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
        ),
      ),
    );
  }
}
