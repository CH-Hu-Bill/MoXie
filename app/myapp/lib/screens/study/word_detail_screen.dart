import 'package:flutter/material.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/theme/app_theme.dart';

class WordDetailScreen extends StatelessWidget {
  final String word;
  final String meaning;
  final String pos;
  final bool isWrong;
  final bool isFavorite;

  const WordDetailScreen({
    super.key,
    required this.word,
    required this.meaning,
    this.pos = '',
    this.isWrong = false,
    this.isFavorite = false,
  });

  @override
  Widget build(BuildContext context) {
    return HandDrawnScaffold(
      title: '单词详情',
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: Column(
          children: [
            HandDrawnCard(
              decoration: 'tape',
              padding: const EdgeInsets.all(24),
              child: Column(
                children: [
                  Text(
                    word,
                    style: TextStyle(
                      fontFamily: 'Kalam',
                      fontSize: 36,
                      fontWeight: FontWeight.w700,
                      color: HandDrawnTheme.pencil,
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    meaning,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      fontSize: 20,
                      color: HandDrawnTheme.pencil,
                    ),
                  ),
                  if (pos.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 12, vertical: 4),
                      decoration: BoxDecoration(
                        color: HandDrawnTheme.muted,
                        borderRadius: HandDrawnTheme.wobblyRadiusSm,
                        border: Border.all(
                            color: HandDrawnTheme.pencil, width: 1),
                      ),
                      child: Text(
                        pos,
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 16,
                          color: HandDrawnTheme.pencil,
                        ),
                      ),
                    ),
                  ],
                ],
              ),
            ),
            const SizedBox(height: 20),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                HandDrawnButton(
                  text: '发音',
                  icon: Icons.volume_up,
                  secondary: true,
                  onPressed: () {},
                ),
                const SizedBox(width: 16),
                HandDrawnButton(
                  text: isWrong ? '移出错题本' : '加入错题本',
                  icon: isWrong ? Icons.check : Icons.error_outline,
                  secondary: true,
                  onPressed: () {},
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}