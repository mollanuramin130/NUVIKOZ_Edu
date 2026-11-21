import 'dart:math';
import 'package:flutter/material.dart';
import '../../auth/auth_controller.dart';
import '../../auth/screens/mfa_challenge_screen.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_card.dart';
import '../../widgets/app_loading_view.dart';
import '../../widgets/app_modal_header.dart';
import '../../widgets/empty_state_view.dart';
import '../../widgets/error_banner.dart';
import '../../widgets/status_badge.dart';
import '../controllers/academic_controller.dart';
import '../models/academic_session.dart';
import '../models/school_class.dart';
import '../models/class_offering.dart';
import '../models/subject.dart';
import '../../preview/preview_academic_repository.dart';

class AcademicWorkspaceScreen extends StatefulWidget {
  const AcademicWorkspaceScreen({
    super.key,
    required this.controller,
    required this.schoolName,
    this.authController,
    this.isPreviewMode = false,
    this.previewRepository,
    this.onOpenDrawer,
  });

  final AcademicController controller;
  final String schoolName;
  final AuthController? authController;
  final bool isPreviewMode;
  final PreviewAcademicRepository? previewRepository;
  final VoidCallback? onOpenDrawer;

  @override
  State<AcademicWorkspaceScreen> createState() => _AcademicWorkspaceScreenState();
}

class _AcademicWorkspaceScreenState extends State<AcademicWorkspaceScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 6, vsync: this);
    _tabController.addListener(() {
      if (_tabController.indexIsChanging) {
        widget.controller.setActiveTab(AcademicTab.values[_tabController.index]);
      }
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.controller.loadAll();
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _showSimulationControls(BuildContext context) {
    final repo = widget.previewRepository;
    if (repo == null) return;

    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheetState) {
          return Padding(
            padding: const EdgeInsets.all(20),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Row(
                    children: const [
                      Icon(Icons.tune_rounded, color: AppColors.primaryBlue),
                      SizedBox(width: 10),
                      Text(
                        'Simulation & Fault Controls',
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Trigger simulated backend states to verify client resilience on this physical device.',
                    style: TextStyle(fontSize: 12, color: AppColors.secondaryText),
                  ),
                  const Divider(height: 24),
                  SwitchListTile(
                    title: const Text('Simulate 403 MFA Step-Up', style: TextStyle(fontSize: 14)),
                    subtitle: const Text('Next creation triggers TOTP Challenge modal', style: TextStyle(fontSize: 11)),
                    value: repo.simulateMfaRequired,
                    onChanged: (val) {
                      setSheetState(() => repo.simulateMfaRequired = val);
                      setState(() {});
                    },
                  ),
                  SwitchListTile(
                    title: const Text('Simulate 403 Access Suspended', style: TextStyle(fontSize: 14)),
                    subtitle: const Text('Triggers institution suspension error banner', style: TextStyle(fontSize: 11)),
                    value: repo.simulateSuspended,
                    onChanged: (val) {
                      setSheetState(() => repo.simulateSuspended = val);
                      setState(() {});
                    },
                  ),
                  SwitchListTile(
                    title: const Text('Simulate 403 Demo Limit Exceeded', style: TextStyle(fontSize: 14)),
                    subtitle: const Text('Triggers evaluation cap error banner', style: TextStyle(fontSize: 11)),
                    value: repo.simulateDemoLimit,
                    onChanged: (val) {
                      setSheetState(() => repo.simulateDemoLimit = val);
                      setState(() {});
                    },
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () {
                            repo.clearAll();
                            widget.controller.loadAll();
                            Navigator.of(ctx).pop();
                          },
                          child: const Text('Clear All (Empty States)'),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton(
                          onPressed: () {
                            repo.resetToDefaults();
                            widget.controller.loadAll();
                            Navigator.of(ctx).pop();
                          },
                          child: const Text('Reset Demo Data'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  void _showCreateBottomSheet(BuildContext context) {
    final tab = widget.controller.activeTab;
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => _CreateResourceSheet(
        controller: widget.controller,
        tab: tab,
        authController: widget.authController,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.controller,
      builder: (context, _) {
        final c = widget.controller;

        if (_tabController.index != c.activeTab.index && !_tabController.indexIsChanging) {
          _tabController.animateTo(c.activeTab.index);
        }

        return Scaffold(
          appBar: AppBar(
            leading: widget.onOpenDrawer != null
                ? IconButton(
                    icon: const Icon(Icons.menu_rounded),
                    tooltip: 'Navigation Menu',
                    onPressed: widget.onOpenDrawer,
                  )
                : null,
            title: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Academic Workspace'),
                Text(
                  widget.schoolName,
                  style: const TextStyle(
                    fontSize: 12,
                    color: Color(0xFFCBD5E1),
                    fontWeight: FontWeight.normal,
                  ),
                ),
              ],
            ),
            actions: [
              if (widget.isPreviewMode && widget.previewRepository != null)
                IconButton(
                  icon: const Icon(Icons.tune_rounded),
                  tooltip: 'Simulation Controls',
                  onPressed: () => _showSimulationControls(context),
                ),
              IconButton(
                icon: const Icon(Icons.refresh_rounded),
                tooltip: 'Refresh',
                onPressed: c.isLoading ? null : () => c.loadActiveTabData(),
              ),
            ],
            bottom: PreferredSize(
              preferredSize: const Size.fromHeight(48),
              child: Container(
                color: AppColors.deepNavy,
                child: TabBar(
                  controller: _tabController,
                  isScrollable: true,
                  indicatorColor: AppColors.premiumTeal,
                  indicatorWeight: 3,
                  labelColor: Colors.white,
                  unselectedLabelColor: const Color(0xFF94A3B8),
                  labelStyle: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
                  tabs: const [
                    Tab(text: 'Sessions'),
                    Tab(text: 'Classes'),
                    Tab(text: 'Offerings'),
                    Tab(text: 'Sections'),
                    Tab(text: 'Subjects'),
                    Tab(text: 'Subject Offerings'),
                  ],
                ),
              ),
            ),
          ),
          body: Column(
            children: [
              if (widget.isPreviewMode)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 16),
                  color: const Color(0xFFFEF3C7),
                  child: Row(
                    children: [
                      const Icon(Icons.warning_amber_rounded, size: 16, color: Color(0xFFB45309)),
                      const SizedBox(width: 8),
                      const Expanded(
                        child: Text(
                          'DEMO DATA — NO REAL BACKEND (PREVIEW MODE)',
                          style: TextStyle(
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            color: Color(0xFF92400E),
                            letterSpacing: 0.2,
                          ),
                        ),
                      ),
                      if (widget.previewRepository != null)
                        InkWell(
                          onTap: () => _showSimulationControls(context),
                          child: const Padding(
                            padding: EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                            child: Text(
                              'SIMULATE',
                              style: TextStyle(
                                fontSize: 10,
                                fontWeight: FontWeight.w800,
                                color: Color(0xFFB45309),
                                decoration: TextDecoration.underline,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),

              // Context Selector Bar (Session & Class Filter)
              _buildContextBar(c),

              // Feedback alerts
              if (c.errorMessage != null)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      ErrorBanner(
                        message: c.errorMessage!,
                        onDismiss: c.clearMessages,
                      ),
                      if (widget.authController != null && c.isMfaRequired) ...[
                        const SizedBox(height: 6),
                        FilledButton.tonalIcon(
                          icon: const Icon(Icons.shield_outlined, size: 18),
                          label: const Text('Verify MFA Step-up (15 min)'),
                          onPressed: () {
                            MfaChallengeSheet.show(
                              context,
                              authController: widget.authController!,
                              onSuccess: () {
                                c.clearMessages();
                                c.loadActiveTabData();
                              },
                            );
                          },
                        ),
                      ],
                    ],
                  ),
                ),

              if (c.successMessage != null)
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                    decoration: BoxDecoration(
                      color: AppColors.successLight,
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: AppColors.success.withValues(alpha: 0.3)),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.check_circle_outline, color: AppColors.success, size: 20),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            c.successMessage!,
                            style: const TextStyle(
                              color: AppColors.success,
                              fontSize: 13,
                              fontWeight: FontWeight.w500,
                            ),
                          ),
                        ),
                        GestureDetector(
                          onTap: c.clearMessages,
                          child: const Icon(Icons.close, color: AppColors.success, size: 18),
                        ),
                      ],
                    ),
                  ),
                ),

              // Main Tab Content
              Expanded(
                child: c.isLoading
                    ? const AppLoadingView(message: 'Loading academic catalog…')
                    : TabBarView(
                        controller: _tabController,
                        children: [
                          _SessionsListView(controller: c),
                          _ClassesListView(controller: c),
                          _ClassOfferingsListView(controller: c),
                          _SectionsListView(controller: c),
                          _SubjectsListView(controller: c),
                          _SubjectOfferingsListView(controller: c),
                        ],
                      ),
              ),
            ],
          ),
          floatingActionButton: FloatingActionButton.extended(
            onPressed: () => _showCreateBottomSheet(context),
            backgroundColor: AppColors.primaryBlue,
            foregroundColor: Colors.white,
            icon: const Icon(Icons.add),
            label: Text(_getAddLabel(c.activeTab)),
          ),
        );
      },
    );
  }

  String _getAddLabel(AcademicTab tab) {
    switch (tab) {
      case AcademicTab.sessions:
        return 'New Session';
      case AcademicTab.classes:
        return 'New Class';
      case AcademicTab.classOfferings:
        return 'Link Offering';
      case AcademicTab.sections:
        return 'New Section';
      case AcademicTab.subjects:
        return 'New Subject';
      case AcademicTab.subjectOfferings:
        return 'Link Subject';
    }
  }

  Widget _buildContextBar(AcademicController c) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
      decoration: const BoxDecoration(
        color: AppColors.whiteSurface,
        border: Border(bottom: BorderSide(color: AppColors.border)),
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final isNarrow = constraints.maxWidth < 400;
          final sessionPicker = DropdownButtonFormField<AcademicSession>(
            value: c.selectedSession,
            isExpanded: true,
            decoration: const InputDecoration(
              labelText: 'Session Context',
              contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            ),
            items: c.sessions.map((s) {
              return DropdownMenuItem(
                value: s,
                child: Text(
                  s.name,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(fontSize: 13),
                ),
              );
            }).toList(),
            onChanged: c.selectSession,
          );

          final showOffering = c.activeTab == AcademicTab.sections || c.activeTab == AcademicTab.subjectOfferings;
          final offeringPicker = showOffering
              ? DropdownButtonFormField<ClassOffering>(
                  value: c.selectedClassOffering,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Offering Context',
                    contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  ),
                  items: c.classOfferings.map((o) {
                    final cls = c.classes.firstWhere(
                      (cls) => cls.id == o.classId,
                      orElse: () => SchoolClass(id: o.classId, name: 'Class ${o.classId.substring(0, 6)}', sortOrder: 0, version: 1),
                    );
                    return DropdownMenuItem(
                      value: o,
                      child: Text(
                        cls.name,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 13),
                      ),
                    );
                  }).toList(),
                  onChanged: c.selectClassOffering,
                )
              : null;

          if (isNarrow && offeringPicker != null) {
            return Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                sessionPicker,
                const SizedBox(height: 8),
                offeringPicker,
              ],
            );
          }

          return Row(
            children: [
              Expanded(child: sessionPicker),
              if (offeringPicker != null) ...[
                const SizedBox(width: 12),
                Expanded(child: offeringPicker),
              ],
            ],
          );
        },
      ),
    );
  }
}

// ----------------- List Views for Each Tab -----------------

class _EndOfListIndicator extends StatelessWidget {
  const _EndOfListIndicator({required this.count, required this.label});
  final int count;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 16),
      child: Center(
        child: Text(
          'All $count $label loaded',
          style: const TextStyle(
            fontSize: 12,
            color: AppColors.secondaryText,
            fontWeight: FontWeight.w500,
          ),
        ),
      ),
    );
  }
}

class _SessionsListView extends StatelessWidget {
  const _SessionsListView({required this.controller});
  final AcademicController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.sessions.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.calendar_today_rounded,
              title: 'No Academic Sessions',
              description: 'Create future academic sessions matching your school timezone.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.loadActiveTabData,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: controller.sessions.length + 1,
        separatorBuilder: (_, index) => index < controller.sessions.length - 1 ? const SizedBox(height: 12) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index == controller.sessions.length) {
            return _EndOfListIndicator(count: controller.sessions.length, label: 'sessions');
          }
          final s = controller.sessions[index];
          final isSelected = controller.selectedSession?.id == s.id;

          return AppCard(
            borderColor: isSelected ? AppColors.primaryBlue : AppColors.border,
            onTap: () => controller.selectSession(s),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Expanded(
                      child: Text(
                        s.name,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w700,
                          color: AppColors.primaryText,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    if (s.isFuture)
                      StatusBadge.future()
                    else if (s.isActive)
                      StatusBadge.active()
                    else
                      StatusBadge.completed(),
                  ],
                ),
                const SizedBox(height: 8),
                Row(
                  children: [
                    const Icon(Icons.date_range, size: 14, color: AppColors.secondaryText),
                    const SizedBox(width: 6),
                    Text(
                      '${s.startsOn}  to  ${s.endsOn}',
                      style: const TextStyle(fontSize: 13, color: AppColors.secondaryText),
                    ),
                    const Spacer(),
                    Text(
                      'v${s.version}',
                      style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                    ),
                  ],
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _ClassesListView extends StatelessWidget {
  const _ClassesListView({required this.controller});
  final AcademicController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.classes.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.class_outlined,
              title: 'No Classes Configured',
              description: 'Define grade levels and curriculum classes for your institution.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.loadActiveTabData,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: controller.classes.length + 1,
        separatorBuilder: (_, index) => index < controller.classes.length - 1 ? const SizedBox(height: 12) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index == controller.classes.length) {
            return _EndOfListIndicator(count: controller.classes.length, label: 'classes');
          }
          final cls = controller.classes[index];

          return AppCard(
            child: Row(
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: const Color(0xFFEFF6FF),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Center(
                    child: Text(
                      '#${cls.sortOrder}',
                      style: const TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w700,
                        color: AppColors.primaryBlue,
                      ),
                    ),
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        cls.name,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.primaryText,
                        ),
                      ),
                      if (cls.gradeLabel != null && cls.gradeLabel!.isNotEmpty)
                        Text(
                          'Grade: ${cls.gradeLabel}',
                          style: const TextStyle(fontSize: 13, color: AppColors.secondaryText),
                        ),
                    ],
                  ),
                ),
                Text(
                  'v${cls.version}',
                  style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _ClassOfferingsListView extends StatelessWidget {
  const _ClassOfferingsListView({required this.controller});
  final AcademicController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.classOfferings.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.link_rounded,
              title: 'No Class Offerings',
              description: 'Link existing classes to the active or selected academic session.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.loadActiveTabData,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: controller.classOfferings.length + 1,
        separatorBuilder: (_, index) => index < controller.classOfferings.length - 1 ? const SizedBox(height: 12) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index == controller.classOfferings.length) {
            return _EndOfListIndicator(count: controller.classOfferings.length, label: 'class offerings');
          }
          final o = controller.classOfferings[index];
          final cls = controller.classes.firstWhere(
            (c) => c.id == o.classId,
            orElse: () => SchoolClass(id: o.classId, name: 'Class ${o.classId.substring(0, 6)}', sortOrder: 0, version: 1),
          );
          final sess = controller.sessions.firstWhere(
            (s) => s.id == o.sessionId,
            orElse: () => AcademicSession(id: o.sessionId, name: 'Session', startsOn: '', endsOn: '', state: '', version: 1),
          );

          return AppCard(
            onTap: () => controller.selectClassOffering(o),
            borderColor: controller.selectedClassOffering?.id == o.id ? AppColors.primaryBlue : AppColors.border,
            child: Row(
              children: [
                const Icon(Icons.school_outlined, color: AppColors.primaryBlue, size: 28),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        cls.name,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.primaryText,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Session: ${sess.name}',
                        style: const TextStyle(fontSize: 12, color: AppColors.secondaryText),
                      ),
                    ],
                  ),
                ),
                StatusBadge(label: 'Linked', color: AppColors.premiumTeal, backgroundColor: const Color(0xFFE6F4F4)),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _SectionsListView extends StatelessWidget {
  const _SectionsListView({required this.controller});
  final AcademicController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.selectedClassOffering == null) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.filter_alt_outlined,
              title: 'No Class Offering Selected',
              description: 'Select an offering from the context bar above or create one in the Offerings tab.',
            ),
          ],
        ),
      );
    }

    if (controller.sections.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.group_work_outlined,
              title: 'No Sections Found',
              description: 'Add sections (e.g. Section A, Section B) to the selected class offering.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.loadActiveTabData,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: controller.sections.length + 1,
        separatorBuilder: (_, index) => index < controller.sections.length - 1 ? const SizedBox(height: 12) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index == controller.sections.length) {
            return _EndOfListIndicator(count: controller.sections.length, label: 'sections');
          }
          final sec = controller.sections[index];

          return AppCard(
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: const Color(0xFFF1F5F9),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Icon(Icons.groups_rounded, color: AppColors.deepNavy, size: 20),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        sec.name,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.primaryText,
                        ),
                      ),
                      const SizedBox(height: 2),
                      const Text(
                        'Teacher assignment pending backend scope',
                        style: TextStyle(fontSize: 11, color: AppColors.secondaryText),
                      ),
                    ],
                  ),
                ),
                Text(
                  'v${sec.version}',
                  style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _SubjectsListView extends StatelessWidget {
  const _SubjectsListView({required this.controller});
  final AcademicController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.subjects.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.menu_book_rounded,
              title: 'No Subjects Cataloged',
              description: 'Define institutional subjects with standardized course codes.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.loadActiveTabData,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: controller.subjects.length + 1,
        separatorBuilder: (_, index) => index < controller.subjects.length - 1 ? const SizedBox(height: 12) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index == controller.subjects.length) {
            return _EndOfListIndicator(count: controller.subjects.length, label: 'subjects');
          }
          final sub = controller.subjects[index];

          return AppCard(
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                  decoration: BoxDecoration(
                    color: const Color(0xFFEBF3FB),
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    sub.code,
                    style: const TextStyle(
                      fontSize: 13,
                      fontWeight: FontWeight.w700,
                      color: AppColors.primaryBlue,
                    ),
                  ),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Text(
                    sub.name,
                    style: const TextStyle(
                      fontSize: 15,
                      fontWeight: FontWeight.w600,
                      color: AppColors.primaryText,
                    ),
                  ),
                ),
                Text(
                  'v${sub.version}',
                  style: const TextStyle(fontSize: 11, color: AppColors.secondaryText),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _SubjectOfferingsListView extends StatelessWidget {
  const _SubjectOfferingsListView({required this.controller});
  final AcademicController controller;

  @override
  Widget build(BuildContext context) {
    if (controller.selectedClassOffering == null) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.filter_alt_outlined,
              title: 'No Class Offering Selected',
              description: 'Select an offering from the context bar above to view and link subjects.',
            ),
          ],
        ),
      );
    }

    if (controller.subjectOfferings.isEmpty) {
      return RefreshIndicator(
        onRefresh: controller.loadActiveTabData,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          children: const [
            SizedBox(height: 48),
            EmptyStateView(
              icon: Icons.assignment_outlined,
              title: 'No Subject Offerings',
              description: 'Map subjects as compulsory or optional to class offerings.',
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: controller.loadActiveTabData,
      child: ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: controller.subjectOfferings.length + 1,
        separatorBuilder: (_, index) => index < controller.subjectOfferings.length - 1 ? const SizedBox(height: 12) : const SizedBox.shrink(),
        itemBuilder: (context, index) {
          if (index == controller.subjectOfferings.length) {
            return _EndOfListIndicator(count: controller.subjectOfferings.length, label: 'subject offerings');
          }
          final so = controller.subjectOfferings[index];
          final sub = controller.subjects.firstWhere(
            (s) => s.id == so.subjectId,
            orElse: () => Subject(id: so.subjectId, code: 'SUB', name: 'Subject ${so.subjectId.substring(0, 6)}', version: 1),
          );

          return AppCard(
            child: Row(
              children: [
                Icon(
                  so.compulsory ? Icons.check_circle_rounded : Icons.radio_button_unchecked_rounded,
                  color: so.compulsory ? AppColors.primaryBlue : AppColors.warning,
                  size: 22,
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        sub.name,
                        style: const TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w600,
                          color: AppColors.primaryText,
                        ),
                      ),
                      Text(
                        'Code: ${sub.code}',
                        style: const TextStyle(fontSize: 12, color: AppColors.secondaryText),
                      ),
                    ],
                  ),
                ),
                so.compulsory ? StatusBadge.compulsory() : StatusBadge.optional(),
              ],
            ),
          );
        },
      ),
    );
  }
}

// ----------------- Create Bottom Sheet -----------------

class _CreateResourceSheet extends StatefulWidget {
  const _CreateResourceSheet({
    required this.controller,
    required this.tab,
    this.authController,
  });

  final AcademicController controller;
  final AcademicTab tab;
  final AuthController? authController;

  @override
  State<_CreateResourceSheet> createState() => _CreateResourceSheetState();
}

class _CreateResourceSheetState extends State<_CreateResourceSheet> {
  final _formKey = GlobalKey<FormState>();

  // Field controllers
  final _nameController = TextEditingController();
  final _startsOnController = TextEditingController();
  final _endsOnController = TextEditingController();
  final _gradeLabelController = TextEditingController();
  final _sortOrderController = TextEditingController(text: '1');
  final _codeController = TextEditingController();

  String? _selectedSessionId;
  String? _selectedClassId;
  String? _selectedOfferingId;
  String? _selectedSubjectId;
  bool _compulsory = true;
  bool _isSubmitting = false;

  String? _activeSubmissionIdempotencyKey;

  String _getOrCreateIdempotencyKey() {
    if (_activeSubmissionIdempotencyKey != null) {
      return _activeSubmissionIdempotencyKey!;
    }
    final random = Random.secure();
    final values = List<int>.generate(24, (_) => random.nextInt(256));
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
    _activeSubmissionIdempotencyKey = List.generate(32, (i) => chars[values[i % values.length] % chars.length]).join();
    return _activeSubmissionIdempotencyKey!;
  }

  void _onFieldChanged([String? _]) {
    if (_activeSubmissionIdempotencyKey != null) {
      _activeSubmissionIdempotencyKey = null;
    }
  }

  @override
  void initState() {
    super.initState();
    _selectedSessionId = widget.controller.selectedSession?.id;
    _selectedOfferingId = widget.controller.selectedClassOffering?.id;
    if (_selectedOfferingId == null && widget.controller.classOfferings.isNotEmpty) {
      _selectedOfferingId = widget.controller.classOfferings.first.id;
    }
    if (_selectedSessionId == null && widget.controller.sessions.isNotEmpty) {
      _selectedSessionId = widget.controller.sessions.first.id;
    }
    if (widget.controller.classes.isNotEmpty) {
      _selectedClassId = widget.controller.classes.first.id;
    }
    if (widget.controller.subjects.isNotEmpty) {
      _selectedSubjectId = widget.controller.subjects.first.id;
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _startsOnController.dispose();
    _endsOnController.dispose();
    _gradeLabelController.dispose();
    _sortOrderController.dispose();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_isSubmitting || widget.controller.isCreating) return;
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() => _isSubmitting = true);
    final idempotencyKey = _getOrCreateIdempotencyKey();
    bool success = false;
    final c = widget.controller;

    try {
      switch (widget.tab) {
        case AcademicTab.sessions:
          success = await c.createSession(
            name: _nameController.text.trim(),
            startsOn: _startsOnController.text.trim(),
            endsOn: _endsOnController.text.trim(),
            idempotencyKey: idempotencyKey,
          );
          break;
        case AcademicTab.classes:
          success = await c.createClass(
            name: _nameController.text.trim(),
            gradeLabel: _gradeLabelController.text.trim().isEmpty ? null : _gradeLabelController.text.trim(),
            sortOrder: int.tryParse(_sortOrderController.text.trim()) ?? 1,
            idempotencyKey: idempotencyKey,
          );
          break;
        case AcademicTab.classOfferings:
          if (_selectedSessionId == null || _selectedClassId == null) return;
          success = await c.createClassOffering(
            sessionId: _selectedSessionId!,
            classId: _selectedClassId!,
            idempotencyKey: idempotencyKey,
          );
          break;
        case AcademicTab.sections:
          if (_selectedOfferingId == null) return;
          success = await c.createSection(
            classOfferingId: _selectedOfferingId!,
            name: _nameController.text.trim(),
            idempotencyKey: idempotencyKey,
          );
          break;
        case AcademicTab.subjects:
          success = await c.createSubject(
            code: _codeController.text.trim().toUpperCase(),
            name: _nameController.text.trim(),
            idempotencyKey: idempotencyKey,
          );
          break;
        case AcademicTab.subjectOfferings:
          if (_selectedOfferingId == null || _selectedSubjectId == null) return;
          success = await c.createSubjectOffering(
            classOfferingId: _selectedOfferingId!,
            subjectId: _selectedSubjectId!,
            compulsory: _compulsory,
            idempotencyKey: idempotencyKey,
          );
          break;
      }

      if (mounted && success) {
        _activeSubmissionIdempotencyKey = null;
        Navigator.of(context).pop();
        return;
      }

      if (!success && c.isMfaRequired) {
        if (widget.authController != null && mounted) {
          final verified = await MfaChallengeSheet.show(context, authController: widget.authController!);
          if (verified == true && mounted) {
            c.clearMessages();
            // Safe retry using the exact same active idempotency key
            if (mounted) {
              setState(() => _isSubmitting = false);
            }
            await _submit();
            return;
          }
        }
      }
    } finally {
      if (mounted) {
        setState(() => _isSubmitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    final title = _getTitle();

    return Container(
      padding: EdgeInsets.only(
        left: 24,
        right: 24,
        top: 24,
        bottom: bottomInset + 24,
      ),
      decoration: const BoxDecoration(
        color: AppColors.whiteSurface,
        borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
      ),
      child: Form(
        key: _formKey,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AppModalHeader(
                title: 'Create $title',
                subtitle: 'Enter required fields to configure institutional setup',
                icon: Icons.add_circle_outline_rounded,
                onClose: () => Navigator.of(context).pop(),
              ),
              const SizedBox(height: 16),

              // Specific Form Inputs
              ..._buildFormFields(),

              const SizedBox(height: 24),
              FilledButton(
                onPressed: (widget.controller.isCreating || _isSubmitting) ? null : _submit,
                child: (widget.controller.isCreating || _isSubmitting)
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                        ),
                      )
                    : Text('Create $title'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _getTitle() {
    switch (widget.tab) {
      case AcademicTab.sessions:
        return 'Academic Session';
      case AcademicTab.classes:
        return 'School Class';
      case AcademicTab.classOfferings:
        return 'Class Offering';
      case AcademicTab.sections:
        return 'Section';
      case AcademicTab.subjects:
        return 'Subject';
      case AcademicTab.subjectOfferings:
        return 'Subject Offering';
    }
  }

  List<Widget> _buildFormFields() {
    switch (widget.tab) {
      case AcademicTab.sessions:
        return [
          TextFormField(
            controller: _nameController,
            textInputAction: TextInputAction.next,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Session Name',
              hintText: 'e.g. 2027–2028',
            ),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Name is required' : null,
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _startsOnController,
            textInputAction: TextInputAction.next,
            keyboardType: TextInputType.datetime,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Starts On (YYYY-MM-DD)',
              hintText: '2027-04-01',
              helperText: 'Must be in the future for school timezone',
            ),
            validator: (v) {
              if (v == null || !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v.trim())) {
                return 'Format YYYY-MM-DD required';
              }
              return null;
            },
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _endsOnController,
            textInputAction: TextInputAction.done,
            keyboardType: TextInputType.datetime,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Ends On (YYYY-MM-DD)',
              hintText: '2028-03-31',
            ),
            validator: (v) {
              if (v == null || !RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v.trim())) {
                return 'Format YYYY-MM-DD required';
              }
              return null;
            },
          ),
        ];

      case AcademicTab.classes:
        return [
          TextFormField(
            controller: _nameController,
            textInputAction: TextInputAction.next,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Class Name',
              hintText: 'e.g. Grade 10 or Class X',
            ),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Name is required' : null,
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _gradeLabelController,
            textInputAction: TextInputAction.next,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Grade Label (Optional)',
              hintText: 'e.g. 10',
            ),
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _sortOrderController,
            textInputAction: TextInputAction.done,
            keyboardType: TextInputType.number,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Sort Order',
              hintText: '1',
            ),
            validator: (v) => (v == null || int.tryParse(v.trim()) == null) ? 'Enter a valid number' : null,
          ),
        ];

      case AcademicTab.classOfferings:
        return [
          DropdownButtonFormField<String>(
            value: _selectedSessionId,
            decoration: const InputDecoration(labelText: 'Academic Session'),
            items: widget.controller.sessions.map((s) {
              return DropdownMenuItem(value: s.id, child: Text(s.name));
            }).toList(),
            onChanged: (v) {
              _onFieldChanged();
              setState(() => _selectedSessionId = v);
            },
            validator: (v) => v == null ? 'Session is required' : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _selectedClassId,
            decoration: const InputDecoration(labelText: 'Class'),
            items: widget.controller.classes.map((c) {
              return DropdownMenuItem(value: c.id, child: Text(c.name));
            }).toList(),
            onChanged: (v) {
              _onFieldChanged();
              setState(() => _selectedClassId = v);
            },
            validator: (v) => v == null ? 'Class is required' : null,
          ),
        ];

      case AcademicTab.sections:
        return [
          DropdownButtonFormField<String>(
            value: _selectedOfferingId,
            decoration: const InputDecoration(labelText: 'Class Offering'),
            items: widget.controller.classOfferings.map((o) {
              final cls = widget.controller.classes.firstWhere(
                (c) => c.id == o.classId,
                orElse: () => SchoolClass(id: o.classId, name: 'Class ${o.classId.substring(0, 6)}', sortOrder: 0, version: 1),
              );
              return DropdownMenuItem(value: o.id, child: Text(cls.name));
            }).toList(),
            onChanged: (v) {
              _onFieldChanged();
              setState(() => _selectedOfferingId = v);
            },
            validator: (v) => v == null ? 'Class Offering is required' : null,
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _nameController,
            textInputAction: TextInputAction.done,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Section Name',
              hintText: 'e.g. Section A or Rose',
            ),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Name is required' : null,
          ),
        ];

      case AcademicTab.subjects:
        return [
          TextFormField(
            controller: _codeController,
            textInputAction: TextInputAction.next,
            textCapitalization: TextCapitalization.characters,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Subject Code',
              hintText: 'e.g. MATH, ENG, PHY',
            ),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Code is required' : null,
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _nameController,
            textInputAction: TextInputAction.done,
            onChanged: _onFieldChanged,
            decoration: const InputDecoration(
              labelText: 'Subject Name',
              hintText: 'e.g. Mathematics',
            ),
            validator: (v) => (v == null || v.trim().isEmpty) ? 'Name is required' : null,
          ),
        ];

      case AcademicTab.subjectOfferings:
        return [
          DropdownButtonFormField<String>(
            value: _selectedOfferingId,
            decoration: const InputDecoration(labelText: 'Class Offering'),
            items: widget.controller.classOfferings.map((o) {
              final cls = widget.controller.classes.firstWhere(
                (c) => c.id == o.classId,
                orElse: () => SchoolClass(id: o.classId, name: 'Class ${o.classId.substring(0, 6)}', sortOrder: 0, version: 1),
              );
              return DropdownMenuItem(value: o.id, child: Text(cls.name));
            }).toList(),
            onChanged: (v) {
              _onFieldChanged();
              setState(() => _selectedOfferingId = v);
            },
            validator: (v) => v == null ? 'Offering is required' : null,
          ),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _selectedSubjectId,
            decoration: const InputDecoration(labelText: 'Subject'),
            items: widget.controller.subjects.map((s) {
              return DropdownMenuItem(value: s.id, child: Text('${s.name} (${s.code})'));
            }).toList(),
            onChanged: (v) {
              _onFieldChanged();
              setState(() => _selectedSubjectId = v);
            },
            validator: (v) => v == null ? 'Subject is required' : null,
          ),
          const SizedBox(height: 12),
          SwitchListTile(
            title: const Text('Compulsory Subject', style: TextStyle(fontSize: 14)),
            subtitle: const Text('All enrolled students take this subject', style: TextStyle(fontSize: 12)),
            value: _compulsory,
            activeColor: AppColors.primaryBlue,
            onChanged: (val) {
              _onFieldChanged();
              setState(() => _compulsory = val);
            },
          ),
        ];
    }
  }
}
