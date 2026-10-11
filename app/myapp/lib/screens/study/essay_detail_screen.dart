import 'package:flutter/material.dart';
import '../../models/word.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';
import '../../widgets/mind_map_view.dart';

/// 作文详情页：标题（如有）+ 全文英文 + 中文直译。
class EssayDetailScreen extends StatelessWidget {
  final Word essay;

  const EssayDetailScreen({super.key, required this.essay});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(essay.title.isNotEmpty ? essay.title : '作文'),
        backgroundColor: AppColors.white,
        shape:
            const Border(bottom: BorderSide(color: AppColors.pencil, width: 3)),
      ),
      body: PaperTexture(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: HandDrawnCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (essay.title.isNotEmpty) ...[
                  Text(
                    essay.title,
                    style: TextStyle(
                      fontFamily: AppTheme.fontHeading,
                      fontSize: 24,
                      color: AppColors.blue,
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                if (essay.hasMindmap) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
                    decoration: BoxDecoration(
                      color: AppColors.paper,
                      borderRadius: AppTheme.wobblyRadius,
                      border:
                          Border.all(color: AppColors.pencil, width: 1.5),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.account_tree_outlined,
                                size: 16, color: AppColors.blue),
                            const SizedBox(width: 6),
                            Text(
                              '思维导图（英文要点）',
                              style: TextStyle(
                                fontFamily: AppTheme.fontHeading,
                                fontSize: 15,
                                color: AppColors.blue,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        MindMapView(markdown: essay.mindmap),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                SelectableText(
                  essay.word,
                  style: TextStyle(
                    fontFamily: AppTheme.fontBody,
                    fontSize: 18,
                    height: 1.6,
                    color: AppColors.pencil,
                  ),
                ),
                if (essay.meaning.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  Divider(color: AppColors.pencil.withValues(alpha: 0.3)),
                  const SizedBox(height: 8),
                  SelectableText(
                    essay.meaning,
                    style: TextStyle(
                      fontFamily: AppTheme.fontBody,
                      fontSize: 16,
                      height: 1.6,
                      color: AppColors.pencil.withValues(alpha: 0.7),
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
