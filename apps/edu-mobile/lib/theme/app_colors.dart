import 'package:flutter/material.dart';

class AppColors {
  AppColors._();

  // Core Brand Palette
  static const Color deepNavy = Color(0xFF10283F);
  static const Color primaryBlue = Color(0xFF164C8C);
  static const Color premiumTeal = Color(0xFF087B79);
  static const Color lightBackground = Color(0xFFF3F6FB);
  static const Color primaryText = Color(0xFF172B45);
  static const Color whiteSurface = Color(0xFFFFFFFF);

  // Semantic & Support Colors
  static const Color secondaryText = Color(0xFF64748B);
  static const Color border = Color(0xFFE2E8F0);
  static const Color cardShadow = Color(0x0C10283F);
  
  static const Color success = Color(0xFF059669);
  static const Color successLight = Color(0xFFECFDF5);
  static const Color error = Color(0xFFDC2626);
  static const Color errorLight = Color(0xFFFEF2F2);
  static const Color warning = Color(0xFFD97706);
  static const Color warningLight = Color(0xFFFFFBEB);
  static const Color info = Color(0xFF2563EB);
  static const Color infoLight = Color(0xFFEFF6FF);

  // Accent Gradients
  static const LinearGradient premiumGradient = LinearGradient(
    colors: [primaryBlue, premiumTeal],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient headerGradient = LinearGradient(
    colors: [deepNavy, primaryBlue],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );
}
