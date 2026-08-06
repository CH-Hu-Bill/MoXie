import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:myapp/widgets/announcement_marquee.dart';

void main() {
  const textWidth = 200.0;
  const gap = 40.0;
  const viewport = 100.0;

  Widget wrap(double value) {
    return MaterialApp(
      home: Scaffold(
        body: Align(
          alignment: Alignment.topLeft,
          child: SizedBox(
            width: viewport,
            height: 30,
            child: BannerMarquee(
              text: '这是一段用于测试跑马灯的长文本内容',
              style: const TextStyle(fontSize: 13),
              animation: AlwaysStoppedAnimation<double>(value),
              textWidth: textWidth,
              fadeEdges: false,
            ),
          ),
        ),
      ),
    );
  }

  testWidgets('两副本任一动画值下都不重叠', (tester) async {
    for (final value in [0.0, 0.1, 0.3, 0.5, 0.7, 0.9, 1.0]) {
      await tester.pumpWidget(wrap(value));
      await tester.pump();

      final texts = tester.widgetList<Text>(find.byType(Text)).toList();
      // 应恰好两份副本
      expect(texts.length, 2, reason: 'value=$value 应有2份文本副本');

      final rectA = tester.getRect(find.byType(Text).at(0));
      final rectB = tester.getRect(find.byType(Text).at(1));

      // 两份不得在水平方向上相交（中间必有 gap 间隔）
      final intersects = !(rectA.right <= rectB.left || rectB.right <= rectA.left);
      expect(intersects, isFalse,
          reason: 'value=$value A=$rectA B=$rectB 不应相交 (gap=$gap)');
    }
  });

  testWidgets('value=0 时第一份在可见区，value=1 时第二份接续', (tester) async {
    await tester.pumpWidget(wrap(0.0));
    await tester.pump();
    final rectA0 = tester.getRect(find.byType(Text).at(0));
    // value=0: A 的 left = 0
    expect(rectA0.left, moreOrLessEquals(0, epsilon: 1), reason: 'A 应从左缘开始');
    // A 右缘 = textWidth，超出视口被裁剪，但仍应在可见区左侧可见
    expect(rectA0.left < viewport, isTrue);

    await tester.pumpWidget(wrap(1.0));
    await tester.pump();
    final rectB1 = tester.getRect(find.byType(Text).at(1));
    // value=1: B 的 left = 0（补到 A 的起始位置）
    expect(rectB1.left, moreOrLessEquals(0, epsilon: 1), reason: 'B 应补位到 A 的起始位置');
  });
}
