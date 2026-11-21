import 'package:flutter/material.dart';

/// NUVIKOZ Edu Academic Spacing & Layout Tokens.
/// Spec: AG-UI-001 Section 5.
class AppSpacing {
  AppSpacing._();

  // 8-point Spacing Scale
  static const double xs = 4.0;
  static const double sm = 8.0;
  static const double md = 12.0;
  static const double lg = 16.0;
  static const double xl = 20.0;
  static const double xxl = 24.0;
  static const double xxxl = 32.0;
  static const double huge = 40.0;

  // Corner Radii
  static const double radiusXs = 6.0;
  static const double radiusSm = 8.0;
  static const double radiusMd = 12.0;
  static const double radiusLg = 16.0;
  static const double radiusXl = 20.0;
  static const double radiusSheet = 24.0;
  static const double radiusPill = 999.0;

  // Touch Targets (Minimum 48dp per accessibility guidelines)
  static const double minTouchTarget = 48.0;

  // Insets
  static const EdgeInsets screenPadding = EdgeInsets.symmetric(horizontal: 16.0, vertical: 12.0);
  static const EdgeInsets cardPadding = EdgeInsets.all(16.0);
  static const EdgeInsets compactCardPadding = EdgeInsets.all(12.0);
  static const EdgeInsets modalPadding = EdgeInsets.all(20.0);
}
