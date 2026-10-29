import 'dart:async';
import 'dart:convert';
import 'package:edu_mobile/api_client.dart';
import 'package:edu_mobile/auth/auth_controller.dart';
import 'package:edu_mobile/auth/secure_token_store.dart';
import 'package:flutter_test/flutter_test.dart';

const school='01ARZ3NDEKTSV4RRFFQ69G5FAV';
const member='01ARZ3NDEKTSV4RRFFQ69G5FAW';
const user='01ARZ3NDEKTSV4RRFFQ69G5FAX';
final token='1|${'a'*64}';
Map<String,dynamic> loginData()=>{'data':{'token':token,'school_id':school,'membership_id':member,'user_id':user,'role':'teacher','expires_at':DateTime.now().add(const Duration(hours:1)).toUtc().toIso8601String(),'abilities':['school:$school'],'must_change_password':false}};
class MemoryStore implements SecureTokenStore {
 String? value;bool fail=false;
 @override Future<String?> read()async {if(fail)throw SecureStorageUnavailable();return value;}
 @override Future<void> write(String next)async {if(fail)throw SecureStorageUnavailable();value=next;}
 @override Future<void> clear()async {if(fail)throw SecureStorageUnavailable();value=null;}
}
class FakeApi implements ApiTransport {
 final calls=<Map<String,dynamic>>[];
 Future<Map<String,dynamic>> Function(String,String,Map<String,dynamic>?,String?,String?)? handler;
 @override Future<Map<String,dynamic>> send(String method,String path,{Map<String,dynamic>? body,String? token,String? school})async {
  calls.add({'method':method,'path':path,'body':body,'token':token,'school':school});return handler==null?loginData():handler!(method,path,body,token,school);
 }
 @override void close(){}
}
void main(){
 TestWidgetsFlutterBinding.ensureInitialized();
 test('uninstalled native secure storage fails closed without an insecure fallback',()async{
  final store=PlatformSecureTokenStore();await expectLater(store.read(),throwsA(isA<SecureStorageUnavailable>()));
 });
 test('login sends actual assigned-identifier contract and school header; logout clears secure state',()async{
  final api=FakeApi(),store=MemoryStore();final auth=AuthController(api,store);await auth.login('A','TEACHER','Synthetic-Password');expect(auth.state,AuthState.authenticated);
  expect(api.calls.first['body'],{'school_code':'A','identifier':'TEACHER','password':'Synthetic-Password','device_name':'NUVIKOZ Edu mobile'});
  expect(store.value,contains(token));api.handler=(m,p,b,t,s)async=>{'data':{'id':school}};await auth.protectedGet('/api/v1/schools/$school');expect(api.calls.last['school'],school);expect(api.calls.last['token'],token);
  await auth.logout();expect(store.value,isNull);expect(auth.session,isNull);expect(auth.state,AuthState.signedOut);auth.dispose();
 });
 test('wrong-school, malformed and unsupported platform claims never authenticate',()async{
  for(final change in [{'abilities':['school:OTHER']},{'role':'platform_super_admin'},{'school_id':'malformed'},{'expires_at':'invalid'}]){
   final api=FakeApi(),store=MemoryStore();api.handler=(m,p,b,t,s)async=>{'data':{...(loginData()['data'] as Map<String,dynamic>),...change}};
   final auth=AuthController(api,store);await auth.login('A','ID','Synthetic');expect(auth.session,isNull);expect(store.value,isNull);auth.dispose();
  }
 });
 test('invalid credentials, revocation and storage failure fail closed',()async{
  final api=FakeApi(),store=MemoryStore();final auth=AuthController(api,store);api.handler=(m,p,b,t,s)async=>throw const ApiFailure(401,'INVALID_CREDENTIALS');
  await auth.login('A','ID','wrong');expect(auth.state,AuthState.invalidCredentials);
  api.handler=null;await auth.login('A','ID','Synthetic');api.handler=(m,p,b,t,s)async=>throw const ApiFailure(401,'UNAUTHENTICATED');
  await expectLater(auth.protectedGet('/api/v1/me'),throwsA(isA<ApiFailure>()));expect(auth.state,AuthState.expired);expect(auth.session,isNull);expect(store.value,isNull);
  store.fail=true;final count=api.calls.length;await auth.login('A','ID','Synthetic');expect(auth.state,AuthState.storageUnavailable);expect(api.calls.length,count);auth.dispose();
 });
 test('expiry, forced setup and network failure do not persist authentication',()async{
  for(final change in [{'expires_at':DateTime.now().subtract(const Duration(seconds:1)).toUtc().toIso8601String()},{'must_change_password':true}]){
   final api=FakeApi(),store=MemoryStore();api.handler=(m,p,b,t,s)async=>{'data':{...(loginData()['data'] as Map<String,dynamic>),...change}};final auth=AuthController(api,store);await auth.login('A','ID','Synthetic');expect(auth.session,isNull);expect(store.value,isNull);auth.dispose();
  }
  final api=FakeApi(),store=MemoryStore();api.handler=(m,p,b,t,s)async=>throw const ApiFailure(0,'NETWORK_TIMEOUT');final auth=AuthController(api,store);await auth.login('A','ID','Synthetic');expect(auth.state,AuthState.networkError);auth.dispose();
 });
 test('school switch requires logout plus a fresh login, never changing an existing token header',()async{
  final api=FakeApi(),store=MemoryStore();final auth=AuthController(api,store);await auth.login('A','ID','Synthetic');await auth.switchSchool('B','OTHER','Synthetic');
  expect(api.calls.map((r)=>r['path']).toList(),['/api/v1/auth/login','/api/v1/auth/logout','/api/v1/auth/login']);expect((api.calls.last['body'] as Map)['school_code'],'B');auth.dispose();
 });
 test('a late authenticated response cannot restore state after logout',()async{
  final api=FakeApi(),store=MemoryStore();final response=Completer<Map<String,dynamic>>();api.handler=(m,p,b,t,s)=>p.endsWith('/login')?response.future:Future.value({'data':{'revoked':true}});
  final auth=AuthController(api,store);final login=auth.login('A','ID','Synthetic');await Future<void>.delayed(Duration.zero);await auth.logout();response.complete(loginData());await login;expect(auth.session,isNull);expect(store.value,isNull);expect(auth.state,AuthState.signedOut);auth.dispose();
 });
 test('restore revalidates actual current identity and school on server',()async{
  final api=FakeApi(),store=MemoryStore();final auth=AuthController(api,store);await auth.login('A','ID','Synthetic');auth.dispose();
  api.handler=(m,p,b,t,s)async=>p.endsWith('/me')?{'data':{'id':user,'must_change_password':false,'memberships':[{'id':member,'school_id':school,'role':'teacher'}]}}:{'data':{'id':school}};
  final restored=AuthController(api,store);await restored.restore();expect(restored.state,AuthState.authenticated);expect(api.calls.last['school'],school);restored.dispose();
 });
 test('privileged device identity remains guidance-only and token serialization is redacted',()async{
  final api=FakeApi(),store=MemoryStore();api.handler=(m,p,b,t,s)async=>{'data':{...(loginData()['data'] as Map<String,dynamic>),'role':'school_admin'}};
  final auth=AuthController(api,store);await auth.login('A','ADMIN','Synthetic');expect(auth.session!.privileged,isTrue);expect(auth.session.toString(),isNot(contains(token)));
  api.handler=(m,p,b,t,s)async=>throw const ApiFailure(403,'MFA_REQUIRED');await expectLater(auth.protectedGet('/api/v1/security/devices'),throwsA(isA<ApiFailure>()));expect(auth.session,isNull);expect(store.value,isNull);auth.dispose();
 });
 test('malformed stored JSON never creates offline authenticated state',()async{
  final api=FakeApi(),store=MemoryStore()..value=jsonEncode(['bad']);final auth=AuthController(api,store);await auth.restore();expect(auth.session,isNull);expect(store.value,isNull);auth.dispose();
 });
}
