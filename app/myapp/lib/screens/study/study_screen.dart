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
    _showLocateHint('正在定位…');
    final found = _words.any((w) => w.word.toLowerCase() == word.toLowerCase());
    if (found) {
      _scrollToHighlight(word);
    } else {
      final auth = context.read<AuthProvider>();
      final classId = auth.currentClassId;
      if (classId != null && classId.isNotEmpty) {
        _loadWordsForSearch(classId, word);
      } else {
        _isLocating = false;
      }
    }
  }

  void _showLocateHint(String msg) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content:
              Text(msg, style: const TextStyle(fontFamily: AppTheme.fontBody)),
          duration: const Duration(milliseconds: 1200),
          behavior: SnackBarBehavior.floating,
        ),
      );
  }

  /// 定位并高亮单词。
  ///
  /// 单词库是分页懒加载 + 卡片高度不一，`index * 固定高度` 估算只能粗定位。
  /// 因此采用「扫描式定位」：
  ///   1. 先跳到估算位置（低估单卡高度 → 落在目标之前）；
  ///   2. 若目标卡尚未构建，则每帧前进约一屏继续扫描（ListView 在 jumpTo 后的
  ///      下一帧构建可视区卡片，确保 key 的 context 会更新）；
  ///   3. 一旦目标卡构建完成，用 `ensureVisible` 精确对齐并高亮；
  ///   4. 高亮持续到用户手动滚动列表（由列表 NotificationListener 清除）。
  void _scrollToHighlight(String word) {
    final targetWord = word.toLowerCase();
    final index = _words.indexWhere((w) => w.word.toLowerCase() == targetWord);
    if (index < 0) {
      _isLocating = false;
      _showLocateHint('未找到该单词');
      return;
    }

    void attempt({int round = 0}) {
      if (!mounted) return;
      if (!_wordListScrollController.hasClients) {
        if (round < 30) {
          Future.delayed(const Duration(milliseconds: 100), () {
            if (mounted) attempt(round: round + 1);
          });
        } else {
          _isLocating = false;
          _showLocateHint('未找到该单词');
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
        _isLocating = false;
        return;
      }

      // 旧算法 base=index*90 低估卡高，向前扫描上限仅 ~21 个视口：
      // 长列表（千词级）尾部累计偏差远超扫描范围，导致"后面的词永远未找到"。
      // 新算法：先触底校准真实列表总高 → 按平均卡高精确跳转 → 小步兜底扫描。
      final max = _wordListScrollController.position.maxScrollExtent;
      if (max <= 0) {
        // 列表还没算出可滚动范围（内容不足一屏 = 目标必在首屏）
        _isLocating = false;
        return;
      }
      final n = _words.length;
      final avg = n > 0 ? max / n : 100.0; // 触底后 maxScrollExtent 为真实值
      final est = (index * avg).clamp(0.0, max);
      double target;
      if (round == 0) {
        target = max; // 一跳到底：逼 ListView 构建尾区，校准真实 maxScrollExtent
      } else if (round == 1) {
        target = est;
      } else if (round <= 9) {
        // 目标附近 ± 交错小步扫（卡高差异通常 ±100px，cacheExtent 250px 大概率第 2 轮即命中）
        final delta = (round - 1) * 500.0 * ((round.isOdd) ? 1 : -1);
        target = (est + delta).clamp(0.0, max);
      } else {
        _isLocating = false;
        _showLocateHint('未找到该单词');
        return;
      }
      _wordListScrollController.jumpTo(target);

      // jumpTo 后等两帧：一帧构建可视区、一帧挂载 key，再检查
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) attempt(round: round + 1);
        });
      });
    }

    WidgetsBinding.instance.addPostFrameCallback((_) => attempt());
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
        if (words
            .any((w) => w.word.toLowerCase() == targetWord.toLowerCase())) {
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
      } else {
        _isLocating = false;
        _showLocateHint('未找到该单词');
      }
    } catch (_) {
      if (mounted) {
        _isLocating = false;
        _showLocateHint('定位失败，请重试');
      }
    }
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
    if (classId != null &&
        classId.isNotEmpty &&
        classId != _lastLoadedClassId) {
      _lastLoadedClassId = classId;
      WidgetsBinding.instance.addPostFrameCallback((_) => _loadData());
    }
  }

  Future<void> _loadData({bool silent = false}) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    if (!silent) {
      final cachedWords = _storage.getCachedWords(classId);
      if (cachedWords != null) {
        setState(
            () => _words = cachedWords.map((w) => Word.fromJson(w)).toList());
      }
    }

    // 静默刷新 / 进入时有新鲜缓存 → 静默合并（保留滚动位置、不弹 loading）
    // 无缓存首次进入 → 全量加载
    final useSilentMerge = silent || _hasFreshCache(classId);
    _loadWords(classId, reset: !useSilentMerge, silent: useSilentMerge);
    _loadWrongWords(classId);
    _loadTasks(classId);
  }

  bool _hasFreshCache(String classId) =>
      _storage.getCachedWords(classId) != null;

  Future<void> _loadWords(String classId,
      {bool reset = true, bool silent = false}) async {
    if (reset) setState(() => _loading = true);
    try {
      // 静默刷新固定拉第 1 页并合并到现有列表，避免列表越长、重建越多
      final page = (reset || silent) ? 1 : _wordsPage + 1;
      final res = await _api.getWords(classId, page: page, perPage: 20);
      final data = res['data'] as Map<String, dynamic>;
      final words = (data['words'] as List)
          .map((w) => Word.fromJson(w as Map<String, dynamic>))
          .toList();
      setState(() {
        if (reset) {
          _words = words;
          _wordsPage = page;
          _wordsTotal = data['total'] ?? 0;
          _wordsHasMore = data['has_more'] ?? false;
        } else if (silent) {
          // 静默刷新：原地更新已有词条的字段（错题/释义等），保持列表顺序与
          // 滚动位置完全不变——旧逻辑把第 1 页 merge 到最前会打乱顺序、每次
          // 重建 ListView，导致滚动监听失效、加载更多在长列表尾部失效
          final byId = {for (final w in words) w.id: w};
          var changed = false;
          for (var i = 0; i < _words.length; i++) {
            final u = byId[_words[i].id];
            if (u != null &&
                (u.isWrong != _words[i].isWrong ||
                    u.word != _words[i].word ||
                    u.meaning != _words[i].meaning ||
                    u.pos != _words[i].pos)) {
              _words[i] = u;
              changed = true;
            }
          }
          if (changed) _words = List.of(_words);
          // 不动分页计数，保持加载更多状态一致
        } else {
          _loading = true; // 加载更多：标记 loading，防止滚动到底并发触发多个请求
          final existingIds = {for (final w in _words) w.id};
          for (final w in words) {
            if (!existingIds.contains(w.id)) {
              existingIds.add(w.id);
              _words.add(w);
            }
          }
          _wordsPage = page;
          _wordsTotal = data['total'] ?? 0;
          _wordsHasMore = data['has_more'] ?? false;
        }
        _loading = false;
      });
      // 缓存全量写盘只在首次加载/下拉刷新时做——"加载更多"高频触发，
      // 千词级列表每次全量序列化写 SharedPreferences 是滚动卡顿来源之一
      if (reset) {
        _storage.cacheWords(
            classId,
            _words
                .map((w) => {
                    'id': w.id,
                    'word': w.word,
                    'meaning': w.meaning,
                    'pos': w.pos
                  })
              .toList());
      }
    } catch (e) {
      setState(() => _loading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('加载单词失败: $e')),
        );
      }
    }
  }

  static bool _sameWordList(List<Word> a, List<Word> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i].id != b[i].id ||
          a[i].isWrong != b[i].isWrong ||
          a[i].word != b[i].word ||
          a[i].meaning != b[i].meaning ||
          a[i].pos != b[i].pos) {
        return false;
      }
    }
    return true;
  }

  static bool _sameTaskList(List<Task> a, List<Task> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i].id != b[i].id || a[i].status != b[i].status) return false;
    }
    return true;
  }

  Future<void> _loadWrongWords(String classId) async {
    try {
      final res = await _api.getWrongWords(classId, perPage: 100);
      final data = res['data'] as Map<String, dynamic>;
      final words = (data['words'] as List)
          .map((w) => Word.fromJson(w as Map<String, dynamic>))
          .toList();
      if (!_sameWordList(_wrongWords, words)) {
        setState(() => _wrongWords = words);
      }
    } catch (_) {}
  }

  Future<void> _loadTasks(String classId) async {
    try {
      final pendingRes = await _api.getTasks(classId, 'pending');
      final pendingData = pendingRes['data'] as Map<String, dynamic>;
      final pending = (pendingData['tasks'] as List)
          .map((t) => Task.fromJson(t as Map<String, dynamic>))
          .toList();
      if (!_sameTaskList(_pendingTasks, pending)) {
        setState(() => _pendingTasks = pending);
      }
      final historyRes = await _api.getTasks(classId, 'history');
      final historyData = historyRes['data'] as Map<String, dynamic>;
      final history = (historyData['tasks'] as List)
          .map((t) => Task.fromJson(t as Map<String, dynamic>))
          .toList();
      if (!_sameTaskList(_historyTasks, history)) {
        setState(() => _historyTasks = history);
      }
    } catch (_) {}
  }

  Future<void> _toggleWrong(String classId, Word word) async {
    final newWrong = !word.isWrong;
    try {
      if (word.isWrong) {
        await _api.unmarkWrong(classId, word.id);
      } else {
        await _api.markWrong(classId, word.id, true);
      }
      if (!mounted) return;
      // 本地即时更新状态，避免整列表重载导致滚动跳动
      setState(() {
        _words = [
          for (final w in _words)
            if (w.id == word.id) w.copyWith(isWrong: newWrong) else w,
        ];
        if (newWrong) {
          if (!_wrongWords.any((w) => w.id == word.id)) {
            _wrongWords = [word.copyWith(isWrong: true), ..._wrongWords];
          }
        } else {
          _wrongWords = _wrongWords.where((w) => w.id != word.id).toList();
        }
      });
      // 清除班级缓存，避免其他页面/下次进入读到旧错题状态
      await _storage.clearClassCaches(classId);
      ScaffoldMessenger.of(context)
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(newWrong ? '已加入错题本' : '已移出错题本',
                style: const TextStyle(fontFamily: AppTheme.fontBody)),
            duration: const Duration(seconds: 1),
            behavior: SnackBarBehavior.floating,
          ),
        );
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
        title: Text('添加单词',
            style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
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
        title: Text(title,
            style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22)),
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
            child: Text('复制',
                style: TextStyle(
                    fontFamily: AppTheme.fontBody, color: AppColors.blue)),
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
        const SnackBar(
            content: Text('已复制到剪贴板'), duration: Duration(seconds: 2)),
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
      return const Center(
          child: CircularProgressIndicator(color: AppColors.red));
    }
    if (_words.isEmpty) {
      return const EmptyState(message: '还没有单词，点击右下角添加');
    }
    return RefreshIndicator(
      onRefresh: () => _loadWords(classId, reset: true),
      color: AppColors.red,
      child: NotificationListener<ScrollNotification>(
        onNotification: (notif) {
          // 用户主动拖动滚动 → 清除定位高亮（ensureVisible 为程序滚动，dragDetails 为空，不会误清）
          if (notif is ScrollStartNotification && notif.dragDetails != null) {
            if (_highlightWord != null) {
              setState(() => _highlightWord = null);
            }
            _isLocating = false;
          }
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
                child: Center(
                    child: CircularProgressIndicator(color: AppColors.red)),
              );
            }
            final w = _words[i];
            final isHighlighted =
                _highlightWord?.toLowerCase() == w.word.toLowerCase();
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
                classId: classId,
                wordId: w.id,
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
                  classId: classId,
                  wordId: w.id,
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
                        type == 'pending'
                            ? Icons.play_circle
                            : Icons.check_circle,
                        size: 28,
                        color: type == 'pending'
                            ? AppColors.blue
                            : AppColors.pencil.withValues(alpha: 0.4),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          t.label.isNotEmpty ? t.label : '未命名任务',
                          style: TextStyle(
                              fontFamily: AppTheme.fontHeading, fontSize: 20),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppColors.postIt,
                          borderRadius: AppTheme.wobblyRadius,
                          border: Border.all(color: AppColors.pencil),
                        ),
                        child: Text(
                          '${t.wordCount}词',
                          style: TextStyle(
                              fontFamily: AppTheme.fontBody, fontSize: 14),
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
