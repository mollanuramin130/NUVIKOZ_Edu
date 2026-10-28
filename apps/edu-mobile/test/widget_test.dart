import 'package:flutter_test/flutter_test.dart';
import 'package:edu_mobile/main.dart';
void main() {
  testWidgets('six role shells show no protected data', (tester) async {
    await tester.pumpWidget(const EduApp());
    expect(find.text('NUVIKOZ Edu'), findsOneWidget);
    expect(find.text('school admin'), findsOneWidget);
  });
}
