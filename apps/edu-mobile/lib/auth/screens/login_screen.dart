import 'package:flutter/material.dart';
import '../../dashboard/screens/dashboard_screen.dart';
import '../../preview/preview_controller_factory.dart';
import '../../preview/preview_guard.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_card.dart';
import '../../widgets/error_banner.dart';
import '../auth_controller.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({
    super.key,
    required this.controller,
  });

  final AuthController controller;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _schoolController = TextEditingController();
  final _identifierController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _loadingPreview = false;

  @override
  void dispose() {
    _schoolController.dispose();
    _identifierController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _enterPreview() async {
    PreviewGuard.assertNotRelease();
    setState(() => _loadingPreview = true);
    try {
      final harness = await PreviewHarness.create();
      if (!mounted) return;
      setState(() => _loadingPreview = false);
      await Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => DashboardScreen(
            authController: harness.authController,
            academicRepository: harness.academicRepository,
            isPreviewMode: true,
          ),
        ),
      );
      harness.authController.dispose();
    } catch (_) {
      if (mounted) {
        setState(() => _loadingPreview = false);
      }
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final secret = _passwordController.text;
    _passwordController.clear();
    await widget.controller.login(
      _schoolController.text.trim(),
      _identifierController.text.trim(),
      secret,
    );
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.controller,
      builder: (context, _) {
        final busy = widget.controller.state == AuthState.authenticating;
        final state = widget.controller.state;

        return Scaffold(
          backgroundColor: AppColors.lightBackground,
          body: SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 32),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 460),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // Brand Header
                      Center(
                        child: Container(
                          width: 64,
                          height: 64,
                          decoration: BoxDecoration(
                            gradient: AppColors.headerGradient,
                            borderRadius: BorderRadius.circular(16),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x2010283F),
                                blurRadius: 16,
                                offset: Offset(0, 8),
                              ),
                            ],
                          ),
                          child: const Icon(
                            Icons.school_rounded,
                            size: 36,
                            color: Colors.white,
                          ),
                        ),
                      ),
                      const SizedBox(height: 20),
                      const Text(
                        'NUVIKOZ Edu',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 26,
                          fontWeight: FontWeight.w800,
                          color: AppColors.deepNavy,
                          letterSpacing: -0.5,
                        ),
                      ),
                      const SizedBox(height: 6),
                      const Text(
                        'School Management & Learning Platform',
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 14,
                          color: AppColors.secondaryText,
                        ),
                      ),
                      const SizedBox(height: 28),

                      // Login Card
                      AppCard(
                        padding: const EdgeInsets.all(28),
                        child: Form(
                          key: _formKey,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              const Text(
                                'Sign in to your school',
                                style: TextStyle(
                                  fontSize: 18,
                                  fontWeight: FontWeight.w700,
                                  color: AppColors.primaryText,
                                ),
                              ),
                              const SizedBox(height: 4),
                              const Text(
                                'Enter your school code and assigned credentials',
                                style: TextStyle(
                                  fontSize: 13,
                                  color: AppColors.secondaryText,
                                ),
                              ),
                              const SizedBox(height: 20),

                              // Error feedback if any
                              if (widget.controller.message.isNotEmpty)
                                ErrorBanner(
                                  message: widget.controller.message,
                                  isWarning: state == AuthState.reauthenticationRequired,
                                ),

                              if (state == AuthState.storageUnavailable)
                                const ErrorBanner(
                                  message: 'Platform secure storage is unavailable. '
                                      'Login fails closed to protect credentials.',
                                ),

                              // School Code
                              TextFormField(
                                controller: _schoolController,
                                enabled: !busy,
                                textCapitalization: TextCapitalization.characters,
                                decoration: const InputDecoration(
                                  labelText: 'School Code',
                                  hintText: 'e.g. DEMO',
                                  prefixIcon: Icon(Icons.domain_rounded, size: 20),
                                ),
                                autofillHints: const [AutofillHints.organizationName],
                                validator: (val) {
                                  if (val == null || val.trim().isEmpty) {
                                    return 'School code is required';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 14),

                              // Account Identifier
                              TextFormField(
                                controller: _identifierController,
                                enabled: !busy,
                                decoration: const InputDecoration(
                                  labelText: 'Account Identifier',
                                  hintText: 'e.g. SA001 or username',
                                  prefixIcon: Icon(Icons.badge_outlined, size: 20),
                                ),
                                autofillHints: const [AutofillHints.username],
                                validator: (val) {
                                  if (val == null || val.trim().isEmpty) {
                                    return 'Account identifier is required';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 14),

                              // Password
                              TextFormField(
                                controller: _passwordController,
                                enabled: !busy,
                                obscureText: _obscurePassword,
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: const Icon(Icons.lock_outline_rounded, size: 20),
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscurePassword ? Icons.visibility_off_outlined : Icons.visibility_outlined,
                                      size: 20,
                                      color: AppColors.secondaryText,
                                    ),
                                    onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                                  ),
                                ),
                                autofillHints: const [AutofillHints.password],
                                onFieldSubmitted: (_) {
                                  if (!busy) _submit();
                                },
                                validator: (val) {
                                  if (val == null || val.isEmpty) {
                                    return 'Password is required';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 24),

                              // Submit Button
                              FilledButton(
                                onPressed: busy ? null : _submit,
                                child: busy
                                    ? const SizedBox(
                                        height: 20,
                                        width: 20,
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                          valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                                        ),
                                      )
                                    : const Text('Sign in'),
                              ),
                            ],
                          ),
                        ),
                      ),
                      const SizedBox(height: 20),

                      // Security Callout
                      Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: const [
                          Icon(Icons.shield_outlined, size: 16, color: AppColors.premiumTeal),
                          SizedBox(width: 6),
                          Flexible(
                            child: Text(
                              'Hardware-backed Android Keystore Security',
                              overflow: TextOverflow.ellipsis,
                              style: TextStyle(
                                fontSize: 12,
                                color: AppColors.secondaryText,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ),
                        ],
                      ),

                      // Development-only Preview Entry
                      if (PreviewGuard.isPreviewSupported) ...[
                        const SizedBox(height: 24),
                        Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(16),
                            border: Border.all(color: const Color(0xFFCBD5E1)),
                            boxShadow: const [
                              BoxShadow(
                                color: Color(0x08000000),
                                blurRadius: 12,
                                offset: Offset(0, 4),
                              ),
                            ],
                          ),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: const [
                                  Icon(Icons.developer_mode_rounded, size: 18, color: AppColors.primaryBlue),
                                  SizedBox(width: 8),
                                  Text(
                                    'Development Preview Build',
                                    style: TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w700,
                                      color: AppColors.primaryBlue,
                                    ),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 6),
                              const Text(
                                'Experience interactive Dashboard, Academic Catalog and Security screens offline with synthetic data.',
                                textAlign: TextAlign.center,
                                style: TextStyle(fontSize: 11, color: AppColors.secondaryText, height: 1.3),
                              ),
                              const SizedBox(height: 12),
                              OutlinedButton.icon(
                                key: const Key('preview_app_button'),
                                onPressed: _loadingPreview ? null : _enterPreview,
                                icon: _loadingPreview
                                    ? const SizedBox(
                                        width: 16,
                                        height: 16,
                                        child: CircularProgressIndicator(strokeWidth: 2),
                                      )
                                    : const Icon(Icons.play_circle_fill_rounded, size: 18, color: AppColors.primaryBlue),
                                label: const Text(
                                  'Preview App — Development Only',
                                  style: TextStyle(fontWeight: FontWeight.w700),
                                ),
                                style: OutlinedButton.styleFrom(
                                  side: const BorderSide(color: AppColors.primaryBlue, width: 1.5),
                                  padding: const EdgeInsets.symmetric(vertical: 12),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}
