import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:edu_mobile/api_client.dart';
import 'package:flutter_test/flutter_test.dart';
class Headers implements HttpHeaders {
 final values=<String,Object>{};
 @override ContentType? contentType;
 @override void set(String name,Object value,{bool preserveHeaderCase=false}){values[name]=value;}
 @override dynamic noSuchMethod(Invocation invocation)=>super.noSuchMethod(invocation);
}
class Reply extends Stream<List<int>> implements HttpClientResponse {
 Reply(this.statusCode,this.text);
 @override final int statusCode;
 final String text;
 @override StreamSubscription<List<int>> listen(void Function(List<int>)? onData,{Function? onError,void Function()? onDone,bool? cancelOnError})=>Stream.value(utf8.encode(text)).listen(onData,onError:onError,onDone:onDone,cancelOnError:cancelOnError);
 @override dynamic noSuchMethod(Invocation invocation)=>super.noSuchMethod(invocation);
}
class Outgoing implements HttpClientRequest {
 Outgoing(this.response);
 final Reply response;
 @override final headers=Headers();
 @override bool followRedirects=true;
 String sent='';
 @override void write(Object? value){sent+='$value';}
 @override Future<HttpClientResponse> close()async=>response;
 @override dynamic noSuchMethod(Invocation invocation)=>super.noSuchMethod(invocation);
}
class Client implements HttpClient {
 Client(this.outgoing);
 final Outgoing outgoing;
 Object? error;Uri? requested;String? method;bool disposed=false;
 @override Duration? connectionTimeout;
 @override Future<HttpClientRequest> openUrl(String method,Uri url)async{this.method=method;requested=url;if(error!=null)throw error!;return outgoing;}
 @override void close({bool force=false}){disposed=true;}
 @override dynamic noSuchMethod(Invocation invocation)=>super.noSuchMethod(invocation);
}
void main(){
 test('real transport serializes JSON and scoped headers, disallows redirects, and disposes',()async{
  final outgoing=Outgoing(Reply(200,'{"data":{"ok":true}}'));final io=Client(outgoing);final api=EduApiClient(Uri.parse('https://edu.example'),client:io);
  expect((await api.send('POST','/api/v1/auth/login',body:{'school_code':'A','identifier':'ID','password':'Synthetic'},token:'synthetic-token',school:'synthetic-school'))['data'],{'ok':true});
  expect(jsonDecode(outgoing.sent),{'school_code':'A','identifier':'ID','password':'Synthetic'});expect((outgoing.headers as Headers).values[HttpHeaders.authorizationHeader],'Bearer synthetic-token');expect((outgoing.headers as Headers).values['X-School-ID'],'synthetic-school');expect(outgoing.followRedirects,isFalse);expect(io.requested!.query,isEmpty);api.close();expect(io.disposed,isTrue);
 });
 test('malformed JSON, HTTP denial and timeout map to safe structured failures',()async{
  for(final reply in [Reply(200,'not JSON'),Reply(200,'[]'),Reply(401,'{"error":{"code":"UNAUTHENTICATED"}}')]){
   final api=EduApiClient(Uri.parse('https://edu.example'),client:Client(Outgoing(reply)));await expectLater(api.send('GET','/api/v1/me'),throwsA(isA<ApiFailure>()));api.close();
  }
  final io=Client(Outgoing(Reply(200,'{}')))..error=TimeoutException('synthetic');final api=EduApiClient(Uri.parse('https://edu.example'),client:io);await expectLater(api.send('GET','/api/v1/me'),throwsA(isA<ApiFailure>().having((e)=>e.code,'code','NETWORK_TIMEOUT')));api.close();
 });
 test('API URL queries, credentials, missing hosts and implicit local HTTP are refused',(){
  for(final value in ['https://edu.example?secret=forbidden','https:///missing','http://127.0.0.1:8000','https://u:p@edu.example']){expect(()=>EduApiClient(Uri.parse(value)),throwsArgumentError);}
 });
}
