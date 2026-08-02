import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/models/word.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/theme/app_theme.dart';

class TaskDetailScreen extends StatefulWidget {
  final String classId;
  final String taskId;

  const TaskDetailScreen({
    super.key,
    required this.classId,
    required this.taskId,
  });

  @override
  State<TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends State<TaskDetailScreen> {
  List<Word> _words = [];
  String _taskDate = '';
  String _taskLabel = '';
  String _taskStatus = '';
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _loadTask();
  }

  Future<void> _loadTask() async {
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('get_task_detail', {
        'class_id': widget.classId,
        'task_id': widget.taskId,
      });
      if (result['success'] == true) {
        final data = result['data'] ?? {};
        setState(() {
          _taskDate = data['date'] ?? '';
          _taskLabel = data['label'] ?? '';
          _taskStatus = data['status'] ?? '';
          _words = (data['words'] as List?)
                  ?.map((j) => Word.fromJson(j))
                  .toList() ??
              [];
          _loading = false;
        });
      }
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  Future<void> _exportWords() async {
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('export_task_text', {
        'class_id': widget.classId,
        'task_id': widget.taskId,
      });
      if (result['success'] == true) {
        final text = result['data']['text'] ?? '';
        if (mounted) {
          showDialog(
            context: context,
            builder: (ctx) => AlertDialog(
              backgroundColor: HandDrawnTheme.warmPaper,
              shape: RoundedRectangleBorder(
                borderRadius: HandDrawnTheme.wobblyRadiusMd,
                side:
                    const BorderSide(color: HandDrawnTheme.pencil, width: 2),
              ),
              title: Text(
                '导出单词',
                style: TextStyle(
                  fontFamily: 'Kalam',
                  fontWeight: FontWeight.w700,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              content: Text(
                text,
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  fontSize: 14,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(ctx),
                  child: Text(
                    '关闭',
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      color: HandDrawnTheme.blue,
                    ),
                  ),
                ),
              ],
            ),
          );
        }
      }
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return HandDrawnScaffold(
        title: '任务详情',
        body: const Center(
          child: CircularProgressIndicator(color: HandDrawnTheme.pencil),
        ),
      );
    }

    return HandDrawnScaffold(
      title: _taskLabel.isNotEmpty ? _taskLabel : '任务详情',
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: HandDrawnCard(
              padding: const EdgeInsets.all(16),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _taskDate,
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 16,
                          color: HandDrawnTheme.pencil,
                        ),
                      ),
                      Text(
                        '${_words.length} 个单词',
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 14,
                          color: HandDrawnTheme.pencil.withValues(alpha: 0.6),
                        ),
                      ),
                    ],
                  ),
                  HandDrawnButton(
                    text: '导出单词',
                    icon: Icons.copy,
                    onPressed: _exportWords,
                    secondary: true,
                    small: true,
                  ),
                ],
              ),
            ),
          ),
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.only(bottom: 16),
              itemCount: _words.length,
              itemBuilder: (context, index) {
                final word = _words[index];
                return HandDrawnCard(
                  rotation: index % 2 == 0 ? 0.5 : -0.5,
                  child: Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              word.word,
                              style: TextStyle(
                                fontFamily: 'Kalam',
                                fontSize: 20,
                                fontWeight: FontWeight.w700,
                                color: HandDrawnTheme.pencil,
                              ),
                            ),
                            Text(
                              word.meaning,
                              style: TextStyle(
                                fontFamily: 'Patrick Hand',
                                fontSize: 16,
                                color: HandDrawnTheme.pencil,
                              ),
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        icon: Icon(
                          Icons.volume_up,
                          color: HandDrawnTheme.blue,
                        ),
                        onPressed: () {},
                      ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }
}