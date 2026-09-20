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
import 'essay_detail_screen.dart';

class StudyScreen extends StatefulWidget {
  const StudyScreen({super.key});

  @override
  State<StudyScreen> createState() => StudyScreenState();
}

class StudyScreenState extends State<StudyScreen> with WidgetsBindingObserver {
  int _mainTab = 0;
  int _taskTab = 0;
  int _kbTab = 0;

  static const List<String> _kbTypes = ['word', 'sentence', 'essay'];
  static const List<String> _kbLabels = ['单词', '句子', '作文'];

  final _api = ApiService();
  final _storage = StorageService();

  // 三个板块各自独立的数据/分页/滚动，互不影响
  final Map<String, List<Word>> _kb = {
    'word': [],
    'sentence': [],
    'essay': [],
  };
  final Map<String, int> _kbPage = {'word': 1, 'sentence': 1, 'essay': 1};
  final Map<String, bool> _kbHasMore = {
    'word': false,
    'sentence': false,
    'essay': false,
  };
  final Map<String, bool> _kbLoading = {
    'word': false,
    'sentence': false,
    'essay': false,
  };
  final Map<String, bool> _kbLoaded = {
    'word': false,
    'sentence': false,
    'essay': false,
  };
  final Map<String, ScrollController> _kbScroll = {};
  final Map<String, GlobalKey> _cardKeys = {};
  final Set<String> _autoLocated = {};

  final Map<String, int> _counts = {'word': 0, 'sentence': 0, 'essay': 0};
  final Map<String, String?> _lastIds = {
    'word': null,
    'sentence': null,
    'essay': null,
  };
  String _libraryRev = '';
  String? _highlightId;
  bool _isLocating = false;

  List<Word> _wrongWords = [];
  List<Task> _pendingTasks = [];
  List<Task> _historyTasks = [];

  Timer? _refreshTimer;
  String? _lastLoadedClassId;

  @override
  void initState() {
    super.initState();
    for (final t in _kbTypes) {
      _kbScroll[t] = ScrollController();
    }
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadAll(initial: true));
    // 后台每 30s 拉一次「内容指纹」，有变化才静默重载（解决新增内容不出现）
    _refreshTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      _pollMeta();
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _refreshTimer?.cancel();
    for (final c in _kbScroll.values) {
      c.dispose();
    }
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) _pollMeta();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId != null && classId.isNotEmpty && classId != _lastLoadedClassId) {
      _lastLoadedClassId = classId;
      WidgetsBinding.instance.addPostFrameCallback((_) => _loadAll(initial: true));
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

  // ── 对外：搜索命中后由 home_screen 调用 ──
  Future<void> locateWord(String type, String id, String word) async {
    final t = _kbTypes.contains(type) ? type : 'word';
    setState(() {
      _mainTab = 0;
      _kbTab = _kbTypes.indexOf(t);
      _isLocating = true;
      _highlightId = null;
    });
    _showLocateHint('正在定位…');
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) {
      _isLocating = false;
      return;
    }
    if (!_kbLoaded[t]!) {
      await _loadKb(classId, t, reset: true);
    }
    final found = await _ensureContains(classId, t, id);
    if (!mounted) return;
    if (found) {
      _locate(t, id, highlight: true);
    } else {
      _isLocating = false;
      _showLocateHint('未找到该内容');
    }
  }

  // ── 数据加载 ──

  Future<void> _loadAll({bool initial = false}) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    if (initial) {
      // 缓存只用于「单词」板块首屏秒开；句子/作文量小，直接取网络
      final cached = _storage.getCachedWords(classId);
      if (cached != null &&
          cached.isNotEmpty &&
          cached.every((e) => e['type'] != null)) {
        setState(() {
          _kb['word'] = cached.map((e) => Word.fromJson(e)).toList();
          _kbLoaded['word'] = true;
        });
      }
    }
    _loadMeta(classId);
    _loadKb(classId, _kbTypes[_kbTab], reset: true);
    _loadWrongWords(classId);
    _loadTasks(classId);
  }

  Future<void> _loadMeta(String classId, {bool locate = true}) async {
    try {
      final res = await _api.getLibraryMeta(classId);
      if (!mounted) return;
      final data = res['data'] as Map<String, dynamic>;
      final rev = (data['rev'] ?? '').toString();
      setState(() {
        _applyMetaData(data);
        if (rev.isNotEmpty) _libraryRev = rev;
      });
      if (locate) _maybeAutoLocateCurrent();
    } catch (_) {}
  }

  void _applyMetaData(Map<String, dynamic> data) {
    final c = data['counts'];
    if (c is Map) {
      for (final t in _kbTypes) {
        _counts[t] = (c[t] as num?)?.toInt() ?? (_counts[t] ?? 0);
      }
    }
    final li = data['last_ids'];
    if (li is Map) {
      for (final t in _kbTypes) {
        _lastIds[t] = li[t]?.toString();
      }
    }
  }

  Future<void> _loadKb(String classId, String type, {bool reset = false}) async {
    if (_kbLoading[type] == true) return;
    setState(() => _kbLoading[type] = true);
    try {
      final page = reset ? 1 : _kbPage[type]! + 1;
      final res = await _api.getWords(classId,
          page: page, perPage: 20, type: type);
      if (!mounted) return;
      final data = res['data'] as Map<String, dynamic>;
      // 客户端再按类型兜底过滤：即便服务端较旧未按 type 过滤，也不会串板块
      final words = (data['words'] as List)
          .map((w) => Word.fromJson(w as Map<String, dynamic>))
          .where((w) => w.type == type)
          .toList();
      setState(() {
        if (reset) {
          _kb[type] = words;
          _kbPage[type] = 1;
        } else {
          final ids = {for (final w in _kb[type]!) w.id};
          for (final w in words) {
            if (!ids.contains(w.id)) {
              ids.add(w.id);
              _kb[type]!.add(w);
            }
          }
          _kbPage[type] = page;
        }
        _kbHasMore[type] = data['has_more'] ?? false;
        _kbLoaded[type] = true;
        _kbLoading[type] = false;
        _applyMetaData(data);
        final rev = (data['rev'] ?? '').toString();
        if (rev.isNotEmpty) _libraryRev = rev;
      });
      if (reset && type == 'word') {
        _storage.cacheWords(
            classId,
            _kb['word']!
                .map((w) => {
                      'id': w.id,
                      'word': w.word,
                      'meaning': w.meaning,
                      'pos': w.pos,
                      'type': w.type,
                      'title': w.title,
                    })
                .toList());
      }
      _maybeAutoLocateCurrent();
    } catch (e) {
      if (!mounted) return;
      setState(() => _kbLoading[type] = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('加载失败: $e')),
      );
    }
  }

  /// 后台轮询：仅当内容指纹变化时才重载，尽量保持滚动位置
  Future<void> _pollMeta() async {
    if (!mounted) return;
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;
    final type = _kbTypes[_kbTab];
    if (_isLocating || _kbLoading[type] == true) return;
    try {
      final res = await _api.getLibraryMeta(classId);
      if (!mounted) return;
      final data = res['data'] as Map<String, dynamic>;
      final rev = (data['rev'] ?? '').toString();
      final changed = rev.isNotEmpty && rev != _libraryRev;
      setState(() => _applyMetaData(data));
      if (!changed) return;
      _libraryRev = rev;
      final offset =
          _kbScroll[type]!.hasClients ? _kbScroll[type]!.offset : 0.0;
      await _loadKb(classId, type, reset: true);
      if (mounted && _kbScroll[type]!.hasClients) {
        final max = _kbScroll[type]!.position.maxScrollExtent;
        _kbScroll[type]!.jumpTo(offset.clamp(0.0, max));
      }
      _loadWrongWords(classId);
    } catch (_) {}
  }

  Future<bool> _ensureContains(String classId, String type, String id) async {
    if (_kb[type]!.any((w) => w.id == id)) return true;
    var page = _kbPage[type]!;
    var hasMore = _kbHasMore[type]!;
    var guard = 0;
    try {
      while (hasMore && guard < 50) {
        guard++;
        page++;
        final res =
            await _api.getWords(classId, page: page, perPage: 100, type: type);
        if (!mounted) return false;
        final data = res['data'] as Map<String, dynamic>;
        final words = (data['words'] as List)
            .map((w) => Word.fromJson(w as Map<String, dynamic>))
            .where((w) => w.type == type)
            .toList();
        setState(() {
          final ids = {for (final w in _kb[type]!) w.id};
          for (final w in words) {
            if (!ids.contains(w.id)) {
              ids.add(w.id);
              _kb[type]!.add(w);
            }
          }
          _kbPage[type] = page;
          _kbHasMore[type] = data['has_more'] ?? false;
        });
        if (_kb[type]!.any((w) => w.id == id)) return true;
        hasMore = _kbHasMore[type]!;
      }
    } catch (_) {}
    return _kb[type]!.any((w) => w.id == id);
  }

  /// 扫描式定位（与旧版单词定位算法一致），作用于指定板块。
  void _locate(String type, String id, {required bool highlight}) {
    final list = _kb[type]!;
    final index = list.indexWhere((w) => w.id == id);
    if (index < 0) {
      _isLocating = false;
      return;
    }
    if (highlight) setState(() => _highlightId = id);
    final controller = _kbScroll[type]!;

    void attempt(int round) {
      if (!mounted) return;
      if (!controller.hasClients) {
        if (round < 30) {
          Future.delayed(const Duration(milliseconds: 100), () {
            if (mounted) attempt(round + 1);
          });
        } else {
          _isLocating = false;
        }
        return;
      }
      final key = _cardKeys[id];
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
      final max = controller.position.maxScrollExtent;
      if (max <= 0) {
        _isLocating = false;
        return;
      }
      final n = list.length;
      final avg = n > 0 ? max / n : 100.0;
      final est = (index * avg).clamp(0.0, max);
      double target;
      if (round == 0) {
        target = max;
      } else if (round == 1) {
        target = est;
      } else if (round <= 9) {
        final delta = (round - 1) * 500.0 * (round.isOdd ? 1 : -1);
        target = (est + delta).clamp(0.0, max);
      } else {
        _isLocating = false;
        return;
      }
      controller.jumpTo(target);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) attempt(round + 1);
        });
      });
    }

    WidgetsBinding.instance.addPostFrameCallback((_) => attempt(0));
  }

  void _maybeAutoLocateCurrent() {
    final type = _kbTypes[_kbTab];
    final id = _lastIds[type];
    if (id == null || _autoLocated.contains(type)) return;
    if (!_kbLoaded[type]!) return;
    _autoLocated.add(type);
    Future.microtask(() => _locate(type, id, highlight: false));
  }

  // ── 错题本 / 任务 ──

  static bool _sameWordList(List<Word> a, List<Word> b) {
    if (a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if (a[i].id != b[i].id ||
          a[i].isWrong != b[i].isWrong ||
          a[i].word != b[i].word ||
          a[i].meaning != b[i].meaning ||
          a[i].pos != b[i].pos ||
          a[i].type != b[i].type ||
          a[i].title != b[i].title) {
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
      if (!mounted) return;
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
      if (!mounted) return;
      if (!_sameTaskList(_pendingTasks, pending)) {
        setState(() => _pendingTasks = pending);
      }
      final historyRes = await _api.getTasks(classId, 'history');
      final historyData = historyRes['data'] as Map<String, dynamic>;
      final history = (historyData['tasks'] as List)
          .map((t) => Task.fromJson(t as Map<String, dynamic>))
          .toList();
      if (!mounted) return;
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
      setState(() {
        for (final t in _kbTypes) {
          _kb[t] = [
            for (final w in _kb[t]!)
              if (w.id == word.id) w.copyWith(isWrong: newWrong) else w,
          ];
        }
        if (newWrong) {
          if (!_wrongWords.any((w) => w.id == word.id)) {
            _wrongWords = [word.copyWith(isWrong: true), ..._wrongWords];
          }
        } else {
          _wrongWords = _wrongWords.where((w) => w.id != word.id).toList();
        }
      });
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

  Future<void> _showAddDialog(String classId) async {
    String type = _kbTypes[_kbTab];
    final contentCtrl = TextEditingController();
    final meaningCtrl = TextEditingController();
    final posCtrl = TextEditingController();
    final titleCtrl = TextEditingController();
    final formKey = GlobalKey<FormState>();
    bool aiLoading = false;

    await showHandDrawnDialog(
      context: context,
      title: '添加到知识库',
      child: StatefulBuilder(
        builder: (ctx, setLocal) {
          final isWord = type == 'word';
          final isEssay = type == 'essay';

          Widget chip(String t, String label) {
            final active = type == t;
            return GestureDetector(
              onTap: () => setLocal(() => type = t),
              child: Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 18, vertical: 8),
                decoration: BoxDecoration(
                  color: active ? AppColors.postIt : AppColors.white,
                  borderRadius: AppTheme.wobblySm,
                  border: Border.all(
                      color: AppColors.pencil, width: active ? 2.5 : 2),
                  boxShadow: AppTheme.hardShadowSm,
                ),
                child: Text(
                  label,
                  style: TextStyle(
                    fontFamily: AppTheme.fontBody,
                    fontSize: 16,
                    color: active
                        ? AppColors.pencil
                        : AppColors.pencil.withValues(alpha: 0.6),
                  ),
                ),
              ),
            );
          }

          return Form(
            key: formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    chip('word', '单词'),
                    const SizedBox(width: 8),
                    chip('sentence', '句子'),
                    const SizedBox(width: 8),
                    chip('essay', '作文'),
                  ],
                ),
                const SizedBox(height: 18),
                if (isEssay) ...[
                  HandDrawnInput(
                    controller: titleCtrl,
                    label: '标题（可选）',
                    hint: '如：My Weekend',
                  ),
                  const SizedBox(height: 14),
                ],
                HandDrawnInput(
                  controller: contentCtrl,
                  label: isWord
                      ? '单词'
                      : (isEssay ? '作文内容（英文）' : '句子（英文）'),
                  hint: isWord ? 'english' : 'English text ...',
                  maxLines: isWord ? 1 : (isEssay ? 5 : 3),
                  validator: (v) =>
                      v == null || v.trim().isEmpty ? '请输入内容' : null,
                ),
                const SizedBox(height: 14),
                HandDrawnInput(
                  controller: meaningCtrl,
                  label: isWord ? '释义' : '中文（直译）',
                  hint: '中文释义',
                  maxLines: isWord ? 2 : 5,
                  validator: (v) =>
                      v == null || v.trim().isEmpty ? '请输入释义' : null,
                ),
                if (isWord) ...[
                  const SizedBox(height: 14),
                  HandDrawnInput(
                    controller: posCtrl,
                    label: '词性（可选）',
                    hint: 'n. / v. / adj.',
                  ),
                ],
                const SizedBox(height: 20),
                HandDrawnButton(
                  label:
                      aiLoading ? '生成中…' : (isWord ? 'AI 补全' : 'AI 直译'),
                  icon: Icons.auto_awesome,
                  isSecondary: true,
                  fullWidth: true,
                  onPressed: aiLoading
                      ? null
                      : () async {
                          final text = contentCtrl.text.trim();
                          if (text.isEmpty) {
                            ScaffoldMessenger.of(ctx).showSnackBar(
                              const SnackBar(content: Text('请先填写内容')),
                            );
                            return;
                          }
                          setLocal(() => aiLoading = true);
                          try {
                            final res =
                                await _api.aiWord(classId, text, type: type);
                            final data = res['data'] as Map<String, dynamic>;
                            meaningCtrl.text =
                                (data['meaning'] ?? '').toString();
                            if (isWord) {
                              posCtrl.text = (data['pos'] ?? '').toString();
                            }
                          } catch (e) {
                            if (ctx.mounted) {
                              ScaffoldMessenger.of(ctx).showSnackBar(
                                SnackBar(content: Text('AI 失败: $e')),
                              );
                            }
                          } finally {
                            setLocal(() => aiLoading = false);
                          }
                        },
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: HandDrawnButton(
                        label: '取消',
                        isSecondary: true,
                        fullWidth: true,
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: HandDrawnButton(
                        label: '添加',
                        fullWidth: true,
                        onPressed: () async {
                          if (!formKey.currentState!.validate()) return;
                          try {
                            await _api.addWord(
                              classId,
                              contentCtrl.text.trim(),
                              meaningCtrl.text.trim(),
                              pos: posCtrl.text.trim(),
                              type: type,
                              title: titleCtrl.text.trim(),
                            );
                            if (ctx.mounted) Navigator.pop(ctx);
                            _loadMeta(classId);
                            _loadKb(classId, type, reset: true);
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
                      ),
                    ),
                  ],
                ),
              ],
            ),
          );
        },
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
        scrollable: true,
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
                  ? _buildLibrary(classId)
                  : _mainTab == 1
                      ? _buildWrongWords(classId)
                      : _buildTasks(classId),
            ),
          ],
        ),
      ),
      floatingActionButton: _mainTab == 0
          ? FloatingActionButton(
              onPressed: () => _showAddDialog(classId),
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

  void _switchKbTab(int i) {
    if (i == _kbTab) return;
    setState(() {
      _kbTab = i;
      _highlightId = null;
    });
    final classId = context.read<AuthProvider>().currentClassId;
    final type = _kbTypes[i];
    if (classId != null && classId.isNotEmpty && !_kbLoaded[type]!) {
      _loadKb(classId, type, reset: true);
    }
    _maybeAutoLocateCurrent();
  }

  Widget _buildLibrary(String classId) {
    return Column(
      children: [
        WobblyTabBar(
          tabs: [
            '单词 ${_counts['word']}',
            '句子 ${_counts['sentence']}',
            '作文 ${_counts['essay']}',
          ],
          selectedIndex: _kbTab,
          onTap: _switchKbTab,
        ),
        Expanded(child: _buildKbList(classId, _kbTypes[_kbTab])),
      ],
    );
  }

  Widget _buildKbList(String classId, String type) {
    final list = _kb[type]!;
    final loading = _kbLoading[type]!;
    if (loading && list.isEmpty) {
      return const Center(
          child: CircularProgressIndicator(color: AppColors.red));
    }
    if (list.isEmpty) {
      return EmptyState(
        message: type == 'word'
            ? '还没有单词，点击右下角添加'
            : (type == 'sentence' ? '还没有句子，点击右下角添加' : '还没有作文，点击右下角添加'),
      );
    }
    return RefreshIndicator(
      onRefresh: () => _loadKb(classId, type, reset: true),
      color: AppColors.red,
      child: NotificationListener<ScrollNotification>(
        onNotification: (notif) {
          if (notif is ScrollStartNotification && notif.dragDetails != null) {
            if (_highlightId != null) {
              setState(() => _highlightId = null);
            }
            _isLocating = false;
          }
          if (notif is ScrollEndNotification &&
              notif.metrics.pixels >= notif.metrics.maxScrollExtent - 100 &&
              _kbHasMore[type]! &&
              !_kbLoading[type]!) {
            _loadKb(classId, type, reset: false);
          }
          return false;
        },
        child: ListView.builder(
          controller: _kbScroll[type],
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(16),
          itemCount: list.length + (_kbHasMore[type]! ? 1 : 0),
          itemBuilder: (ctx, i) {
            if (i >= list.length) {
              return const Padding(
                padding: EdgeInsets.all(16),
                child: Center(
                    child: CircularProgressIndicator(color: AppColors.red)),
              );
            }
            final w = list[i];
            final key = _cardKeys.putIfAbsent(w.id, () => GlobalKey());
            return Container(
              key: key,
              padding: const EdgeInsets.only(bottom: 12),
              child: _buildItemCard(classId, w,
                  highlighted: _highlightId == w.id),
            );
          },
        ),
      ),
    );
  }

  String _typeLabel(String type) =>
      type == 'sentence' ? '句子' : (type == 'essay' ? '作文' : '单词');

  /// 按知识库类型渲染对应卡片（单词/句子/作文）。
  Widget _buildItemCard(String classId, Word w,
      {bool highlighted = false, bool showRemoveButton = false}) {
    if (w.isSentence) {
      return SentenceCard(
        english: w.word,
        meaning: w.meaning,
        isWrong: w.isWrong,
        showRemoveButton: showRemoveButton,
        highlight: highlighted,
        onToggleWrong: () => _toggleWrong(classId, w),
      );
    }
    if (w.isEssay) {
      return EssayCard(
        english: w.word,
        title: w.title,
        isWrong: w.isWrong,
        showRemoveButton: showRemoveButton,
        highlight: highlighted,
        onToggleWrong: () => _toggleWrong(classId, w),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => EssayDetailScreen(essay: w)),
        ),
      );
    }
    return WordCard(
      word: w.word,
      meaning: w.meaning,
      pos: w.pos,
      isWrong: w.isWrong,
      showRemoveButton: showRemoveButton,
      highlight: highlighted,
      onToggleWrong: () => _toggleWrong(classId, w),
      classId: classId,
      wordId: w.id,
    );
  }

  Widget _buildWrongWords(String classId) {
    if (_wrongWords.isEmpty) {
      return RefreshIndicator(
        onRefresh: () => _loadWrongWords(classId),
        color: AppColors.red,
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          children: const [
            SizedBox(height: 160),
            EmptyState(message: '错题本为空', icon: Icons.check_circle_outline),
          ],
        ),
      );
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
          child: RefreshIndicator(
            onRefresh: () => _loadWrongWords(classId),
            color: AppColors.red,
            child: ListView.builder(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.symmetric(horizontal: 16),
              itemCount: _wrongWords.length,
              itemBuilder: (ctx, i) {
                final w = _wrongWords[i];
                return Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: _buildItemCard(classId, w, showRemoveButton: true),
                );
              },
            ),
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
        physics: const AlwaysScrollableScrollPhysics(),
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
