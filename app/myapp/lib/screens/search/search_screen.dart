import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/models/word.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/theme/app_theme.dart';
import 'package:listenwrite/screens/study/task_detail_screen.dart';

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _searchController = TextEditingController();
  String? _currentClassId;
  Map<String, dynamic>? _results;
  bool _loading = false;
  bool _searched = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final auth = context.read<AuthProvider>();
      setState(() {
        _currentClassId = auth.user?.classIds.isNotEmpty == true
            ? auth.user!.classIds.first
            : null;
      });
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  Future<void> _search() async {
    final query = _searchController.text.trim();
    if (query.isEmpty || _currentClassId == null) return;

    setState(() => _loading = true);
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('search_all', {
        'class_id': _currentClassId!,
        'query': query,
      });
      if (result['success'] == true) {
        setState(() {
          _results = result['data'] ?? {};
          _loading = false;
          _searched = true;
        });
      }
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: HandDrawnInput(
            hint: '搜索单词或任务...',
            controller: _searchController,
            suffixIcon: IconButton(
              icon: const Icon(Icons.search, color: HandDrawnTheme.pencil),
              onPressed: _search,
            ),
          ),
        ),
        Expanded(
          child: _loading
              ? const Center(
                  child: CircularProgressIndicator(
                      color: HandDrawnTheme.pencil),
                )
              : !_searched
                  ? Center(
                      child: Text(
                        '输入关键词搜索',
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 16,
                          color: HandDrawnTheme.pencil
                              .withValues(alpha: 0.5),
                        ),
                      ),
                    )
                  : _buildResults(),
        ),
      ],
    );
  }

  Widget _buildResults() {
    if (_results == null) return const SizedBox();

    final words = _results!['words']?['items'] as List? ?? [];
    final pendingTasks = _results!['pending_tasks']?['items'] as List? ?? [];
    final historyTasks = _results!['history_tasks']?['items'] as List? ?? [];
    final total = _results!['total'] ?? 0;

    if (total == 0) {
      return Center(
        child: Text(
          '没有找到相关结果',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil.withValues(alpha: 0.5),
          ),
        ),
      );
    }

    return ListView(
      padding: const EdgeInsets.only(bottom: 16),
      children: [
        if (words.isNotEmpty) ...[
          _sectionHeader('单词 (${_results!['words']['total']})'),
          ...words.map((w) => _buildWordCard(w)),
        ],
        if (pendingTasks.isNotEmpty) ...[
          _sectionHeader('进行中的任务 (${_results!['pending_tasks']['total']})'),
          ...pendingTasks.map((t) => _buildTaskCard(t)),
        ],
        if (historyTasks.isNotEmpty) ...[
          _sectionHeader('历史任务 (${_results!['history_tasks']['total']})'),
          ...historyTasks.map((t) => _buildTaskCard(t)),
        ],
      ],
    );
  }

  Widget _sectionHeader(String title) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 16, 20, 8),
      child: Text(
        title,
        style: TextStyle(
          fontFamily: 'Kalam',
          fontSize: 20,
          fontWeight: FontWeight.w700,
          color: HandDrawnTheme.pencil,
        ),
      ),
    );
  }

  Widget _buildWordCard(dynamic word) {
    return HandDrawnCard(
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  word['word'] ?? '',
                  style: TextStyle(
                    fontFamily: 'Kalam',
                    fontSize: 20,
                    fontWeight: FontWeight.w700,
                    color: HandDrawnTheme.pencil,
                  ),
                ),
                Text(
                  word['meaning'] ?? '',
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
            icon: const Icon(Icons.volume_up, color: HandDrawnTheme.blue),
            onPressed: () {},
          ),
        ],
      ),
    );
  }

  Widget _buildTaskCard(dynamic task) {
    final matchedWords = task['matched_words'] as List? ?? [];
    return HandDrawnCard(
      onTap: () {
        Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => TaskDetailScreen(
              classId: _currentClassId!,
              taskId: task['id'] ?? '',
            ),
          ),
        );
      },
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            task['label'] ?? '默写任务',
            style: TextStyle(
              fontFamily: 'Kalam',
              fontSize: 18,
              fontWeight: FontWeight.w700,
              color: HandDrawnTheme.pencil,
            ),
          ),
          Text(
            task['date'] ?? '',
            style: TextStyle(
              fontFamily: 'Patrick Hand',
              fontSize: 14,
              color: HandDrawnTheme.pencil.withValues(alpha: 0.6),
            ),
          ),
          if (matchedWords.isNotEmpty) ...[
            const SizedBox(height: 8),
            ...matchedWords.map((w) => Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(
                          color: HandDrawnTheme.postItYellow,
                          borderRadius: HandDrawnTheme.wobblyRadiusSm,
                          border: Border.all(
                              color: HandDrawnTheme.pencil, width: 1),
                        ),
                        child: Text(
                          w['word'] ?? '',
                          style: TextStyle(
                            fontFamily: 'Patrick Hand',
                            fontSize: 14,
                            fontWeight: FontWeight.w700,
                            color: HandDrawnTheme.pencil,
                          ),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          w['meaning'] ?? '',
                          style: TextStyle(
                            fontFamily: 'Patrick Hand',
                            fontSize: 14,
                            color: HandDrawnTheme.pencil,
                          ),
                        ),
                      ),
                    ],
                  ),
                )),
          ],
        ],
      ),
    );
  }
}