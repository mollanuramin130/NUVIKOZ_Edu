import 'package:flutter/material.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_spacing.dart';
import '../../theme/app_typography.dart';
import '../../widgets/app_card.dart';
import '../../widgets/app_section_header.dart';
import '../../widgets/preview_banner.dart';

/// Academic Analytics & Marksheet Visual Presentation Prototype.
/// Spec: AG-UI-001 Section 7.E, 10, 12.
/// Strictly labeled synthetic preview prototype. Zero live backend data.
class AcademicAnalyticsScreen extends StatelessWidget {
  const AcademicAnalyticsScreen({
    super.key,
    this.isPreviewMode = true,
  });

  final bool isPreviewMode;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.lightBackground,
      appBar: AppBar(
        title: const Text('Academic Analytics & Marksheet'),
        actions: [
          Container(
            margin: const EdgeInsets.only(right: 12),
            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
            decoration: BoxDecoration(
              color: AppColors.accentViolet.withValues(alpha: 0.2),
              borderRadius: BorderRadius.circular(AppSpacing.radiusPill),
              border: Border.all(color: Colors.white24),
            ),
            child: const Text(
              'PROTOTYPE — NOT LIVE SCHOOL DATA',
              style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: Colors.white),
            ),
          ),
        ],
      ),
      body: Column(
        children: [
          if (isPreviewMode) const PreviewBanner(compact: true),
          Expanded(
            child: ListView(
              padding: AppSpacing.screenPadding,
              children: [
                // Prototype Notice Banner (AG-UI-005 Requirement 4)
                Container(
                  padding: const EdgeInsets.all(AppSpacing.md),
                  decoration: BoxDecoration(
                    color: AppColors.violetLight,
                    borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
                    border: Border.all(color: AppColors.accentViolet.withValues(alpha: 0.3)),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.info_outline_rounded, color: AppColors.accentViolet, size: 20),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: const [
                            Text(
                              'PROTOTYPE — NOT LIVE SCHOOL DATA',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w800,
                                color: AppColors.accentViolet,
                                letterSpacing: 0.3,
                              ),
                            ),
                            SizedBox(height: 4),
                            Text(
                              'SYNTHETIC PREVIEW PROTOTYPE: Demonstrating proposed attendance and marks visualization components.',
                              style: TextStyle(
                                fontSize: 11,
                                fontWeight: FontWeight.w600,
                                color: AppColors.secondaryText,
                                height: 1.35,
                              ),
                            ),
                            SizedBox(height: 4),
                            Text(
                              'Attendance tracking and official gradebook calculations are scheduled for Phase 02 backend integration. These metrics do not reflect live school records.',
                              style: TextStyle(
                                fontSize: 10,
                                color: AppColors.mutedText,
                                height: 1.3,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // 1. Attendance Donut & Insights Card
                const AppSectionHeader(
                  title: 'School Attendance Overview',
                  subtitle: 'Aggregate student presence across all grades',
                ),
                _buildAttendanceCard(),
                const SizedBox(height: 20),

                // 2. Marks Distribution Chart Card
                const AppSectionHeader(
                  title: 'Academic Grade Distribution',
                  subtitle: 'Mid-term performance metrics by grade tier',
                ),
                _buildGradeDistributionCard(),
                const SizedBox(height: 20),

                // 3. Marksheet Style Layout Prototype
                const AppSectionHeader(
                  title: 'Student Marksheet Report Card',
                  subtitle: 'Official assessment summary layout format',
                ),
                _buildMarksheetCard(),
                const SizedBox(height: 24),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildAttendanceCard() {
    return AppCard(
      accentColor: AppColors.premiumTeal,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: const [
                    Text('Overall Attendance Rate', style: AppTypography.titleSmall),
                    SizedBox(height: 2),
                    Text('Active Academic Term (2026-2027)', style: AppTypography.bodySmall),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                decoration: BoxDecoration(
                  color: AppColors.successLight,
                  borderRadius: BorderRadius.circular(AppSpacing.radiusPill),
                ),
                child: const Text(
                  'EXCELLENT',
                  style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.success),
                ),
              ),
            ],
          ),
          const SizedBox(height: 20),
          Row(
            children: [
              // Custom Donut Widget
              SizedBox(
                width: 110,
                height: 110,
                child: CustomPaint(
                  painter: _AttendanceDonutPainter(
                    presentRate: 0.942,
                    leaveRate: 0.038,
                    absentRate: 0.020,
                  ),
                  child: Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: const [
                        Text(
                          '94.2%',
                          style: TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.w800,
                            color: AppColors.primaryNavy,
                          ),
                        ),
                        Text(
                          'Present',
                          style: TextStyle(fontSize: 10, color: AppColors.secondaryText),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 24),
              // Legend
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _buildLegendItem('Present', '94.2%', AppColors.premiumTeal),
                    const SizedBox(height: 10),
                    _buildLegendItem('Excused Leave', '3.8%', AppColors.academicAmber),
                    const SizedBox(height: 10),
                    _buildLegendItem('Unexcused Absent', '2.0%', AppColors.error),
                  ],
                ),
              ),
            ],
          ),
          const Divider(height: 28),
          // Class Breakdown Progress Bars
          _buildClassProgressBar('Grade 10 (Section A, B)', 0.96),
          const SizedBox(height: 8),
          _buildClassProgressBar('Grade 9 (Section Rose)', 0.93),
          const SizedBox(height: 8),
          _buildClassProgressBar('Grade 11 & 12', 0.94),
        ],
      ),
    );
  }

  Widget _buildLegendItem(String label, String value, Color color) {
    return Row(
      children: [
        Container(
          width: 10,
          height: 10,
          decoration: BoxDecoration(
            color: color,
            shape: BoxShape.circle,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            label,
            style: const TextStyle(fontSize: 12, color: AppColors.secondaryText),
          ),
        ),
        Text(
          value,
          style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.primaryNavy),
        ),
      ],
    );
  }

  Widget _buildClassProgressBar(String className, double percentage) {
    final pctString = '${(percentage * 100).toInt()}%';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(className, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.primaryText)),
            Text(pctString, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.premiumTeal)),
          ],
        ),
        const SizedBox(height: 4),
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: percentage,
            backgroundColor: AppColors.border,
            valueColor: const AlwaysStoppedAnimation<Color>(AppColors.premiumTeal),
            minHeight: 6,
          ),
        ),
      ],
    );
  }

  Widget _buildGradeDistributionCard() {
    return AppCard(
      accentColor: AppColors.accentViolet,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: const [
              Text('Score Distribution', style: AppTypography.titleSmall),
              Text('450 Assessed', style: TextStyle(fontSize: 11, color: AppColors.secondaryText)),
            ],
          ),
          const SizedBox(height: 16),
          // Vertical Bar Chart Representation
          SizedBox(
            height: 140,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceEvenly,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                _buildBarCol('A+ (90-100)', 0.35, '35%', AppColors.accentViolet),
                _buildBarCol('A (80-89)', 0.42, '42%', AppColors.premiumTeal),
                _buildBarCol('B (70-79)', 0.18, '18%', AppColors.academicAmber),
                _buildBarCol('C (60-69)', 0.05, '5%', const Color(0xFF64748B)),
              ],
            ),
          ),
          const Divider(height: 24),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: const [
              _StatItem(label: 'Median Score', value: '86.4%'),
              _StatItem(label: 'Highest Subject', value: 'CS (96%)'),
              _StatItem(label: 'Pass Rate', value: '99.2%'),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildBarCol(String label, double ratio, String count, Color color) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.end,
      children: [
        Text(count, style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.primaryNavy)),
        const SizedBox(height: 4),
        Container(
          width: 38,
          height: 90 * ratio,
          decoration: BoxDecoration(
            color: color,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(6)),
          ),
        ),
        const SizedBox(height: 6),
        Text(
          label.split(' ')[0],
          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.secondaryText),
        ),
      ],
    );
  }

  Widget _buildMarksheetCard() {
    return AppCard(
      accentColor: AppColors.primaryNavy,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Marksheet Header
          Container(
            padding: const EdgeInsets.all(AppSpacing.md),
            decoration: BoxDecoration(
              color: AppColors.primaryNavy.withValues(alpha: 0.04),
              borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
              border: Border.all(color: AppColors.border),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'STUDENT PROGRESS REPORT',
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 0.6,
                        color: AppColors.primaryNavy,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: AppColors.successLight,
                        borderRadius: BorderRadius.circular(4),
                      ),
                      child: const Text(
                        'PASS',
                        style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.success),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: const [
                    Expanded(child: _InfoField(label: 'Student Name', value: 'Aarav Sharma')),
                    Expanded(child: _InfoField(label: 'Roll / ID', value: '10-A-04')),
                  ],
                ),
                const SizedBox(height: 6),
                Row(
                  children: const [
                    Expanded(child: _InfoField(label: 'Class', value: 'Grade 10 — Sec A')),
                    Expanded(child: _InfoField(label: 'Term', value: 'Mid-Term Exam')),
                  ],
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // Marks Table
          Table(
            columnWidths: const {
              0: FlexColumnWidth(2.2),
              1: FlexColumnWidth(1.0),
              2: FlexColumnWidth(1.1),
              3: FlexColumnWidth(0.9),
            },
            children: [
              TableRow(
                decoration: const BoxDecoration(
                  border: Border(bottom: BorderSide(color: AppColors.border, width: 1.5)),
                ),
                children: const [
                  Padding(padding: EdgeInsets.symmetric(vertical: 6), child: Text('SUBJECT', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.secondaryText))),
                  Padding(padding: EdgeInsets.symmetric(vertical: 6), child: Text('MAX', textAlign: TextAlign.center, style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.secondaryText))),
                  Padding(padding: EdgeInsets.symmetric(vertical: 6), child: Text('MARKS', textAlign: TextAlign.center, style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.secondaryText))),
                  Padding(padding: EdgeInsets.symmetric(vertical: 6), child: Text('GRADE', textAlign: TextAlign.right, style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.secondaryText))),
                ],
              ),
              _buildTableRow('Mathematics (MATH)', '100', '92', 'A+'),
              _buildTableRow('English (ENG)', '100', '85', 'A'),
              _buildTableRow('Physics (PHY)', '100', '88', 'A+'),
              _buildTableRow('Chemistry (CHEM)', '100', '79', 'B'),
              _buildTableRow('Computer Science (CS)', '100', '96', 'A+'),
            ],
          ),
          const Divider(height: 20),

          // Total & Result Summary
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: const [
              Text('Grand Total', style: TextStyle(fontSize: 13, fontWeight: FontWeight.w800, color: AppColors.primaryNavy)),
              Text('440 / 500 (88.0%)', style: TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: AppColors.premiumTeal)),
            ],
          ),
          const SizedBox(height: 4),
          const Text(
            'Remarks: First Class with Distinction. Excellent analytical aptitude in STEM subjects.',
            style: TextStyle(fontSize: 11, fontStyle: FontStyle.italic, color: AppColors.secondaryText),
          ),
        ],
      ),
    );
  }

  TableRow _buildTableRow(String subject, String max, String marks, String grade) {
    return TableRow(
      decoration: const BoxDecoration(
        border: Border(bottom: BorderSide(color: AppColors.borderSubtle)),
      ),
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(subject, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.primaryNavy)),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(max, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12, color: AppColors.secondaryText)),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(marks, textAlign: TextAlign.center, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.primaryNavy)),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 8),
          child: Text(grade, textAlign: TextAlign.right, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: AppColors.premiumTeal)),
        ),
      ],
    );
  }
}

class _AttendanceDonutPainter extends CustomPainter {
  _AttendanceDonutPainter({
    required this.presentRate,
    required this.leaveRate,
    required this.absentRate,
  });

  final double presentRate;
  final double leaveRate;
  final double absentRate;

  @override
  void paint(Canvas canvas, Size size) {
    final center = Offset(size.width / 2, size.height / 2);
    final radius = size.width / 2 - 8;
    const strokeWidth = 12.0;

    final paintBg = Paint()
      ..color = AppColors.border
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth;

    canvas.drawCircle(center, radius, paintBg);

    final paintPresent = Paint()
      ..color = AppColors.premiumTeal
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round;

    final paintLeave = Paint()
      ..color = AppColors.academicAmber
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round;

    final paintAbsent = Paint()
      ..color = AppColors.error
      ..style = PaintingStyle.stroke
      ..strokeWidth = strokeWidth
      ..strokeCap = StrokeCap.round;

    double startAngle = -3.14159 / 2;
    final sweepPresent = 2 * 3.14159 * presentRate;
    canvas.drawArc(Rect.fromCircle(center: center, radius: radius), startAngle, sweepPresent, false, paintPresent);

    startAngle += sweepPresent;
    final sweepLeave = 2 * 3.14159 * leaveRate;
    canvas.drawArc(Rect.fromCircle(center: center, radius: radius), startAngle, sweepLeave, false, paintLeave);

    startAngle += sweepLeave;
    final sweepAbsent = 2 * 3.14159 * absentRate;
    canvas.drawArc(Rect.fromCircle(center: center, radius: radius), startAngle, sweepAbsent, false, paintAbsent);
  }

  @override
  bool shouldRepaint(covariant _AttendanceDonutPainter oldDelegate) => false;
}

class _InfoField extends StatelessWidget {
  const _InfoField({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(fontSize: 10, color: AppColors.mutedText)),
        Text(value, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.primaryNavy)),
      ],
    );
  }
}

class _StatItem extends StatelessWidget {
  const _StatItem({required this.label, required this.value});
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(value, style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: AppColors.primaryNavy)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(fontSize: 10, color: AppColors.secondaryText)),
      ],
    );
  }
}
