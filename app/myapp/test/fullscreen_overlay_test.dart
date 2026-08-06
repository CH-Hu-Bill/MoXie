import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:myapp/models/announcement.dart';
import 'package:myapp/services/announcement_service.dart';
import 'package:myapp/widgets/fullscreen_announcement_overlay.dart';

void main() {
  setUp(() {
    AnnouncementService.instance.debugSet(banner: null, fullscreen: null);
  });

  testWidgets('触发后全屏展示，到时长自动隐藏', (tester) async {
    AnnouncementService.instance.debugSet(
      fullscreen: Announcement(
        id: 'fs1',
        content: '超级霸屏测试内容',
        color: '#00aa88',
        mode: 'fullscreen',
        fullscreenSeconds: 1,
      ),
    );

    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Stack(children: [SizedBox(), FullscreenAnnouncementOverlay()])),
      ),
    );

    // 初始不显示
    expect(find.text('超级霸屏测试内容'), findsNothing);

    // 触发
    AnnouncementService.instance.triggerFullscreen();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('超级霸屏测试内容'), findsOneWidget);

    // 到时长自动隐藏
    await tester.pump(const Duration(seconds: 1));
    await tester.pump(const Duration(milliseconds: 350));
    expect(find.text('超级霸屏测试内容'), findsNothing);
  });

  testWidgets('点击任意处立即跳过', (tester) async {
    AnnouncementService.instance.debugSet(
      fullscreen: Announcement(
        id: 'fs2',
        content: '可跳过内容',
        color: '#ff4d4d',
        mode: 'fullscreen',
        fullscreenSeconds: 5,
      ),
    );

    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(body: Stack(children: [SizedBox(), FullscreenAnnouncementOverlay()])),
      ),
    );

    AnnouncementService.instance.triggerFullscreen();
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('可跳过内容'), findsOneWidget);

    await tester.tap(find.byType(GestureDetector).last);
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 350));
    expect(find.text('可跳过内容'), findsNothing);
  });
}
