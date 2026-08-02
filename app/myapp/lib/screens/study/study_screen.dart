import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../models/word.dart';
import '../../models/task.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../services/storage_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';
import 'task_detail_screen.dart';

class StudyScreen extends StatefulWidget {
  const StudyScreen({super.key});

  @override
  State<StudyScreen> createState() => StudyScreenState();
}

class StudyScreenState extends State<StudyScreen> {
  int _mainTab = 0;
  int _taskTab = 0;
  final _wordListScrollController = ScrollController();

  final _api = ApiService();
  final _storage = StorageService();

  List<Word> _words = [];
  List<Word> _wrongWords = [];
  List<Word> _favoriteWords = [];
  List<Task> _pendingTasks = [];
  List<Task> _historyTasks = [];
  bool _loading = false;
  int _wordsPage = 1;
  int _wordsTotal = 0;
  bool _wordsHasMore = false;

  void scrollToWord(String word) {
    setState(() => _mainTab = 0);
    final idx = _words.indexWhere(
      (w) => w.word.toLowerCase() == word.toLowerCase());
    if (idx >= 0) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_wordListScrollController.hasClients) {
          _wordListScrollController.animateTo(
            idx * 140.0,
            duration: const Duration(milliseconds: 400),
            curve: Curves.easeInOut,
          );
        }
      });
    }
  }

  @override
  void dispose() {
    _wordListScrollController.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadData());
  }

  Future<void> _loadData() async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    final cachedWords = _storage.getCachedWords(classId);
    if (cachedWords != null) {
      setState(() => _words = cachedWords.map((w) => Word.fromJson(w)).toList());
    }

    _loadWords(classId);
    _loadWrongWords(classId);
    _loadFavorites(classId);
    _loadTasks(classId);
  }

  Future<void> _loadWords(String classId, {bool reset = true}) async {
    if (reset) setState(() => _loading = true);
    try {
      final page = reset ? 1 : _wordsPage + 1;
      final res = await _api.getWords(classId, page: page, perPage: 20);
      final data = res['data'] as Map<String, dynamic>;
      final words = (data['words'] as List)
          .map((w) => Word.fromJson(w as Map<String, dynamic>))
          .toList();
      setState(() {
        if (reset) {
          _words = words;
        } else {
          _words.addAll(words);
        }
        _wordsPage = page;
        _wordsTotal = data['total'] ?? 0;
        _wordsHasMore = data['has_more'] ?? false;
        _loading = false;
      });
      _storage.cacheWords(classId,
          _words.map((w) => {'id': w.id, 'word': w.word, 'meaning': w.meaning, 'pos': w.pos}).toList());
    } catch (e) {
      setState(() => _loading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('加载单词失败: $e')),
        );
      }
    }
  }

  Future<void> _loadWrongWords(String classId) async {
    try {
      final res = await _api.getWrongWords(classId, perPage: 100);
      final data = res['data'] as Map<String, dynamic>;
      setState(() {
        _wrongWords = (data['words'] as List)
            .map((w) => Word.fromJson(w as Map<String, dynamic>))
            .toList();
      });
    } catch (_) {}
  }

  Future<void> _loadFavorites(String classId) async {
    try {
      final res = await _api.getFavorites(classId, perPage: 100);
      final data = res['data'] as Map<String, dynamic>;
      setState(() {
        _favoriteWords = (data['words'] as List)
            .map((w) => Word.fromJson(w as Map<String, dynamic>))
            .toList();
      });
    } catch (_) {}
  }

  Future<void> _loadTasks(String classId) async {
    try {
      final pendingRes = await _api.getTasks(classId, 'pending');
      final pendingData = pendingRes['data'] as Map<String, dynamic>;
      setState(() {
        _pendingTasks = (pendingData['tasks'] as List)
            .map((t) => Task.fromJson(t as Map<String, dynamic>))
            .toList();
      });
      final historyRes = await _api.getTasks(classId, 'history');
      final historyData = historyRes['data'] as Map<String, dynamic>;
      setState(() {
        _historyTasks = (historyData['tasks'] as List)
            .map((t) => Task.fromJson(t as Map<String, dynamic>))
            .toList();
      });
    } catch (_) {}
  }

  Future<void> _toggleWrong(String classId, Word word) async {
    try {
      if (word.isWrong) {
        await _api.unmarkWrong(classId, word.id);
      } else {
        await _api.markWrong(classId, word.id, true);
      }
      _loadWords(classId);
      _loadWrongWords(classId);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('操作失败: $e')),
        );
      }
    }
  }

  Future<void> _toggleFavorite(String classId, Word word) async {
    try {
      await _api.toggleFavorite(classId, word.id);
      _loadWords(classId);
      _loadFavorites(classId);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('操作失败: $e')),
        );
      }
    }
  }

  Future<void> _showAddWordDialog(String classId) async {
    final wordCtrl = TextEditingController();
    final meaningCtrl = TextEditingController();
    final posCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.paper,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.pencil, width: 2),
        ),
        title: Text('添加单词', style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
        content: Form(
          key: formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextFormField(
                controller: wordCtrl,
                decoration: const InputDecoration(labelText: '单词'),
                validator: (v) =>
                    v == null || v.trim().isEmpty ? '请输入单词' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: meaningCtrl,
                decoration: const InputDecoration(labelText: '释义'),
                validator: (v) =>
                    v == null || v.trim().isEmpty ? '请输入释义' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: posCtrl,
                decoration: const InputDecoration(labelText: '词性（可选）'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('取消', style: TextStyle(fontFamily: AppTheme.fontBody)),
          ),
          TextButton(
            onPressed: () async {
              if (!formKey.currentState!.validate()) return;
              try {
                await _api.addWord(classId, wordCtrl.text.trim(),
                    meaningCtrl.text.trim(), posCtrl.text.trim());
                if (ctx.mounted) Navigator.pop(ctx);
                _loadWords(classId);
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('添加成功')),
                  );
                }
              } catch (e) {
                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('添加失败: $e')),
                  );
                }
              }
            },
            child: Text('添加', style: TextStyle(fontFamily: AppTheme.fontBody)),
          ),
        ],
      ),
    );
  }

  Future<void> _exportWrongWords(String classId) async {
    try {
      final res = await _api.exportWrongText(classId);
      final text = res['data']['text'] as String;
      if (!mounted) return;
      _showExportDialog('错题本导出', text);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    }
  }

  void _showExportDialog(String title, String text) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.paper,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.pencil, width: 2),
        ),
        title: Text(title, style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
        content: SizedBox(
          width: double.maxFinite,
          child: TextField(
            readOnly: true,
            maxLines: 15,
            controller: TextEditingController(text: text),
            decoration: const InputDecoration(border: OutlineInputBorder()),
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
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final classId = auth.currentClassId ?? '';

    return Scaffold(
      appBar: AppBar(
        title: Text(auth.currentClassName ?? '学习'),
        backgroundColor: AppColors.white,
        shape: const Border(
          bottom: BorderSide(color: AppColors.pencil, width: 3),
        ),
      ),
      body: PaperTexture(
        child: Column(
          children: [
            WobblyTabBar(
              tabs: const ['单词库', '单词本', '错题本', '任务'],
              selectedIndex: _mainTab,
              onTap: (i) => setState(() => _mainTab = i),
            ),
            Expanded(
              child: _mainTab == 0
                  ? _buildWordLibrary(classId)
                  : _mainTab == 1
                      ? _buildFavorites(classId)
                      : _mainTab == 2
                          ? _buildWrongWords(classId)
                          : _buildTasks(classId),
            ),
          ],
        ),
      ),
      floatingActionButton: _mainTab == 0
          ? FloatingActionButton(
              onPressed: () => _showAddWordDialog(classId),
              backgroundColor: AppColors.pencil,
              shape: RoundedRectangleBorder(
                borderRadius: AppTheme.wobblyRadius,
                side: const BorderSide(color: AppColors.pencil, width: 2),
              ),
              child: const Icon(Icons.add, color: AppColors.white),
            )
          : null,
    );
  }

  Widget _buildWordLibrary(String classId) {
    if (_loading && _words.isEmpty) {
      return const Center(child: CircularProgressIndicator(color: AppColors.red));
    }
    if (_words.isEmpty) {
      return const EmptyState(message: '还没有单词，点击右下角添加');
    }
    return NotificationListener<ScrollNotification>(
      onNotification: (notif) {
        if (notif is ScrollEndNotification &&
            notif.metrics.pixels >= notif.metrics.maxScrollExtent - 100 &&
            _wordsHasMore &&
            !_loading) {
          _loadWords(classId, reset: false);
        }
        return false;
      },
      child: ListView.builder(
        controller: _wordListScrollController,
        padding: const EdgeInsets.all(16),
        itemCount: _words.length + (_wordsHasMore ? 1 : 0),
        itemBuilder: (ctx, i) {
          if (i >= _words.length) {
            return const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator(color: AppColors.red)),
            );
          }
          final w = _words[i];
          return Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: WordCard(
              word: w.word,
              meaning: w.meaning,
              pos: w.pos,
              isWrong: w.isWrong,
              isFavorite: w.isFavorite,
              onToggleWrong: () => _toggleWrong(classId, w),
              onToggleFavorite: () => _toggleFavorite(classId, w),
            ),
          );
        },
      ),
    );
  }

  Widget _buildFavorites(String classId) {
    if (_favoriteWords.isEmpty) {
      return const EmptyState(
          message: '单词本为空，在单词库中点击星标添加', icon: Icons.star_border);
    }
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _favoriteWords.length,
      itemBuilder: (ctx, i) {
        final w = _favoriteWords[i];
        return Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: WordCard(
            word: w.word,
            meaning: w.meaning,
            pos: w.pos,
            isFavorite: true,
            onToggleWrong: () => _toggleWrong(classId, w),
            onToggleFavorite: () => _toggleFavorite(classId, w),
          ),
        );
      },
    );
  }

  Widget _buildWrongWords(String classId) {
    if (_wrongWords.isEmpty) {
      return const EmptyState(
          message: '错题本为空', icon: Icons.check_circle_outline);
    }
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: HandDrawnButton(
            label: '复制导出文本',
            icon: Icons.copy,
            isSecondary: true,
            fullWidth: true,
            onPressed: () => _exportWrongWords(classId),
          ),
        ),
        Expanded(
          child: ListView.builder(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            itemCount: _wrongWords.length,
            itemBuilder: (ctx, i) {
              final w = _wrongWords[i];
              return Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: WordCard(
                  word: w.word,
                  meaning: w.meaning,
                  pos: w.pos,
                  isWrong: true,
                  showRemoveButton: true,
                  onToggleWrong: () => _toggleWrong(classId, w),
                  onToggleFavorite: () => _toggleFavorite(classId, w),
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _buildTasks(String classId) {
    return Column(
      children: [
        WobblyTabBar(
          tabs: const ['进行中', '历史'],
          selectedIndex: _taskTab,
          onTap: (i) => setState(() => _taskTab = i),
        ),
        Expanded(
          child: _taskTab == 0
              ? _buildTaskList(classId, _pendingTasks, 'pending')
              : _buildTaskList(classId, _historyTasks, 'history'),
        ),
      ],
    );
  }

  Widget _buildTaskList(String classId, List<Task> tasks, String type) {
    if (tasks.isEmpty) {
      return EmptyState(
        message: type == 'pending' ? '没有进行中的任务' : '没有历史任务',
        icon: Icons.task_outlined,
      );
    }
    return RefreshIndicator(
      onRefresh: () => _loadTasks(classId),
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: tasks.length,
        itemBuilder: (ctx, i) {
          final t = tasks[i];
          return Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: HandDrawnCard(
              onTap: () async {
                await Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => TaskDetailScreen(
                      classId: classId,
                      taskId: t.id,
                      taskLabel: t.label,
                    ),
                  ),
                );
                _loadTasks(classId);
              },
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        type == 'pending' ? Icons.play_circle : Icons.check_circle,
                        size: 28,
                        color: type == 'pending'
                            ? AppColors.blue
                            : AppColors.pencil.withValues(alpha: 0.4),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          t.label.isNotEmpty ? t.label : '未命名任务',
                          style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 20),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppColors.postIt,
                          borderRadius: AppTheme.wobblyRadius,
                          border: Border.all(color: AppColors.pencil),
                        ),
                        child: Text(
                          '${t.wordCount}词',
                          style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 14),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    t.date,
                    style: TextStyle(
                      fontFamily: AppTheme.fontBody,
                      fontSize: 15,
                      color: AppColors.pencil.withValues(alpha: 0.5),
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}