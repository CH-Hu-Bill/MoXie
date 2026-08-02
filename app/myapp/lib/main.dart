import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'providers/auth_provider.dart';
import 'theme/app_theme.dart';
import 'screens/home/splash_screen.dart';
import 'screens/home/home_screen.dart';
import 'screens/auth/login_screen.dart';
import 'screens/class_selection/class_selection_screen.dart';

void main() {
  runApp(const ListenWriteApp());
}

class ListenWriteApp extends StatelessWidget {
  const ListenWriteApp({super.key});

  @override
  Widget build(BuildContext context) {
    return ChangeNotifierProvider(
      create: (_) => AuthProvider(),
      child: MaterialApp(
        title: 'ListenWrite',
        debugShowCheckedModeBanner: false,
        theme: HandDrawnTheme.theme,
        initialRoute: '/',
        routes: {
          '/': (context) => const SplashScreen(),
          '/login': (context) => const LoginScreen(),
          '/class_selection': (context) => const ClassSelectionScreen(),
          '/home': (context) => const HomeScreen(),
        },
      ),
    );
  }
}