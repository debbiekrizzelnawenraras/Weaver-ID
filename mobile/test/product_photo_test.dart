// Product photo address handling. Runs on the laptop with `flutter test`.

import 'package:flutter_test/flutter_test.dart';
import 'package:weaver_id/main.dart';

void main() {
  test('joins the server address and the photo path', () {
    expect(
      resolvePhotoUrl('http://192.168.137.1:8000', '/api/product-photos/a.jpg'),
      'http://192.168.137.1:8000/api/product-photos/a.jpg',
    );
    expect(
      resolvePhotoUrl('http://192.168.137.1:8000/', '/api/product-photos/a.jpg'),
      'http://192.168.137.1:8000/api/product-photos/a.jpg',
    );
  });

  test('keeps a full address and returns null when there is no photo', () {
    expect(resolvePhotoUrl('http://x:8000', 'https://example.com/a.jpg'),
        'https://example.com/a.jpg');
    expect(resolvePhotoUrl('http://x:8000', null), isNull);
    expect(resolvePhotoUrl('http://x:8000', '  '), isNull);
  });

  test('reads the photo address from an authenticated scan', () {
    final result = VerifyResult.fromJson({
      'result': 'authenticated',
      'message': 'ok',
      'product': {
        'title': 'Blanket',
        'category': 'Textile',
        'photo_url': '/api/product-photos/a.jpg',
      },
      'association': {'name': 'A', 'municipality': 'Bontoc'},
    });
    expect(result.photoPath, '/api/product-photos/a.jpg');
  });

  test('has no photo when the server sends none', () {
    final result = VerifyResult.fromJson({
      'result': 'authenticated',
      'message': 'ok',
      'product': {'title': 'Blanket', 'category': 'Textile', 'photo_url': null},
    });
    expect(result.photoPath, isNull);
  });
}
