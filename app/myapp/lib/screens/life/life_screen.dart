import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:table_calendar/table_calendar.dart';
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/theme/app_theme.dart';

class LifeScreen extends StatefulWidget {
  const LifeScreen({super.key});

  @override
  State<LifeScreen> createState() => _LifeScreenState();
}

class _LifeScreenState extends State<LifeScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;
  DateTime _focusedDay = DateTime.now();
  DateTime? _selectedDay;
  String? _currentClassId;
  Map<String, dynamic> _myHistory = {};
  Map<String, dynamic> _classHistory = {};
  bool _loading = false;

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

  Future<void> _loadHistory(String type) async {
    if (_currentClassId == null) return;
    final auth = context.read<AuthProvider>();
    setState(() => _loading = true);
    try {
      final month = '${_focusedDay.year}-${_focusedDay.month.toString().padLeft(2, '0')}';
      final action = type == 'personal'
          ? 'get_personal_history'
          : 'get_class_history';
      final result = await auth.api.post(action, {
        'class_id': _currentClassId!,
        'month': month,
      });
      if (result['success'] == true) {
        setState(() {
          if (type == 'personal') {
            _myHistory = result['data'] ?? {};
          } else {
            _classHistory = result['data'] ?? {};
          }
          _loading = false;
        });
      }
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  List<DateTime> _getDaysWithHistory(Map<String, dynamic> history) {
    return history.keys
        .where((k) => history[k] != null)
        .map((k) => DateTime.tryParse(k) ?? DateTime.now())
        .where((d) => d.year > 2000)
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    if (auth.user?.classIds.isEmpty == true) {
      return Center(
        child: Text(
          '请先选择班级',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil.withValues(alpha: 0.5),
          ),
        ),
      );
    }

    return Column(
      children: [
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
            tabs: const [
              Tab(text: '我的Vlog'),
              Tab(text: '他人Vlog'),
              Tab(text: '班级史记'),
            ],
          ),
        ),
        Expanded(
          child: TabBarView(
            controller: _tabController,
            children: [
              _buildCalendarView(_myHistory, isPersonal: true),
              _buildCalendarView(_classHistory, isPersonal: false),
              _buildClassHistoryView(),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildCalendarView(Map<String, dynamic> history,
      {required bool isPersonal}) {
    final daysWithHistory = _getDaysWithHistory(history);

    return Column(
      children: [
        HandDrawnCard(
          margin: const EdgeInsets.all(16),
          padding: const EdgeInsets.all(12),
          child: TableCalendar(
            firstDay: DateTime(2020),
            lastDay: DateTime(2030),
            focusedDay: _focusedDay,
            selectedDayPredicate: (day) => isSameDay(_selectedDay, day),
            onDaySelected: (selectedDay, focusedDay) {
              setState(() {
                _selectedDay = selectedDay;
                _focusedDay = focusedDay;
              });
              if (isPersonal) {
                final dateKey =
                    '${selectedDay.year}-${selectedDay.month.toString().padLeft(2, '0')}-${selectedDay.day.toString().padLeft(2, '0')}';
                if (history.containsKey(dateKey)) {
                  _showVlogDetail(dateKey, history[dateKey], isPersonal: true);
                }
              }
            },
            onPageChanged: (focusedDay) {
              _focusedDay = focusedDay;
              _loadHistory(isPersonal ? 'personal' : 'class');
            },
            calendarStyle: CalendarStyle(
              todayDecoration: BoxDecoration(
                color: HandDrawnTheme.postItYellow,
                shape: BoxShape.circle,
                border: Border.all(color: HandDrawnTheme.pencil, width: 2),
              ),
              selectedDecoration: BoxDecoration(
                color: HandDrawnTheme.accent,
                shape: BoxShape.circle,
              ),
              defaultTextStyle: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.pencil,
              ),
              weekendTextStyle: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.accent,
              ),
              outsideTextStyle: TextStyle(
                fontFamily: 'Patrick Hand',
                color: HandDrawnTheme.muted,
              ),
            ),
            headerStyle: HeaderStyle(
              titleTextStyle: TextStyle(
                fontFamily: 'Kalam',
                fontSize: 18,
                fontWeight: FontWeight.w700,
                color: HandDrawnTheme.pencil,
              ),
              formatButtonVisible: false,
              titleCentered: true,
            ),
            daysOfWeekStyle: DaysOfWeekStyle(
              weekdayStyle: TextStyle(
                fontFamily: 'Patrick Hand',
                fontWeight: FontWeight.w700,
                color: HandDrawnTheme.pencil,
              ),
              weekendStyle: TextStyle(
                fontFamily: 'Patrick Hand',
                fontWeight: FontWeight.w700,
                color: HandDrawnTheme.accent,
              ),
            ),
            calendarBuilders: CalendarBuilders(
              defaultBuilder: (context, day, focusedDay) {
                final dateKey =
                    '${day.year}-${day.month.toString().padLeft(2, '0')}-${day.day.toString().padLeft(2, '0')}';
                final hasEntry = history.containsKey(dateKey);
                return Container(
                  margin: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: hasEntry ? HandDrawnTheme.postItYellow : null,
                    shape: BoxShape.circle,
                    border: hasEntry
                        ? Border.all(color: HandDrawnTheme.pencil, width: 1)
                        : null,
                  ),
                  child: Center(
                    child: Text(
                      '${day.day}',
                      style: TextStyle(
                        fontFamily: 'Patrick Hand',
                        color: hasEntry
                            ? HandDrawnTheme.pencil
                            : HandDrawnTheme.pencil.withValues(alpha: 0.3),
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(16),
            child: CircularProgressIndicator(color: HandDrawnTheme.pencil),
          ),
      ],
    );
  }

  Widget _buildClassHistoryView() {
    return _buildCalendarView(_classHistory, isPersonal: false);
  }

  void _showVlogDetail(String date, dynamic entry, {required bool isPersonal}) {
    final content = entry is Map ? (entry['content'] ?? '') : entry.toString();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: HandDrawnTheme.warmPaper,
        shape: RoundedRectangleBorder(
          borderRadius: HandDrawnTheme.wobblyRadiusMd,
          side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
        ),
        title: Text(
          isPersonal ? '我的Vlog - $date' : '班级史记 - $date',
          style: TextStyle(
            fontFamily: 'Kalam',
            fontWeight: FontWeight.w700,
            color: HandDrawnTheme.pencil,
          ),
        ),
        content: SizedBox(
          width: double.maxFinite,
          child: content is String
              ? Text(
                  content,
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    fontSize: 16,
                    color: HandDrawnTheme.pencil,
                  ),
                )
              : Text(
                  content.toString(),
                  style: TextStyle(
                    fontFamily: 'Patrick Hand',
                    fontSize: 16,
                    color: HandDrawnTheme.pencil,
                  ),
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