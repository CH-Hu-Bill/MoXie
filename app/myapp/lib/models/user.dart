class User {
  final String id;
  final String name;
  final List<String> classIds;
  final String? token;
  final int? expiresAt;
  final bool isNew;
  final bool consent;
  final Map<String, bool> consentMap;

  User({
    required this.id,
    required this.name,
    this.classIds = const [],
    this.token,
    this.expiresAt,
    this.isNew = false,
    this.consent = false,
    this.consentMap = const {},
  });

  factory User.fromJson(Map<String, dynamic> json) {
    return User(
      id: json['user_id'] ?? json['id'] ?? '',
      name: json['name'] ?? '',
      classIds: List<String>.from(json['class_ids'] ?? []),
      token: json['token'],
      expiresAt: json['expires_at'] is int
          ? json['expires_at'] as int
          : int.tryParse(json['expires_at']?.toString() ?? '') ?? null,
      isNew: json['is_new'] ?? false,
      consent: json['consent'] ?? false,
      consentMap: Map<String, bool>.from(
        (json['consent_map'] ?? {}).map((k, v) => MapEntry(k, v == true)),
      ),
    );
  }

  User copyWith({
    String? id,
    String? name,
    List<String>? classIds,
    String? token,
    int? expiresAt,
    bool? isNew,
    bool? consent,
    Map<String, bool>? consentMap,
  }) {
    return User(
      id: id ?? this.id,
      name: name ?? this.name,
      classIds: classIds ?? this.classIds,
      token: token ?? this.token,
      expiresAt: expiresAt ?? this.expiresAt,
      isNew: isNew ?? this.isNew,
      consent: consent ?? this.consent,
      consentMap: consentMap ?? this.consentMap,
    );
  }
}
