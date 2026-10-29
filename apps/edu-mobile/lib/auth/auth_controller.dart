import 'dart:convert';
import 'dart:async';
import 'package:flutter/foundation.dart';
import '../api_client.dart';
import 'device_session.dart';
import 'secure_token_store.dart';

enum AuthState { signedOut, authenticating, authenticated, expired, denied, networkError, storageUnavailable, reauthenticationRequired, invalidCredentials }
class AuthController extends ChangeNotifier {
  AuthController(this.api, this.store, {DateTime Function()? now}) : _now = now ?? DateTime.now;
  final ApiTransport api;
  final SecureTokenStore store;
  final DateTime Function() _now;
  AuthState state = AuthState.signedOut;
  DeviceSession? _session;
  DeviceSession? get session => _session;
  String message = '';
  bool _disposed = false;
  Timer? _expiry;
  void _activate(DeviceSession session) {
    _expiry?.cancel(); _session=session; state=AuthState.authenticated;
    final generation=_generation;
    _expiry=Timer(session.expires.difference(_now()), () async {
      if(!_disposed&&generation==_generation){++_generation;_session=null;state=AuthState.expired;message='Your session expired. Sign in again.';try{await _write(null);}catch(e){_failure(e);return;}_emit();}
    }); _emit();
  }
  int _generation = 0;
  Future<void> _storage = Future<void>.value();
  void _emit() { if (!_disposed) notifyListeners(); }
  Map<String,dynamic> _data(Map<String,dynamic> response) {
    final data=response['data']; if(data is! Map<String,dynamic>) throw const ApiFailure(0,'MALFORMED_RESPONSE'); return data;
  }
  Future<void> _write(String? value) {
    final next = _storage.catchError((Object _) {}).then((_) async {
      if(value == null) { await store.clear(); } else { await store.write(value); }
    }); _storage=next; return next;
  }
  void _failure(Object e, {bool login = false}) {
    ++_generation; _expiry?.cancel();
    _session=null;
    state=e is SecureStorageUnavailable?AuthState.storageUnavailable:e is ApiFailure?(e.code=='PASSWORD_CHANGE_REQUIRED'?AuthState.reauthenticationRequired:e.status==401?(login?AuthState.invalidCredentials:AuthState.expired):e.status==403?AuthState.denied:AuthState.networkError):AuthState.networkError;
    message=e is SecureStorageUnavailable?e.toString():e is ApiFailure?e.toString():'Authentication unavailable. Please retry.';
    _emit();
  }
  Future<void> login(String schoolCode,String identifier,String password) async {
    final generation=++_generation;_expiry?.cancel();_session=null;state=AuthState.authenticating;message='';_emit(); DeviceSession? issued;
    try {
      // Verify the production storage boundary before sending any credentials.
      await store.read(); await _write(null);
      if(generation!=_generation||_disposed)return;
      final data=_data(await api.send('POST','/api/v1/auth/login',body:{'school_code':schoolCode,'identifier':identifier,'password':password,'device_name':'NUVIKOZ Edu mobile'}));
      issued=DeviceSession.parse(data);
      if(data['must_change_password'] != false) throw const ApiFailure(403,'PASSWORD_CHANGE_REQUIRED');
      if(issued.expired(_now())) throw const ApiFailure(401,'TOKEN_EXPIRED');
      if(generation!=_generation||_disposed) { await _revoke(issued); return; }
      await _write(jsonEncode(issued.toJson()));
      if(generation!=_generation||_disposed){await _revoke(issued);return;}
      _activate(issued);
    } catch(e) {
      if(issued!=null) await _revoke(issued);
      if(generation==_generation&&!_disposed){try{await _write(null);}catch(_){} _failure(e,login:true);}
    }
  }
  Future<void> restore() async {
    final generation=++_generation;
    try {
      final raw=await store.read();if(raw==null)return;
      final value=jsonDecode(raw);if(value is! Map<String,dynamic>)throw const ApiFailure(0,'MALFORMED_RESPONSE');
      final saved=DeviceSession.parse(value);if(saved.expired(_now()))throw const ApiFailure(401,'TOKEN_EXPIRED');
      final me=_data(await api.send('GET','/api/v1/me',token:saved.token,school:saved.school));
      final members=me['memberships'];
      if(me['id']!=saved.user||me['must_change_password']!=false||members is! List||!members.any((m)=>m is Map&&m['id']==saved.membership&&m['school_id']==saved.school&&m['role']==saved.role))throw const ApiFailure(403,'TOKEN_SCHOOL_MISMATCH');
      final school=_data(await api.send('GET','/api/v1/schools/${saved.school}',token:saved.token,school:saved.school));
      if(school['id']!=saved.school)throw const ApiFailure(403,'TOKEN_SCHOOL_MISMATCH');
      if(generation==_generation&&!_disposed){_activate(saved);}
    } catch(e){if(generation==_generation&&!_disposed){try{await _write(null);}catch(_){} _failure(e);}}
  }
  Future<Map<String,dynamic>> protectedGet(String path) async {
    final current=_session; final generation=_generation;
    try {
      if(current==null||current.expired(_now()))throw const ApiFailure(401,'TOKEN_EXPIRED');
      final result=_data(await api.send('GET',path,token:current.token,school:current.school));
      if(generation!=_generation||_disposed)throw const ApiFailure(401,'SESSION_CHANGED');
      return result;
    }catch(e){if(generation==_generation&&!_disposed){try{await _write(null);}catch(_){} _failure(e);}rethrow;}
  }
  Future<void> _revoke(DeviceSession session) async {
    try{await api.send('POST','/api/v1/auth/logout',body:{},token:session.token,school:session.school);}catch(_){}
  }
  Future<void> logout() async {
    final old=_session;++_generation;_expiry?.cancel();_session=null;state=AuthState.signedOut;message='';_emit();
    try{await _write(null);}catch(e){_failure(e);}
    if(old!=null)await _revoke(old);
  }
  // School switching requires a fresh login, never changing a header on an old token.
  Future<void> switchSchool(String school,String identifier,String password) async {await logout();await login(school,identifier,password);}
  @override
  void dispose(){_expiry?.cancel();_disposed=true;++_generation;_session=null;api.close();super.dispose();}
}
