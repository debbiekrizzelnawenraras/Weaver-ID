// Tests for reading the tag code record from a tag's memory. Run on the laptop
// with `flutter test`; no phone or NFC needed.

import 'package:flutter_test/flutter_test.dart';
import 'package:weaver_id/main.dart';

// Made by build_code_record() in tools/reader_bridge.py for the code
// 0011223344556677: pages 4..10, then the zero bytes of page 11.
const _record =
    '0316d40b087765617665722e69643a740011223344556677fe000000' '00000000';

void main() {
  test('reads the code from a record written by the tag desk', () {
    expect(parseTagCode(_record), '0011223344556677');
  });

  test('accepts the two reads joined, in capitals, with spaces', () {
    expect(parseTagCode(_record.toUpperCase()), '0011223344556677');
    expect(parseTagCode('0316d40b087765617665722e69643a74 0011223344556677fe000000'),
        '0011223344556677');
  });

  test('returns null for a tag with no Weaver-ID record', () {
    expect(parseTagCode('00' * 32), isNull);
    expect(parseTagCode(''), isNull);
    expect(parseTagCode('0310d1010c5502776561766572'), isNull);
  });

  test('returns null when the record is cut short', () {
    expect(parseTagCode('0316d40b087765617665722e69643a740011'), isNull);
  });

  test('returns null for a record of another type', () {
    expect(parseTagCode('0316d40b087765617665722e69643a75' '0011223344556677fe'), isNull);
  });
}
