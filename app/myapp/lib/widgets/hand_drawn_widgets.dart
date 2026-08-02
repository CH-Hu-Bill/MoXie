import 'package:flutter/material.dart';
import 'package:listenwrite/theme/app_theme.dart';

class HandDrawnButton extends StatelessWidget {
  final String text;
  final VoidCallback? onPressed;
  final bool secondary;
  final bool small;
  final IconData? icon;

  const HandDrawnButton({
    super.key,
    required this.text,
    this.onPressed,
    this.secondary = false,
    this.small = false,
    this.icon,
  });

  @override
  Widget build(BuildContext context) {
    final isDisabled = onPressed == null;

    return GestureDetector(
      onTap: onPressed,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 100),
        padding: EdgeInsets.symmetric(
          horizontal: small ? 16 : 24,
          vertical: small ? 10 : 14,
        ),
        decoration: BoxDecoration(
          color: isDisabled
              ? HandDrawnTheme.muted
              : (secondary ? HandDrawnTheme.muted : Colors.white),
          borderRadius: HandDrawnTheme.wobblyRadius,
          border: Border.all(
            color: HandDrawnTheme.pencil,
            width: small ? 2 : 3,
          ),
          boxShadow: isDisabled
              ? null
              : [
                  BoxShadow(
                    color: HandDrawnTheme.pencil,
                    offset: const Offset(4, 4),
                    blurRadius: 0,
                  ),
                ],
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            if (icon != null) ...[
              Icon(icon, size: small ? 18 : 22, color: HandDrawnTheme.pencil),
              const SizedBox(width: 8),
            ],
            Text(
              text,
              style: TextStyle(
                fontFamily: 'Patrick Hand',
                fontSize: small ? 16 : 18,
                fontWeight: FontWeight.w700,
                color: isDisabled
                    ? HandDrawnTheme.pencil.withValues(alpha: 0.4)
                    : HandDrawnTheme.pencil,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class HandDrawnCard extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final String? decoration;
  final Color? backgroundColor;
  final double rotation;
  final VoidCallback? onTap;

  const HandDrawnCard({
    super.key,
    required this.child,
    this.padding,
    this.margin,
    this.decoration,
    this.backgroundColor,
    this.rotation = 0,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      child: Transform.rotate(
        angle: rotation * 3.14159 / 180,
        child: Container(
          padding: padding ?? const EdgeInsets.all(16),
          margin: margin ?? const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          decoration: BoxDecoration(
            color: backgroundColor ?? Colors.white,
            borderRadius: HandDrawnTheme.wobblyRadiusMd,
            border: Border.all(color: HandDrawnTheme.pencil, width: 2),
            boxShadow: HandDrawnTheme.hardShadow,
          ),
          child: Stack(
            children: [
              child,
              if (decoration == 'tape')
                Positioned(
                  top: -8,
                  left: 0,
                  right: 0,
                  child: Center(
                    child: Transform.rotate(
                      angle: -0.05,
                      child: Container(
                        width: 60,
                        height: 20,
                        decoration: BoxDecoration(
                          color: HandDrawnTheme.tapeGray,
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                    ),
                  ),
                ),
              if (decoration == 'tack')
                Positioned(
                  top: 4,
                  left: 0,
                  right: 0,
                  child: Center(
                    child: Container(
                      width: 16,
                      height: 16,
                      decoration: const BoxDecoration(
                        color: HandDrawnTheme.accent,
                        shape: BoxShape.circle,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class HandDrawnInput extends StatelessWidget {
  final String? label;
  final String? hint;
  final TextEditingController? controller;
  final bool obscureText;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final int? maxLines;
  final Widget? suffixIcon;

  const HandDrawnInput({
    super.key,
    this.label,
    this.hint,
    this.controller,
    this.obscureText = false,
    this.keyboardType,
    this.validator,
    this.maxLines = 1,
    this.suffixIcon,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (label != null) ...[
          Text(
            label!,
            style: TextStyle(
              fontFamily: 'Patrick Hand',
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: HandDrawnTheme.pencil,
            ),
          ),
          const SizedBox(height: 8),
        ],
        TextFormField(
          controller: controller,
          obscureText: obscureText,
          keyboardType: keyboardType,
          maxLines: maxLines,
          validator: validator,
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil,
          ),
          decoration: InputDecoration(
            hintText: hint,
            suffixIcon: suffixIcon,
            filled: true,
            fillColor: Colors.white,
            contentPadding:
                const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
            border: OutlineInputBorder(
              borderRadius: HandDrawnTheme.wobblyRadiusSm,
              borderSide: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: HandDrawnTheme.wobblyRadiusSm,
              borderSide: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: HandDrawnTheme.wobblyRadiusSm,
              borderSide:
                  const BorderSide(color: HandDrawnTheme.blue, width: 3),
            ),
            errorBorder: OutlineInputBorder(
              borderRadius: HandDrawnTheme.wobblyRadiusSm,
              borderSide:
                  const BorderSide(color: HandDrawnTheme.accent, width: 2),
            ),
            focusedErrorBorder: OutlineInputBorder(
              borderRadius: HandDrawnTheme.wobblyRadiusSm,
              borderSide:
                  const BorderSide(color: HandDrawnTheme.accent, width: 3),
            ),
            hintStyle: TextStyle(
              fontFamily: 'Patrick Hand',
              color: HandDrawnTheme.pencil.withValues(alpha: 0.4),
              fontSize: 16,
            ),
          ),
        ),
      ],
    );
  }
}

class HandDrawnScaffold extends StatelessWidget {
  final String? title;
  final Widget body;
  final Widget? bottomNavigationBar;
  final List<Widget>? actions;
  final bool showBackButton;
  final Widget? floatingActionButton;

  const HandDrawnScaffold({
    super.key,
    this.title,
    required this.body,
    this.bottomNavigationBar,
    this.actions,
    this.showBackButton = true,
    this.floatingActionButton,
  });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HandDrawnTheme.warmPaper,
      appBar: title != null
          ? AppBar(
              backgroundColor: HandDrawnTheme.warmPaper,
              foregroundColor: HandDrawnTheme.pencil,
              title: Text(
                title!,
                style: TextStyle(
                  fontFamily: 'Kalam',
                  fontSize: 22,
                  fontWeight: FontWeight.w700,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              centerTitle: true,
              elevation: 0,
              leading: showBackButton && Navigator.of(context).canPop()
                  ? IconButton(
                      icon: const Icon(Icons.arrow_back_ios_new),
                      onPressed: () => Navigator.of(context).pop(),
                    )
                  : null,
              actions: actions,
            )
          : null,
      body: Stack(
        children: [
          PaperBackground(child: const SizedBox.expand()),
          body,
        ],
      ),
      bottomNavigationBar: bottomNavigationBar,
      floatingActionButton: floatingActionButton,
    );
  }
}

class LoadingOverlay extends StatelessWidget {
  final String? message;

  const LoadingOverlay({super.key, this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: Colors.black.withValues(alpha: 0.3),
      child: Center(
        child: HandDrawnCard(
          decoration: 'tape',
          padding: const EdgeInsets.symmetric(horizontal: 40, vertical: 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(
                width: 40,
                height: 40,
                child: CircularProgressIndicator(
                  color: HandDrawnTheme.pencil,
                  strokeWidth: 3,
                ),
              ),
              if (message != null) ...[
                const SizedBox(height: 16),
                Text(
                  message!,
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    fontSize: 16,
                    color: HandDrawnTheme.pencil,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}