import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/vlog_entry.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';
import '../study/task_detail_screen.dart';

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _api = ApiService();
  final _controller = TextEditingController();
  SearchResult? _result;
  bool _loading = false;
  bool _searched = false;

  Future<void> _search() async {
    final query = _controller.text.trim();
    if (query.isEmpty) return;
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    setState(() {
      _loading = true;
      _searched = true;
    });
    try {
      final res = await _api.searchAll(classId, query);
      setState(() {
        _result = SearchResult.fromJson(res['data'] as Map<String, dynamic>);
        _loading = false;
      });
    } catch (e) {
      setState(() => _loading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      appBar: AppBar(title: Text(auth.currentClassName ?? '搜索')),
      body: PaperTexture(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _controller,
                      decoration: InputDecoration(
                        hintText: '搜索单词、释义或任务...',
                        prefixIcon: const Icon(Icons.search),
                        suffixIcon: _controller.text.isNotEmpty
                            ? IconButton(
                                icon: const Icon(Icons.clear),
                                onPressed: () {
                                  _controller.clear();
                                  setState(() {
                                    _result = null;
                                    _searched = false;
                                  });
                                },
                              )
                            : null,
                      ),
                      onSubmitted: (_) => _search(),
                      onChanged: (_) => setState(() {}),
                    ),
                  ),
                  const SizedBox(width: 12),
                  HandDrawnButton(
                    label: '搜索',
                    onPressed: _search,
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : !_searched
                      ? const EmptyState(
                          message: '输入关键词开始搜索',
                          icon: Icons.search,
                        )
                      : _result == null || _result!.total == 0
                          ? const EmptyState(
                              message: '没有找到结果',
                              icon: Icons.sentiment_dissatisfied,
                            )
                          : _buildResults(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildResults() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        if (_result!.words.isNotEmpty) ...[
          StickyNote(text: '单词 (${_result!.words.length})'),
          const SizedBox(height: 8),
          ..._result!.words.map((w) => Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: HandDrawnCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          Text(
                            w.word,
                            style: AppTheme.headingStyle.copyWith(fontSize: 20),
                          ),
                          if (w.pos.isNotEmpty) ...[
                            const SizedBox(width: 8),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 8, vertical: 2),
                              decoration: BoxDecoration(
                                color: AppColors.secondaryAccent
                                    .withValues(alpha: 0.1),
                                borderRadius: AppTheme.wobblyRadius,
                              ),
                              child: Text(w.pos,
                                  style: AppTheme.bodyStyle.copyWith(
                                      fontSize: 13,
                                      color: AppColors.secondaryAccent)),
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 4),
                      Text(
                        w.meaning,
                        style: AppTheme.bodyStyle.copyWith(
                          fontSize: 16,
                          color: AppColors.foreground.withValues(alpha: 0.7),
                        ),
                      ),
                    ],
                  ),
                ),
              )),
          const SizedBox(height: 16),
        ],
        if (_result!.pendingTasks.isNotEmpty) ...[
          StickyNote(
              text: '进行中任务 (${_result!.pendingTasks.length})',
              color: AppColors.postItYellow),
          const SizedBox(height: 8),
          ..._result!.pendingTasks.map((t) => _buildTaskCard(t, 'pending')),
          const SizedBox(height: 16),
        ],
        if (_result!.historyTasks.isNotEmpty) ...[
          StickyNote(
              text: '历史任务 (${_result!.historyTasks.length})',
              color: AppColors.postItYellow),
          const SizedBox(height: 8),
          ..._result!.historyTasks.map((t) => _buildTaskCard(t, 'history')),
        ],
      ],
    );
  }

  Widget _buildTaskCard(TaskMatch task, String type) {
    final auth = context.read<AuthProvider>();
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: HandDrawnCard(
        onTap: () {
          Navigator.push(
            context,
            MaterialPageRoute(
              builder: (_) => TaskDetailScreen(
                classId: auth.currentClassId!,
                taskId: task.id,
                taskLabel: task.label,
              ),
            ),
          );
        },
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(
                  type == 'pending' ? Icons.play_circle : Icons.history,
                  size: 24,
                  color: type == 'pending'
                      ? AppColors.secondaryAccent
                      : AppColors.foreground.withValues(alpha: 0.4),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    task.label.isNotEmpty ? task.label : '未命名任务',
                    style: AppTheme.headingStyle.copyWith(fontSize: 18),
                  ),
                ),
                Text(
                  task.date,
                  style: AppTheme.bodyStyle.copyWith(
                    fontSize: 14,
                    color: AppColors.foreground.withValues(alpha: 0.5),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              children: task.matchedWords.map((w) {
                final query = _controller.text.trim();
                return Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppColors.accent.withValues(alpha: 0.1),
                    borderRadius: AppTheme.wobblyRadius,
                  ),
                  child: _highlightText(w.word, query),
                );
              }).toList(),
            ),
          ],
        ),
      ),
    );
  }

  Widget _highlightText(String text, String query) {
    final lowerText = text.toLowerCase();
    final lowerQuery = query.toLowerCase();
    final idx = lowerText.indexOf(lowerQuery);
    if (idx < 0) {
      return Text(text,
          style: AppTheme.bodyStyle.copyWith(fontSize: 14));
    }
    return RichText(
      text: TextSpan(
        style: AppTheme.bodyStyle.copyWith(fontSize: 14),
        children: [
          TextSpan(text: text.substring(0, idx)),
          TextSpan(
            text: text.substring(idx, idx + query.length),
            style: AppTheme.bodyStyle.copyWith(
              fontSize: 14,
              color: AppColors.accent,
              fontWeight: FontWeight.bold,
              backgroundColor: AppColors.accent.withValues(alpha: 0.15),
            ),
          ),
          TextSpan(text: text.substring(idx + query.length)),
        ],
      ),
    );
  }
}
