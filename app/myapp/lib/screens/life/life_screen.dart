import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_widget_from_html/flutter_widget_from_html.dart';
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
  int _tab = 0;
  final _api = ApiService();

  Map<String, VlogEntry> _personalHistory = {};
  Map<String, VlogEntry> _classHistory = {};
  bool _loading = false;
  DateTime _calendarMonth = DateTime.now();

  @override
  void initState() {
    super.initState();
    _loadData();
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
    } catch (_) {}
    setState(() => _loading = false);
  }

  void _changeMonth(int delta) {
    setState(() {
      _calendarMonth = DateTime(
        _calendarMonth.year,
        _calendarMonth.month + delta,
      );
    });
    _loadData();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      appBar: AppBar(title: Text(auth.currentClassName ?? '生活')),
      body: PaperTexture(
        child: Column(
          children: [
            WobblyTabBar(
              tabs: const ['我的史记', '班级史记'],
              selectedIndex: _tab,
              onTap: (i) => setState(() => _tab = i),
            ),
            Expanded(
              child: _tab == 0
                  ? _buildCalendarView(_personalHistory, true)
                  : _buildCalendarView(_classHistory, false),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildCalendarView(
      Map<String, VlogEntry> entries, bool canEdit) {
    final today = DateTime.now();
    final todayStr =
        '${today.year}-${today.month.toString().padLeft(2, '0')}-${today.day.toString().padLeft(2, '0')}';

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                icon: const Icon(Icons.chevron_left, size: 28),
                onPressed: () => _changeMonth(-1),
              ),
              Text(
                '${_calendarMonth.year}年${_calendarMonth.month}月',
                style: AppTheme.headingStyle.copyWith(fontSize: 22),
              ),
              IconButton(
                icon: const Icon(Icons.chevron_right, size: 28),
                onPressed: () => _changeMonth(1),
              ),
            ],
          ),
        ),
        if (canEdit && entries[todayStr] == null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: HandDrawnButton(
              label: '写今天的史记',
              icon: Icons.edit,
              fullWidth: true,
              onPressed: () => _openEditor(todayStr, null),
            ),
          ),
        if (canEdit && entries[todayStr] != null)
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: Row(
              children: [
                Expanded(
                  child: HandDrawnButton(
                    label: '编辑今天的史记',
                    icon: Icons.edit,
                    isSecondary: true,
                    fullWidth: true,
                    onPressed: () =>
                        _openEditor(todayStr, entries[todayStr]),
                  ),
                ),
              ],
            ),
          ),
        const SizedBox(height: 8),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _buildCalendarGrid(entries, todayStr),
        ),
      ],
    );
  }

  Widget _buildCalendarGrid(
      Map<String, VlogEntry> entries, String todayStr) {
    final firstDay =
        DateTime(_calendarMonth.year, _calendarMonth.month, 1);
    final daysInMonth =
        DateTime(_calendarMonth.year, _calendarMonth.month + 1, 0).day;
    final firstWeekday = firstDay.weekday % 7;

    final days = <Widget>[];
    const weekDays = ['日', '一', '二', '三', '四', '五', '六'];
    for (final wd in weekDays) {
      days.add(Center(
        child: Text(wd,
            style: AppTheme.bodyStyle.copyWith(
              fontSize: 14,
              fontWeight: FontWeight.bold,
              color: AppColors.foreground.withValues(alpha: 0.5),
            )),
      ));
    }

    for (int i = 0; i < firstWeekday; i++) {
      days.add(const SizedBox());
    }

    for (int d = 1; d <= daysInMonth; d++) {
      final dateStr =
          '${_calendarMonth.year}-${_calendarMonth.month.toString().padLeft(2, '0')}-${d.toString().padLeft(2, '0')}';
      final hasEntry = entries.containsKey(dateStr);
      final isToday = dateStr == todayStr;
      final isPast = dateStr.compareTo(todayStr) < 0;

      days.add(GestureDetector(
        onTap: hasEntry
            ? () => _openViewer(dateStr, entries[dateStr]!)
            : null,
        child: Container(
          margin: const EdgeInsets.all(3),
          decoration: BoxDecoration(
            color: hasEntry
                ? (isToday
                    ? AppColors.postItYellow
                    : AppColors.cardWhite)
                : AppColors.muted.withValues(alpha: 0.3),
            borderRadius: AppTheme.wobblyRadius,
            border: hasEntry
                ? Border.all(color: AppColors.border, width: 2)
                : null,
            boxShadow: hasEntry ? AppTheme.hardShadowSm : null,
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                '$d',
                style: AppTheme.bodyStyle.copyWith(
                  fontSize: 16,
                  fontWeight: hasEntry ? FontWeight.bold : FontWeight.normal,
                  color: hasEntry
                      ? AppColors.foreground
                      : AppColors.foreground.withValues(alpha: 0.3),
                ),
              ),
              if (hasEntry)
                Icon(Icons.fiber_manual_record,
                    size: 8, color: AppColors.accent),
            ],
          ),
        ),
      ));
    }

    return GridView.count(
      crossAxisCount: 7,
      padding: const EdgeInsets.symmetric(horizontal: 12),
      childAspectRatio: 0.9,
      shrinkWrap: true,
      children: days,
    );
  }

  void _openViewer(String date, VlogEntry entry) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => _VlogViewerScreen(entry: entry, date: date),
      ),
    );
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

class _VlogViewerScreen extends StatelessWidget {
  final VlogEntry entry;
  final String date;

  const _VlogViewerScreen({required this.entry, required this.date});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(date)),
      body: PaperTexture(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(16),
          child: HandDrawnCard(
            padding: const EdgeInsets.all(20),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (entry.title.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Text(
                      entry.title,
                      style: AppTheme.headingStyle.copyWith(fontSize: 24),
                    ),
                  ),
                Row(
                  children: [
                    if (entry.mood.isNotEmpty)
                      _buildMetaChip(entry.mood),
                    if (entry.weather.isNotEmpty) ...[
                      const SizedBox(width: 8),
                      _buildMetaChip(entry.weather),
                    ],
                    if (entry.location.isNotEmpty) ...[
                      const SizedBox(width: 8),
                      _buildMetaChip(entry.location),
                    ],
                  ],
                ),
                if (entry.tags.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Wrap(
                    spacing: 8,
                    children: entry.tags
                        .map((t) => Container(
                              padding: const EdgeInsets.symmetric(
                                  horizontal: 10, vertical: 4),
                              decoration: BoxDecoration(
                                color: AppColors.secondaryAccent
                                    .withValues(alpha: 0.1),
                                borderRadius: AppTheme.wobblyRadius,
                              ),
                              child: Text('#$t',
                                  style: AppTheme.bodyStyle.copyWith(
                                      fontSize: 14,
                                      color: AppColors.secondaryAccent)),
                            ))
                        .toList(),
                  ),
                ],
                const Divider(height: 24, thickness: 2),
                HtmlWidget(
                  entry.content,
                  textStyle: AppTheme.bodyStyle.copyWith(fontSize: 16),
                ),
                const SizedBox(height: 12),
                Text(
                  '更新于 ${entry.updatedAt}',
                  style: AppTheme.bodyStyle.copyWith(
                    fontSize: 13,
                    color: AppColors.foreground.withValues(alpha: 0.4),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildMetaChip(String label) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: AppColors.postItYellow,
        borderRadius: AppTheme.wobblyRadius,
      ),
      child: Text(label, style: AppTheme.bodyStyle.copyWith(fontSize: 14)),
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
    final matches = imgRegex.allMatches(html);
    for (final m in matches) {
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
      final fullUrl = url.startsWith('http') ? url : url;
      htmlParts.add('<p><img src="$fullUrl"></p>');
    }
    return htmlParts.join('');
  }

  String _escapeHtml(String text) {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
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
      final res =
          await _api.uploadImage(classId, File(image.path), image.name);
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
        actions: [
          IconButton(
            icon: const Icon(Icons.check),
            onPressed: _saving ? null : _save,
          ),
        ],
      ),
      body: PaperTexture(
        child: _saving && _imageUrls.length == _imageUrls.length
            ? const LoadingOverlay(message: '保存中...')
            : SingleChildScrollView(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    HandDrawnInput(
                      label: '标题',
                      hint: '给今天起个标题',
                      controller: _titleCtrl,
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('心情', style: AppTheme.bodyStyle),
                              const SizedBox(height: 4),
                              Wrap(
                                spacing: 8,
                                children: _moods.map((m) {
                                  final selected = m == _mood;
                                  return GestureDetector(
                                    onTap: () =>
                                        setState(() => _mood = m),
                                    child: Container(
                                      padding: const EdgeInsets.all(8),
                                      decoration: BoxDecoration(
                                        color: selected
                                            ? AppColors.postItYellow
                                            : AppColors.cardWhite,
                                        borderRadius:
                                            AppTheme.wobblyRadius,
                                        border: Border.all(
                                          color: AppColors.border,
                                          width: selected ? 2.5 : 1.5,
                                        ),
                                      ),
                                      child: Text(m, style: const TextStyle(fontSize: 20)),
                                    ),
                                  );
                                }).toList(),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('天气', style: AppTheme.bodyStyle),
                        const SizedBox(height: 4),
                        Wrap(
                          spacing: 8,
                          children: _weathers.map((w) {
                            final selected = w == _weather;
                            return GestureDetector(
                              onTap: () => setState(() => _weather = w),
                              child: Container(
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: selected
                                      ? AppColors.postItYellow
                                      : AppColors.cardWhite,
                                  borderRadius: AppTheme.wobblyRadius,
                                  border: Border.all(
                                    color: AppColors.border,
                                    width: selected ? 2.5 : 1.5,
                                  ),
                                ),
                                child: Text(w, style: const TextStyle(fontSize: 20)),
                              ),
                            );
                          }).toList(),
                        ),
                      ],
                    ),
                    const SizedBox(height: 16),
                    HandDrawnInput(
                      label: '位置',
                      hint: '你在哪里？',
                      controller: _locationCtrl,
                    ),
                    const SizedBox(height: 16),
                    HandDrawnInput(
                      label: '标签',
                      hint: '用逗号分隔',
                      controller: _tagsCtrl,
                    ),
                    const SizedBox(height: 16),
                    HandDrawnInput(
                      label: '内容',
                      hint: '记录今天的故事...',
                      controller: _contentCtrl,
                      maxLines: 8,
                    ),
                    const SizedBox(height: 16),
                    if (_imageUrls.isNotEmpty) ...[
                      Text('图片', style: AppTheme.bodyStyle),
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
                                child: Image.network(
                                  url,
                                  width: 100,
                                  height: 100,
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, __, ___) => Container(
                                    width: 100,
                                    height: 100,
                                    color: AppColors.muted,
                                    child: const Icon(Icons.broken_image),
                                  ),
                                ),
                              ),
                              Positioned(
                                top: 0,
                                right: 0,
                                child: GestureDetector(
                                  onTap: () => _removeImage(i),
                                  child: Container(
                                    padding: const EdgeInsets.all(2),
                                    decoration: const BoxDecoration(
                                      color: AppColors.accent,
                                      shape: BoxShape.circle,
                                    ),
                                    child: const Icon(Icons.close,
                                        size: 16, color: Colors.white),
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
}
