import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../providers/auth_provider.dart';
import '../../models/word.dart';
import '../../models/task.dart';
import '../../widgets/hand_drawn_widgets.dart';
import '../../theme/app_theme.dart';
import 'word_detail_screen.dart';
import 'task_detail_screen.dart';

class StudyScreen extends StatefulWidget {
  const StudyScreen({super.key});

  @override
  State<StudyScreen> createState() => _StudyScreenState();
}

class _StudyScreenState extends State<StudyScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  String? _currentClassId;

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 3, vsync: this);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadClassId();
    });
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  void _loadClassId() {
    final auth = context.read<AuthProvider>();
    setState(() {
      _currentClassId = auth.user?.classIds.isNotEmpty == true
          ? auth.user!.classIds.first
          : null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final classIds = auth.user?.classIds ?? [];

    if (classIds.isEmpty) {
      return Center(
        child: HandDrawnCard(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.class_outlined, size: 48, color: HandDrawnTheme.pencil),
              const SizedBox(height: 16),
              Text(
                '请先选择班级',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  fontSize: 18,
                  color: HandDrawnTheme.pencil,
                ),
              ),
              const SizedBox(height: 16),
              HandDrawnButton(
                text: '选择班级',
                onPressed: () {
                  Navigator.of(context).pushReplacementNamed('/class_selection');
                },
              ),
            ],
          ),
        ),
      );
    }

    final cid = _currentClassId ?? classIds.first;

    return Column(
      children: [
        if (classIds.length > 1)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            child: HandDrawnCard(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  value: cid,
                  isExpanded: true,
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    fontSize: 16,
                    color: HandDrawnTheme.pencil,
                  ),
                  items: classIds.map((id) {
                    return DropdownMenuItem(
                      value: id,
                      child: Text('班级 $id'),
                    );
                  }).toList(),
                  onChanged: (v) {
                    if (v != null) setState(() => _currentClassId = v);
                  },
                ),
              ),
            ),
          ),
        Container(
          decoration: const BoxDecoration(
            border: Border(
              bottom: BorderSide(color: HandDrawnTheme.pencil, width: 2),
            ),
          ),
          child: TabBar(
            controller: _tabController,
            labelColor: HandDrawnTheme.pencil,
            unselectedLabelColor: HandDrawnTheme.muted,
            indicatorColor: HandDrawnTheme.accent,
            indicatorWeight: 3,
            labelStyle: TextStyle(
              fontFamily: 'Kalam',
              fontSize: 16,
              fontWeight: FontWeight.w700,
            ),
            unselectedLabelStyle: TextStyle(
              fontFamily: 'Patrick Hand',
              fontSize: 16,
            ),
            tabs: const [
              Tab(text: '单词库'),
              Tab(text: '任务'),
              Tab(text: '错题本'),
            ],
          ),
        ),
        Expanded(
          child: TabBarView(
            controller: _tabController,
            children: [
              _WordListView(classId: cid),
              _TaskListView(classId: cid),
              _WrongWordsView(classId: cid),
            ],
          ),
        ),
      ],
    );
  }
}

class _WordListView extends StatefulWidget {
  final String classId;
  const _WordListView({required this.classId});

  @override
  State<_WordListView> createState() => _WordListViewState();
}

class _WordListViewState extends State<_WordListView> {
  List<Word> _words = [];
  bool _loading = true;
  int _page = 1;
  bool _hasMore = true;
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _loadWords();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 200 &&
        !_loading &&
        _hasMore) {
      _loadWords();
    }
  }

  Future<void> _loadWords() async {
    if (_loading) return;
    setState(() => _loading = true);
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('get_words', {
        'class_id': widget.classId,
        'page': _page.toString(),
        'per_page': '20',
      });
      if (result['success'] == true) {
        final data = result['data'] ?? {};
        final list = (data['words'] as List?)
                ?.map((j) => Word.fromJson(j))
                .toList() ??
            [];
        setState(() {
          _words.addAll(list);
          _page++;
          _hasMore = data['has_more'] ?? false;
          _loading = false;
        });
      } else {
        setState(() => _loading = false);
      }
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  Future<void> _toggleWrong(Word word) async {
    final auth = context.read<AuthProvider>();
    try {
      await auth.api.post(word.isWrong ? 'unmark_wrong' : 'mark_wrong', {
        'class_id': widget.classId,
        'word_id': word.id,
      });
      setState(() {
        final idx = _words.indexWhere((w) => w.id == word.id);
        if (idx >= 0) {
          _words[idx] = Word(
            id: word.id,
            word: word.word,
            meaning: word.meaning,
            pos: word.pos,
            isWrong: !word.isWrong,
            isFavorite: word.isFavorite,
          );
        }
      });
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _words.isEmpty) {
      return const Center(
        child: CircularProgressIndicator(color: HandDrawnTheme.pencil),
      );
    }

    return ListView.builder(
      controller: _scrollController,
      padding: const EdgeInsets.only(top: 8, bottom: 16),
      itemCount: _words.length + (_hasMore ? 1 : 0),
      itemBuilder: (context, index) {
        if (index >= _words.length) {
          return const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator()),
          );
        }
        final word = _words[index];
        return _buildWordCard(word);
      },
    );
  }

  Widget _buildWordCard(Word word) {
    return HandDrawnCard(
      rotation: (word.id.hashCode % 3 - 1).toDouble(),
      backgroundColor: word.isWrong ? HandDrawnTheme.postItYellow : null,
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
                const SizedBox(height: 4),
                Text(
                  word.meaning,
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    fontSize: 16,
                    color: HandDrawnTheme.pencil,
                  ),
                ),
                if (word.pos.isNotEmpty)
                  Container(
                    margin: const EdgeInsets.only(top: 4),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: HandDrawnTheme.muted,
                      borderRadius: HandDrawnTheme.wobblyRadiusSm,
                      border: Border.all(color: HandDrawnTheme.pencil, width: 1),
                    ),
                    child: Text(
                      word.pos,
                      style: TextStyle(
                        fontFamily: 'Patrick Hand',
                        fontSize: 12,
                        color: HandDrawnTheme.pencil,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          Column(
            children: [
              IconButton(
                icon: Icon(
                  word.isWrong ? Icons.error : Icons.error_outline,
                  color: word.isWrong
                      ? HandDrawnTheme.accent
                      : HandDrawnTheme.pencil,
                ),
                onPressed: () => _toggleWrong(word),
                tooltip: '错题本',
              ),
              IconButton(
                icon: Icon(
                  Icons.volume_up,
                  color: HandDrawnTheme.blue,
                ),
                onPressed: () {},
                tooltip: '发音',
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _TaskListView extends StatefulWidget {
  final String classId;
  const _TaskListView({required this.classId});

  @override
  State<_TaskListView> createState() => _TaskListViewState();
}

class _TaskListViewState extends State<_TaskListView>
    with SingleTickerProviderStateMixin {
  late TabController _taskTabController;
  List<Task> _pendingTasks = [];
  List<Task> _historyTasks = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _taskTabController = TabController(length: 2, vsync: this);
    _loadTasks();
  }

  @override
  void dispose() {
    _taskTabController.dispose();
    super.dispose();
  }

  Future<void> _loadTasks() async {
    final auth = context.read<AuthProvider>();
    try {
      final pending = await auth.api.post('get_tasks', {
        'class_id': widget.classId,
        'type': 'pending',
      });
      final history = await auth.api.post('get_tasks', {
        'class_id': widget.classId,
        'type': 'history',
      });
      setState(() {
        if (pending['success'] == true) {
          _pendingTasks = (pending['data']['tasks'] as List?)
                  ?.map((j) => Task.fromJson(j))
                  .toList() ??
              [];
        }
        if (history['success'] == true) {
          _historyTasks = (history['data']['tasks'] as List?)
                  ?.map((j) => Task.fromJson(j))
                  .toList() ??
              [];
        }
        _loading = false;
      });
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(
        child: CircularProgressIndicator(color: HandDrawnTheme.pencil),
      );
    }

    return Column(
      children: [
        Container(
          decoration: const BoxDecoration(
            border: Border(
              bottom: BorderSide(color: HandDrawnTheme.pencil, width: 1),
            ),
          ),
          child: TabBar(
            controller: _taskTabController,
            labelColor: HandDrawnTheme.pencil,
            unselectedLabelColor: HandDrawnTheme.muted,
            indicatorColor: HandDrawnTheme.blue,
            indicatorWeight: 2,
            labelStyle: const TextStyle(
              fontFamily: 'Patrick Hand',
              fontSize: 15,
              fontWeight: FontWeight.w700,
            ),
            tabs: const [
              Tab(text: '进行中'),
              Tab(text: '历史'),
            ],
          ),
        ),
        Expanded(
          child: TabBarView(
            controller: _taskTabController,
            children: [
              _buildTaskList(_pendingTasks, isHistory: false),
              _buildTaskList(_historyTasks, isHistory: true),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildTaskList(List<Task> tasks, {required bool isHistory}) {
    if (tasks.isEmpty) {
      return Center(
        child: Text(
          isHistory ? '暂无历史任务' : '暂无进行中的任务',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil.withValues(alpha: 0.5),
          ),
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _loadTasks,
      child: ListView.builder(
        padding: const EdgeInsets.only(top: 8, bottom: 16),
        itemCount: tasks.length,
        itemBuilder: (context, index) {
          final task = tasks[index];
          return HandDrawnCard(
            rotation: index % 2 == 0 ? 0.5 : -0.5,
            onTap: () {
              Navigator.push(
                context,
                MaterialPageRoute(
                  builder: (_) =>
                      TaskDetailScreen(classId: widget.classId, taskId: task.id),
                ),
              );
            },
            child: Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        task.label.isNotEmpty ? task.label : '默写任务',
                        style: TextStyle(
                          fontFamily: 'Kalam',
                          fontSize: 18,
                          fontWeight: FontWeight.w700,
                          color: HandDrawnTheme.pencil,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${task.date}  ·  ${task.wordCount} 个单词',
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 14,
                          color: HandDrawnTheme.pencil.withValues(alpha: 0.6),
                        ),
                      ),
                      if (task.weekendWeek.isNotEmpty)
                        Text(
                          '周末大礼包 ${task.weekendWeek}',
                          style: TextStyle(
                            fontFamily: 'Patrick Hand',
                            fontSize: 12,
                            color: HandDrawnTheme.accent,
                          ),
                        ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: task.status == 'completed'
                        ? HandDrawnTheme.postItYellow
                        : HandDrawnTheme.muted,
                    borderRadius: HandDrawnTheme.wobblyRadiusSm,
                    border: Border.all(color: HandDrawnTheme.pencil, width: 1),
                  ),
                  child: Text(
                    task.status == 'completed'
                        ? '已完成'
                        : task.status == 'cancelled'
                            ? '已取消'
                            : '进行中',
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      fontSize: 14,
                      color: HandDrawnTheme.pencil,
                    ),
                  ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}

class _WrongWordsView extends StatefulWidget {
  final String classId;
  const _WrongWordsView({required this.classId});

  @override
  State<_WrongWordsView> createState() => _WrongWordsViewState();
}

class _WrongWordsViewState extends State<_WrongWordsView> {
  List<Word> _words = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _loadWrongWords();
  }

  Future<void> _loadWrongWords() async {
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('get_wrong_words', {
        'class_id': widget.classId,
      });
      if (result['success'] == true) {
        setState(() {
          _words = (result['data']['words'] as List?)
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

  Future<void> _removeFromWrong(Word word) async {
    final auth = context.read<AuthProvider>();
    try {
      await auth.api.post('unmark_wrong', {
        'class_id': widget.classId,
        'word_id': word.id,
      });
      setState(() => _words.removeWhere((w) => w.id == word.id));
    } catch (_) {}
  }

  Future<void> _exportWrongWords() async {
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('export_wrong_text', {
        'class_id': widget.classId,
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
                '导出错题本',
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
      return const Center(
        child: CircularProgressIndicator(color: HandDrawnTheme.pencil),
      );
    }

    return Column(
      children: [
        if (_words.isNotEmpty)
          Padding(
            padding: const EdgeInsets.all(16),
            child: HandDrawnButton(
              text: '导出错题本',
              icon: Icons.copy,
              onPressed: _exportWrongWords,
              secondary: true,
              small: true,
            ),
          ),
        Expanded(
          child: _words.isEmpty
              ? Center(
                  child: Text(
                    '错题本为空，加油！',
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      fontSize: 16,
                      color:
                          HandDrawnTheme.pencil.withValues(alpha: 0.5),
                    ),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _loadWrongWords,
                  child: ListView.builder(
                    padding: const EdgeInsets.only(bottom: 16),
                    itemCount: _words.length,
                    itemBuilder: (context, index) {
                      final word = _words[index];
                      return HandDrawnCard(
                        backgroundColor: HandDrawnTheme.postItYellow,
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
                              icon: const Icon(
                                Icons.check_circle_outline,
                                color: HandDrawnTheme.blue,
                              ),
                              onPressed: () => _removeFromWrong(word),
                              tooltip: '移出错题本',
                            ),
                          ],
                        ),
                      );
                    },
                  ),
                ),
        ),
      ],
    );
  }
}