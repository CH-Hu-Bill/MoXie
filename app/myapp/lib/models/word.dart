class Word {
  final String id;
  final String word;
  final String meaning;
  final String pos;

  /// 知识库类型：word（单词） / sentence（句子） / essay（作文）
  final String type;

  /// 作文标题（可选），句子/单词为空
  final String title;

  final bool isWrong;
  final bool isFavorite;

  Word({
    required this.id,
    required this.word,
    required this.meaning,
    this.pos = '',
    this.type = 'word',
    this.title = '',
    this.isWrong = false,
    this.isFavorite = false,
  });

  bool get isWord => type == 'word';
  bool get isSentence => type == 'sentence';
  bool get isEssay => type == 'essay';

  factory Word.fromJson(Map<String, dynamic> json) {
    final rawType = (json['type'] ?? 'word').toString();
    return Word(
      id: json['id'] ?? json['word_id'] ?? '',
      word: json['word'] ?? '',
      meaning: json['meaning'] ?? '',
      pos: json['pos'] ?? '',
      type: (rawType == 'sentence' || rawType == 'essay') ? rawType : 'word',
      title: (json['title'] ?? '').toString(),
      isWrong: json['is_wrong'] ?? false,
      isFavorite: json['is_favorite'] ?? false,
    );
  }

  Word copyWith({
    String? id,
    String? word,
    String? meaning,
    String? pos,
    String? type,
    String? title,
    bool? isWrong,
    bool? isFavorite,
  }) {
    return Word(
      id: id ?? this.id,
      word: word ?? this.word,
      meaning: meaning ?? this.meaning,
      pos: pos ?? this.pos,
      type: type ?? this.type,
      title: title ?? this.title,
      isWrong: isWrong ?? this.isWrong,
      isFavorite: isFavorite ?? this.isFavorite,
    );
  }
}
