import 'package:flutter/material.dart';
import '../../models/word.dart';
import '../../services/api_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';

class TaskDetailScreen extends StatefulWidget {
  final String classId;
  final String taskId;
  final String taskLabel;

  const TaskDetailScreen({
    super.key,
    required this.classId,
    required this.taskId,
    required this.taskLabel,
  });

  @override
  State<TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends State<TaskDetailScreen> {
  final _api = ApiService();
  bool _loading = true;
  String _date = '';
  String _status = '';
  List<Word> _words = [];
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadDetail();
  }

  Future<void> _loadDetail() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res =
          await _api.getTaskDetail(widget.classId, widget.taskId);
      final data = res['data'] as Map<String, dynamic>;
      setState(() {
        _date = data['date'] ?? '';
        _status = data['status'] ?? '';
        _words = (data['words'] as List)
            .map((w) => Word.fromJson(w as Map<String, dynamic>))
            .toList();
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = e.toString();
        _loading = false;
      });
    }
  }

  Future<void> _toggleWrong(Word word) async {
    try {
      if (word.isWrong) {
        await _api.unmarkWrong(widget.classId, word.id);
      } else {
        await _api.markWrong(widget.classId, word.id, true);
      }
      _loadDetail();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    }
  }

  Future<void> _exportText() async {
    try {
      final res = await _api.exportTaskText(widget.classId, widget.taskId);
      final text = res['data']['text'] as String;
      if (!mounted) return;
      showDialog(
        context: context,
        builder: (_) => AlertDialog(
          backgroundColor: AppColors.background,
          shape: RoundedRectangleBorder(
            borderRadius: AppTheme.wobblyRadius,
            side: const BorderSide(color: AppColors.border, width: 2),
          ),
          title: Text('导出文本', style: AppTheme.headingStyle),
          content: SizedBox(
            width: double.maxFinite,
            child: TextField(
              readOnly: true,
              maxLines: 15,
              controller: TextEditingController(text: text),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(_),
              child: Text('关闭', style: AppTheme.bodyStyle),
            ),
          ],
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.taskLabel.isNotEmpty ? widget.taskLabel : '任务详情'),
        actions: [
          IconButton(
            icon: const Icon(Icons.copy),
            tooltip: '导出文本',
            onPressed: _exportText,
          ),
        ],
      ),
      body: PaperTexture(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _error != null
                ? Center(child: EmptyState(message: _error!))
                : ListView.builder(
                    padding: const EdgeInsets.all(16),
                    itemCount: _words.length + 1,
                    itemBuilder: (ctx, i) {
                      if (i == 0) {
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 16),
                          child: TapeDecoration(
                            child: HandDrawnCard(
                              backgroundColor: AppColors.postItYellow,
                              child: Row(
                                children: [
                                  Icon(Icons.event, color: AppColors.foreground),
                                  const SizedBox(width: 8),
                                  Text(
                                    _date,
                                    style: AppTheme.headingStyle
                                        .copyWith(fontSize: 20),
                                  ),
                                  const Spacer(),
                                  Container(
                                    padding: const EdgeInsets.symmetric(
                                        horizontal: 12, vertical: 4),
                                    decoration: BoxDecoration(
                                      color: _status == 'pending'
                                          ? AppColors.secondaryAccent
                                              .withValues(alpha: 0.15)
                                          : AppColors.muted,
                                      borderRadius: AppTheme.wobblyRadius,
                                      border: Border.all(
                                          color: AppColors.border),
                                    ),
                                    child: Text(
                                      _status == 'pending' ? '进行中' : _status == 'completed' ? '已完成' : '已取消',
                                      style: AppTheme.bodyStyle.copyWith(
                                        fontSize: 14,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        );
                      }
                      final w = _words[i - 1];
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: HandDrawnCard(
                          onTap: () => _toggleWrong(w),
                          backgroundColor: w.isWrong
                              ? AppColors.accent.withValues(alpha: 0.08)
                              : null,
                          borderWidth: w.isWrong ? 3 : 2,
                          child: Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Text(
                                          w.word,
                                          style: AppTheme.headingStyle
                                              .copyWith(
                                            fontSize: 22,
                                            decoration: w.isWrong
                                                ? TextDecoration.lineThrough
                                                : null,
                                            decorationColor:
                                                AppColors.accent,
                                            decorationThickness: 2.5,
                                          ),
                                        ),
                                        if (w.pos.isNotEmpty) ...[
                                          const SizedBox(width: 8),
                                          Container(
                                            padding: const EdgeInsets
                                                .symmetric(
                                                horizontal: 8, vertical: 2),
                                            decoration: BoxDecoration(
                                              color: AppColors
                                                  .secondaryAccent
                                                  .withValues(alpha: 0.1),
                                              borderRadius:
                                                  AppTheme.wobblyRadius,
                                            ),
                                            child: Text(
                                              w.pos,
                                              style: AppTheme.bodyStyle
                                                  .copyWith(
                                                fontSize: 13,
                                                color: AppColors
                                                    .secondaryAccent,
                                              ),
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                    const SizedBox(height: 4),
                                    Text(
                                      w.meaning,
                                      style: AppTheme.bodyStyle.copyWith(
                                        fontSize: 16,
                                        color: AppColors.foreground
                                            .withValues(alpha: 0.7),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              Icon(
                                w.isWrong
                                    ? Icons.error
                                    : Icons.error_outline,
                                color: w.isWrong
                                    ? AppColors.accent
                                    : AppColors.foreground
                                        .withValues(alpha: 0.3),
                              ),
                            ],
                          ),
                        ),
                      );
                    },
                  ),
      ),
    );
  }
}
