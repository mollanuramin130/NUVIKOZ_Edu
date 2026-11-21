import '../../api_client.dart';
import 'preview_guard.dart';

/// In-memory fake API transport that intercepts all HTTP calls in preview mode
/// and returns synthetic responses without sending any bytes to external networks.
class PreviewApiTransport implements ApiTransport, AcademicApiTransport {
  PreviewApiTransport() {
    PreviewGuard.assertNotRelease();
  }

  @override
  Future<Map<String, dynamic>> send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    String? token,
    String? school,
    Map<String, String>? queryParams,
    Map<String, String>? headers,
  }) async {
    PreviewGuard.assertNotRelease();

    // Preview Auth Login
    if (path == '/api/v1/auth/login') {
      return {
        'data': {
          'token': '1|preview_synthetic_token_${'0'*64}',
          'school_id': '01JPREV1EW0000000000000001',
          'membership_id': '01JPREV1EW0000000000000002',
          'user_id': '01JPREV1EW0000000000000003',
          'role': 'school_admin',
          'expires_at': DateTime.now().add(const Duration(days: 7)).toUtc().toIso8601String(),
          'abilities': ['school:01JPREV1EW0000000000000001'],
          'must_change_password': false,
        }
      };
    }

    // School Profile
    if (path.startsWith('/api/v1/schools/')) {
      return {
        'data': {
          'id': '01JPREV1EW0000000000000001',
          'name': 'Springdale Academy (Synthetic Demo)',
          'code': 'DEMO-01',
          'status': 'active',
          'timezone': 'Asia/Kolkata',
        }
      };
    }

    // Security Devices
    if (path == '/api/v1/security/devices') {
      return {
        'data': {
          'devices': [
            {
              'id': '01JPREVIEWDEV000000000001',
              'name': 'OPPO F25 Pro (CPH2603)',
              'model': 'CPH2603',
              'last_seen': 'Just now (Active)',
              'is_current': true,
              'platform': 'Android 16',
              'keystore_hardware': true,
            },
            {
              'id': '01JPREVIEWDEV000000000002',
              'name': 'Android Admin Tablet (Simulated)',
              'model': 'Tablet-10',
              'last_seen': 'Yesterday 18:42',
              'is_current': false,
              'platform': 'Android 15',
              'keystore_hardware': true,
            },
          ]
        }
      };
    }

    // Revoke Device
    if (path.contains('/api/v1/security/devices/') && path.endsWith('/revoke')) {
      return {'data': {'revoked': true}};
    }

    // MFA Status
    if (path == '/api/v1/security/mfa/status') {
      return {
        'data': {
          'enrolled': true,
          'method': 'totp',
          'verified': true,
          'required_for_privileged': true,
        }
      };
    }

    // MFA Challenge
    if (path == '/api/v1/security/mfa/challenge') {
      return {
        'data': {
          'challenge_id': 'preview_challenge_id_01',
          'expires_in': 300,
        }
      };
    }

    // MFA Verify
    if (path == '/api/v1/security/mfa/verify') {
      return {
        'data': {
          'verified': true,
          'step_up_token': 'preview_step_up_token_01',
        }
      };
    }

    // Logout
    if (path == '/api/v1/auth/logout') {
      return {'data': {'success': true}};
    }

    throw UnsupportedError('External network calls are strictly blocked in preview mode: $path');
  }

  @override
  void close() {}
}
