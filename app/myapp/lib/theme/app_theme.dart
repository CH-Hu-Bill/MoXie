import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

class HandDrawnTheme {
  static const Color warmPaper = Color(0xFFFDFBF7);
  static const Color pencil = Color(0xFF2D2D2D);
  static const Color muted = Color(0xFFE5E0D8);
  static const Color accent = Color(0xFFFF4D4D);
  static const Color blue = Color(0xFF2D5DA1);
  static const Color postItYellow = Color(0xFFFFF9C4);
  static const Color tapeGray = Color(0x88CCCCCC);

  static const wobblyRadius = BorderRadius.only(
    topLeft: Radius.circular(255),
    topRight: Radius.circular(15),
    bottomLeft: Radius.circular(15),
    bottomRight: Radius.circular(225),
  );

  static const wobblyRadiusMd = BorderRadius.only(
    topLeft: Radius.circular(185),
    topRight: Radius.circular(12),
    bottomLeft: Radius.circular(12),
    bottomRight: Radius.circular(155),
  );

  static const wobblyRadiusSm = BorderRadius.only(
    topLeft: Radius.circular(120),
    topRight: Radius.circular(8),
    bottomLeft: Radius.circular(8),
    bottomRight: Radius.circular(100),
  );

  static const hardShadow = [
    BoxShadow(
      color: pencil,
      offset: Offset(4, 4),
      blurRadius: 0,
    ),
  ];

  static const hardShadowSm = [
    BoxShadow(
      color: pencil,
      offset: Offset(2, 2),
      blurRadius: 0,
    ),
  ];

  static const hardShadowLg = [
    BoxShadow(
      color: pencil,
      offset: Offset(8, 8),
      blurRadius: 0,
    ),
  ];

  static ThemeData get theme {
    final base = ThemeData(
      useMaterial3: true,
      scaffoldBackgroundColor: warmPaper,
      colorScheme: ColorScheme.fromSeed(
        seedColor: blue,
        primary: pencil,
        secondary: blue,
        surface: warmPaper,
        error: accent,
      ),
    );

    return base.copyWith(
      textTheme: GoogleFonts.patrickHandTextTheme(base.textTheme).copyWith(
        headlineLarge: GoogleFonts.kalam(
          fontSize: 32,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
        headlineMedium: GoogleFonts.kalam(
          fontSize: 28,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
        headlineSmall: GoogleFonts.kalam(
          fontSize: 24,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
        titleLarge: GoogleFonts.kalam(
          fontSize: 20,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
        titleMedium: GoogleFonts.kalam(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
        bodyLarge: GoogleFonts.patrickHand(
          fontSize: 18,
          color: pencil,
        ),
        bodyMedium: GoogleFonts.patrickHand(
          fontSize: 16,
          color: pencil,
        ),
        bodySmall: GoogleFonts.patrickHand(
          fontSize: 14,
          color: pencil,
        ),
        labelLarge: GoogleFonts.patrickHand(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: warmPaper,
        foregroundColor: pencil,
        elevation: 0,
        centerTitle: true,
        titleTextStyle: GoogleFonts.kalam(
          fontSize: 22,
          fontWeight: FontWeight.w700,
          color: pencil,
        ),
      ),
      bottomNavigationBarTheme: BottomNavigationBarThemeData(
        backgroundColor: warmPaper,
        selectedItemColor: pencil,
        unselectedItemColor: muted,
        type: BottomNavigationBarType.fixed,
        elevation: 0,
        selectedLabelStyle: GoogleFonts.patrickHand(
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
        unselectedLabelStyle: GoogleFonts.patrickHand(
          fontSize: 12,
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusSm,
          borderSide: const BorderSide(color: pencil, width: 2),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusSm,
          borderSide: const BorderSide(color: pencil, width: 2),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusSm,
          borderSide: const BorderSide(color: blue, width: 3),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusSm,
          borderSide: const BorderSide(color: accent, width: 2),
        ),
        hintStyle: GoogleFonts.patrickHand(
          color: pencil.withValues(alpha: 0.4),
          fontSize: 16,
        ),
        labelStyle: GoogleFonts.patrickHand(
          color: pencil,
          fontSize: 16,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: Colors.white,
          foregroundColor: pencil,
          elevation: 0,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          shape: RoundedRectangleBorder(
            borderRadius: HandDrawnTheme.wobblyRadius,
            side: const BorderSide(color: pencil, width: 3),
          ),
          shadowColor: Colors.transparent,
          textStyle: GoogleFonts.patrickHand(
            fontSize: 18,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusMd,
          side: const BorderSide(color: pencil, width: 2),
        ),
        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      ),
    );
  }
}

class PaperBackground extends StatelessWidget {
  final Widget child;
  const PaperBackground({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      painter: _PaperPainter(),
      child: child,
    );
  }
}

class _PaperPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = HandDrawnTheme.muted
      ..style = PaintingStyle.fill;

    const spacing = 24.0;
    const dotRadius = 1.0;

    for (double y = 0; y < size.height; y += spacing) {
      for (double x = 0; x < size.width; x += spacing) {
        canvas.drawCircle(Offset(x, y), dotRadius, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}