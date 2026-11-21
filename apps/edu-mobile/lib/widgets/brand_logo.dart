import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

/// NUVIKOZ Edu Vector Brand Mark.
/// Spec: AG-UI-001 Section 3.
class NuvikozBrandMark extends StatelessWidget {
  const NuvikozBrandMark({
    super.key,
    this.size = 36.0,
    this.iconColor = Colors.white,
    this.backgroundColor,
  });

  final double size;
  final Color iconColor;
  final Color? backgroundColor;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: backgroundColor == null ? AppColors.brandGradient : null,
        color: backgroundColor,
        borderRadius: BorderRadius.circular(size * 0.28),
        boxShadow: const [
          BoxShadow(
            color: Color(0x1F142D4E),
            blurRadius: 8,
            offset: Offset(0, 3),
          ),
        ],
      ),
      child: Center(
        child: Icon(
          Icons.school_rounded,
          size: size * 0.58,
          color: iconColor,
        ),
      ),
    );
  }
}

/// NUVIKOZ Edu Horizontal Brand Header Widget (Mark + Wordmark).
class NuvikozBrandHeader extends StatelessWidget {
  const NuvikozBrandHeader({
    super.key,
    this.markSize = 38.0,
    this.isLight = false,
    this.subtitle = 'ACADEMIC MANAGEMENT',
  });

  final double markSize;
  final bool isLight;
  final String subtitle;

  @override
  Widget build(BuildContext context) {
    final titleColor = isLight ? Colors.white : AppColors.primaryNavy;
    final subtitleColor = isLight ? const Color(0xFF90CDF4) : AppColors.secondaryText;

    return Row(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        NuvikozBrandMark(size: markSize),
        const SizedBox(width: 12),
        Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  'NUVIKOZ',
                  style: TextStyle(
                    fontSize: markSize * 0.44,
                    fontWeight: FontWeight.w900,
                    letterSpacing: -0.5,
                    color: titleColor,
                  ),
                ),
                const SizedBox(width: 4),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1.5),
                  decoration: BoxDecoration(
                    color: AppColors.premiumTeal,
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    'EDU',
                    style: TextStyle(
                      fontSize: markSize * 0.26,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 0.8,
                      color: Colors.white,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 2),
            Text(
              subtitle,
              style: TextStyle(
                fontSize: markSize * 0.24,
                fontWeight: FontWeight.w600,
                letterSpacing: 0.9,
                color: subtitleColor,
              ),
            ),
          ],
        ),
      ],
    );
  }
}
