import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class AppColors {
  static const Color background = Color(0xFFfdfbf7);
  static const Color foreground = Color(0xFF2d2d2d);
  static const Color muted = Color(0xFFe5e0d8);
  static const Color accent = Color(0xFFff4d4d);
  static const Color border = Color(0xFF2d2d2d);
  static const Color secondaryAccent = Color(0xFF2d5da1);
  static const Color postItYellow = Color(0xFFfff9c4);
  static const Color cardWhite = Color(0xFFFFFFFF);
}

class AppTheme {
  static BorderRadius get wobblyRadius => const BorderRadius.all(
        Radius.elliptical(20, 18),
      );

  static BorderRadius get wobblyRadiusLg => const BorderRadius.only(
        topLeft: Radius.circular(255),
        topRight: Radius.circular(15),
        bottomLeft: Radius.circular(225),
        bottomRight: Radius.circular(15),
      );

  static BorderRadius get wobblyRadiusMd => const BorderRadius.only(
        topLeft: Radius.circular(15),
        topRight: Radius.circular(225),
        bottomLeft: Radius.circular(15),
        bottomRight: Radius.circular(255),
      );

  static List<BoxShadow> get hardShadow => [
        const BoxShadow(
          color: AppColors.foreground,
          offset: Offset(4, 4),
          blurRadius: 0,
        ),
      ];

  static List<BoxShadow> get hardShadowSm => [
        const BoxShadow(
          color: AppColors.foreground,
          offset: Offset(2, 2),
          blurRadius: 0,
        ),
      ];

  static List<BoxShadow> get hardShadowLg => [
        const BoxShadow(
          color: AppColors.foreground,
          offset: Offset(8, 8),
          blurRadius: 0,
        ),
      ];

  static List<BoxShadow> get softShadow => [
        BoxShadow(
          color: AppColors.foreground.withValues(alpha: 0.1),
          offset: const Offset(3, 3),
          blurRadius: 0,
        ),
      ];

  static TextStyle get headingStyle => GoogleFonts.kalam(
        fontWeight: FontWeight.w700,
        color: AppColors.foreground,
      );

  static TextStyle get bodyStyle => GoogleFonts.patrickHand(
        color: AppColors.foreground,
      );

  static ThemeData get theme {
    return ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: AppColors.background,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.accent,
        background: AppColors.background,
        surface: AppColors.cardWhite,
        primary: AppColors.accent,
        secondary: AppColors.secondaryAccent,
        onBackground: AppColors.foreground,
        onSurface: AppColors.foreground,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: AppColors.background,
        foregroundColor: AppColors.foreground,
        elevation: 0,
        centerTitle: true,
        titleTextStyle: GoogleFonts.kalam(
          fontSize: 24,
          fontWeight: FontWeight.w700,
          color: AppColors.foreground,
        ),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.cardWhite,
        selectedItemColor: AppColors.accent,
        unselectedItemColor: AppColors.foreground,
        type: BottomNavigationBarType.fixed,
        selectedIconTheme: IconThemeData(size: 28),
        unselectedIconTheme: IconThemeData(size: 24),
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
        titleSmall: bodyStyle.copyWith(fontSize: 14, fontWeight: FontWeight.bold),
        bodyLarge: bodyStyle.copyWith(fontSize: 18),
        bodyMedium: bodyStyle.copyWith(fontSize: 16),
        bodySmall: bodyStyle.copyWith(fontSize: 14),
        labelLarge: bodyStyle.copyWith(fontSize: 16, fontWeight: FontWeight.bold),
      ),
      inputDecorationTheme: InputDecorationTheme(
        border: OutlineInputBorder(
          borderRadius: wobblyRadius,
          borderSide: const BorderSide(color: AppColors.border, width: 2),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: wobblyRadius,
          borderSide: const BorderSide(color: AppColors.border, width: 2),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: wobblyRadius,
          borderSide: const BorderSide(color: AppColors.secondaryAccent, width: 2.5),
        ),
        labelStyle: bodyStyle.copyWith(fontSize: 16),
        hintStyle: bodyStyle.copyWith(
          fontSize: 16,
          color: AppColors.foreground.withValues(alpha: 0.4),
        ),
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
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
      color: AppColors.background,
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
    const dotSize = 1.0;
    final paint = Paint()..color = AppColors.muted;

    for (double x = 0; x < size.width; x += spacing) {
      for (double y = 0; y < size.height; y += spacing) {
        canvas.drawCircle(Offset(x, y), dotSize, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
