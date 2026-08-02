class Word {
  final String id;
  final String word;
  final String meaning;
  final String pos;
  final bool isWrong;
  final bool isFavorite;

  Word({
    required this.id,
    required this.word,
    required this.meaning,
    this.pos = '',
    this.isWrong = false,
    this.isFavorite = false,
  });

  factory Word.fromJson(Map<String, dynamic> json) {
    return Word(
      id: json['id'] ?? json['word_id'] ?? '',
      word: json['word'] ?? '',
      meaning: json['meaning'] ?? '',
      pos: json['pos'] ?? '',
      isWrong: json['is_wrong'] ?? false,
      isFavorite: json['is_favorite'] ?? false,
    );
  }

  Word copyWith({
    String? id,
    String? word,
    String? meaning,
    String? pos,
    bool? isWrong,
    bool? isFavorite,
  }) {
    return Word(
      id: id ?? this.id,
      word: word ?? this.word,
      meaning: meaning ?? this.meaning,
      pos: pos ?? this.pos,
      isWrong: isWrong ?? this.isWrong,
      isFavorite: isFavorite ?? this.isFavorite,
    );
  }
}
