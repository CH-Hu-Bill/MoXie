class GalleryItem {
  final String id;
  final String imageUrl;
  final String description;
  final String uploadedAt;
  final bool isGif;

  GalleryItem({
    required this.id,
    required this.imageUrl,
    this.description = '',
    this.uploadedAt = '',
    this.isGif = false,
  });

  factory GalleryItem.fromJson(Map<String, dynamic> json) {
    final type = json['type'] ?? '';
    final imageUrl = json['image_url'] ?? '';
    return GalleryItem(
      id: json['id'] ?? '',
      imageUrl: imageUrl,
      description: json['description'] ?? '',
      uploadedAt: json['uploaded_at'] ?? '',
      isGif:
          type == 'gif' || (imageUrl as String).toLowerCase().contains('.gif'),
    );
  }

  Map<String, dynamic> toCacheJson() => {
        'id': id,
        'image_url': imageUrl,
        'type': isGif ? 'gif' : 'static',
        'description': description,
        'uploaded_at': uploadedAt,
      };
}
