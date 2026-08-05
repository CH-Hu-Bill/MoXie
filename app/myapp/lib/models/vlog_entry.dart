class VlogEntry {
  final String date;
  final String content;
  final String delta;
  final String title;
  final String mood;
  final String weather;
  final String location;
  final List<String> tags;
  final String updatedAt;

  VlogEntry({
    required this.date,
    this.content = '',
    this.delta = '',
    this.title = '',
    this.mood = '😊',
    this.weather = '☀️',
    this.location = '',
    this.tags = const [],
    this.updatedAt = '',
  });

  factory VlogEntry.fromJson(String dateKey, Map<String, dynamic> json) {
    return VlogEntry(
      date: dateKey,
      content: json['content'] ?? '',
      delta: json['delta'] ?? '',
      title: json['title'] ?? '',
      mood: json['mood'] ?? '😊',
      weather: json['weather'] ?? '☀️',
      location: json['location'] ?? '',
      tags: List<String>.from(json['tags'] ?? []),
      updatedAt: json['updated_at'] ?? '',
    );
  }

  Map<String, dynamic> toFormData() {
    return {
      'date': date,
      'content': content,
      'delta': delta,
      'title': title,
      'mood': mood,
      'weather': weather,
      'location': location,
      'tags': tags,
    };
  }
}

class SearchResult {
  final List<WordMatch> words;
  final List<TaskMatch> pendingTasks;
  final List<TaskMatch> historyTasks;
  final int total;

  SearchResult({
    this.words = const [],
    this.pendingTasks = const [],
    this.historyTasks = const [],
    this.total = 0,
  });

  factory SearchResult.fromJson(Map<String, dynamic> json) {
    return SearchResult(
      words: ((json['words']?['items']) as List<dynamic>?)
              ?.map((w) => WordMatch.fromJson(w as Map<String, dynamic>))
              .toList() ??
          [],
      pendingTasks: ((json['pending_tasks']?['items']) as List<dynamic>?)
              ?.map((t) => TaskMatch.fromJson(t as Map<String, dynamic>))
              .toList() ??
          [],
      historyTasks: ((json['history_tasks']?['items']) as List<dynamic>?)
              ?.map((t) => TaskMatch.fromJson(t as Map<String, dynamic>))
              .toList() ??
          [],
      total: json['total'] ?? 0,
    );
  }
}

class WordMatch {
  final String id;
  final String word;
  final String meaning;
  final String pos;

  WordMatch({
    required this.id,
    required this.word,
    required this.meaning,
    this.pos = '',
  });

  factory WordMatch.fromJson(Map<String, dynamic> json) {
    return WordMatch(
      id: json['id'] ?? '',
      word: json['word'] ?? '',
      meaning: json['meaning'] ?? '',
      pos: json['pos'] ?? '',
    );
  }
}

class TaskMatch {
  final String id;
  final String date;
  final String label;
  final String status;
  final List<WordMatch> matchedWords;

  TaskMatch({
    required this.id,
    required this.date,
    this.label = '',
    this.status = '',
    this.matchedWords = const [],
  });

  factory TaskMatch.fromJson(Map<String, dynamic> json) {
    return TaskMatch(
      id: json['id'] ?? '',
      date: json['date'] ?? '',
      label: json['label'] ?? '',
      status: json['status'] ?? '',
      matchedWords: (json['matched_words'] as List<dynamic>?)
              ?.map((w) => WordMatch.fromJson(w as Map<String, dynamic>))
              .toList() ??
          [],
    );
  }
}
