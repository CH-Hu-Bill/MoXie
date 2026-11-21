class GalleryItem {
  final String id;
  final String imageUrl;
  final String thumbUrl;
  final String description;
  final String uploadedAt;
  final String uploaderName;
  final bool isGif;
  final bool isVideo;
  final bool isLive;
  final String liveVideoUrl;
  final int width;
  final int height;

  GalleryItem({
    required this.id,
    required this.imageUrl,
    this.thumbUrl = '',
    this.description = '',
    this.uploadedAt = '',
    this.uploaderName = '',
    this.isGif = false,
    this.isVideo = false,
    this.isLive = false,
    this.liveVideoUrl = '',
    this.width = 0,
    this.height = 0,
  });

  factory GalleryItem.fromJson(Map<String, dynamic> json) {
    final type = json['type'] ?? '';
    final imageUrl = json['image_url'] ?? '';
    final liveVideoUrl = (json['live_video_url'] ?? '').toString();
    return GalleryItem(
      id: json['id'] ?? '',
      imageUrl: imageUrl,
      thumbUrl: json['thumb_url'] ?? '',
      description: json['description'] ?? '',
      uploadedAt: json['uploaded_at'] ?? '',
      uploaderName: (json['uploader_name'] ?? '').toString(),
      isGif:
          type == 'gif' || (imageUrl as String).toLowerCase().contains('.gif'),
      isVideo:
          type == 'mp4' || (imageUrl as String).toLowerCase().contains('.mp4'),
      isLive: type == 'live' || liveVideoUrl.isNotEmpty,
      liveVideoUrl: liveVideoUrl,
      width: (json['width'] is num) ? (json['width'] as num).toInt() : 0,
      height: (json['height'] is num) ? (json['height'] as num).toInt() : 0,
    );
  }

  Map<String, dynamic> toCacheJson() => {
        'id': id,
        'image_url': imageUrl,
        'thumb_url': thumbUrl,
        'type':
            isLive ? 'live' : (isVideo ? 'mp4' : (isGif ? 'gif' : 'static')),
        'live_video_url': liveVideoUrl,
        'description': description,
        'uploaded_at': uploadedAt,
        'uploader_name': uploaderName,
        'width': width,
        'height': height,
      };
}
