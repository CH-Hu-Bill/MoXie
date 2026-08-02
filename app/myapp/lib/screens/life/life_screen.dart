import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html/flutter_widget_from_html.dart' hide ImageSource;
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../../models/vlog_entry.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';

class LifeScreen extends StatefulWidget {
  const LifeScreen({super.key});

  @override
  State<LifeScreen> createState() => _LifeScreenState();
}

class _LifeScreenState extends State<LifeScreen> {
  int _tab = 0; // 0=我的, 1=他人, 2=班级
  final _api = ApiService();

  Map<String, VlogEntry> _personalHistory = {};
  Map<String, VlogEntry> _classHistory = {};
  List<Map<String, dynamic>> _authorizedVlogs = [];
  bool _loading = false;
  DateTime _calendarMonth = DateTime.now();
  String? _selectedDate;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadData());
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

  Map<String, VlogEntry> _currentEntries() {
    if (_tab == 0) return _personalHistory;
    if (_tab == 2) return _classHistory;
    return {};
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

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final today = DateTime.now();
    final todayStr =
        '${today.year}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';

    return Scaffold(
      appBar: AppBar(
        title: Text(auth.currentClassName ?? '生活'),
        backgroundColor: AppColors.white,
        shape: const Border(bottom: BorderSide(color: AppColors.pencil, width: 3)),
      ),
      body: PaperTexture(
        child: Column(
          children: [
            WobblyTabBar(
              tabs: const ['我的', '他人', '班级'],
              selectedIndex: _tab,
              onTap: (i) => setState(() { _tab = i; _selectedDate = null; }),
            ),
            _buildCalendar(),
            Expanded(child: _buildContentArea(todayStr)),
          ],
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
              final isSelected = dateStr == _selectedDate;

              return GestureDetector(
                onTap: hasEntry || (_tab == 0 && dateStr == _todayStr())
                    ? () => setState(() => _selectedDate = dateStr)
                    : null,
                child: Container(
                  margin: const EdgeInsets.all(3),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? AppColors.postIt
                        : hasEntry
                            ? AppColors.white
                            : AppColors.oldPaper.withValues(alpha: 0.3),
                    borderRadius: AppTheme.wobblyRadius,
                    border: hasEntry || isSelected
                        ? Border.all(color: AppColors.pencil, width: 2)
                        : null,
                    boxShadow: hasEntry || isSelected ? AppTheme.hardShadowSm : null,
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text(
                        '$d',
                        style: TextStyle(
                          fontFamily: AppTheme.fontBody,
                          fontSize: 16,
                          color: hasEntry || isSelected
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

  String _todayStr() {
    final today = DateTime.now();
    return '${today.year}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';
  }

  Widget _buildContentArea(String todayStr) {
    if (_selectedDate == null) {
      return const EmptyState(
        message: '请在上方日历选择日期',
        icon: Icons.calendar_today_outlined,
      );
    }

    if (_tab == 0) {
      final entry = _personalHistory[_selectedDate!];
      if (entry == null && _selectedDate == todayStr) {
        return Center(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: HandDrawnButton(
              label: '写今天的史记',
              icon: Icons.edit,
              fullWidth: true,
              onPressed: () => _openEditor(todayStr, null),
            ),
          ),
        );
      }
      if (entry == null) {
        return const EmptyState(message: '这天没有记录', icon: Icons.event_busy);
      }
      return Column(
        children: [
          if (_selectedDate == todayStr)
            Padding(
              padding: const EdgeInsets.all(16),
              child: HandDrawnButton(
                label: '编辑今天的史记',
                icon: Icons.edit,
                isSecondary: true,
                fullWidth: true,
                onPressed: () => _openEditor(todayStr, entry),
              ),
            ),
          Expanded(child: _VlogViewer(entry: entry)),
        ],
      );
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

    // tab == 2, class history
    final entry = _classHistory[_selectedDate!];
    if (entry == null) {
      return const EmptyState(message: '这天没有班级史记', icon: Icons.event_busy);
    }
    return _VlogViewer(entry: entry);
  }

  void _openEditor(String date, VlogEntry? existing) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => _VlogEditorScreen(
          date: date,
          existing: existing,
          onSave: _loadData,
        ),
      ),
    );
  }
}

class _VlogViewer extends StatelessWidget {
  final VlogEntry entry;

  const _VlogViewer({required this.entry});

  @override
  Widget build(BuildContext context) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(16),
      child: HandDrawnCard(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
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
              entry.content,
              textStyle: TextStyle(fontFamily: AppTheme.fontBody, fontSize: 16),
              customWidgetBuilder: (element) {
                if (element.localName == 'img') {
                  final src = element.attributes['src'] ?? '';
                  final fullSrc = src.startsWith('http')
                      ? src
                      : ApiService().resolveImageUrl(src);
                  return Image.network(fullSrc, fit: BoxFit.contain);
                }
                return null;
              },
            ),
            const SizedBox(height: 12),
            Text('更新于 ${entry.updatedAt}',
                style: TextStyle(
                    fontFamily: AppTheme.fontBody,
                    fontSize: 13,
                    color: AppColors.pencil.withValues(alpha: 0.4))),
          ],
        ),
      ),
    );
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
}

class _VlogEditorScreen extends StatefulWidget {
  final String date;
  final VlogEntry? existing;
  final VoidCallback onSave;

  const _VlogEditorScreen({
    required this.date,
    this.existing,
    required this.onSave,
  });

  @override
  State<_VlogEditorScreen> createState() => _VlogEditorScreenState();
}

class _VlogEditorScreenState extends State<_VlogEditorScreen> {
  final _api = ApiService();
  final _titleCtrl = TextEditingController();
  final _contentCtrl = TextEditingController();
  final _locationCtrl = TextEditingController();
  final _tagsCtrl = TextEditingController();

  String _mood = '😊';
  String _weather = '☀️';
  List<String> _imageUrls = [];
  bool _saving = false;

  static const _moods = ['😊', '🥰', '😌', '😢', '😤', '🤩', '😴'];
  static const _weathers = ['☀️', '⛅', '☁️', '🌧️', '⛈️', '🌨️', '🌬️'];

  @override
  void initState() {
    super.initState();
    if (widget.existing != null) {
      final e = widget.existing!;
      _titleCtrl.text = e.title;
      _locationCtrl.text = e.location;
      _tagsCtrl.text = e.tags.join(', ');
      _mood = e.mood.isNotEmpty ? e.mood : '😊';
      _weather = e.weather.isNotEmpty ? e.weather : '☀️';
      _extractImagesFromContent(e.content);
      _contentCtrl.text = _stripHtml(e.content);
    }
  }

  void _extractImagesFromContent(String html) {
    final imgRegex = RegExp(r'<img[^>]+src="([^"]+)"');
    for (final m in imgRegex.allMatches(html)) {
      _imageUrls.add(m.group(1)!);
    }
  }

  String _stripHtml(String html) {
    return html
        .replaceAll(RegExp(r'<br\s*/?>'), '\n')
        .replaceAll(RegExp(r'</p>'), '\n')
        .replaceAll(RegExp(r'<[^>]+>'), '')
        .replaceAll('&nbsp;', ' ')
        .replaceAll('&amp;', '&')
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .trim();
  }

  String _buildHtml() {
    final lines = _contentCtrl.text.split('\n');
    final htmlParts = <String>[];
    for (final line in lines) {
      if (line.trim().isNotEmpty) {
        htmlParts.add('<p>${_escapeHtml(line.trim())}</p>');
      }
    }
    for (final url in _imageUrls) {
      htmlParts.add('<p><img src="$url"></p>');
    }
    return htmlParts.join('');
  }

  String _escapeHtml(String text) {
    return text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
  }

  Future<void> _pickImage() async {
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
      setState(() {
        _imageUrls.add(url);
        _saving = false;
      });
    } catch (e) {
      setState(() => _saving = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('图片上传失败: $e')),
        );
      }
    }
  }

  void _removeImage(int index) {
    setState(() => _imageUrls.removeAt(index));
  }

  Future<void> _save() async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId!;
    final tags = _tagsCtrl.text
        .split(',')
        .map((t) => t.trim())
        .where((t) => t.isNotEmpty)
        .toList();

    setState(() => _saving = true);
    try {
      await _api.savePersonalHistory(classId, {
        'date': widget.date,
        'content': _buildHtml(),
        'title': _titleCtrl.text.trim(),
        'mood': _mood,
        'weather': _weather,
        'location': _locationCtrl.text.trim(),
        'tags': tags,
      });
      widget.onSave();
      if (mounted) {
        Navigator.pop(context);
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('编辑 ${widget.date}'),
        backgroundColor: AppColors.white,
        shape: const Border(bottom: BorderSide(color: AppColors.pencil, width: 3)),
        actions: [
          IconButton(icon: const Icon(Icons.check), onPressed: _saving ? null : _save),
        ],
      ),
      body: PaperTexture(
        child: _saving
            ? const LoadingOverlay(message: '保存中...')
            : SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    HandDrawnInput(label: '标题', hint: '给今天起个标题', controller: _titleCtrl),
                    const SizedBox(height: 16),
                    _buildSelector('心情', _moods, _mood, (v) => setState(() => _mood = v)),
                    const SizedBox(height: 12),
                    _buildSelector('天气', _weathers, _weather, (v) => setState(() => _weather = v)),
                    const SizedBox(height: 16),
                    HandDrawnInput(label: '位置', hint: '你在哪里？', controller: _locationCtrl),
                    const SizedBox(height: 16),
                    HandDrawnInput(label: '标签', hint: '用逗号分隔', controller: _tagsCtrl),
                    const SizedBox(height: 16),
                    HandDrawnInput(
                      label: '内容',
                      hint: '记录今天的故事...',
                      controller: _contentCtrl,
                      maxLines: 8,
                    ),
                    const SizedBox(height: 16),
                    if (_imageUrls.isNotEmpty) ...[
                      Text('图片', style: TextStyle(fontFamily: AppTheme.fontBody)),
                      const SizedBox(height: 8),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: List.generate(_imageUrls.length, (i) {
                          final url = _api.resolveImageUrl(_imageUrls[i]);
                          return Stack(
                            children: [
                              ClipRRect(
                                borderRadius: AppTheme.wobblyRadius,
                                child: Image.network(url,
                                    width: 100, height: 100, fit: BoxFit.cover,
                                    errorBuilder: (_, __, ___) => Container(
                                      width: 100, height: 100, color: AppColors.oldPaper,
                                      child: const Icon(Icons.broken_image))),
                              ),
                              Positioned(
                                top: 0, right: 0,
                                child: GestureDetector(
                                  onTap: () => _removeImage(i),
                                  child: Container(
                                    padding: const EdgeInsets.all(2),
                                    decoration: const BoxDecoration(
                                      color: AppColors.red, shape: BoxShape.circle),
                                    child: const Icon(Icons.close, size: 16, color: AppColors.white),
                                  ),
                                ),
                              ),
                            ],
                          );
                        }),
                      ),
                      const SizedBox(height: 16),
                    ],
                    HandDrawnButton(
                      label: '添加图片',
                      icon: Icons.image,
                      isSecondary: true,
                      fullWidth: true,
                      onPressed: _pickImage,
                    ),
                    const SizedBox(height: 24),
                    HandDrawnButton(
                      label: '保存',
                      icon: Icons.save,
                      fullWidth: true,
                      fontSize: 20,
                      onPressed: _saving ? null : _save,
                    ),
                  ],
                ),
              ),
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
}
