import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// 验证「扫描式定位」算法：在懒加载、变高卡片列表中能可靠找到并滚动到目标项。
/// 用真实 ListView.builder 复刻 StudyScreen 的懒加载 + GlobalKey 机制。
/// 测试手动逐帧驱动扫描（jumpTo → pump → 检查 key），模拟真实运行。
void main() {
  const itemCount = 500;
  // 高度不一：大部分 90px，少量 140px（模拟有/无 pos、长/短词）
  double itemHeight(int i) => (i % 7 == 0) ? 140 : 90;

  testWidgets('懒加载变高列表中扫描定位到目标项', (tester) async {
    final controller = ScrollController();
    final keys = <String, GlobalKey>{};
    final targetWord = 'word_300';

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ListView.builder(
            controller: controller,
            itemCount: itemCount,
            itemBuilder: (ctx, i) {
              final w = 'word_$i';
              final key = keys.putIfAbsent(w, () => GlobalKey());
              return Container(
                key: key,
                height: itemHeight(i),
                margin: const EdgeInsets.only(bottom: 12),
                color: i == 300 ? Colors.yellow : Colors.white,
                child: Center(child: Text(w)),
              );
            },
          ),
        ),
      ),
    );
    await tester.pump();

    // 逐帧驱动扫描
    GlobalKey? foundKey;
    final max = controller.position.maxScrollExtent;
    final viewport = controller.position.viewportDimension;
    final base = 300 * 90.0;
    final step = viewport * 0.7;
    for (int round = 0; round < 25; round++) {
      await tester.pump(const Duration(milliseconds: 80));
      final key = keys[targetWord];
      if (key?.currentContext != null) {
        foundKey = key;
        Scrollable.ensureVisible(
          key!.currentContext!,
          alignment: 0.4,
          duration: const Duration(milliseconds: 350),
          curve: Curves.easeInOut,
        );
        break;
      }
      double t;
      if (round == 0) {
        t = base;
      } else if (round <= 12) {
        t = base + step * round;
      } else {
        t = base - step * (round - 12);
      }
      t = t.clamp(0.0, max);
      controller.jumpTo(t);
    }
    await tester.pump(const Duration(milliseconds: 500));
    await tester.pump(const Duration(milliseconds: 500));

    final resolved = foundKey;
    expect(resolved, isNotNull, reason: '扫描应能找到目标项');
    if (resolved == null) return;
    final ctx = resolved.currentContext!;
    final box = ctx.findRenderObject() as RenderBox;
    final top = box.localToGlobal(Offset.zero).dy;
    final bottom = top + box.size.height;
    final viewportH =
        tester.view.physicalSize.height / tester.view.devicePixelRatio;
    expect(top < viewportH && bottom > 0, isTrue,
        reason: '目标应位于可视区域内 top=$top bottom=$bottom viewport=$viewportH');
  });
}
