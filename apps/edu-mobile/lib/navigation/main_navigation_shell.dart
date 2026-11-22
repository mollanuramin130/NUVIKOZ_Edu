import 'package:flutter/material.dart';
import '../api_client.dart';
import '../academic/controllers/academic_controller.dart';
import '../academic/repository/academic_repository.dart';
import '../academic/screens/academic_workspace_screen.dart';
import '../academic/screens/academic_analytics_screen.dart';
import '../auth/auth_controller.dart';
import '../auth/screens/security_screen.dart';
import '../dashboard/screens/dashboard_screen.dart';
import '../preview/preview_academic_repository.dart';
import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';
import '../widgets/brand_logo.dart';
import '../widgets/status_badge.dart';

/// NUVIKOZ Edu Unified Global Navigation Shell.
/// Spec: AG-UI-001 Section 6.
class MainNavigationShell extends StatefulWidget {
  const MainNavigationShell({
    super.key,
    required this.authController,
    this.academicRepository,
    this.isPreviewMode = false,
    this.initialTab = 0,
  });

  final AuthController authController;
  final AcademicRepository? academicRepository;
  final bool isPreviewMode;
  final int initialTab;

  @override
  State<MainNavigationShell> createState() => _MainNavigationShellState();
}

class _MainNavigationShellState extends State<MainNavigationShell> {
  late int _currentIndex;
  late final AcademicController _academicController;
  late final AcademicRepository _repo;
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();

  @override
  void initState() {
    super.initState();
    _currentIndex = widget.initialTab;
    _repo = widget.academicRepository ??
        HttpAcademicRepository(
          api: (widget.authController.api is AcademicApiTransport)
              ? (widget.authController.api as AcademicApiTransport)
              : _AcademicTransportAdapter(widget.authController.api),
          sessionProvider: () => widget.authController.session,
        );
    _academicController = AcademicController(_repo);
  }

  @override
  void dispose() {
    _academicController.dispose();
    super.dispose();
  }

  void _switchTab(int index) {
    if (_currentIndex == index) return;
    setState(() => _currentIndex = index);
  }

  void _navigateToAcademicTab(int resourceTabIndex) {
    setState(() {
      _currentIndex = 1; // Academic tab
      _academicController.setTab(resourceTabIndex);
    });
  }

  Future<void> _logout() async {
    if (widget.isPreviewMode) {
      Navigator.of(context).pop();
      return;
    }
    await widget.authController.logout();
  }

  void _showComingLaterDialog(String featureName) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppSpacing.radiusLg)),
        title: Row(
          children: [
            const Icon(Icons.info_outline_rounded, color: AppColors.primaryNavy),
            const SizedBox(width: 8),
            Text(featureName, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: AppColors.amberLight,
                borderRadius: BorderRadius.circular(4),
              ),
              child: const Text(
                'COMING LATER / NOT IMPLEMENTED',
                style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: AppColors.amberDark),
              ),
            ),
            const SizedBox(height: 12),
            Text(
              '$featureName is scheduled for Milestone 3 development and is not yet available in the active mobile contract.',
              style: const TextStyle(fontSize: 13, color: AppColors.secondaryText, height: 1.4),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Understood'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final session = widget.authController.session;
    final schoolName = session?.school != null
        ? 'School ${session!.school.substring(0, session.school.length > 8 ? 8 : session.school.length)}'
        : 'Springdale Academy';

    return PopScope(
      canPop: _currentIndex == 0 && !(_scaffoldKey.currentState?.isDrawerOpen ?? false),
      onPopInvokedWithResult: (didPop, result) {
        if (didPop) return;
        if (_scaffoldKey.currentState?.isDrawerOpen ?? false) {
          _scaffoldKey.currentState?.closeDrawer();
        } else if (_currentIndex != 0) {
          setState(() => _currentIndex = 0);
        }
      },
      child: Scaffold(
        key: _scaffoldKey,
        backgroundColor: AppColors.lightBackground,
        drawer: _buildDrawer(session),
        body: IndexedStack(
          index: _currentIndex,
          children: [
            // 0: Dashboard (Home)
            DashboardScreen(
              authController: widget.authController,
              academicRepository: _repo,
              academicController: _academicController,
              isPreviewMode: widget.isPreviewMode,
              onOpenDrawer: () => _scaffoldKey.currentState?.openDrawer(),
              onNavigateToAcademic: _navigateToAcademicTab,
              onNavigateToAnalytics: () => _switchTab(2),
              onNavigateToSecurity: () => _switchTab(3),
            ),

            // 1: Academic Workspace
            AcademicWorkspaceScreen(
              controller: _academicController,
              schoolName: schoolName,
              authController: widget.authController,
              isPreviewMode: widget.isPreviewMode,
              previewRepository: _repo is PreviewAcademicRepository ? _repo : null,
              onOpenDrawer: () => _scaffoldKey.currentState?.openDrawer(),
            ),

            // 2: Academic Analytics & Marksheet Preview Prototype
            AcademicAnalyticsScreen(
              isPreviewMode: widget.isPreviewMode,
            ),

            // 3: Profile & Device Security
            SecurityScreen(
              authController: widget.authController,
              isPreviewMode: widget.isPreviewMode,
              onOpenDrawer: () => _scaffoldKey.currentState?.openDrawer(),
            ),
          ],
        ),
        bottomNavigationBar: Container(
          decoration: const BoxDecoration(
            border: Border(top: BorderSide(color: AppColors.border, width: 1)),
          ),
          child: NavigationBar(
            selectedIndex: _currentIndex,
            onDestinationSelected: _switchTab,
            backgroundColor: Colors.white,
            elevation: 0,
            indicatorColor: AppColors.tealLight,
            destinations: const [
              NavigationDestination(
                icon: Icon(Icons.dashboard_outlined),
                selectedIcon: Icon(Icons.dashboard_rounded, color: AppColors.premiumTeal),
                label: 'Home',
              ),
              NavigationDestination(
                icon: Icon(Icons.school_outlined),
                selectedIcon: Icon(Icons.school_rounded, color: AppColors.premiumTeal),
                label: 'Academic',
              ),
              NavigationDestination(
                icon: Icon(Icons.insights_outlined),
                selectedIcon: Icon(Icons.insights_rounded, color: AppColors.premiumTeal),
                label: 'Analytics',
              ),
              NavigationDestination(
                icon: Icon(Icons.person_outline_rounded),
                selectedIcon: Icon(Icons.person_rounded, color: AppColors.premiumTeal),
                label: 'Profile',
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildDrawer(dynamic session) {
    final role = session?.role ?? 'school_admin';
    final schoolContext = session?.school ?? 'Springdale Academy';

    return Drawer(
      child: Column(
        children: [
          // Header
          Container(
            width: double.infinity,
            padding: const EdgeInsets.only(top: 50, bottom: 20, left: 20, right: 20),
            decoration: const BoxDecoration(
              gradient: AppColors.headerGradient,
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const NuvikozBrandHeader(isLight: true, markSize: 36),
                const SizedBox(height: 16),
                Text(
                  widget.isPreviewMode ? 'Springdale Academy' : 'School $schoolContext',
                  style: const TextStyle(
                    fontSize: 15,
                    fontWeight: FontWeight.w700,
                    color: Colors.white,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 4),
                Row(
                  children: [
                    StatusBadge.role(role),
                    const SizedBox(width: 8),
                    if (widget.isPreviewMode)
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                        decoration: BoxDecoration(
                          color: AppColors.academicAmber,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: const Text(
                          'PREVIEW',
                          style: TextStyle(fontSize: 9, fontWeight: FontWeight.w800, color: AppColors.primaryNavy),
                        ),
                      ),
                  ],
                ),
              ],
            ),
          ),

          // Drawer Menu List
          Expanded(
            child: ListView(
              padding: const EdgeInsets.symmetric(vertical: 8),
              children: [
                _buildDrawerSectionTitle('CORE WORKSPACE'),
                _buildDrawerItem(
                  icon: Icons.dashboard_rounded,
                  title: 'Dashboard (Home)',
                  isSelected: _currentIndex == 0,
                  onTap: () {
                    Navigator.of(context).pop();
                    _switchTab(0);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.school_rounded,
                  title: 'Academic Catalog',
                  isSelected: _currentIndex == 1,
                  onTap: () {
                    Navigator.of(context).pop();
                    _switchTab(1);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.insights_rounded,
                  title: 'Analytics & Marksheet',
                  badge: 'PROTOTYPE',
                  isSelected: _currentIndex == 2,
                  onTap: () {
                    Navigator.of(context).pop();
                    _switchTab(2);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.security_rounded,
                  title: 'Device Security & Profile',
                  isSelected: _currentIndex == 3,
                  onTap: () {
                    Navigator.of(context).pop();
                    _switchTab(3);
                  },
                ),

                const Divider(height: 24),
                _buildDrawerSectionTitle('ACADEMIC CATALOG (6 MODULES)'),
                _buildDrawerItem(
                  icon: Icons.calendar_today_rounded,
                  title: 'Sessions',
                  onTap: () {
                    Navigator.of(context).pop();
                    _navigateToAcademicTab(0);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.meeting_room_rounded,
                  title: 'Classes',
                  onTap: () {
                    Navigator.of(context).pop();
                    _navigateToAcademicTab(1);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.view_agenda_rounded,
                  title: 'Class Offerings',
                  onTap: () {
                    Navigator.of(context).pop();
                    _navigateToAcademicTab(2);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.groups_rounded,
                  title: 'Sections',
                  onTap: () {
                    Navigator.of(context).pop();
                    _navigateToAcademicTab(3);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.menu_book_rounded,
                  title: 'Subjects',
                  onTap: () {
                    Navigator.of(context).pop();
                    _navigateToAcademicTab(4);
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.alt_route_rounded,
                  title: 'Subject Offerings',
                  onTap: () {
                    Navigator.of(context).pop();
                    _navigateToAcademicTab(5);
                  },
                ),

                const Divider(height: 24),
                _buildDrawerSectionTitle('PLANNED MODULES (COMING LATER)'),
                _buildDrawerItem(
                  icon: Icons.schedule_rounded,
                  title: 'Timetable',
                  badge: 'COMING LATER',
                  badgeColor: AppColors.secondaryText,
                  onTap: () {
                    Navigator.of(context).pop();
                    _showComingLaterDialog('Timetable');
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.fact_check_rounded,
                  title: 'Attendance Register',
                  badge: 'COMING LATER',
                  badgeColor: AppColors.secondaryText,
                  onTap: () {
                    Navigator.of(context).pop();
                    _showComingLaterDialog('Attendance Register');
                  },
                ),
                _buildDrawerItem(
                  icon: Icons.grade_rounded,
                  title: 'Marks & Results',
                  badge: 'COMING LATER',
                  badgeColor: AppColors.secondaryText,
                  onTap: () {
                    Navigator.of(context).pop();
                    _showComingLaterDialog('Marks & Results');
                  },
                ),
              ],
            ),
          ),

          // Drawer Footer
          Container(
            padding: const EdgeInsets.all(16),
            decoration: const BoxDecoration(
              border: Border(top: BorderSide(color: AppColors.border, width: 1)),
            ),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _logout,
                    icon: const Icon(Icons.logout_rounded, size: 16),
                    label: Text(widget.isPreviewMode ? 'Exit Preview' : 'Sign Out'),
                    style: OutlinedButton.styleFrom(
                      foregroundColor: AppColors.error,
                      side: const BorderSide(color: Color(0xFFFCA5A5)),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildDrawerSectionTitle(String title) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Text(
        title,
        style: const TextStyle(
          fontSize: 10,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.8,
          color: AppColors.mutedText,
        ),
      ),
    );
  }

  Widget _buildDrawerItem({
    required IconData icon,
    required String title,
    bool isSelected = false,
    String? badge,
    Color? badgeColor,
    required VoidCallback onTap,
  }) {
    final color = isSelected ? AppColors.premiumTeal : AppColors.primaryNavy;
    return Container(
      margin: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
      decoration: BoxDecoration(
        color: isSelected ? AppColors.tealLight : Colors.transparent,
        borderRadius: BorderRadius.circular(AppSpacing.radiusMd),
      ),
      child: Material(
        color: Colors.transparent,
        child: ListTile(
          leading: Icon(icon, color: color, size: 20),
          title: Text(
            title,
            style: TextStyle(
              fontSize: 13,
              fontWeight: isSelected ? FontWeight.w700 : FontWeight.w600,
              color: color,
            ),
          ),
          trailing: badge != null
              ? Container(
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(
                    color: (badgeColor ?? AppColors.accentViolet).withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(4),
                  ),
                  child: Text(
                    badge,
                    style: TextStyle(
                      fontSize: 9,
                      fontWeight: FontWeight.w800,
                      color: badgeColor ?? AppColors.accentViolet,
                    ),
                  ),
                )
              : null,
          dense: true,
          onTap: onTap,
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
