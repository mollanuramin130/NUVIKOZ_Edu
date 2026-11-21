import 'package:flutter/material.dart';
import '../../api_client.dart';
import '../../academic/controllers/academic_controller.dart';
import '../../academic/repository/academic_repository.dart';
import '../../academic/screens/academic_workspace_screen.dart';
import '../../auth/auth_controller.dart';
import '../../auth/screens/security_screen.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_card.dart';
import '../../widgets/status_badge.dart';

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({
    super.key,
    required this.authController,
  });

  final AuthController authController;

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  Map<String, dynamic>? _schoolDetails;
  bool _loadingSchool = false;
  String? _schoolError;

  @override
  void initState() {
    super.initState();
    _fetchSchoolDetails();
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

  void _openAcademicWorkspace() {
    final session = widget.authController.session;
    if (session == null) return;

    final api = widget.authController.api;
    final AcademicApiTransport academicApi = (api is AcademicApiTransport)
        ? (api as AcademicApiTransport)
        : _AcademicTransportAdapter(api);
    final repo = HttpAcademicRepository(
      api: academicApi,
      sessionProvider: () => widget.authController.session,
    );
    final academicController = AcademicController(repo);

    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => AcademicWorkspaceScreen(
          controller: academicController,
          schoolName: _schoolDetails?['name'] as String? ?? 'School ${session.school.substring(0, 6)}',
        ),
      ),
    );
  }

  void _openSecurityScreen() {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => SecurityScreen(authController: widget.authController),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.authController,
      builder: (context, _) {
        final session = widget.authController.session;
        if (session == null) {
          return const Scaffold(body: Center(child: CircularProgressIndicator()));
        }

        final schoolName = _schoolDetails?['name'] as String? ?? 'School ${session.school.substring(0, 8)}';
        final schoolCode = _schoolDetails?['code'] as String? ?? session.school.substring(0, 6);

        return Scaffold(
          backgroundColor: AppColors.lightBackground,
          appBar: AppBar(
            title: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(6),
                  decoration: BoxDecoration(
                    color: AppColors.premiumTeal,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.school, size: 18, color: Colors.white),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(schoolName, overflow: TextOverflow.ellipsis),
                      Text(
                        'Code: $schoolCode',
                        style: const TextStyle(fontSize: 11, color: Color(0xFF94A3B8)),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            actions: [
              IconButton(
                icon: const Icon(Icons.security_outlined),
                tooltip: 'Device Security',
                onPressed: _openSecurityScreen,
              ),
              IconButton(
                icon: const Icon(Icons.logout_rounded),
                tooltip: 'Sign Out',
                onPressed: widget.authController.logout,
              ),
            ],
          ),
          body: RefreshIndicator(
            onRefresh: _fetchSchoolDetails,
            child: ListView(
              padding: const EdgeInsets.all(20),
              children: [
                // Identity Card
                AppCard(
                  padding: const EdgeInsets.all(20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Row(
                            children: [
                              CircleAvatar(
                                radius: 24,
                                backgroundColor: AppColors.primaryBlue.withValues(alpha: 0.1),
                                child: const Icon(Icons.person, color: AppColors.primaryBlue, size: 28),
                              ),
                              const SizedBox(width: 14),
                              Column(
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
                                    style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                                  ),
                                ],
                              ),
                            ],
                          ),
                          StatusBadge.role(session.role),
                        ],
                      ),
                      const SizedBox(height: 16),
                      const Divider(),
                      const SizedBox(height: 12),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Row(
                            children: const [
                              Icon(Icons.timer_outlined, size: 14, color: AppColors.secondaryText),
                              SizedBox(width: 6),
                              Text('Session Expiry:', style: TextStyle(fontSize: 12, color: AppColors.secondaryText)),
                            ],
                          ),
                          Text(
                            session.expires.toLocal().toString().substring(0, 16),
                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppColors.primaryText),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // Privileged Security Alert Notice
                if (session.privileged)
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: AppColors.infoLight,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: AppColors.info.withValues(alpha: 0.3)),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: const [
                        Icon(Icons.info_outline_rounded, color: AppColors.info, size: 20),
                        SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'Privileged mobile actions are protected. Academic catalog reading and verified device operations are enabled; sensitive browser-MFA mutations are performed via the Web Portal.',
                            style: TextStyle(
                              fontSize: 12,
                              color: AppColors.deepNavy,
                              height: 1.35,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: 20),

                // Module Navigation Grid
                const Text(
                  'PORTAL MODULES',
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    color: AppColors.secondaryText,
                    letterSpacing: 0.8,
                  ),
                ),
                const SizedBox(height: 12),

                // Academic Setup Tile
                AppCard(
                  onTap: _openAcademicWorkspace,
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.primaryBlue.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: const Icon(Icons.account_tree_outlined, color: AppColors.primaryBlue, size: 28),
                      ),
                      const SizedBox(width: 16),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: const [
                            Text(
                              'Academic Setup',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.w700,
                                color: AppColors.primaryText,
                              ),
                            ),
                            SizedBox(height: 4),
                            Text(
                              'Sessions, Classes, Offerings, Sections & Subjects',
                              style: TextStyle(fontSize: 12, color: AppColors.secondaryText),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.arrow_forward_ios_rounded, size: 16, color: AppColors.secondaryText),
                    ],
                  ),
                ),
                const SizedBox(height: 12),

                // Security & Devices Tile
                AppCard(
                  onTap: _openSecurityScreen,
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: AppColors.premiumTeal.withValues(alpha: 0.1),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: const Icon(Icons.devices_rounded, color: AppColors.premiumTeal, size: 28),
                      ),
                      const SizedBox(width: 16),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: const [
                            Text(
                              'Device Security & Sessions',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.w700,
                                color: AppColors.primaryText,
                              ),
                            ),
                            SizedBox(height: 4),
                            Text(
                              'Active devices, Keystore encryption & session control',
                              style: TextStyle(fontSize: 12, color: AppColors.secondaryText),
                            ),
                          ],
                        ),
                      ),
                      const Icon(Icons.arrow_forward_ios_rounded, size: 16, color: AppColors.secondaryText),
                    ],
                  ),
                ),
                const SizedBox(height: 24),

                if (_schoolError != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Text(
                      _schoolError!,
                      style: const TextStyle(color: AppColors.error, fontSize: 12),
                    ),
                  ),

                // Quick Actions
                OutlinedButton(
                  onPressed: widget.authController.logout,
                  child: const Text('Sign out'),
                ),
                const SizedBox(height: 10),
                OutlinedButton.icon(
                  onPressed: _loadingSchool ? null : _fetchSchoolDetails,
                  icon: const Icon(Icons.verified_user_outlined, size: 18),
                  label: Text(_loadingSchool ? 'Verifying Context…' : 'Re-verify School Context'),
                ),
                const SizedBox(height: 10),
                TextButton.icon(
                  onPressed: widget.authController.logout,
                  icon: const Icon(Icons.swap_horiz_rounded, size: 18),
                  label: const Text('Sign out to switch school'),
                ),
              ],
            ),
          ),
        );
      },
    );
  }
}

class _AcademicTransportAdapter implements AcademicApiTransport {
  _AcademicTransportAdapter(this.transport);
  final ApiTransport transport;

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
    return transport.send(
      method,
      path,
      body: body,
      token: token,
      school: school,
    );
  }
}

