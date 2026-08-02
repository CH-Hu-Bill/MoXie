class ClassInfo {
  final String id;
  final String name;
  final bool hasPassword;

  ClassInfo({
    required this.id,
    required this.name,
    this.hasPassword = false,
  });

  factory ClassInfo.fromJson(Map<String, dynamic> json) {
    return ClassInfo(
      id: json['id'] ?? json['class_id'] ?? '',
      name: json['name'] ?? json['class_name'] ?? '',
      hasPassword: json['has_password'] ?? false,
    );
  }
}
