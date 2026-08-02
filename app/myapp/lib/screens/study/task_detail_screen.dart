import 'package:flutter/material.dart';
import '../../models/word.dart';
import '../../services/api_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';

class TaskDetailScreen extends StatefulWidget {
  final String classId;
  final String taskId;
  final String taskLabel;
  final String? highlightQuery;

  const TaskDetailScreen({
    super.key,
    required this.classId,
    required this.taskId,
    required this.taskLabel,
    this.highlightQuery,
  });

  @override
  State<TaskDetailScreen> createState() => _TaskDetailScreenState();
}

class _TaskDetailScreenState extends State<TaskDetailScreen> {
  final _api = ApiService();
  final _scrollController = ScrollController();
  bool _loading = true;
  String _date = '';
  String _status = '';
  List<Word> _words = [];
  String? _error;
  int? _highlightIndex;

  @override
  void initState() {
    super.initState();
    _loadDetail();
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  Future<void> _loadDetail() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final res = await _api.getTaskDetail(widget.classId, widget.taskId);
      final data = res['data'] as Map<String, dynamic>;
      final words = (data['words'] as List)
          .map((w) => Word.fromJson(w as Map<String, dynamic>))
          .toList();
      int? highlightIdx;
      if (widget.highlightQuery != null && widget.highlightQuery!.isNotEmpty) {
        final q = widget.highlightQuery!.toLowerCase();
        for (int i = 0; i < words.length; i++) {
          if (words[i].word.toLowerCase().contains(q) ||
              words[i].meaning.toLowerCase().contains(q)) {
            highlightIdx = i;
            break;
          }
        }
      }
      setState(() {
        _date = data['date'] ?? '';
        _status = data['status'] ?? '';
        _words = words;
        _highlightIndex = highlightIdx;
        _loading = false;
      });
      if (highlightIdx != null) {
        WidgetsBinding.instance.addPostFrameCallback((_) {
          final offset = highlightIdx! * 140.0;
          if (_scrollController.hasClients) {
            _scrollController.animateTo(
              offset.clamp(0.0, _scrollController.position.maxScrollExtent),
              duration: const Duration(milliseconds: 400),
              curve: Curves.easeInOut,
            );
          }
        });
      }
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

  Future<void> _toggleFavorite(Word word) async {
    try {
      await _api.toggleFavorite(widget.classId, word.id);
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
        builder: (ctx) => AlertDialog(
          backgroundColor: AppColors.paper,
          shape: RoundedRectangleBorder(
            borderRadius: AppTheme.wobblyRadius,
            side: const BorderSide(color: AppColors.pencil, width: 2),
          ),
          title: Text('导出文本', style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
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
              onPressed: () => Navigator.pop(ctx),
              child: Text('关闭', style: TextStyle(fontFamily: AppTheme.fontBody)),
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

  bool _shouldHighlight(Word w) {
    if (widget.highlightQuery == null || widget.highlightQuery!.isEmpty) return false;
    final q = widget.highlightQuery!.toLowerCase();
    return w.word.toLowerCase().contains(q) ||
        w.meaning.toLowerCase().contains(q);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.taskLabel.isNotEmpty ? widget.taskLabel : '任务详情'),
        backgroundColor: AppColors.white,
        shape: const Border(bottom: BorderSide(color: AppColors.pencil, width: 3)),
        actions: [
          IconButton(icon: const Icon(Icons.copy), tooltip: '导出文本', onPressed: _exportText),
        ],
      ),
      body: PaperTexture(
        child: _loading
            ? const Center(child: CircularProgressIndicator(color: AppColors.red))
            : _error != null
                ? Center(child: EmptyState(message: _error!))
                : ListView.builder(
                    controller: _scrollController,
                    padding: const EdgeInsets.all(16),
                    itemCount: _words.length + 1,
                    itemBuilder: (ctx, i) {
                      if (i == 0) {
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 16),
                          child: HandDrawnCard(
                            backgroundColor: AppColors.postIt,
                            child: Row(
                              children: [
                                const Icon(Icons.event, color: AppColors.pencil),
                                const SizedBox(width: 8),
                                Flexible(
                                  child: Text(_date,
                                      style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 20),
                                      overflow: TextOverflow.ellipsis),
                                ),
                                const Spacer(),
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                                  decoration: BoxDecoration(
                                    color: _status == 'pending'
                                        ? AppColors.blue.withValues(alpha: 0.15)
                                        : AppColors.oldPaper,
                                    borderRadius: AppTheme.wobblyRadius,
                                    border: Border.all(color: AppColors.pencil),
                                  ),
                                  child: Text(
                                    _status == 'pending' ? '进行中' : _status == 'completed' ? '已完成' : '已取消',
                                    style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 14),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        );
                      }
                      final w = _words[i - 1];
                      return Padding(
                        padding: const EdgeInsets.only(bottom: 12),
                        child: WordCard(
                          word: w.word,
                          meaning: w.meaning,
                          pos: w.pos,
                          isWrong: w.isWrong,
                          isFavorite: w.isFavorite,
                          highlight: _shouldHighlight(w),
                          onToggleWrong: () => _toggleWrong(w),
                          onToggleFavorite: () => _toggleFavorite(w),
                        ),
                      );
                    },
                  ),
      ),
    );
  }
}