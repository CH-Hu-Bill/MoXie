class Announcement {
  final String id;
  final String content;
  final String color;
  final bool allowClose;
  final String startTime;
  final String endTime;

  Announcement({
    required this.id,
    required this.content,
    this.color = '#ff4d4d',
    this.allowClose = true,
    this.startTime = '',
    this.endTime = '',
  });

  factory Announcement.fromJson(Map<String, dynamic> json) {
    return Announcement(
      id: json['id'] ?? '',
      content: json['content'] ?? '',
      color: json['color'] ?? '#ff4d4d',
      allowClose: json['allow_close'] == true,
      startTime: json['start_time'] ?? '',
      endTime: json['end_time'] ?? '',
    );
  }
}