import 'package:flutter/material.dart';
import '../../api_client.dart';
import '../../theme/app_colors.dart';
import '../../widgets/app_modal_header.dart';
import '../../widgets/error_banner.dart';
import '../auth_controller.dart';

class MfaChallengeSheet extends StatefulWidget {
  const MfaChallengeSheet({
    super.key,
    required this.authController,
    this.onSuccess,
  });

  final AuthController authController;
  final VoidCallback? onSuccess;

  static Future<bool?> show(BuildContext context, {required AuthController authController, VoidCallback? onSuccess}) {
    return showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => MfaChallengeSheet(
        authController: authController,
        onSuccess: onSuccess,
      ),
    );
  }

  @override
  State<MfaChallengeSheet> createState() => _MfaChallengeSheetState();
}

class _MfaChallengeSheetState extends State<MfaChallengeSheet> {
  final _formKey = GlobalKey<FormState>();
  final _passwordController = TextEditingController();
  final _codeController = TextEditingController();

  String _mode = 'totp'; // 'totp' or 'recovery'
  bool _obscurePassword = true;
  bool _isSubmitting = false;
  String? _errorMessage;

  @override
  void dispose() {
    _passwordController.clear();
    _codeController.clear();
    _passwordController.dispose();
    _codeController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _isSubmitting = true;
      _errorMessage = null;
    });

    final password = _passwordController.text;
    final code = _codeController.text.trim();

    try {
      final res = await widget.authController.protectedPost(
        '/api/v1/security/mfa/challenge',
        body: {
          'password': password,
          'code': code,
          'mode': _mode,
        },
      );

      if (mounted) {
        setState(() => _isSubmitting = false);
        _passwordController.clear();
        _codeController.clear();

        if (res['verified'] == true || res['data']?['verified'] == true) {
          widget.onSuccess?.call();
          Navigator.of(context).pop(true);
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('MFA verification active. Privileged operations enabled for 15 minutes.'),
              backgroundColor: AppColors.primaryBlue,
            ),
          );
        } else {
          setState(() {
            _errorMessage = 'Verification could not be confirmed.';
          });
        }
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isSubmitting = false;
          if (e is ApiFailure) {
            _errorMessage = switch (e.code) {
              'INVALID_MFA_CODE' => 'Invalid authenticator code. Please enter the active 6-digit code.',
              'MFA_EXPIRED' => 'Authenticator code expired. Please use the newest 6-digit code.',
              'INVALID_CREDENTIALS' => 'Incorrect password. Please enter your account password.',
              'NETWORK_TIMEOUT' => 'Verification timed out. Please check your connection and retry.',
              'NETWORK_UNAVAILABLE' => 'Cannot connect to server. Please check your internet connection.',
              _ => e.toString(),
            };
            if (e.code == 'INVALID_MFA_CODE' || e.code == 'MFA_EXPIRED') {
              _codeController.clear();
            } else if (e.code == 'INVALID_CREDENTIALS') {
              _passwordController.clear();
            }
          } else {
            _errorMessage = e.toString();
          }
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;

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
              // Header
              AppModalHeader(
                icon: Icons.security_rounded,
                title: 'Step-up Verification (MFA)',
                subtitle: 'Requires password & 2FA to authorize actions',
                onClose: () => Navigator.of(context).pop(false),
              ),
              const SizedBox(height: 16),

              if (_errorMessage != null) ...[
                ErrorBanner(message: _errorMessage!),
                const SizedBox(height: 12),
              ],

              // Mode toggle (TOTP vs Recovery)
              SegmentedButton<String>(
                segments: const [
                  ButtonSegment(
                    value: 'totp',
                    label: Text('Authenticator (TOTP)'),
                    icon: Icon(Icons.pin_rounded, size: 16),
                  ),
                  ButtonSegment(
                    value: 'recovery',
                    label: Text('Recovery Code'),
                    icon: Icon(Icons.vpn_key_rounded, size: 16),
                  ),
                ],
                selected: {_mode},
                onSelectionChanged: (set) {
                  setState(() {
                    _mode = set.first;
                    _codeController.clear();
                    _errorMessage = null;
                  });
                },
              ),
              const SizedBox(height: 16),

              // Account Password
              TextFormField(
                controller: _passwordController,
                obscureText: _obscurePassword,
                enabled: !_isSubmitting,
                decoration: InputDecoration(
                  labelText: 'Account Password',
                  prefixIcon: const Icon(Icons.lock_outline_rounded, size: 20),
                  suffixIcon: IconButton(
                    icon: Icon(
                      _obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                      size: 20,
                    ),
                    onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                  ),
                ),
                validator: (val) {
                  if (val == null || val.isEmpty) return 'Password is required';
                  return null;
                },
              ),
              const SizedBox(height: 14),

              // Code field
              if (_mode == 'totp')
                TextFormField(
                  controller: _codeController,
                  enabled: !_isSubmitting,
                  keyboardType: TextInputType.number,
                  maxLength: 6,
                  decoration: const InputDecoration(
                    labelText: '6-digit Authenticator Code',
                    hintText: 'e.g. 123456',
                    prefixIcon: Icon(Icons.pin_outlined, size: 20),
                    counterText: '',
                  ),
                  validator: (val) {
                    if (val == null || val.trim().length != 6) {
                      return 'Enter 6 digits from your authenticator app';
                    }
                    return null;
                  },
                )
              else
                TextFormField(
                  controller: _codeController,
                  enabled: !_isSubmitting,
                  maxLength: 32,
                  decoration: const InputDecoration(
                    labelText: '32-character Hex Recovery Code',
                    hintText: 'e.g. 0123456789abcdef0123456789abcdef',
                    prefixIcon: Icon(Icons.key_outlined, size: 20),
                    counterText: '',
                  ),
                  validator: (val) {
                    if (val == null || !RegExp(r'^[a-fA-F0-9]{32}$').hasMatch(val.trim())) {
                      return 'Enter a valid 32-character hexadecimal recovery code';
                    }
                    return null;
                  },
                ),
              const SizedBox(height: 20),

              // Submit Button
              FilledButton(
                onPressed: _isSubmitting ? null : _submit,
                style: FilledButton.styleFrom(
                  backgroundColor: AppColors.primaryBlue,
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                ),
                child: _isSubmitting
                    ? const SizedBox(
                        height: 20,
                        width: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : const Text(
                        'Verify & Authorize (15 min)',
                        style: TextStyle(fontSize: 15, fontWeight: FontWeight.w600),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
