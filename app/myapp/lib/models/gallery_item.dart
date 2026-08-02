class GalleryItem {
  final String id;
  final String imageUrl;
  final String description;
  final String uploadedAt;

  GalleryItem({
    required this.id,
    required this.imageUrl,
    this.description = '',
    this.uploadedAt = '',
  });

  factory GalleryItem.fromJson(Map<String, dynamic> json) {
    return GalleryItem(
      id: json['id'] ?? '',
      imageUrl: json['image_url'] ?? '',
      description: json['description'] ?? '',
      uploadedAt: json['uploaded_at'] ?? '',
    );
  }
}
