import 'package:flutter/material.dart';
import 'api_client.dart';
import 'auth/auth_controller.dart';
import 'auth/screens/login_screen.dart';
import 'auth/secure_token_store.dart';
import 'core/app_config.dart';
import 'navigation/main_navigation_shell.dart';
import 'theme/app_theme.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const EduApp());
}

class EduApp extends StatefulWidget {
  const EduApp({super.key, this.controller});
  final AuthController? controller;

  @override
  State<EduApp> createState() => _EduAppState();
}

class _EduAppState extends State<EduApp> {
  late final AuthController auth;

  @override
  void initState() {
    super.initState();
    auth = widget.controller ??
        AuthController(
          EduApiClient(Uri.parse(AppConfig.apiBaseUrl)),
          PlatformSecureTokenStore(),
        );
    auth.restore();
  }

  @override
  void dispose() {
    if (widget.controller == null) auth.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConfig.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.lightTheme,
      home: AnimatedBuilder(
        animation: auth,
        builder: (context, _) {
          final session = auth.session;
          if (session != null && auth.state == AuthState.authenticated) {
            return MainNavigationShell(authController: auth);
          }
          return LoginScreen(controller: auth);
        },
      ),
    );
  }
}
