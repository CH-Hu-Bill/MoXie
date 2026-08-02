import 'dart:math';
import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

class HandDrawnCard extends StatelessWidget {
  final Widget child;
  final EdgeInsets padding;
  final Color? backgroundColor;
  final BorderRadius? borderRadius;
  final List<BoxShadow>? shadows;
  final double? rotation;
  final double borderWidth;
  final Decoration? decoration;
  final VoidCallback? onTap;

  const HandDrawnCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.backgroundColor,
    this.borderRadius,
    this.shadows,
    this.rotation,
    this.borderWidth = 2,
    this.decoration,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final card = Container(
      padding: padding,
      decoration: decoration ??
          BoxDecoration(
            color: backgroundColor ?? AppColors.cardWhite,
            borderRadius: borderRadius ?? AppTheme.wobblyRadius,
            border: Border.all(
              color: AppColors.border,
              width: borderWidth,
            ),
            boxShadow: shadows ?? AppTheme.softShadow,
          ),
      child: child,
    );

    final rotated = rotation != null
        ? Transform.rotate(angle: rotation! * pi / 180, child: card)
        : card;

    if (onTap != null) {
      return InkWell(
        onTap: onTap,
        borderRadius: borderRadius ?? AppTheme.wobblyRadius,
        child: rotated,
      );
    }
    return rotated;
  }
}

class HandDrawnButton extends StatefulWidget {
  final String label;
  final VoidCallback? onPressed;
  final Color? backgroundColor;
  final Color? textColor;
  final IconData? icon;
  final bool isSecondary;
  final bool fullWidth;
  final double fontSize;

  const HandDrawnButton({
    super.key,
    required this.label,
    this.onPressed,
    this.backgroundColor,
    this.textColor,
    this.icon,
    this.isSecondary = false,
    this.fullWidth = false,
    this.fontSize = 18,
  });

  @override
  State<HandDrawnButton> createState() => _HandDrawnButtonState();
}

class _HandDrawnButtonState extends State<HandDrawnButton> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final isDisabled = widget.onPressed == null;
    final bgColor = isDisabled
        ? AppColors.muted
        : (widget.backgroundColor ??
            (widget.isSecondary ? AppColors.muted : AppColors.cardWhite));
    final fgColor = isDisabled
        ? AppColors.foreground.withValues(alpha: 0.4)
        : (widget.textColor ?? AppColors.foreground);

    return GestureDetector(
      onTapDown: (_) => setState(() => _pressed = true),
      onTapUp: (_) => setState(() => _pressed = false),
      onTapCancel: () => setState(() => _pressed = false),
      onTap: widget.onPressed,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 80),
        transform: _pressed
            ? (Matrix4.translationValues(4, 4, 0))
            : Matrix4.identity(),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          decoration: BoxDecoration(
            color: bgColor,
            borderRadius: AppTheme.wobblyRadius,
            border: Border.all(
              color: AppColors.border,
              width: 3,
            ),
            boxShadow: _pressed
                ? []
                : (isDisabled ? null : AppTheme.hardShadow),
          ),
          child: Row(
            mainAxisSize: widget.fullWidth
                ? MainAxisSize.max
                : MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (widget.icon != null) ...[
                Icon(widget.icon, size: widget.fontSize + 2, color: fgColor),
                const SizedBox(width: 8),
              ],
              Text(
                widget.label,
                style: AppTheme.bodyStyle.copyWith(
                  fontSize: widget.fontSize,
                  color: fgColor,
                  fontWeight: FontWeight.bold,
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
  final TextInputType keyboardType;
  final int maxLines;
  final Widget? suffix;
  final String? Function(String?)? validator;
  final void Function(String)? onChanged;
  final FocusNode? focusNode;

  const HandDrawnInput({
    super.key,
    this.label,
    this.hint,
    this.controller,
    this.obscureText = false,
    this.keyboardType = TextInputType.text,
    this.maxLines = 1,
    this.suffix,
    this.validator,
    this.onChanged,
    this.focusNode,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (label != null) ...[
          Text(label!, style: AppTheme.bodyStyle.copyWith(fontSize: 16)),
          const SizedBox(height: 6),
        ],
        TextFormField(
          controller: controller,
          obscureText: obscureText,
          keyboardType: keyboardType,
          maxLines: maxLines,
          validator: validator,
          onChanged: onChanged,
          focusNode: focusNode,
          style: AppTheme.bodyStyle.copyWith(fontSize: 16),
          decoration: InputDecoration(
            hintText: hint,
            suffixIcon: suffix,
          ),
        ),
      ],
    );
  }
}

class StickyNote extends StatelessWidget {
  final String text;
  final Color? color;
  final double rotation;

  const StickyNote({
    super.key,
    required this.text,
    this.color,
    this.rotation = -1,
  });

  @override
  Widget build(BuildContext context) {
    return Transform.rotate(
      angle: rotation * pi / 180,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: color ?? AppColors.postItYellow,
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(4),
            topRight: Radius.circular(4),
            bottomLeft: Radius.circular(2),
            bottomRight: Radius.circular(8),
          ),
          boxShadow: [
            BoxShadow(
              color: AppColors.foreground.withValues(alpha: 0.15),
              offset: const Offset(3, 3),
              blurRadius: 0,
            ),
          ],
        ),
        child: Text(
          text,
          style: AppTheme.bodyStyle.copyWith(
            fontSize: 16,
            fontWeight: FontWeight.bold,
          ),
        ),
      ),
    );
  }
}

class TapeDecoration extends StatelessWidget {
  final Widget child;
  final Color? tapeColor;

  const TapeDecoration({
    super.key,
    required this.child,
    this.tapeColor,
  });

  @override
  Widget build(BuildContext context) {
    return Stack(
      children: [
        child,
        Positioned(
          top: -8,
          left: 0,
          right: 0,
          child: Center(
            child: Transform.rotate(
              angle: -2 * pi / 180,
              child: Container(
                width: 60,
                height: 20,
                color: (tapeColor ?? Colors.grey).withValues(alpha: 0.35),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class LoadingOverlay extends StatelessWidget {
  final String? message;

  const LoadingOverlay({super.key, this.message});

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.background.withValues(alpha: 0.8),
      child: Center(
        child: HandDrawnCard(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const SizedBox(
                width: 32,
                height: 32,
                child: CircularProgressIndicator(
                  color: AppColors.accent,
                  strokeWidth: 3,
                ),
              ),
              if (message != null) ...[
                const SizedBox(height: 16),
                Text(message!, style: AppTheme.bodyStyle.copyWith(fontSize: 16)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  final String message;
  final IconData icon;

  const EmptyState({
    super.key,
    required this.message,
    this.icon = Icons.sketch_outlined,
  });

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 64, color: AppColors.muted),
            const SizedBox(height: 16),
            Text(
              message,
              textAlign: TextAlign.center,
              style: AppTheme.bodyStyle.copyWith(
                fontSize: 18,
                color: AppColors.foreground.withValues(alpha: 0.5),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class WobblyTabBar extends StatelessWidget {
  final List<String> tabs;
  final int selectedIndex;
  final ValueChanged<int> onTap;

  const WobblyTabBar({
    super.key,
    required this.tabs,
    required this.selectedIndex,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: AppColors.muted.withValues(alpha: 0.5),
        borderRadius: AppTheme.wobblyRadius,
        border: Border.all(color: AppColors.border, width: 2),
      ),
      child: Row(
        children: List.generate(tabs.length, (i) {
          final selected = i == selectedIndex;
          return Expanded(
            child: GestureDetector(
              onTap: () => onTap(i),
              child: AnimatedContainer(
                duration: const Duration(milliseconds: 150),
                margin: const EdgeInsets.all(2),
                padding:
                    const EdgeInsets.symmetric(vertical: 10, horizontal: 8),
                decoration: BoxDecoration(
                  color: selected ? AppColors.cardWhite : Colors.transparent,
                  borderRadius: AppTheme.wobblyRadius,
                  border: selected
                      ? Border.all(color: AppColors.border, width: 2)
                      : null,
                  boxShadow: selected ? AppTheme.hardShadowSm : null,
                ),
                child: Text(
                  tabs[i],
                  textAlign: TextAlign.center,
                  style: AppTheme.bodyStyle.copyWith(
                    fontSize: 15,
                    fontWeight: selected ? FontWeight.bold : FontWeight.normal,
                    color: selected
                        ? AppColors.accent
                        : AppColors.foreground.withValues(alpha: 0.6),
                  ),
                ),
              ),
            ),
          );
        }),
      ),
    );
  }
}
