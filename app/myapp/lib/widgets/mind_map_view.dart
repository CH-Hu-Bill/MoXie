import 'dart:math' as math;
import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// 作文思维导图：把服务端生成的 Markdown 大纲（嵌套 "- " 列表，英文要点树）
/// 解析并用 CustomPaint 绘制成手绘风树状图（白底 + 铅笔描边 + 硬阴影 + 贝塞尔连线）。
/// 超宽时可左右滑动查看。零第三方依赖，样式与 Web 端一致。
class MindMapView extends StatelessWidget {
  final String markdown;

  const MindMapView({super.key, required this.markdown});

  @override
  Widget build(BuildContext context) {
    final root = _parse(markdown);
    if (root.children.isEmpty) return const SizedBox.shrink();
    final layout = _MMLayout(root)..compute();
    return LayoutBuilder(
      builder: (ctx, constraints) {
        final content = SizedBox(
          width: layout.width,
          height: layout.height,
          child: CustomPaint(painter: _MindMapPainter(root, layout)),
        );
        if (layout.width <= constraints.maxWidth) {
          return Align(alignment: Alignment.centerLeft, child: content);
        }
        return SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: content,
        );
      },
    );
  }
}

class _MMNode {
  final String text;
  final List<_MMNode> children = [];
  _MMNode(this.text);

  double w = 54;
  double subW = 54;
  double x = 0;
  double y = 0;
  TextPainter? tp;
}

class _StackItem {
  final _MMNode node;
  final int indent;
  _StackItem(this.node, this.indent);
}

_MMNode _parse(String md) {
  final root = _MMNode('');
  final stack = <_StackItem>[_StackItem(root, -1)];
  final re = RegExp(r'^(\s*)(?:[-*+]|\d+\.)\s+(.*)$');
  for (final raw in md.replaceAll('\r', '').split('\n')) {
    if (raw.trim().isEmpty) continue;
    final m = re.firstMatch(raw);
    if (m == null) continue;
    final indent = m.group(1)!.replaceAll('\t', '  ').length;
    final text = m.group(2)!.trim();
    if (text.isEmpty) continue;
    final node = _MMNode(text);
    while (stack.length > 1 && indent <= stack.last.indent) {
      stack.removeLast();
    }
    stack.last.node.children.add(node);
    stack.add(_StackItem(node, indent));
  }
  return root;
}

class _MMLayout {
  static const double nodeH = 34;
  static const double vGap = 30;
  static const double padX = 14;
  static const double sibGap = 18;
  static const double pad = 10;

  final _MMNode root;
  double width = 0;
  double height = 0;
  int maxDepth = 0;

  _MMLayout(this.root);

  void compute() {
    for (final n in root.children) {
      _layoutSubtree(n);
    }
    double total = 0;
    for (int i = 0; i < root.children.length; i++) {
      total += root.children[i].subW + (i > 0 ? sibGap : 0);
    }
    double x = 0;
    for (final n in root.children) {
      _assign(n, x, 0);
      x += n.subW + sibGap;
    }
    width = total + pad * 2;
    height = maxDepth * (nodeH + vGap) + nodeH + pad * 2;
  }

  void _measure(_MMNode n) {
    final tp = TextPainter(
      text: TextSpan(
        text: n.text,
        style: const TextStyle(
          fontFamily: AppTheme.fontHeading,
          fontSize: 15,
          color: AppColors.pencil,
        ),
      ),
      textDirection: TextDirection.ltr,
      maxLines: 1,
    )..layout();
    n.tp = tp;
    n.w = math.max(54, tp.width.ceilToDouble() + padX * 2);
  }

  double _layoutSubtree(_MMNode n) {
    _measure(n);
    if (n.children.isEmpty) {
      n.subW = n.w;
      return n.subW;
    }
    double span = 0;
    for (int i = 0; i < n.children.length; i++) {
      span += _layoutSubtree(n.children[i]) + (i > 0 ? sibGap : 0);
    }
    n.subW = math.max(n.w, span);
    return n.subW;
  }

  void _assign(_MMNode n, double left, int depth) {
    n.y = pad + depth * (nodeH + vGap);
    if (depth > maxDepth) maxDepth = depth;
    if (n.children.isEmpty) {
      n.x = pad + left + n.subW / 2;
      return;
    }
    double span = 0;
    for (int i = 0; i < n.children.length; i++) {
      span += n.children[i].subW + (i > 0 ? sibGap : 0);
    }
    double cur = left + (n.subW - span) / 2;
    for (final ch in n.children) {
      _assign(ch, cur, depth + 1);
      cur += ch.subW + sibGap;
    }
    n.x = pad + left + n.subW / 2;
  }
}

class _MindMapPainter extends CustomPainter {
  final _MMNode root;
  final _MMLayout layout;

  _MindMapPainter(this.root, this.layout);

  @override
  void paint(Canvas canvas, Size size) {
    final shadow = Paint()
      ..style = PaintingStyle.fill
      ..color = AppColors.pencil.withValues(alpha: 0.18);
    final fill = Paint()
      ..style = PaintingStyle.fill
      ..color = AppColors.white;
    final border = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..color = AppColors.pencil;
    final conn = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round
      ..color = AppColors.pencil.withValues(alpha: 0.75);

    void drawConnector(_MMNode p, _MMNode c) {
      final x1 = p.x;
      final y1 = p.y + _MMLayout.nodeH;
      final x2 = c.x;
      final y2 = c.y;
      final my = (y1 + y2) / 2;
      final path = Path()
        ..moveTo(x1, y1)
        ..cubicTo(x1, my, x2, my, x2, y2);
      canvas.drawPath(path, conn);
    }

    void drawNode(_MMNode n) {
      final left = n.x - n.w / 2;
      final top = n.y;
      final shadowRRect = RRect.fromRectAndRadius(
        Rect.fromLTWH(left + 3, top + 3, n.w, _MMLayout.nodeH),
        const Radius.circular(8),
      );
      canvas.drawRRect(shadowRRect, shadow);
      final rrect = RRect.fromRectAndRadius(
        Rect.fromLTWH(left, top, n.w, _MMLayout.nodeH),
        const Radius.circular(8),
      );
      canvas.drawRRect(rrect, fill);
      canvas.drawRRect(rrect, border);
      final tp = n.tp;
      if (tp != null) {
        tp.paint(
          canvas,
          Offset(n.x - tp.width / 2, top + (_MMLayout.nodeH - tp.height) / 2),
        );
      }
    }

    void walk(_MMNode n) {
      drawNode(n);
      for (final c in n.children) {
        drawConnector(n, c);
        walk(c);
      }
    }

    for (final n in root.children) {
      walk(n);
    }
  }

  @override
  bool shouldRepaint(covariant _MindMapPainter old) =>
      old.root != root || old.layout != layout;
}
