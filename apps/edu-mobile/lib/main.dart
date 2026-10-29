import 'package:flutter/material.dart';
import 'api_client.dart';
import 'auth/auth_controller.dart';
import 'auth/secure_token_store.dart';

void main() => runApp(const EduApp());
class EduApp extends StatefulWidget {
  const EduApp({super.key,this.controller});
  final AuthController? controller;
  @override
  State<EduApp> createState()=>_EduAppState();
}
class _EduAppState extends State<EduApp> {
  late final AuthController auth;
  final school=TextEditingController(),identifier=TextEditingController(),password=TextEditingController();
  @override
  void initState(){super.initState();auth=widget.controller??AuthController(EduApiClient(Uri.parse(const String.fromEnvironment('EDU_API_BASE_URL',defaultValue:'https://api.invalid'))),PlatformSecureTokenStore());auth.restore();}
  @override
  void dispose(){school.dispose();identifier.dispose();password.dispose();if(widget.controller==null)auth.dispose();super.dispose();}
  Future<void> login() async {final secret=password.text;password.clear();await auth.login(school.text.trim(),identifier.text.trim(),secret);}
  @override
  Widget build(BuildContext context)=>MaterialApp(title:'NUVIKOZ Edu',theme:ThemeData(colorScheme:ColorScheme.fromSeed(seedColor:const Color(0xff087F8C)),inputDecorationTheme:const InputDecorationTheme(border:OutlineInputBorder())),home:AnimatedBuilder(animation:auth,builder:(context,_) {
    final session=auth.session;final busy=auth.state==AuthState.authenticating;
    return Scaffold(appBar:AppBar(title:const Text('NUVIKOZ Edu')),body:Center(child:ConstrainedBox(constraints:const BoxConstraints(maxWidth:520),child:ListView(padding:const EdgeInsets.all(24),children:[
      if(session!=null&&auth.state==AuthState.authenticated)...[
        const Text('Signed-in identity',style:TextStyle(fontSize:24)),Text('Role: ${session.role}'),Text('School context: ${session.school}'),Text('Membership: ${session.membership}'),Text('Expires: ${session.expires.toLocal()}'),
        if(session.privileged)const Text('Privileged mobile actions are unavailable until an approved device MFA flow exists. Use the secure browser flow.'),
        const Text('Academic modules are not connected. No protected records are cached.'),
        FilledButton(onPressed:()async{try{await auth.protectedGet('/api/v1/schools/${session.school}');}catch(_){}},child:const Text('Verify school access')),
        OutlinedButton(onPressed:auth.logout,child:const Text('Sign out')),
        TextButton(onPressed:auth.logout,child:const Text('Sign out to switch schools')),
      ]else...[
        const Text('Sign in to your school',style:TextStyle(fontSize:24)),const Text('Use the school code and assigned account identifier. Shared phone numbers do not select an identity.'),const SizedBox(height:16),
        TextField(controller:school,enabled:!busy,decoration:const InputDecoration(labelText:'School code'),autofillHints:const [AutofillHints.organizationName]),const SizedBox(height:12),
        TextField(controller:identifier,enabled:!busy,decoration:const InputDecoration(labelText:'Account identifier'),autofillHints:const [AutofillHints.username]),const SizedBox(height:12),
        TextField(controller:password,enabled:!busy,obscureText:true,decoration:const InputDecoration(labelText:'Password'),autofillHints:const [AutofillHints.password],onSubmitted:(_){if(!busy)login();}),const SizedBox(height:12),
        if(busy)const LinearProgressIndicator(),
        FilledButton(onPressed:busy?null:login,child:Text(busy?'Signing in…':'Sign in')),
        if(auth.state==AuthState.storageUnavailable)const Text('Platform-backed secure storage has not been installed in this scaffold. Authentication fails closed; no insecure persistence fallback is used.'),
        if(auth.state==AuthState.reauthenticationRequired)const Text('Complete approved account activation/password setup before mobile sign-in.'),
      ],if(auth.message.isNotEmpty)Semantics(liveRegion:true,child:Text(auth.message)),
    ]))));
  }));
}
