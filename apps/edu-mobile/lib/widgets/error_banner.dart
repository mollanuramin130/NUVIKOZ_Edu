import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

class ErrorBanner extends StatelessWidget {
  const ErrorBanner({
    super.key,
    required this.message,
    this.isWarning = false,
    this.onDismiss,
  });

  final String message;
  final bool isWarning;
  final VoidCallback? onDismiss;

  @override
  Widget build(BuildContext context) {
    final bgColor = isWarning ? AppColors.warningLight : AppColors.errorLight;
    final fgColor = isWarning ? AppColors.warning : AppColors.error;
    final icon = isWarning ? Icons.warning_amber_rounded : Icons.error_outline_rounded;

    return Container(
      margin: const EdgeInsets.symmetric(vertical: 8),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
      decoration: BoxDecoration(
        color: bgColor,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: fgColor.withValues(alpha: 0.3)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, color: fgColor, size: 20),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: TextStyle(
                color: fgColor,
                fontSize: 13,
                fontWeight: FontWeight.w500,
                height: 1.35,
              ),
            ),
          ),
          if (onDismiss != null)
            GestureDetector(
              onTap: onDismiss,
              child: Icon(Icons.close, color: fgColor, size: 18),
            ),
        ],
      ),
    );
  }
}
