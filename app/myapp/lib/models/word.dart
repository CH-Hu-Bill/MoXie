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
}