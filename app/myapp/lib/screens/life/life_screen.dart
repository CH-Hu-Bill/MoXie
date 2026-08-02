import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html/flutter_widget_from_html.dart' hide ImageSource;
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../../models/vlog_entry.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../config/api_config.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';
import '../../widgets/rich_text_editor.dart';

class LifeScreen extends StatefulWidget {
  const LifeScreen({super.key});

  @override
  State<LifeScreen> createState() => _LifeScreenState();
}

class _LifeScreenState extends State<LifeScreen> {
  int _tab = 0;
  final _api = ApiService();

  Map<String, VlogEntry> _personalHistory = {};
  Map<String, VlogEntry> _classHistory = {};
  List<Map<String, dynamic>> _authorizedVlogs = [];
  bool _loading = false;
  DateTime _calendarMonth = DateTime.now();
  String? _selectedDate;
  bool _isEditing = false;
  final _editTitleCtrl = TextEditingController();
  final _editLocationCtrl = TextEditingController();
  final _editTagsCtrl = TextEditingController();
  final _richTextKey = GlobalKey<RichTextEditorState>();
  String _editMood = '😊';
  String _editWeather = '☀️';
  bool _saving = false;

  static const _moods = ['😊', '🥰', '😌', '😢', '😤', '🤩', '😴'];
  static const _weathers = ['☀️', '⛅', '☁️', '🌧️', '⛈️', '🌨️', '🌬️'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadData());
  }

  @override
  void dispose() {
    _editTitleCtrl.dispose();
    _editLocationCtrl.dispose();
    _editTagsCtrl.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    setState(() => _loading = true);
    try {
      final monthStr =
          '${_calendarMonth.year}-${_calendarMonth.month.toString().padLeft(2, '0')}';

      final personalRes =
          await _api.getPersonalHistory(classId, month: monthStr);
      final personalData = personalRes['data'] as Map<String, dynamic>;
      _personalHistory = personalData.map((k, v) =>
          MapEntry(k, VlogEntry.fromJson(k, v as Map<String, dynamic>)));

      final classRes = await _api.getClassHistory(classId, month: monthStr);
      final classData = classRes['data'] as Map<String, dynamic>;
      _classHistory = classData.map((k, v) =>
          MapEntry(k, VlogEntry.fromJson(k, v as Map<String, dynamic>)));

      final vlogsRes = await _api.getAuthorizedVlogs(classId, month: monthStr);
      _authorizedVlogs = (vlogsRes['data'] as List<dynamic>).cast<Map<String, dynamic>>();
    } catch (_) {}
    setState(() => _loading = false);
  }

  void _changeMonth(int delta) {
    setState(() {
      _calendarMonth = DateTime(_calendarMonth.year, _calendarMonth.month + delta);
      _selectedDate = null;
    });
    _loadData();
  }

  bool _hasEntryOnDate(String dateStr) {
    if (_tab == 0) return _personalHistory.containsKey(dateStr);
    if (_tab == 2) return _classHistory.containsKey(dateStr);
    if (_tab == 1) {
      for (final author in _authorizedVlogs) {
        final entries = author['entries'] as Map<String, dynamic>;
        if (entries.containsKey(dateStr)) return true;
      }
    }
    return false;
  }

  String _todayStr() {
    final today = DateTime.now();
    return '${today.year}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';
  }

  void _onDateSelected(String dateStr) {
    setState(() {
      _selectedDate = dateStr;
      _isEditing = false;
    });
    if (_tab == 0) {
      final entry = _personalHistory[dateStr];
      final isToday = dateStr == _todayStr();
      if (entry == null && isToday) {
        _startEditing(null);
      }
    }
  }

  void _startEditing(VlogEntry? existing) {
    _editTitleCtrl.text = existing?.title ?? '';
    _editLocationCtrl.text = existing?.location ?? '';
    _editTagsCtrl.text = existing?.tags.join(', ') ?? '';
    _editMood = (existing?.mood.isNotEmpty == true) ? existing!.mood : '😊';
    _editWeather = (existing?.weather.isNotEmpty == true) ? existing!.weather : '☀️';
    final html = existing != null ? _resolveHtmlImages(existing.content) : '';
    setState(() => _isEditing = true);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _richTextKey.currentState?.setHtml(html);
    });
  }

  String _resolveHtmlImages(String html) {
    try {
      final base = ApiConfig.baseUrl;
      return html.replaceAllMapped(
        RegExp(r'(<img[^>]*\bsrc=")([^"]+)("[^>]*>)'),
        (m) {
          final src = m.group(2)!;
          if (src.startsWith('http')) return m.group(0)!;
          return '${m.group(1)}$base/$src${m.group(3)}';
        },
      );
    } catch (_) {
      return html;
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final today = DateTime.now();
    final todayStr = _todayStr();

    return Scaffold(
      appBar: AppBar(
        title: Text(auth.currentClassName ?? '生活'),
        backgroundColor: AppColors.white,
        shape: const Border(bottom: BorderSide(color: AppColors.pencil, width: 3)),
      ),
      body: PaperTexture(
        child: _loading
            ? const LoadingOverlay(message: '加载中...')
            : SingleChildScrollView(
                child: Column(
                  children: [
                    WobblyTabBar(
                      tabs: const ['我的', '他人', '班级'],
                      selectedIndex: _tab,
                      onTap: (i) => setState(() { _tab = i; _selectedDate = null; }),
                    ),
                    _buildCalendar(),
                    _buildContentArea(todayStr),
                  ],
                ),
              ),
      ),
    );
  }

  Widget _buildCalendar() {
    final firstDay = DateTime(_calendarMonth.year, _calendarMonth.month, 1);
    final daysInMonth =
        DateTime(_calendarMonth.year, _calendarMonth.month + 1, 0).day;
    final firstWeekday = firstDay.weekday % 7;

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                icon: const Icon(Icons.chevron_left, size: 28),
                onPressed: () => _changeMonth(-1),
              ),
              Text(
                '${_calendarMonth.year}年${_calendarMonth.month}月',
                style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 22),
              ),
              IconButton(
                icon: const Icon(Icons.chevron_right, size: 28),
                onPressed: () => _changeMonth(1),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8),
          child: GridView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 7,
              childAspectRatio: 0.9,
            ),
            itemCount: 7 + firstWeekday + daysInMonth,
            itemBuilder: (ctx, i) {
              if (i < 7) {
                const weekDays = ['日', '一', '二', '三', '四', '五', '六'];
                return Center(
                  child: Text(weekDays[i],
                      style: TextStyle(
                          fontFamily: AppTheme.fontBody,
                          fontSize: 14,
                          color: AppColors.pencil.withValues(alpha: 0.5))),
                );
              }
              final dayIndex = i - 7 - firstWeekday;
              if (dayIndex < 0) return const SizedBox();
              final d = dayIndex + 1;
              final dateStr =
                  '${_calendarMonth.year}-${_calendarMonth.month.toString().padLeft(2, '0')}-${d.toString().padLeft(2, '0')}';
              final hasEntry = _hasEntryOnDate(dateStr);
              final isToday = dateStr == _todayStr();
              final isSelected = dateStr == _selectedDate;
              final canClick = hasEntry || (_tab == 0 && isToday);

              return GestureDetector(
                onTap: canClick ? () => _onDateSelected(dateStr) : null,
                child: Container(
                  margin: const EdgeInsets.all(3),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? AppColors.postIt
                        : hasEntry
                            ? AppColors.white
                            : AppColors.oldPaper.withValues(alpha: 0.3),
                    borderRadius: AppTheme.wobblyRadius,
                    border: canClick
                        ? Border.all(
                            color: isSelected ? AppColors.red : AppColors.pencil,
                            width: isSelected ? 3 : 2,
                          )
                        : null,
                    boxShadow: canClick ? AppTheme.hardShadowSm : null,
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        '$d',
                        style: TextStyle(
                          fontFamily: AppTheme.fontBody,
                          fontSize: 16,
                          color: canClick
                              ? AppColors.pencil
                              : AppColors.pencil.withValues(alpha: 0.3),
                        ),
                      ),
                      if (hasEntry)
                        const Icon(Icons.fiber_manual_record,
                            size: 6, color: AppColors.red),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
      ],
    );
  }

  Widget _buildContentArea(String todayStr) {
    if (_selectedDate == null) {
      return const EmptyState(
        message: '请在上方日历选择日期',
        icon: Icons.calendar_today_outlined,
      );
    }

    if (_tab == 0) {
      if (_isEditing) {
        return _buildInlineEditor();
      }
      final entry = _personalHistory[_selectedDate!];
      if (entry != null) {
        return Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
          child: _VlogViewer(
            entry: entry,
            isToday: _selectedDate == todayStr,
            onEdit: () => _startEditing(entry),
            scrollable: false,
          ),
        );
      }
      if (_selectedDate == todayStr) {
        return Padding(
          padding: const EdgeInsets.all(16),
          child: HandDrawnButton(
            label: '写今天的史记',
            icon: Icons.edit,
            fullWidth: true,
            onPressed: () => _startEditing(null),
          ),
        );
      }
      return const EmptyState(message: '这天没有记录', icon: Icons.event_busy);
    }

    if (_tab == 1) {
      final authors = _authorizedVlogs.where((a) {
        final entries = a['entries'] as Map<String, dynamic>;
        return entries.containsKey(_selectedDate!);
      }).toList();

      if (authors.isEmpty) {
        return const EmptyState(message: '当天没有授权用户的史记', icon: Icons.people_outline);
      }

      return ListView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.all(16),
        itemCount: authors.length,
        itemBuilder: (ctx, i) {
          final author = authors[i];
          final entries = author['entries'] as Map<String, dynamic>;
          final entry = VlogEntry.fromJson(
              _selectedDate!, entries[_selectedDate!] as Map<String, dynamic>);
          return Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: HandDrawnCard(
              onTap: () {
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => Scaffold(
                      appBar: AppBar(title: Text('${author['author_name']} - $_selectedDate')),
                      body: PaperTexture(child: _VlogViewer(entry: entry)),
                    ),
                  ),
                );
              },
              child: Row(
                children: [
                  const Icon(Icons.person, size: 28, color: AppColors.blue),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      author['author_name'] as String,
                      style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 18),
                    ),
                  ),
                  const Icon(Icons.chevron_right),
                ],
              ),
            ),
          );
        },
      );
    }

    final entry = _classHistory[_selectedDate!];
    if (entry == null) {
      return const EmptyState(message: '这天没有班级史记', icon: Icons.event_busy);
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      child: _VlogViewer(entry: entry, scrollable: false),
    );
  }

  Future<void> _saveInline() async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId!;
    if (_selectedDate == null) return;
    final tags = _editTagsCtrl.text
        .split(',')
        .map((t) => t.trim())
        .where((t) => t.isNotEmpty)
        .toList();
    final content = _richTextKey.currentState?.getHtml() ?? '';

    setState(() => _saving = true);
    try {
      await _api.savePersonalHistory(classId, {
        'date': _selectedDate!,
        'content': content,
        'title': _editTitleCtrl.text.trim(),
        'mood': _editMood,
        'weather': _editWeather,
        'location': _editLocationCtrl.text.trim(),
        'tags': tags,
      });
      setState(() => _isEditing = false);
      _loadData();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('保存成功')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('保存失败: $e')),
        );
      }
    }
    setState(() => _saving = false);
  }

  Widget _buildInlineEditor() {
    return SingleChildScrollView(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          HandDrawnInput(label: '标题', hint: '给今天起个标题', controller: _editTitleCtrl),
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(child: _buildSelector('心情', _moods, _editMood, (v) => setState(() => _editMood = v))),
              const SizedBox(width: 12),
              Expanded(child: _buildSelector('天气', _weathers, _editWeather, (v) => setState(() => _editWeather = v))),
            ],
          ),
          const SizedBox(height: 12),
          HandDrawnInput(label: '位置', hint: '你在哪里？', controller: _editLocationCtrl),
          const SizedBox(height: 12),
          HandDrawnInput(label: '标签', hint: '用逗号分隔', controller: _editTagsCtrl),
          const SizedBox(height: 16),
          RichTextEditor(
            key: _richTextKey,
            minHeight: 280,
          ),
          const SizedBox(height: 12),
          HandDrawnButton(
            label: '插入图片',
            icon: Icons.image,
            isSecondary: true,
            fullWidth: true,
            onPressed: _pickImageInline,
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Expanded(
                child: HandDrawnButton(
                  label: '取消',
                  isSecondary: true,
                  onPressed: () => setState(() => _isEditing = false),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: HandDrawnButton(
                  label: _saving ? '保存中...' : '保存',
                  onPressed: _saving ? null : _saveInline,
                  fontSize: 20,
                ),
              ),
            ],
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }

  Widget _buildSelector(String label, List<String> options, String selected, ValueChanged<String> onTap) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 15)),
        const SizedBox(height: 6),
        Wrap(
          spacing: 8,
          children: options.map((opt) {
            final isSelected = opt == selected;
            return GestureDetector(
              onTap: () => onTap(opt),
              child: Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: isSelected ? AppColors.postIt : AppColors.white,
                  borderRadius: AppTheme.wobblyRadius,
                  border: Border.all(
                    color: AppColors.pencil,
                    width: isSelected ? 3 : 2,
                  ),
                ),
                child: Text(opt, style: const TextStyle(fontSize: 20)),
              ),
            );
          }).toList(),
        ),
      ],
    );
  }

  Future<void> _pickImageInline() async {
    final picker = ImagePicker();
    final image = await picker.pickImage(
      source: ImageSource.gallery,
      maxWidth: 1920,
      maxHeight: 1920,
    );
    if (image == null) return;
    setState(() => _saving = true);
    try {
      final auth = context.read<AuthProvider>();
      final classId = auth.currentClassId!;
      final res = await _api.uploadImage(classId, File(image.path), image.name);
      final url = res['data']['url'] as String;
      final fullUrl = _api.resolveImageUrl(url);
      _richTextKey.currentState?.insertImage(fullUrl);
      setState(() => _saving = false);
    } catch (e) {
      setState(() => _saving = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('图片上传失败: $e')),
        );
      }
    }
  }
}

class _VlogViewer extends StatelessWidget {
  final VlogEntry entry;
  final bool isToday;
  final VoidCallback? onEdit;
  final bool scrollable;

  const _VlogViewer({required this.entry, this.isToday = false, this.onEdit, this.scrollable = true});

  @override
  Widget build(BuildContext context) {
    final resolvedContent = _resolveHtmlImages(entry.content);
    final card = HandDrawnCard(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (isToday && onEdit != null)
            Align(
              alignment: Alignment.centerRight,
              child: GestureDetector(
                onTap: onEdit,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.postIt,
                    borderRadius: AppTheme.wobblyRadius,
                    border: Border.all(color: AppColors.pencil, width: 2),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.edit, size: 16, color: AppColors.pencil),
                      const SizedBox(width: 4),
                      Text('编辑',
                          style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 14)),
                    ],
                  ),
                ),
              ),
            ),
          if (entry.title.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Text(entry.title,
                  style: TextStyle(fontFamily: AppTheme.fontHeading, fontSize: 24)),
            ),
          Row(
            children: [
              if (entry.mood.isNotEmpty) _buildChip(entry.mood),
              if (entry.weather.isNotEmpty) ...[const SizedBox(width: 8), _buildChip(entry.weather)],
              if (entry.location.isNotEmpty) ...[const SizedBox(width: 8), _buildChip(entry.location)],
            ],
          ),
          if (entry.tags.isNotEmpty) ...[
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              children: entry.tags
                  .map((t) => Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: AppColors.blue.withValues(alpha: 0.1),
                          borderRadius: AppTheme.wobblyRadius,
                        ),
                        child: Text('#$t',
                            style: TextStyle(
                                fontFamily: AppTheme.fontBody,
                                fontSize: 14,
                                color: AppColors.blue)),
                      ))
                  .toList(),
            ),
          ],
          const Divider(height: 24, thickness: 2),
          HtmlWidget(
            resolvedContent,
            textStyle: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 16),
          ),
          const SizedBox(height: 12),
          Text('更新于 ${entry.updatedAt}',
              style: TextStyle(
                  fontFamily: AppTheme.fontBody,
                  fontSize: 13,
                  color: AppColors.pencil.withValues(alpha: 0.4))),
        ],
      ),
    );
    if (scrollable) {
      return Padding(
        padding: const EdgeInsets.all(16),
        child: card,
      );
    }
    return card;
  }

  Widget _buildChip(String label) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.postIt,
        borderRadius: AppTheme.wobblyRadius,
      ),
      child: Text(label, style: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 14)),
    );
  }

  String _resolveHtmlImages(String html) {
    try {
      final base = ApiConfig.baseUrl;
      return html.replaceAllMapped(
        RegExp(r'(<img[^>]*\bsrc=")([^"]+)("[^>]*>)'),
        (m) {
          final src = m.group(2)!;
          if (src.startsWith('http')) return m.group(0)!;
          return '${m.group(1)}$base/$src${m.group(3)}';
        },
      );
    } catch (_) {
      return html;
    }
  }
}

