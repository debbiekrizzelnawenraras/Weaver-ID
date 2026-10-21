// Report flow helpers. Runs on the laptop with `flutter test`.

import 'package:flutter_test/flutter_test.dart';
import 'package:weaver_id/main.dart';

void main() {
  test('reads can_report and retry from a suspicious scan', () {
    final result = VerifyResult.fromJson({
      'result': 'suspicious',
      'message': 'flagged',
      'can_report': true,
    });
    expect(result.verdict, Verdict.suspicious);
    expect(result.canReport, isTrue);
    expect(result.retry, isFalse);
  });

  test('asks for another tap when the server says the read may be incomplete', () {
    final result = VerifyResult.fromJson({
      'result': 'suspicious',
      'message': 'tap again',
      'can_report': false,
      'retry': true,
    });
    expect(result.canReport, isFalse);
    expect(result.retry, isTrue);
  });

  test('does not offer a report unless the server says so', () {
    final result = VerifyResult.fromJson({'result': 'suspicious', 'message': 'x'});
    expect(result.canReport, isFalse);
    final ok = VerifyResult.fromJson({'result': 'authenticated', 'message': 'ok'});
    expect(ok.canReport, isFalse);
  });

  test('formats the date the way the server expects', () {
    expect(formatDate(DateTime(2026, 10, 5)), '2026-10-05');
    expect(formatDate(DateTime(2026, 12, 31)), '2026-12-31');
  });
}
