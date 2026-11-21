import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/theme/app_theme.dart';
import 'package:edu_mobile/widgets/app_card.dart';
import 'package:edu_mobile/widgets/app_loading_view.dart';
import 'package:edu_mobile/widgets/app_modal_header.dart';
import 'package:edu_mobile/widgets/app_section_header.dart';
import 'package:edu_mobile/widgets/empty_state_view.dart';
import 'package:edu_mobile/widgets/error_banner.dart';
import 'package:edu_mobile/widgets/status_badge.dart';

void main() {
  group('Design System & UI Components', () {
    testWidgets('AppSectionHeader renders title, subtitle, and action', (tester) async {
      bool actionTapped = false;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: Scaffold(
            body: AppSectionHeader(
              title: 'Test Section',
              subtitle: 'Section description here',
              actionLabel: 'View All',
              onAction: () => actionTapped = true,
            ),
          ),
        ),
      );

      expect(find.text('TEST SECTION'), findsOneWidget);
      expect(find.text('Section description here'), findsOneWidget);
      expect(find.text('View All'), findsOneWidget);

      await tester.tap(find.text('View All'));
      expect(actionTapped, isTrue);
    });

    testWidgets('AppModalHeader renders title, subtitle, icon and closes', (tester) async {
      bool closed = false;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: Scaffold(
            body: AppModalHeader(
              title: 'Create Session',
              subtitle: 'Configure academic dates',
              icon: Icons.calendar_today_rounded,
              onClose: () => closed = true,
            ),
          ),
        ),
      );

      expect(find.text('Create Session'), findsOneWidget);
      expect(find.text('Configure academic dates'), findsOneWidget);
      expect(find.byIcon(Icons.calendar_today_rounded), findsOneWidget);

      await tester.tap(find.byIcon(Icons.close_rounded));
      expect(closed, isTrue);
    });

    testWidgets('AppLoadingView renders indicator and message in compact and full modes', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: const Scaffold(
            body: Column(
              children: [
                AppLoadingView(message: 'Full loading...'),
                AppLoadingView(message: 'Compact loading...', isCompact: true),
              ],
            ),
          ),
        ),
      );

      expect(find.text('Full loading...'), findsOneWidget);
      expect(find.text('Compact loading...'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNWidgets(2));
    });

    testWidgets('AppCard, EmptyStateView, ErrorBanner, and StatusBadge adhere to enterprise tokens', (tester) async {
      bool cardTapped = false;
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.lightTheme,
          home: Scaffold(
            body: SingleChildScrollView(
              child: Column(
                children: [
                  AppCard(
                    onTap: () => cardTapped = true,
                    child: const Text('Card Content'),
                  ),
                  EmptyStateView(
                    icon: Icons.inbox_outlined,
                    title: 'Empty State',
                    description: 'No items found in this section.',
                    actionLabel: 'Add Item',
                    onAction: () {},
                  ),
                  const ErrorBanner(
                    message: 'Action failed. Please retry.',
                    isWarning: false,
                  ),
                  StatusBadge.active(),
                  StatusBadge.future(),
                  StatusBadge.role('school_admin'),
                ],
              ),
            ),
          ),
        ),
      );

      expect(find.text('Card Content'), findsOneWidget);
      await tester.tap(find.text('Card Content'));
      expect(cardTapped, isTrue);

      expect(find.text('Empty State'), findsOneWidget);
      expect(find.text('No items found in this section.'), findsOneWidget);
      expect(find.text('Add Item'), findsOneWidget);

      expect(find.text('Action failed. Please retry.'), findsOneWidget);
      expect(find.text('Active'), findsOneWidget);
      expect(find.text('Future'), findsOneWidget);
      expect(find.text('School Admin'), findsOneWidget);
    });
  });
}
