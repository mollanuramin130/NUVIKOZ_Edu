import 'package:flutter/material.dart';
void main() => runApp(const EduApp());
const roles = ['platform_super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'];
class EduApp extends StatelessWidget {
  const EduApp({super.key});
  @override
  Widget build(BuildContext context) => MaterialApp(title: 'NUVIKOZ Edu', home: Scaffold(
    appBar: AppBar(title: const Text('NUVIKOZ Edu')),
    body: ListView(children: [const ListTile(title: Text('Development scaffold'), subtitle: Text('No protected records connected.')), for (final role in roles) ListTile(title: Text(role.replaceAll('_', ' ')))]),
  ));
}
