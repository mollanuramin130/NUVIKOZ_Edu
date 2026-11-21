import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

class StatusBadge extends StatelessWidget {
  const StatusBadge({
    super.key,
    required this.label,
    this.color = AppColors.primaryBlue,
    this.backgroundColor,
  });

  final String label;
  final Color color;
  final Color? backgroundColor;

  factory StatusBadge.future() => const StatusBadge(
        label: 'Future',
        color: AppColors.info,
        backgroundColor: AppColors.infoLight,
      );

  factory StatusBadge.active() => const StatusBadge(
        label: 'Active',
        color: AppColors.success,
        backgroundColor: AppColors.successLight,
      );

  factory StatusBadge.completed() => const StatusBadge(
        label: 'Completed',
        color: AppColors.secondaryText,
        backgroundColor: Color(0xFFF1F5F9),
      );

  factory StatusBadge.compulsory() => const StatusBadge(
        label: 'Compulsory',
        color: AppColors.primaryBlue,
        backgroundColor: Color(0xFFEBF3FB),
      );

  factory StatusBadge.optional() => const StatusBadge(
        label: 'Optional',
        color: AppColors.warning,
        backgroundColor: AppColors.warningLight,
      );

  factory StatusBadge.role(String role) {
    switch (role) {
      case 'school_admin':
        return const StatusBadge(
          label: 'School Admin',
          color: AppColors.deepNavy,
          backgroundColor: Color(0xFFE2E8F0),
        );
      case 'principal':
        return const StatusBadge(
          label: 'Principal',
          color: AppColors.premiumTeal,
          backgroundColor: Color(0xFFE6F4F4),
        );
      case 'teacher':
        return const StatusBadge(
          label: 'Teacher',
          color: AppColors.primaryBlue,
          backgroundColor: Color(0xFFEBF3FB),
        );
      default:
        return StatusBadge(
          label: role.toUpperCase(),
          color: AppColors.secondaryText,
          backgroundColor: const Color(0xFFF1F5F9),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: backgroundColor ?? color.withValues(alpha: 0.1),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(
        label,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w600,
          letterSpacing: 0.1,
        ),
      ),
    );
  }
}
