import 'package:flutter/material.dart';

/// NUVIKOZ Edu Premium Academic Design System Color Tokens.
/// Spec: AG-UI-001 Section 4.
class AppColors {
  AppColors._();

  // Core Brand Palette (AG-UI-001 Approved Spec)
  static const Color primaryNavy = Color(0xFF142D4E);
  static const Color deepNavy = Color(0xFF10283F);
  static const Color navyDark = Color(0xFF0B192C);
  static const Color navyLight = Color(0xFF1B3D68);

  static const Color primaryBlue = Color(0xFF164C8C);
  static const Color premiumTeal = Color(0xFF009E92);
  static const Color tealDark = Color(0xFF007A71);
  static const Color tealLight = Color(0xFFE0F5F3);
  static const Color tealSoft = Color(0xFFEBF8F7);

  static const Color accentViolet = Color(0xFF7062DC);
  static const Color violetDark = Color(0xFF5647C9);
  static const Color violetLight = Color(0xFFF0EEFC);

  static const Color academicAmber = Color(0xFFF3AA48);
  static const Color amberDark = Color(0xFFD98E2E);
  static const Color amberLight = Color(0xFFFEF6EB);

  // Background & Surfaces
  static const Color lightBackground = Color(0xFFF4F7FC);
  static const Color whiteSurface = Color(0xFFFFFFFF);
  static const Color surfaceMuted = Color(0xFFF8FAFC);
  static const Color surfaceElevated = Color(0xFFFFFFFF);

  // Typography
  static const Color primaryText = Color(0xFF142D4E);
  static const Color secondaryText = Color(0xFF5A6E85);
  static const Color mutedText = Color(0xFF8E9CAE);
  static const Color inverseText = Color(0xFFFFFFFF);

  // Borders & Dividers
  static const Color border = Color(0xFFE2E8F0);
  static const Color borderSubtle = Color(0xFFEDF2F7);
  static const Color borderFocused = Color(0xFF009E92);

  // Shadows
  static const Color cardShadow = Color(0x0A142D4E);
  static const Color cardShadowHover = Color(0x14142D4E);

  // Semantic Feedback
  static const Color success = Color(0xFF059669);
  static const Color successLight = Color(0xFFECFDF5);
  static const Color error = Color(0xFFDC2626);
  static const Color errorLight = Color(0xFFFEF2F2);
  static const Color warning = Color(0xFFD97706);
  static const Color warningLight = Color(0xFFFFFBEB);
  static const Color info = Color(0xFF2563EB);
  static const Color infoLight = Color(0xFFEFF6FF);

  // Academic Resource Color Accents (Consistent Module Identification)
  static const Color sessionColor = Color(0xFF142D4E);      // Navy
  static const Color classColor = Color(0xFF009E92);        // Teal
  static const Color sectionColor = Color(0xFF7062DC);      // Violet
  static const Color subjectColor = Color(0xFFF3AA48);      // Amber
  static const Color offeringColor = Color(0xFF2563EB);     // Blue
  static const Color analyticsColor = Color(0xFF8B5CF6);    // Purple

  // Gradients
  static const LinearGradient brandGradient = LinearGradient(
    colors: [primaryNavy, navyLight],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient tealGradient = LinearGradient(
    colors: [premiumTeal, Color(0xFF00BFA5)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient violetGradient = LinearGradient(
    colors: [accentViolet, Color(0xFF9084EE)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient amberGradient = LinearGradient(
    colors: [academicAmber, Color(0xFFF6BC6D)],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient previewBannerGradient = LinearGradient(
    colors: [Color(0xFFFFFBEB), Color(0xFFFEF3C7)],
    begin: Alignment.centerLeft,
    end: Alignment.centerRight,
  );

  static const LinearGradient premiumGradient = LinearGradient(
    colors: [primaryNavy, premiumTeal],
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
  );

  static const LinearGradient headerGradient = LinearGradient(
    colors: [deepNavy, primaryNavy],
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
  );
}
