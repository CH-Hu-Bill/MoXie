import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/vlog_entry.dart';
import '../../models/word.dart';
import '../../models/gallery_item.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../services/storage_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';
import '../study/task_detail_screen.dart';
import '../study/essay_detail_screen.dart';
import '../gallery/gallery_screen.dart';
import 'package:cached_network_image/cached_network_image.dart';

class SearchScreen extends StatefulWidget {
  final String? initialQuery;
  final String? highlightWord;
  final ValueChanged<WordMatch>? onWordFound;

  const SearchScreen(
      {super.key, this.initialQuery, this.highlightWord, this.onWordFound});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _api = ApiService();
  final _controller = TextEditingController();
  SearchResult? _result;
  bool _loading = false;
  bool _searched = false;
  final Set<String> _wrongWordIds = {};

  @override
  void initState() {
    super.initState();
    if (widget.initialQuery != null) {
      _controller.text = widget.initialQuery!;
      WidgetsBinding.instance.addPostFrameCallback((_) => _search());
    }
  }

  Future<void> _toggleWrong(WordMatch w) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;
    final isWrong = _wrongWordIds.contains(w.id);
    try {
      if (isWrong) {
        await _api.unmarkWrong(classId, w.id);
        setState(() => _wrongWordIds.remove(w.id));
      } else {
        await _api.markWrong(classId, w.id, true);
        setState(() => _wrongWordIds.add(w.id));
      }
      // 清除班级缓存，避免其他页面/下次进入读到旧错题状态
      await StorageService().clearClassCaches(classId);
      if (mounted) {
        ScaffoldMessenger.of(context)
          ..hideCurrentSnackBar()
          ..showSnackBar(
            SnackBar(
              content: Text(isWrong ? '已移出错题本' : '已加入错题本',
                  style: const TextStyle(fontFamily: AppTheme.fontBody)),
              duration: const Duration(seconds: 1),
              behavior: SnackBarBehavior.floating,
            ),
          );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('操作失败: $e')),
        );
      }
    }
  }

  Future<void> _search() async {
    final query = _controller.text.trim();
    if (query.isEmpty) return;
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('请先选择班级')),
      );
      return;
    }

    setState(() {
      _loading = true;
      _searched = true;
    });
    try {
      final res = await _api.searchAll(classId, query);
      if (!mounted) return;
      final result = SearchResult.fromJson(res['data'] as Map<String, dynamic>);
      setState(() {
        _wrongWordIds
          ..clear()
          ..addAll(result.words.where((w) => w.isWrong).map((w) => w.id))
          ..addAll(result.pendingTasks
              .expand((t) => t.matchedWords)
              .where((w) => w.isWrong)
              .map((w) => w.id))
          ..addAll(result.historyTasks
              .expand((t) => t.matchedWords)
              .where((w) => w.isWrong)
              .map((w) => w.id));
        _result = result;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() => _loading = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('搜索失败: $e')),
      );
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: PaperTexture(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  TextField(
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
                  const SizedBox(height: 12),
                  HandDrawnButton(
                    label: '搜索',
                    icon: Icons.search,
                    fullWidth: true,
                    onPressed: _loading ? null : _search,
                  ),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(
                      child: CircularProgressIndicator(color: AppColors.red))
                  : !_searched
                      ? const EmptyState(
                          message: '输入关键词开始搜索', icon: Icons.search)
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

  Widget _buildWordMatchCard(WordMatch w) {
    final auth = context.read<AuthProvider>();
    final onTap =
        widget.onWordFound != null ? () => widget.onWordFound!(w) : null;
    if (w.type == 'sentence') {
      return SentenceCard(
        english: w.word,
        meaning: w.meaning,
        isWrong: _wrongWordIds.contains(w.id) || w.isWrong,
        highlight: true,
        onToggleWrong: () => _toggleWrong(w),
        onTap: onTap,
      );
    }
    if (w.type == 'essay') {
      return EssayCard(
        english: w.word,
        title: w.title,
        isWrong: _wrongWordIds.contains(w.id) || w.isWrong,
        highlight: true,
        onToggleWrong: () => _toggleWrong(w),
        onTap: onTap ??
            () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => EssayDetailScreen(
                      essay: Word(
                        id: w.id,
                        word: w.word,
                        meaning: w.meaning,
                        type: 'essay',
                        title: w.title,
                      ),
                    ),
                  ),
                ),
      );
    }
    return WordCard(
      word: w.word,
      meaning: w.meaning,
      pos: w.pos,
      highlight: true,
      isWrong: _wrongWordIds.contains(w.id) || w.isWrong,
      onToggleWrong: () => _toggleWrong(w),
      onTap: onTap,
      classId: auth.currentClassId,
      wordId: w.id,
    );
  }

  Widget _buildGalleryMatchCard(GalleryItem g) {
    return HandDrawnCard(
      padding: EdgeInsets.zero,
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(
          builder: (_) => GalleryScreen(openItemId: g.id, focusItem: g),
        ),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 96,
            height: 96,
            child: ClipRRect(
              borderRadius: const BorderRadius.only(
                topLeft: Radius.circular(18),
                bottomLeft: Radius.circular(18),
              ),
              child: (g.thumbUrl.isNotEmpty || g.imageUrl.isNotEmpty)
                  ? CachedNetworkImage(
                      imageUrl: g.thumbUrl.isNotEmpty ? g.thumbUrl : g.imageUrl,
                      fit: BoxFit.cover,
                      placeholder: (_, __) =>
                          Container(color: AppColors.oldPaper),
                      errorWidget: (_, __, ___) => Container(
                        color: AppColors.muted,
                        child: const Icon(Icons.image, size: 28),
                      ),
                    )
                  : Container(
                      color: AppColors.oldPaper,
                      child: const Icon(Icons.image, size: 28),
                    ),
            ),
          ),
          Expanded(
            child: Padding(
              padding: const EdgeInsets.all(10),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    g.description.isEmpty ? '未填写描述' : g.description,
                    style: AppTheme.bodyStyle.copyWith(fontSize: 14),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '${g.isVideo ? '视频' : (g.isLive ? '实况' : '图片')} · ${g.uploadedAt}',
                    style: AppTheme.bodyStyle.copyWith(
                      fontSize: 11,
                      color: AppColors.muted,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildResults() {
    final children = <Widget>[];
    if (_result!.words.isNotEmpty) {
      children.add(const StickyNote(text: '知识库'));
      children.add(const SizedBox(height: 8));
      for (final w in _result!.words) {
        children.add(Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _buildWordMatchCard(w),
        ));
      }
      children.add(const SizedBox(height: 16));
    }
    if (_result!.gallery.isNotEmpty) {
      children.add(StickyNote(text: '图集 (${_result!.gallery.length})'));
      children.add(const SizedBox(height: 8));
      for (final g in _result!.gallery.take(20)) {
        children.add(Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: _buildGalleryMatchCard(g),
        ));
      }
      children.add(const SizedBox(height: 16));
    }
    if (_result!.pendingTasks.isNotEmpty) {
      children.add(StickyNote(text: '进行中任务', color: AppColors.postIt));
      children.add(const SizedBox(height: 8));
      for (final t in _result!.pendingTasks) {
        children.add(_buildTaskCard(t, 'pending'));
      }
      children.add(const SizedBox(height: 16));
    }
    if (_result!.historyTasks.isNotEmpty) {
      children.add(StickyNote(text: '历史任务', color: AppColors.postIt));
      children.add(const SizedBox(height: 8));
      for (final t in _result!.historyTasks) {
        children.add(_buildTaskCard(t, 'history'));
      }
    }
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: children.length,
      itemBuilder: (_, index) => children[index],
    );
  }

  Widget _buildTaskCard(TaskMatch task, String type) {
    final auth = context.read<AuthProvider>();
    final query = _controller.text.trim();
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
                highlightQuery: query,
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
                      ? AppColors.blue
                      : AppColors.pencil.withValues(alpha: 0.4),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    task.label.isNotEmpty ? task.label : '未命名任务',
                    style: TextStyle(
                        fontFamily: AppTheme.fontHeading, fontSize: 18),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Text(task.date,
                    style: TextStyle(
                        fontFamily: AppTheme.fontBody,
                        fontSize: 14,
                        color: AppColors.pencil.withValues(alpha: 0.5))),
              ],
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              children: task.matchedWords.map((w) {
                return Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: AppColors.postIt,
                    borderRadius: AppTheme.wobblyRadius,
                    border: Border.all(color: AppColors.pencil, width: 1.5),
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
          style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 14));
    }
    return RichText(
      text: TextSpan(
        style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 14),
        children: [
          TextSpan(text: text.substring(0, idx)),
          TextSpan(
            text: text.substring(idx, idx + query.length),
            style: TextStyle(
              fontFamily: AppTheme.fontBody,
              fontSize: 14,
              color: AppColors.red,
              fontWeight: FontWeight.bold,
            ),
          ),
          TextSpan(text: text.substring(idx + query.length)),
        ],
      ),
    );
  }
}
