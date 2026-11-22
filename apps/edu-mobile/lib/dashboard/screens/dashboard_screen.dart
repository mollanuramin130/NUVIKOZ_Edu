import 'package:flutter/material.dart';
import '../../api_client.dart';
import '../../academic/controllers/academic_controller.dart';
import '../../academic/repository/academic_repository.dart';
import '../../academic/screens/academic_workspace_screen.dart';
import '../../academic/screens/academic_analytics_screen.dart';
import '../../auth/auth_controller.dart';
import '../../auth/screens/security_screen.dart';
import '../../preview/preview_academic_repository.dart';
import '../../theme/app_colors.dart';
import '../../theme/app_spacing.dart';
import '../../widgets/app_card.dart';
import '../../widgets/app_metric_card.dart';
import '../../widgets/app_section_header.dart';
import '../../widgets/brand_logo.dart';
import '../../widgets/error_banner.dart';
import '../../widgets/preview_banner.dart';
import '../../widgets/status_badge.dart';

/// NUVIKOZ Edu Premium Executive Dashboard.
/// Spec: AG-UI-001 Section 7.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({
    super.key,
    required this.authController,
    this.academicRepository,
    this.academicController,
    this.isPreviewMode = false,
    this.onOpenDrawer,
    this.onNavigateToAcademic,
    this.onNavigateToAnalytics,
    this.onNavigateToSecurity,
  });

  final AuthController authController;
  final AcademicRepository? academicRepository;
  final AcademicController? academicController;
  final bool isPreviewMode;
  final VoidCallback? onOpenDrawer;
  final void Function(int tabIndex)? onNavigateToAcademic;
  final VoidCallback? onNavigateToAnalytics;
  final VoidCallback? onNavigateToSecurity;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Map<String, dynamic>? _schoolDetails;
  bool _loadingSchool = false;
  String? _schoolError;

  bool _loadingMetrics = false;
  String? _metricsError;
  int? _sessionCount;
  String? _activeSessionName;
  int? _classCount;
  int? _sectionCount;
  int? _subjectCount;

  AcademicRepository get _repo =>
      widget.academicRepository ??
      widget.academicController?.repository ??
      HttpAcademicRepository(
        api: (widget.authController.api is AcademicApiTransport)
            ? (widget.authController.api as AcademicApiTransport)
            : _AcademicTransportAdapter(widget.authController.api),
        sessionProvider: () => widget.authController.session,
      );

  @override
  void initState() {
    super.initState();
    _fetchSchoolDetails();
    _loadCatalogMetrics();
    widget.academicController?.addListener(_onAcademicControllerChanged);
  }

  void _onAcademicControllerChanged() {
    if (widget.academicController != null &&
        !widget.academicController!.isLoading &&
        !widget.academicController!.isCreating) {
      _loadCatalogMetrics();
    }
  }

  @override
  void dispose() {
    widget.academicController?.removeListener(_onAcademicControllerChanged);
    super.dispose();
  }

  Future<void> _loadCatalogMetrics() async {
    final session = widget.authController.session;
    if (session == null) return;
    final token = session.token;
    final school = session.school;

    setState(() {
      _loadingMetrics = true;
      _metricsError = null;
    });

    try {
      final repo = _repo;
      final results = await Future.wait([
        repo.getSessions(),
        repo.getClasses(),
        repo.getSections(),
        repo.getSubjects(),
      ]);

      if (!mounted) return;
      final current = widget.authController.session;
      if (current?.token != token || current?.school != school) return;

      final sessions = results[0] as List<dynamic>;
      final classes = results[1] as List<dynamic>;
      final sections = results[2] as List<dynamic>;
      final subjects = results[3] as List<dynamic>;

      final active = sessions.where((s) => (s as dynamic).state == 'active').toList();
      final activeName = active.isNotEmpty ? (active.first as dynamic).name as String? : null;

      setState(() {
        _sessionCount = sessions.length;
        _activeSessionName = activeName;
        _classCount = classes.length;
        _sectionCount = sections.length;
        _subjectCount = subjects.length;
        _loadingMetrics = false;
      });
    } catch (e) {
      if (!mounted) return;
      final current = widget.authController.session;
      if (current?.token != token || current?.school != school) return;

      setState(() {
        _loadingMetrics = false;
        _metricsError = e is ApiFailure ? e.toString() : 'Could not load summary metrics';
      });
    }
  }

  Future<void> _fetchSchoolDetails() async {
    final session = widget.authController.session;
    if (session == null) return;
    setState(() {
      _loadingSchool = true;
      _schoolError = null;
    });
    try {
      final res = await widget.authController.protectedGet('/api/v1/schools/${session.school}');
      if (mounted) {
        setState(() {
          _schoolDetails = res;
          _loadingSchool = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _schoolError = 'Could not load school profile';
          _loadingSchool = false;
        });
      }
    }
  }

  void _openAcademicWorkspace([int tabIndex = 0]) {
    if (widget.onNavigateToAcademic != null) {
      widget.onNavigateToAcademic!(tabIndex);
      return;
    }

    final session = widget.authController.session;
    if (session == null) return;

    final repo = widget.academicRepository ??
        HttpAcademicRepository(
          api: (widget.authController.api is AcademicApiTransport)
              ? (widget.authController.api as AcademicApiTransport)
              : _AcademicTransportAdapter(widget.authController.api),
          sessionProvider: () => widget.authController.session,
        );
    final academicController = AcademicController(repo);
    academicController.setTab(tabIndex);

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => AcademicWorkspaceScreen(
          controller: academicController,
          schoolName: _schoolDetails?['name'] as String? ?? 'School ${session.school.substring(0, 6)}',
          authController: widget.authController,
          isPreviewMode: widget.isPreviewMode,
          previewRepository: widget.academicRepository is PreviewAcademicRepository
              ? (widget.academicRepository as PreviewAcademicRepository)
              : null,
        ),
      ),
    );
  }

  void _openAnalytics() {
    if (widget.onNavigateToAnalytics != null) {
      widget.onNavigateToAnalytics!();
      return;
    }
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => AcademicAnalyticsScreen(
          isPreviewMode: widget.isPreviewMode,
        ),
      ),
    );
  }

  void _openSecurityScreen() {
    if (widget.onNavigateToSecurity != null) {
      widget.onNavigateToSecurity!();
      return;
    }
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => SecurityScreen(
          authController: widget.authController,
          isPreviewMode: widget.isPreviewMode,
        ),
      ),
    );
  }

  Future<void> _logout() async {
    if (widget.isPreviewMode) {
      Navigator.of(context).pop();
      return;
    }
    await widget.authController.logout();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.authController,
      builder: (context, _) {
        final session = widget.authController.session;
        if (session == null) {
          return const Scaffold(
            body: Center(child: Text('Session expired')),
          );
        }

        final schoolName = _schoolDetails?['name'] as String? ??
            (widget.isPreviewMode ? 'Springdale Academy' : 'School ${session.school.substring(0, session.school.length > 8 ? 8 : session.school.length)}');

        return Scaffold(
          backgroundColor: AppColors.lightBackground,
          appBar: AppBar(
            leading: widget.onOpenDrawer != null
                ? IconButton(
                    icon: const Icon(Icons.menu_rounded),
                    tooltip: 'Navigation Menu',
                    onPressed: widget.onOpenDrawer,
                  )
                : null,
            title: Text(schoolName),
            actions: [
              IconButton(
                icon: const Icon(Icons.security_rounded),
                tooltip: 'Device Security',
                onPressed: _openSecurityScreen,
              ),
            ],
          ),
          body: Column(
            children: [
              if (widget.isPreviewMode) const PreviewBanner(),
              if (_loadingSchool || _loadingMetrics)
                const LinearProgressIndicator(
                  minHeight: 2,
                  backgroundColor: Colors.transparent,
                  valueColor: AlwaysStoppedAnimation<Color>(AppColors.premiumTeal),
                ),
              Expanded(
                child: RefreshIndicator(
                  onRefresh: () async {
                    await Future.wait([
                      _fetchSchoolDetails(),
                      _loadCatalogMetrics(),
                    ]);
                  },
                  child: ListView(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: AppSpacing.screenPadding,
                    children: [
                    if (_schoolError != null) ...[
                      ErrorBanner(message: _schoolError!),
                      const SizedBox(height: 12),
                    ],

                    // A. Welcome & Identity Card (Section 7.A)
                    AppCard(
                      accentColor: AppColors.premiumTeal,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Expanded(
                                child: Row(
                                  children: [
                                    CircleAvatar(
                                      radius: 22,
                                      backgroundColor: AppColors.tealLight,
                                      child: const Icon(Icons.person, color: AppColors.premiumTeal, size: 24),
                                    ),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          const Text(
                                            'Signed-in identity',
                                            style: TextStyle(
                                              fontSize: 16,
                                              fontWeight: FontWeight.w700,
                                              color: AppColors.primaryText,
                                            ),
                                          ),
                                          Text(
                                            'Role: ${session.role}',
                                            style: const TextStyle(fontSize: 12, color: AppColors.secondaryText),
                                          ),
                                          Text(
                                            'School context: ${session.school}',
                                            overflow: TextOverflow.ellipsis,
                                            style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              StatusBadge.role(session.role),
                            ],
                          ),
                          const SizedBox(height: 14),
                          const Divider(),
                          const SizedBox(height: 10),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              Row(
                                children: const [
                                  Icon(Icons.check_circle_rounded, size: 14, color: AppColors.premiumTeal),
                                  SizedBox(width: 6),
                                  Text(
                                    'Academic Session Active (2026-2027)',
                                    style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: AppColors.premiumTeal),
                                  ),
                                ],
                              ),
                              const NuvikozBrandMark(size: 24),
                            ],
                          ),
                          const SizedBox(height: 12),
                          OutlinedButton(
                            onPressed: _logout,
                            child: Text(widget.isPreviewMode ? 'Exit Preview' : 'Sign out'),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),

                    // B. Quick Action Grid (2x2)
                    const AppSectionHeader(
                      title: 'Quick Actions',
                      subtitle: 'Direct shortcuts to authorized academic workflows',
                    ),
                    GridView.count(
                      crossAxisCount: 2,
                      crossAxisSpacing: 12,
                      mainAxisSpacing: 12,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      childAspectRatio: 1.5,
                      children: [
                        _buildQuickActionTile(
                          icon: Icons.school_rounded,
                          title: 'Academic Catalog',
                          subtitle: '6 Resources',
                          accentColor: AppColors.premiumTeal,
                          onTap: () => _openAcademicWorkspace(0),
                        ),
                        _buildQuickActionTile(
                          icon: Icons.insights_rounded,
                          title: 'Analytics & Marks',
                          subtitle: 'Visual Prototype',
                          accentColor: AppColors.accentViolet,
                          onTap: _openAnalytics,
                        ),
                        _buildQuickActionTile(
                          icon: Icons.security_rounded,
                          title: 'Device Security',
                          subtitle: 'Keystore AES-256',
                          accentColor: AppColors.primaryNavy,
                          onTap: _openSecurityScreen,
                        ),
                        _buildQuickActionTile(
                          icon: Icons.swap_horiz_rounded,
                          title: 'Switch School',
                          subtitle: widget.isPreviewMode ? 'Exit Mode' : 'Change School',
                          accentColor: AppColors.academicAmber,
                          onTap: _logout,
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // C. Academic Summary Metric Cards
                    AppSectionHeader(
                      title: 'Academic Summary',
                      subtitle: _loadingMetrics
                          ? 'Refreshing live catalog metrics...'
                          : (_metricsError != null
                              ? 'Catalog summary unavailable'
                              : 'Configured catalog records for this school'),
                      actionLabel: 'View All',
                      onAction: () => _openAcademicWorkspace(0),
                    ),
                    if (_metricsError != null) ...[
                      Container(
                        margin: const EdgeInsets.only(bottom: 12),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(
                          color: AppColors.amberLight,
                          borderRadius: BorderRadius.circular(AppSpacing.radiusSm),
                          border: Border.all(color: AppColors.academicAmber.withValues(alpha: 0.3)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.info_outline, color: AppColors.academicAmber, size: 16),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'Live summary unavailable: $_metricsError',
                                style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                              ),
                            ),
                            TextButton(
                              onPressed: _loadCatalogMetrics,
                              style: TextButton.styleFrom(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                minimumSize: Size.zero,
                                tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                              ),
                              child: const Text('Retry', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
                            ),
                          ],
                        ),
                      ),
                    ],
                    GridView.count(
                      crossAxisCount: 2,
                      crossAxisSpacing: 12,
                      mainAxisSpacing: 12,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      childAspectRatio: 1.35,
                      children: [
                        AppMetricCard(
                          title: 'Sessions',
                          value: _loadingMetrics
                              ? '...'
                              : (_sessionCount != null
                                  ? '$_sessionCount ${_sessionCount == 1 ? "Term" : "Terms"}'
                                  : (_metricsError != null ? 'Unavailable' : '3 Terms')),
                          subtitle: _loadingMetrics
                              ? 'Updating...'
                              : (_sessionCount != null
                                  ? (_activeSessionName != null
                                      ? 'Active: $_activeSessionName'
                                      : (_sessionCount == 0 ? 'No active term' : 'Catalog terms'))
                                  : (_metricsError != null ? 'Tap to retry' : '2026-2027 Active')),
                          icon: Icons.calendar_today_rounded,
                          accentColor: AppColors.primaryNavy,
                          badgeText: _loadingMetrics ? 'UPDATING' : 'CATALOG',
                          onTap: _metricsError != null ? _loadCatalogMetrics : () => _openAcademicWorkspace(0),
                        ),
                        AppMetricCard(
                          title: 'Classes',
                          value: _loadingMetrics
                              ? '...'
                              : (_classCount != null
                                  ? '$_classCount ${_classCount == 1 ? "Grade" : "Grades"}'
                                  : (_metricsError != null ? 'Unavailable' : '4 Grades')),
                          subtitle: _loadingMetrics
                              ? 'Updating...'
                              : (_classCount != null
                                  ? (_classCount == 0 ? 'No classes' : 'Grade Levels')
                                  : (_metricsError != null ? 'Tap to retry' : 'Grade 9 — 12')),
                          icon: Icons.meeting_room_rounded,
                          accentColor: AppColors.premiumTeal,
                          badgeText: 'LEVELS',
                          onTap: _metricsError != null ? _loadCatalogMetrics : () => _openAcademicWorkspace(1),
                        ),
                        AppMetricCard(
                          title: 'Sections',
                          value: _loadingMetrics
                              ? '...'
                              : (_sectionCount != null
                                  ? '$_sectionCount ${_sectionCount == 1 ? "Group" : "Groups"}'
                                  : (_metricsError != null ? 'Unavailable' : '3 Groups')),
                          subtitle: _loadingMetrics
                              ? 'Updating...'
                              : (_sectionCount != null
                                  ? (_sectionCount == 0 ? 'No sections' : 'Active Groups')
                                  : (_metricsError != null ? 'Tap to retry' : 'Sec A, B, Rose')),
                          icon: Icons.groups_rounded,
                          accentColor: AppColors.accentViolet,
                          badgeText: 'CAPACITY',
                          onTap: _metricsError != null ? _loadCatalogMetrics : () => _openAcademicWorkspace(3),
                        ),
                        AppMetricCard(
                          title: 'Subjects',
                          value: _loadingMetrics
                              ? '...'
                              : (_subjectCount != null
                                  ? '$_subjectCount ${_subjectCount == 1 ? "Course" : "Courses"}'
                                  : (_metricsError != null ? 'Unavailable' : '5 Courses')),
                          subtitle: _loadingMetrics
                              ? 'Updating...'
                              : (_subjectCount != null
                                  ? (_subjectCount == 0 ? 'No subjects' : 'STEM & Humanities')
                                  : (_metricsError != null ? 'Tap to retry' : 'STEM & Humanities')),
                          icon: Icons.menu_book_rounded,
                          accentColor: AppColors.academicAmber,
                          badgeText: 'ASSIGNED',
                          onTap: _metricsError != null ? _loadCatalogMetrics : () => _openAcademicWorkspace(4),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // D. Academic Analytics Preview Card
                    const AppSectionHeader(
                      title: 'Analytics & Assessment Preview',
                      subtitle: 'Visual prototypes of planned student performance tools',
                    ),
                    AppCard(
                      accentColor: AppColors.accentViolet,
                      onTap: _openAnalytics,
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Container(
                                width: 32,
                                height: 32,
                                decoration: BoxDecoration(
                                  color: AppColors.violetLight,
                                  borderRadius: BorderRadius.circular(AppSpacing.radiusSm),
                                ),
                                child: const Icon(Icons.insights_rounded, size: 18, color: AppColors.accentViolet),
                              ),
                              const SizedBox(width: 10),
                              const Expanded(
                                child: Text(
                                  'Attendance & Marksheet Preview',
                                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: AppColors.primaryNavy),
                                  overflow: TextOverflow.ellipsis,
                                ),
                              ),
                              const SizedBox(width: 8),
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                decoration: BoxDecoration(
                                  color: AppColors.violetLight,
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: const Text(
                                  'PROTOTYPE',
                                  style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: AppColors.accentViolet),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          const Text(
                            'View interactive donut chart for overall school attendance (94.2%), grade distribution bar chart, and official student marksheet report card layout.',
                            style: TextStyle(fontSize: 12, color: AppColors.secondaryText, height: 1.4),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            children: const [
                              Text(
                                'Explore Analytics & Marksheet',
                                style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.accentViolet),
                              ),
                              SizedBox(width: 4),
                              Icon(Icons.arrow_forward_rounded, size: 14, color: AppColors.accentViolet),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 20),

                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ),
          ],
        ),
      );
    },
    );
  }

  Widget _buildQuickActionTile({
    required IconData icon,
    required String title,
    required String subtitle,
    required Color accentColor,
    required VoidCallback onTap,
  }) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          decoration: BoxDecoration(
            color: AppColors.whiteSurface,
            borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
            border: Border.all(color: AppColors.border),
            boxShadow: const [
              BoxShadow(
                color: AppColors.cardShadow,
                blurRadius: 6,
                offset: Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Icon(icon, color: accentColor, size: 24),
              const SizedBox(height: 8),
              Text(
                title,
                style: const TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w700,
                  color: AppColors.primaryNavy,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              const SizedBox(height: 1),
              Text(
                subtitle,
                style: const TextStyle(
                  fontSize: 11,
                  color: AppColors.secondaryText,
                ),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _AcademicTransportAdapter implements AcademicApiTransport {
  _AcademicTransportAdapter(this.api);
  final ApiTransport api;

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
    Map<String, String>? queryParams,
    Map<String, String>? headers,
  }) {
    if (api is AcademicApiTransport) {
      return (api as AcademicApiTransport).send(
        method,
        path,
        body: body,
        token: token,
        school: school,
        queryParams: queryParams,
        headers: headers,
      );
    }
    return api.send(method, path, body: body, token: token, school: school);
  }
}
