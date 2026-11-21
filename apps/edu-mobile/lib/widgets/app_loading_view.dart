import 'package:flutter/material.dart';
import '../theme/app_colors.dart';

class AppLoadingView extends StatelessWidget {
  const AppLoadingView({
    super.key,
    this.message,
    this.isCompact = false,
  });

  final String? message;
  final bool isCompact;

  @override
  Widget build(BuildContext context) {
    final indicator = const SizedBox(
      width: 28,
      height: 28,
      child: CircularProgressIndicator(
        strokeWidth: 2.5,
        valueColor: AlwaysStoppedAnimation<Color>(AppColors.primaryBlue),
      ),
    );

    if (isCompact) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: Center(
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              indicator,
              if (message != null) ...[
                const SizedBox(width: 12),
                Text(
                  message!,
                  style: const TextStyle(
                    fontSize: 13,
                    color: AppColors.secondaryText,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
            ],
          ),
        ),
      );
    }

    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            indicator,
            if (message != null) ...[
              const SizedBox(height: 16),
              Text(
                message!,
                textAlign: TextAlign.center,
                style: const TextStyle(
                  fontSize: 14,
                  color: AppColors.secondaryText,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}
