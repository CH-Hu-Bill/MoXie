import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
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
  final Map<String, GlobalKey> _wordCardKeys = {};
  String? _highlightWord;
  bool _isLocating = false;

  final _api = ApiService();
  final _storage = StorageService();

  List<Word> _words = [];
  List<Word> _wrongWords = [];
  List<Task> _pendingTasks = [];
  List<Task> _historyTasks = [];
  bool _loading = false;
  int _wordsPage = 1;
  int _wordsTotal = 0;
  bool _wordsHasMore = false;

  void scrollToWord(String word) {
    _isLocating = true;
    setState(() {
      _mainTab = 0;
      _highlightWord = word;
    });
    final found = _words.any((w) => w.word.toLowerCase() == word.toLowerCase());
    if (found) {
      _scrollToHighlight(word);
    } else {
      final auth = context.read<AuthProvider>();
      final classId = auth.currentClassId;
      if (classId != null && classId.isNotEmpty) {
        _loadWordsForSearch(classId, word);
      }
    }
  }

  /// 定位并高亮单词。
  ///
  /// 单词库是分页懒加载 + 卡片高度不一，`index * 固定高度` 估算只能粗定位。
  /// 因此采用「扫描式定位」：
  ///   1. 先跳到估算位置（低估单卡高度 → 落在目标之前）；
  ///   2. 若目标卡尚未构建，则每帧前进约一屏继续扫描（ListView 在 jumpTo 后的
  ///      下一帧构建可视区卡片，确保 key 的 context 会更新）；
  ///   3. 一旦目标卡构建完成，用 `ensureVisible` 精确对齐并高亮。
  void _scrollToHighlight(String word) {
    final targetWord = word.toLowerCase();
    final index =
        _words.indexWhere((w) => w.word.toLowerCase() == targetWord);
    if (index < 0) return;

    void attempt({int round = 0}) {
      if (!_wordListScrollController.hasClients) {
        if (round < 20) {
          Future.delayed(const Duration(milliseconds: 100), () {
            if (mounted) attempt(round: round + 1);
          });
        }
        return;
      }

      final key = _wordCardKeys[targetWord];
      if (key?.currentContext != null) {
        Scrollable.ensureVisible(
          key!.currentContext!,
          alignment: 0.4,
          duration: const Duration(milliseconds: 350),
          curve: Curves.easeInOut,
        );
        return;
      }

      final max = _wordListScrollController.position.maxScrollExtent;
      final viewport = _wordListScrollController.position.viewportDimension;
      final base = index * 90.0; // 低估 → 通常落在目标之前
      final step = viewport * 0.7;
      double target;
      if (round == 0) {
        target = base;
      } else if (round <= 12) {
        target = base + step * round; // 向前扫描
      } else {
        target = base - step * (round - 12); // 向后回扫
      }
      target = target.clamp(0.0, max);
      _wordListScrollController.jumpTo(target);

      // jumpTo 后等待一帧让 ListView 构建可视区，再下一轮检查
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        if (round < 25) {
          attempt(round: round + 1);
        }
      });
    }

    WidgetsBinding.instance.addPostFrameCallback((_) => attempt());
    Future.delayed(const Duration(seconds: 3), () {
      if (mounted) setState(() => _highlightWord = null);
      _isLocating = false;
    });
  }

  Future<void> _loadWordsForSearch(String classId, String targetWord) async {
    // 服务器 per_page 上限 100，需分页拉取直到覆盖目标单词
    final all = <Word>[];
    var page = 1;
    var hasMore = true;
    var found = false;
    try {
      while (hasMore && page <= 30) {
        final res = await _api.getWords(classId, page: page, perPage: 100);
        final data = res['data'] as Map<String, dynamic>;
        final words = (data['words'] as List)
            .map((w) => Word.fromJson(w as Map<String, dynamic>))
            .toList();
        all.addAll(words);
        if (words.any((w) =>
            w.word.toLowerCase() == targetWord.toLowerCase())) {
          found = true;
          hasMore = false;
        } else {
          hasMore = data['has_more'] ?? false;
          page++;
        }
      }
      if (!mounted) return;
      setState(() {
        _words = all;
        _wordsPage = page;
        _wordsTotal = all.length;
        _wordsHasMore = false;
      });
      if (found) {
        _scrollToHighlight(targetWord);
      }
    } catch (_) {}
  }

  Timer? _refreshTimer;

  @override
  void dispose() {
    _wordListScrollController.dispose();
    _refreshTimer?.cancel();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadData());
    _refreshTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      if (_isLocating) return;
      final auth = context.read<AuthProvider>();
      final classId = auth.currentClassId;
      if (classId != null && classId.isNotEmpty) {
        _loadData(silent: true);
      }
    });
  }

  String? _lastLoadedClassId;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId != null && classId.isNotEmpty && classId != _lastLoadedClassId) {
      _lastLoadedClassId = classId;
      WidgetsBinding.instance.addPostFrameCallback((_) => _loadData());
    }
  }

  Future<void> _loadData({bool silent = false}) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    final cachedWords = _storage.getCachedWords(classId);
    if (cachedWords != null) {
      setState(() => _words = cachedWords.map((w) => Word.fromJson(w)).toList());
    }

    _loadWords(classId, reset: !silent, silent: silent);
    _loadWrongWords(classId);
    _loadTasks(classId);
  }

  Future<void> _loadWords(String classId, {bool reset = true, bool silent = false}) async {
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
        } else if (silent) {
          // 静默刷新：仅更新已有数据，保留滚动位置（不重新赋列表避免跳变）
          final existingById = {for (final w in _words) w.id: w};
          final merged = <Word>[];
          for (final w in words) {
            existingById[w.id] = w;
            merged.add(w);
          }
          for (final w in _words) {
            if (!merged.any((m) => m.id == w.id)) merged.add(w);
          }
          _words = merged;
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
            onPressed: () {
              _copyToClipboard(text);
              Navigator.pop(ctx);
            },
            child: Text('复制', style: TextStyle(fontFamily: AppTheme.fontBody, color: AppColors.blue)),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('关闭', style: TextStyle(fontFamily: AppTheme.fontBody)),
          ),
        ],
      ),
    );
  }

  void _copyToClipboard(String text) {
    // ignore: depend_on_referenced_packages
    Clipboard.setData(ClipboardData(text: text));
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('已复制到剪贴板'), duration: Duration(seconds: 2)),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final classId = auth.currentClassId ?? '';

    return Scaffold(
      body: PaperTexture(
        child: Column(
          children: [
            WobblyTabBar(
              tabs: const ['单词库', '错题本', '任务'],
              selectedIndex: _mainTab,
              onTap: (i) => setState(() => _mainTab = i),
            ),
            Expanded(
              child: _mainTab == 0
                  ? _buildWordLibrary(classId)
                  : _mainTab == 1
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
    return RefreshIndicator(
      onRefresh: () => _loadWords(classId, reset: true),
      color: AppColors.red,
      child: NotificationListener<ScrollNotification>(
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
            final isHighlighted = _highlightWord?.toLowerCase() == w.word.toLowerCase();
            final key = _wordCardKeys.putIfAbsent(
              w.word.toLowerCase(),
              () => GlobalKey(),
            );
            return Container(
              key: key,
            padding: const EdgeInsets.only(bottom: 12),
            child: WordCard(
              word: w.word,
              meaning: w.meaning,
              pos: w.pos,
              isWrong: w.isWrong,
              highlight: isHighlighted,
              onToggleWrong: () => _toggleWrong(classId, w),
            ),
          );
        },
      ),
      ),
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
                  if (t.updatedAt != null && t.updatedAt!.isNotEmpty)
                    Text(
                      '更新于 ${t.updatedAt}',
                      style: TextStyle(
                        fontFamily: AppTheme.fontBody,
                        fontSize: 13,
                        color: AppColors.pencil.withValues(alpha: 0.3),
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