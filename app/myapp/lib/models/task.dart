class Task {
  final String id;
  final String date;
  final String label;
  final String status;
  final int wordCount;
  final String weekendWeek;
  final String createdAt;
  final List<Word>? words;

  Task({
    required this.id,
    required this.date,
    this.label = '',
    this.status = 'pending',
    this.wordCount = 0,
    this.weekendWeek = '',
    this.createdAt = '',
    this.words,
  });

  factory Task.fromJson(Map<String, dynamic> json) {
    return Task(
      id: json['id'] ?? '',
      date: json['date'] ?? '',
      label: json['label'] ?? '',
      status: json['status'] ?? '',
      wordCount: json['word_count'] ?? 0,
      weekendWeek: json['weekend_week'] ?? '',
      createdAt: json['created_at'] ?? '',
      words: json['words'] != null
          ? (json['words'] as List).map((w) => Word.fromJson(w)).toList()
          : null,
    );
  }
}