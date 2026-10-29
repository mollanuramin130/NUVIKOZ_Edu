import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/main.dart';
import 'package:edu_mobile/auth/auth_controller.dart';
import 'auth_controller_test.dart' show FakeApi, MemoryStore;
void main(){
 testWidgets('ordinary login and logout render only server-granted context',(tester)async{
  final auth=AuthController(FakeApi(),MemoryStore());await tester.pumpWidget(EduApp(controller:auth));await tester.pumpAndSettle();
  expect(find.text('Sign in to your school'),findsOneWidget);
  await tester.enterText(find.byType(TextField).at(0),'A');await tester.enterText(find.byType(TextField).at(1),'TEACHER');await tester.enterText(find.byType(TextField).at(2),'Synthetic');
  await tester.ensureVisible(find.text('Sign in'));await tester.tap(find.text('Sign in'));await tester.pumpAndSettle();expect(find.text('Signed-in identity'),findsOneWidget);expect(find.text('Role: teacher'),findsOneWidget);
  await tester.ensureVisible(find.text('Sign out'));await tester.tap(find.text('Sign out'));await tester.pumpAndSettle();expect(find.text('Sign in to your school'),findsOneWidget);await tester.pumpWidget(const SizedBox());auth.dispose();
 });
}
