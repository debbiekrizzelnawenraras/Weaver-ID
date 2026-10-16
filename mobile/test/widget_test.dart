// Smoke test for the Weaver-ID scanner screen. Runs on the laptop with
// `flutter test`; no phone or NFC needed.

import 'package:flutter_test/flutter_test.dart';
import 'package:weaver_id/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('scanner screen shows the scan button and the UID test box', (tester) async {
    await tester.pumpWidget(const WeaverIdApp());
    await tester.pumpAndSettle();

    expect(find.text('Check a handwoven product'), findsOneWidget);
    expect(find.text('Scan a tag'), findsOneWidget);
    expect(find.text('Test with a UID (no NFC needed)'), findsOneWidget);
  });
}
