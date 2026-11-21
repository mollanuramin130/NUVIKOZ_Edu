import 'package:flutter/material.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_card.dart';
import '../../widgets/empty_state_view.dart';
import '../../widgets/error_banner.dart';
import '../auth_controller.dart';
import 'mfa_challenge_screen.dart';

class SecurityScreen extends StatefulWidget {
  const SecurityScreen({
    super.key,
    required this.authController,
  });

  final AuthController authController;

  @override
  State<SecurityScreen> createState() => _SecurityScreenState();
}

class _SecurityScreenState extends State<SecurityScreen> {
  List<Map<String, dynamic>> _devices = [];
  Map<String, dynamic>? _mfaStatus;
  bool _loading = false;
  String? _error;
  String? _success;

  @override
  void initState() {
    super.initState();
    _loadDevices();
    _loadMfaStatus();
  }

  Future<void> _loadMfaStatus() async {
    final s = widget.authController.session;
    if (s == null || !s.privileged) return;
    try {
      final res = await widget.authController.api.send(
        'GET',
        '/api/v1/security/mfa/status',
        token: s.token,
        school: s.school,
      );
      if (mounted) {
        setState(() {
          _mfaStatus = res['data'] is Map<String, dynamic> ? res['data'] as Map<String, dynamic> : null;
        });
      }
    } catch (_) {
      // Endpoint may not be active yet on earlier staging deployments
    }
  }

  Future<void> _loadDevices() async {
    final s = widget.authController.session;
    if (s == null) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await widget.authController.api.send(
        'GET',
        '/api/v1/security/devices',
        token: s.token,
        school: s.school,
      );
      final data = res['data'];
      if (data is List) {
        setState(() {
          _devices = data.whereType<Map<String, dynamic>>().toList();
          _loading = false;
        });
      } else if (data is Map && data['items'] is List) {
        setState(() {
          _devices = (data['items'] as List).whereType<Map<String, dynamic>>().toList();
          _loading = false;
        });
      } else {
        setState(() => _loading = false);
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _showRevokeDialog(String deviceId, String deviceName) async {
    final passwordController = TextEditingController();
    final formKey = GlobalKey<FormState>();

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Revoke Device Session'),
        content: Form(
          key: formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Enter your account password to revoke session for "$deviceName".'),
              const SizedBox(height: 16),
              TextFormField(
                controller: passwordController,
                obscureText: true,
                decoration: const InputDecoration(labelText: 'Password'),
                validator: (v) => (v == null || v.isEmpty) ? 'Password is required' : null,
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Cancel')),
          FilledButton(
            onPressed: () {
              if (formKey.currentState!.validate()) {
                Navigator.of(ctx).pop(true);
              }
            },
            style: FilledButton.styleFrom(backgroundColor: AppColors.error),
            child: const Text('Revoke'),
          ),
        ],
      ),
    );

    if (confirmed == true) {
      final s = widget.authController.session;
      if (s == null) return;
      try {
        await widget.authController.api.send(
          'POST',
          '/api/v1/security/devices/$deviceId/revoke',
          token: s.token,
          school: s.school,
          body: {'password': passwordController.text},
        );
        setState(() {
          _success = 'Device session revoked.';
        });
        _loadDevices();
      } catch (e) {
        setState(() {
          _error = 'Failed to revoke device: $e';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Device Security & Sessions'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loading ? null : _loadDevices,
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          // Keystore Hardware Security Card
          AppCard(
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.premiumTeal.withValues(alpha: 0.1),
                    shape: BoxShape.circle,
                  ),
                  child: const Icon(Icons.lock_clock_rounded, color: AppColors.premiumTeal, size: 28),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: const [
                      Text(
                        'Android Keystore AES-256',
                        style: TextStyle(
                          fontSize: 15,
                          fontWeight: FontWeight.w700,
                          color: AppColors.primaryText,
                        ),
                      ),
                      SizedBox(height: 2),
                      Text(
                        'Device tokens are encrypted inside the hardware-backed Android Keystore with AES-GCM and authenticated AAD.',
                        style: TextStyle(fontSize: 12, color: AppColors.secondaryText, height: 1.3),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          // MFA Assurance Card (for privileged school admins)
          if (widget.authController.session?.privileged == true) ...[
            AppCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(10),
                        decoration: BoxDecoration(
                          color: (_mfaStatus?['verified'] == true)
                              ? AppColors.successLight
                              : const Color(0xFFEFF6FF),
                          shape: BoxShape.circle,
                        ),
                        child: Icon(
                          (_mfaStatus?['verified'] == true)
                              ? Icons.verified_user_rounded
                              : Icons.security_rounded,
                          color: (_mfaStatus?['verified'] == true)
                              ? AppColors.success
                              : AppColors.primaryBlue,
                          size: 24,
                        ),
                      ),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text(
                              'Two-Factor Authentication (MFA)',
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w700,
                                color: AppColors.primaryText,
                              ),
                            ),
                            Text(
                              (_mfaStatus?['verified'] == true)
                                  ? 'Step-up assurance active (15 min)'
                                  : 'Step-up verification required for creations',
                              style: TextStyle(
                                fontSize: 12,
                                color: (_mfaStatus?['verified'] == true)
                                    ? AppColors.success
                                    : AppColors.secondaryText,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.shield_outlined, size: 18),
                      label: Text((_mfaStatus?['verified'] == true)
                          ? 'Re-verify MFA Step-up'
                          : 'Verify MFA Step-up Now'),
                      onPressed: () {
                        MfaChallengeSheet.show(
                          context,
                          authController: widget.authController,
                          onSuccess: _loadMfaStatus,
                        );
                      },
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
          ],

          if (_error != null)
            ErrorBanner(message: _error!, onDismiss: () => setState(() => _error = null)),

          if (_success != null)
            Container(
              margin: const EdgeInsets.only(bottom: 12),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.successLight,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(_success!, style: const TextStyle(color: AppColors.success, fontSize: 13)),
            ),

          const Text(
            'ACTIVE AUTHORIZED DEVICES',
            style: TextStyle(
              fontSize: 12,
              fontWeight: FontWeight.w700,
              color: AppColors.secondaryText,
              letterSpacing: 0.8,
            ),
          ),
          const SizedBox(height: 12),

          if (_loading)
            const Center(child: Padding(padding: EdgeInsets.all(24), child: CircularProgressIndicator()))
          else if (_devices.isEmpty)
            const EmptyStateView(
              icon: Icons.devices_other,
              title: 'No Active Devices',
              description: 'Active devices bound to this school account will appear here.',
            )
          else
            ..._devices.map((device) {
              final isCurrent = device['current'] == true;
              final name = device['name'] as String? ?? 'Android Device';
              final id = device['id'] as String? ?? '';
              final expires = device['expires_at'] as String? ?? '';

              return AppCard(
                margin: const EdgeInsets.only(bottom: 12),
                borderColor: isCurrent ? AppColors.primaryBlue : AppColors.border,
                child: Row(
                  children: [
                    Icon(
                      isCurrent ? Icons.phone_android_rounded : Icons.laptop_chromebook_rounded,
                      color: isCurrent ? AppColors.primaryBlue : AppColors.secondaryText,
                      size: 28,
                    ),
                    const SizedBox(width: 14),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Text(
                                name,
                                style: const TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.w600,
                                  color: AppColors.primaryText,
                                ),
                              ),
                              if (isCurrent) ...[
                                const SizedBox(width: 8),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                                  decoration: BoxDecoration(
                                    color: AppColors.primaryBlue.withValues(alpha: 0.1),
                                    borderRadius: BorderRadius.circular(10),
                                  ),
                                  child: const Text(
                                    'This Device',
                                    style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppColors.primaryBlue),
                                  ),
                                ),
                              ],
                            ],
                          ),
                          if (expires.isNotEmpty)
                            Text(
                              'Expires: ${expires.substring(0, 10)}',
                              style: const TextStyle(fontSize: 12, color: AppColors.secondaryText),
                            ),
                        ],
                      ),
                    ),
                    if (!isCurrent)
                      IconButton(
                        icon: const Icon(Icons.delete_outline_rounded, color: AppColors.error),
                        tooltip: 'Revoke session',
                        onPressed: () => _showRevokeDialog(id, name),
                      ),
                  ],
                ),
              );
            }),
        ],
      ),
    );
  }
}
