class User {
  final String userId;
  final String name;
  final List<String> classIds;
  final String token;
  final DateTime expiresAt;
  final bool isNew;
  final Map<String, bool>? consentMap;

  User({
    required this.userId,
    required this.name,
    required this.classIds,
    required this.token,
    required this.expiresAt,
    this.isNew = false,
    this.consentMap,
  });

  factory User.fromJson(Map<String, dynamic> json) {
    return User(
      userId: json['user_id'] ?? '',
      name: json['name'] ?? '',
      classIds: List<String>.from(json['class_ids'] ?? []),
      token: json['token'] ?? '',
      expiresAt: DateTime.parse(json['expires_at'] ?? DateTime.now().toIso8601String()),
      isNew: json['is_new'] ?? false,
      consentMap: json['consent_map'] != null
          ? Map<String, bool>.from(json['consent_map'])
          : null,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'user_id': userId,
      'name': name,
      'class_ids': classIds,
      'token': token,
      'expires_at': expiresAt.toIso8601String(),
      'is_new': isNew,
      'consent_map': consentMap,
    };
  }

  User copyWith({
    String? userId,
    String? name,
    List<String>? classIds,
    String? token,
    DateTime? expiresAt,
    bool? isNew,
    Map<String, bool>? consentMap,
  }) {
    return User(
      userId: userId ?? this.userId,
      name: name ?? this.name,
      classIds: classIds ?? this.classIds,
      token: token ?? this.token,
      expiresAt: expiresAt ?? this.expiresAt,
      isNew: isNew ?? this.isNew,
      consentMap: consentMap ?? this.consentMap,
    );
  }
}