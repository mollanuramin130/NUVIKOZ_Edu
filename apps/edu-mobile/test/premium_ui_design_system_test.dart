import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/academic/screens/academic_analytics_screen.dart';
import 'package:edu_mobile/navigation/main_navigation_shell.dart';
import 'package:edu_mobile/preview/preview_controller_factory.dart';
import 'package:edu_mobile/theme/app_colors.dart';
import 'package:edu_mobile/theme/app_spacing.dart';
import 'package:edu_mobile/theme/app_theme.dart';
import 'package:edu_mobile/theme/app_typography.dart';
import 'package:edu_mobile/widgets/app_metric_card.dart';
import 'package:edu_mobile/widgets/brand_logo.dart';

void main() {
  group('AG-UI-001 Premium Academic UI Design System & Navigation Tests', () {
    test('Design Tokens adhere strictly to approved AG-UI-001 spec', () {
      // Color tokens
      expect(AppColors.primaryNavy, const Color(0xFF142D4E));
      expect(AppColors.premiumTeal, const Color(0xFF009E92));
      expect(AppColors.accentViolet, const Color(0xFF7062DC));
      expect(AppColors.academicAmber, const Color(0xFFF3AA48));
      expect(AppColors.lightBackground, const Color(0xFFF4F7FC));

      // Spacing tokens (8-point scale)
      expect(AppSpacing.xs, 4.0);
      expect(AppSpacing.sm, 8.0);
      expect(AppSpacing.md, 12.0);
      expect(AppSpacing.lg, 16.0);
      expect(AppSpacing.xl, 20.0);
      expect(AppSpacing.xxl, 24.0);
      expect(AppSpacing.xxxl, 32.0);
      expect(AppSpacing.minTouchTarget, 48.0);

      // Typography tokens
      expect(AppTypography.displayLarge.fontSize, 24);
      expect(AppTypography.titleLarge.fontSize, 20);
      expect(AppTypography.titleMedium.fontSize, 16);
      expect(AppTypography.titleSmall.fontSize, 14);
      expect(AppTypography.bodyLarge.fontSize, 14);
      expect(AppTypography.bodySmall.fontSize, 12);
    });

    testWidgets('BrandLogo and BrandHeader render vectors cleanly without raster dependencies', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(
            body: Column(
              children: [
                NuvikozBrandMark(size: 48),
                NuvikozBrandHeader(markSize: 36),
              ],
            ),
          ),
        ),
      );

      expect(find.byType(NuvikozBrandMark), findsNWidgets(2));
      expect(find.text('NUVIKOZ'), findsOneWidget);
      expect(find.text('EDU'), findsOneWidget);
      expect(find.text('ACADEMIC MANAGEMENT'), findsOneWidget);
    });

    testWidgets('AppMetricCard renders values, icons, badges, and handles tap events', (tester) async {
      bool tapped = false;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: Scaffold(
            body: AppMetricCard(
              title: 'Active Sessions',
              value: '3 Terms',
              subtitle: '2026-2027 Active',
              icon: Icons.calendar_today_rounded,
              accentColor: AppColors.primaryNavy,
              badgeText: 'CATALOG',
              onTap: () => tapped = true,
            ),
          ),
        ),
      );

      expect(find.text('3 Terms'), findsOneWidget);
      expect(find.text('Active Sessions'), findsOneWidget);
      expect(find.text('2026-2027 Active'), findsOneWidget);
      expect(find.text('CATALOG'), findsOneWidget);
      expect(find.byIcon(Icons.calendar_today_rounded), findsOneWidget);

      await tester.tap(find.text('3 Terms'));
      expect(tapped, isTrue);
    });

    testWidgets('AcademicAnalyticsScreen renders attendance donut, grade distribution, and marksheet table', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      await tester.pumpWidget(
        const MaterialApp(
          home: AcademicAnalyticsScreen(isPreviewMode: true),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Academic Analytics & Marksheet'), findsOneWidget);
      expect(find.text('SYNTHETIC PREVIEW PROTOTYPE: Demonstrating proposed attendance and marks visualization components.'), findsOneWidget);
      expect(find.text('Overall Attendance Rate'), findsOneWidget);
      expect(find.text('94.2%'), findsNWidgets(2));
      expect(find.text('Excused Leave'), findsOneWidget);
      expect(find.text('Score Distribution'), findsOneWidget);
      expect(find.text('STUDENT PROGRESS REPORT'), findsOneWidget);
      expect(find.text('Aarav Sharma'), findsOneWidget);
      expect(find.text('Mathematics (MATH)'), findsOneWidget);
      expect(find.text('Computer Science (CS)'), findsOneWidget);
      expect(find.text('440 / 500 (88.0%)'), findsOneWidget);
    });

    testWidgets('MainNavigationShell renders 4-tab bottom navigation, drawer, and switches tabs', (tester) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 2.0;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
      });

      final harness = await PreviewHarness.create();

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: MainNavigationShell(
            authController: harness.authController,
            academicRepository: harness.academicRepository,
            isPreviewMode: true,
          ),
        ),
      );
      await tester.pumpAndSettle();

      // Check BottomNavigationBar destinations
      expect(find.text('Home'), findsOneWidget);
      expect(find.text('Academic'), findsOneWidget);
      expect(find.text('Analytics'), findsOneWidget);
      expect(find.text('Profile'), findsOneWidget);

      // Verify Home (Dashboard) is active initially
      expect(find.text('QUICK ACTIONS'), findsOneWidget);
      expect(find.text('ACADEMIC SUMMARY'), findsOneWidget);

      // Switch to Academic tab (tab 1)
      await tester.tap(find.text('Academic'));
      await tester.pumpAndSettle();
      expect(find.text('Academic Workspace'), findsOneWidget);
      expect(find.text('Sessions'), findsWidgets);
      expect(find.text('Classes'), findsWidgets);

      // Switch to Analytics tab (tab 2)
      await tester.tap(find.text('Analytics'));
      await tester.pumpAndSettle();
      expect(find.text('Academic Analytics & Marksheet'), findsOneWidget);
      expect(find.text('STUDENT MARKSHEET REPORT CARD'), findsOneWidget);

      // Switch to Profile tab (tab 3)
      await tester.tap(find.text('Profile'));
      await tester.pumpAndSettle();
      expect(find.text('Device Security & Profile'), findsOneWidget);
      expect(find.text('Android Keystore AES-256'), findsOneWidget);

      // Open drawer from Profile screen
      await tester.tap(find.byTooltip('Navigation Menu'));
      await tester.pumpAndSettle();
      expect(find.text('CORE WORKSPACE'), findsOneWidget);
      expect(find.text('ACADEMIC CATALOG (6 MODULES)'), findsOneWidget);
      expect(find.text('PLANNED MODULES (COMING LATER)'), findsOneWidget);
      expect(find.text('Timetable'), findsOneWidget);

      // Tap on future module "Timetable" in drawer to check Coming Later dialog
      await tester.tap(find.text('Timetable'));
      await tester.pumpAndSettle();
      expect(find.text('COMING LATER / NOT IMPLEMENTED'), findsOneWidget);
      expect(find.text('Understood'), findsOneWidget);

      await tester.tap(find.text('Understood'));
      await tester.pumpAndSettle();

      harness.authController.dispose();
    });
  });
}
