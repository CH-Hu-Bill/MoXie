class GalleryItem {
  final String id;
  final String imageUrl;
  final String thumbUrl;
  final String description;
  final String uploadedAt;
  final bool isGif;
  final bool isVideo;

  GalleryItem({
    required this.id,
    required this.imageUrl,
    this.thumbUrl = '',
    this.description = '',
    this.uploadedAt = '',
    this.isGif = false,
    this.isVideo = false,
  });

  factory GalleryItem.fromJson(Map<String, dynamic> json) {
    final type = json['type'] ?? '';
    final imageUrl = json['image_url'] ?? '';
    return GalleryItem(
      id: json['id'] ?? '',
      imageUrl: imageUrl,
      thumbUrl: json['thumb_url'] ?? '',
      description: json['description'] ?? '',
      uploadedAt: json['uploaded_at'] ?? '',
      isGif:
          type == 'gif' || (imageUrl as String).toLowerCase().contains('.gif'),
      isVideo:
          type == 'mp4' || (imageUrl as String).toLowerCase().contains('.mp4'),
    );
  }

  Map<String, dynamic> toCacheJson() => {
        'id': id,
        'image_url': imageUrl,
        'thumb_url': thumbUrl,
        'type': isVideo ? 'mp4' : (isGif ? 'gif' : 'static'),
        'description': description,
        'uploaded_at': uploadedAt,
      };
}
