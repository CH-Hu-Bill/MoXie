import 'package:flutter/material.dart';

class AppColors {
  static const Color paper = Color(0xFFfdfbf7);
  static const Color pencil = Color(0xFF2d2d2d);
  static const Color red = Color(0xFFff4d4d);
  static const Color blue = Color(0xFF2d5da1);
  static const Color oldPaper = Color(0xFFe5e0d8);
  static const Color white = Color(0xFFFFFFFF);
  static const Color postIt = Color(0xFFfff9c4);
  static const Color favorite = Color(0xFF2d5da1);

  static const Color background = paper;
  static const Color foreground = pencil;
  static const Color muted = oldPaper;
  static const Color accent = red;
  static const Color border = pencil;
  static const Color secondaryAccent = blue;
  static const Color cardWhite = white;
  static const Color postItYellow = postIt;
}

class AppTheme {
  static const String fontHeading = 'ZCOOLKuaiLe';
  static const String fontBody = 'MaShanZheng';

  static BorderRadius get wobblySm => const BorderRadius.only(
        topLeft: Radius.elliptical(128, 14),
        topRight: Radius.elliptical(14, 88),
        bottomRight: Radius.elliptical(98, 14),
        bottomLeft: Radius.elliptical(14, 75),
      );

  static BorderRadius get wobblyRadius => const BorderRadius.only(
        topLeft: Radius.elliptical(255, 15),
        topRight: Radius.elliptical(15, 225),
        bottomRight: Radius.elliptical(225, 15),
        bottomLeft: Radius.elliptical(15, 255),
      );

  static BorderRadius get wobblyRadiusMd => const BorderRadius.only(
        topLeft: Radius.elliptical(15, 225),
        topRight: Radius.elliptical(225, 15),
        bottomRight: Radius.elliptical(15, 255),
        bottomLeft: Radius.elliptical(255, 15),
      );

  static BorderRadius get wobblyLg => const BorderRadius.only(
        topLeft: Radius.elliptical(255, 25),
        topRight: Radius.elliptical(25, 225),
        bottomRight: Radius.elliptical(225, 25),
        bottomLeft: Radius.elliptical(25, 255),
      );

  static List<BoxShadow> get hardShadowSm => const [
        BoxShadow(color: AppColors.pencil, offset: Offset(3, 3), blurRadius: 0),
      ];

  static List<BoxShadow> get hardShadowMd => const [
        BoxShadow(color: AppColors.pencil, offset: Offset(4, 4), blurRadius: 0),
      ];

  static List<BoxShadow> get hardShadowLg => const [
        BoxShadow(color: AppColors.pencil, offset: Offset(6, 6), blurRadius: 0),
      ];

  static List<BoxShadow> get softShadow => const [
        BoxShadow(color: AppColors.pencil, offset: Offset(3, 3), blurRadius: 0),
      ];

  static TextStyle get headingStyle => const TextStyle(
        fontFamily: fontHeading,
        color: AppColors.pencil,
      );

  static TextStyle get bodyStyle => const TextStyle(
        fontFamily: fontBody,
        color: AppColors.pencil,
      );

  static ThemeData get theme {
    return ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: AppColors.paper,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.red,
        background: AppColors.paper,
        surface: AppColors.white,
        primary: AppColors.red,
        secondary: AppColors.blue,
        onBackground: AppColors.pencil,
        onSurface: AppColors.pencil,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.white,
        foregroundColor: AppColors.pencil,
        elevation: 0,
        centerTitle: true,
        titleTextStyle: const TextStyle(
          fontFamily: fontHeading,
          fontSize: 18,
          color: AppColors.pencil,
        ),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.white,
        selectedItemColor: AppColors.red,
        unselectedItemColor: AppColors.pencil,
        type: BottomNavigationBarType.fixed,
      ),
      textTheme: TextTheme(
        displayLarge: headingStyle.copyWith(fontSize: 48),
        displayMedium: headingStyle.copyWith(fontSize: 36),
        displaySmall: headingStyle.copyWith(fontSize: 28),
        headlineLarge: headingStyle.copyWith(fontSize: 24),
        headlineMedium: headingStyle.copyWith(fontSize: 22),
        headlineSmall: headingStyle.copyWith(fontSize: 20),
        titleLarge: headingStyle.copyWith(fontSize: 18),
        titleMedium: headingStyle.copyWith(fontSize: 16),
        titleSmall: bodyStyle.copyWith(fontSize: 14),
        bodyLarge: bodyStyle.copyWith(fontSize: 18),
        bodyMedium: bodyStyle.copyWith(fontSize: 16),
        bodySmall: bodyStyle.copyWith(fontSize: 14),
        labelLarge: bodyStyle.copyWith(fontSize: 16),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(
          borderRadius: wobblyRadius,
          borderSide: const BorderSide(color: AppColors.pencil, width: 2),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: wobblyRadius,
          borderSide: const BorderSide(color: AppColors.pencil, width: 2),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: wobblyRadius,
          borderSide: const BorderSide(color: AppColors.blue, width: 3),
        ),
        labelStyle: bodyStyle.copyWith(fontSize: 15),
        hintStyle: bodyStyle.copyWith(
          fontSize: 15,
          color: const Color(0xFF999999),
          fontStyle: FontStyle.italic,
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      ),
    );
  }
}

class PaperTexture extends StatelessWidget {
  final Widget child;

  const PaperTexture({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.paper,
      child: CustomPaint(
        painter: _DotPatternPainter(),
        child: child,
      ),
    );
  }
}

class _DotPatternPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    const spacing = 24.0;
    final paint = Paint()..color = AppColors.oldPaper;
    for (double x = 0; x < size.width; x += spacing) {
      for (double y = 0; y < size.height; y += spacing) {
        canvas.drawCircle(Offset(x, y), 1.0, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}