import 'package:flutter_test/flutter_test.dart';
import 'package:myapp/models/gallery_item.dart';

void main() {
  test('parses live gallery item', () {
    final item = GalleryItem.fromJson({
      'id': 'g',
      'image_url': 'http://x/a.jpg',
      'type': 'live',
      'live_video_url': 'http://x/b.mp4',
      'description': 'd',
      'uploaded_at': 't',
    });
    expect(item.isLive, true);
    expect(item.liveVideoUrl, 'http://x/b.mp4');
    expect(item.toCacheJson()['type'], 'live');
    expect(item.toCacheJson()['live_video_url'], 'http://x/b.mp4');
  });

  test('normal image is not live', () {
    final item = GalleryItem.fromJson({
      'id': 'g',
      'image_url': 'http://x/a.jpg',
      'type': 'static',
    });
    expect(item.isLive, false);
    expect(item.liveVideoUrl, isEmpty);
  });
}
