import 'dart:io';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import '../../models/gallery_item.dart';
import '../../providers/auth_provider.dart';
import '../../services/api_service.dart';
import '../../services/storage_service.dart';
import '../../theme/app_theme.dart';
import '../../widgets/hand_drawn.dart';

class GalleryScreen extends StatefulWidget {
  const GalleryScreen({super.key});

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  static const _maxBytes = 12582912; // 12MB
  static const _allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  static const _allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
  final _api = ApiService();
  final _storage = StorageService();
  List<GalleryItem> _items = [];
  bool _loading = false;
  int _page = 1;
  bool _hasMore = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadGallery());
  }

  Future<void> _loadGallery({bool reset = true}) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    final cached = _storage.getCachedGallery(classId);
    if (cached != null && reset) {
      setState(() {
        _items = cached.map((e) => GalleryItem.fromJson(e)).toList();
      });
    }

    setState(() => _loading = true);
    try {
      final page = reset ? 1 : _page + 1;
      final res = await _api.getGallery(classId, page: page);
      final data = res['data'] as Map<String, dynamic>;
      final items = (data['items'] as List)
          .map((e) => GalleryItem.fromJson(e as Map<String, dynamic>))
          .toList();
      setState(() {
        if (reset) {
          _items = items;
        } else {
          _items.addAll(items);
        }
        _page = page;
        _hasMore = data['has_more'] ?? false;
        _loading = false;
      });
      _storage.cacheGallery(
          classId,
          _items
              .map((e) => {
                    'id': e.id,
                    'image_url': e.imageUrl,
                    'description': e.description,
                    'uploaded_at': e.uploadedAt
                  })
              .toList());
    } catch (e) {
      setState(() => _loading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('加载失败: $e')),
        );
      }
    }
  }

  Future<void> _uploadImage() async {
    final picker = ImagePicker();
    final image = await picker.pickImage(
      source: ImageSource.gallery,
      maxWidth: 1920,
      maxHeight: 1920,
    );
    if (image == null) return;

    final fileSize = await image.length();
    if (fileSize > _maxBytes) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('图片大小不能超过 12MB')),
        );
      }
      return;
    }

    final mimeType = image.mimeType;
    final ext = image.name.split('.').last.toLowerCase();
    final typeOk = mimeType != null && _allowedTypes.contains(mimeType);
    final extOk = _allowedExts.contains(ext);
    if (!typeOk && !extOk) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('仅支持 JPEG、PNG 或 WebP 格式')),
        );
      }
      return;
    }

    final descController = TextEditingController();
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.background,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.border, width: 2),
        ),
        title: Text('添加描述', style: AppTheme.headingStyle),
        content: TextField(
          controller: descController,
          decoration: const InputDecoration(hintText: '描述这张图片...'),
          maxLines: 3,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('取消', style: AppTheme.bodyStyle),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, descController.text.trim()),
            child: Text('上传', style: AppTheme.bodyStyle),
          ),
        ],
      ),
    );

    if (result == null) return;

    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId!;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const LoadingOverlay(message: '上传中...'),
    );

    try {
      await _api.saveGallery(classId, File(image.path), result);
      if (mounted) Navigator.pop(context);
      _loadGallery();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('上传成功')),
        );
      }
    } catch (e) {
      if (mounted) Navigator.pop(context);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('上传失败: $e')),
        );
      }
    }
  }

  void _viewImage(GalleryItem item) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => _GalleryViewerScreen(item: item),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    return Scaffold(
      appBar: AppBar(title: Text(auth.currentClassName ?? '画廊')),
      body: PaperTexture(
        child: _items.isEmpty && _loading
            ? const Center(child: CircularProgressIndicator())
            : _items.isEmpty
                ? const EmptyState(
                    message: '画廊还是空的，上传第一张图片吧',
                    icon: Icons.photo,
                  )
                : NotificationListener<ScrollNotification>(
                    onNotification: (notif) {
                      if (notif is ScrollEndNotification &&
                          notif.metrics.pixels >=
                              notif.metrics.maxScrollExtent - 100 &&
                          _hasMore &&
                          !_loading) {
                        _loadGallery(reset: false);
                      }
                      return false;
                    },
                    child: GridView.builder(
                      padding: const EdgeInsets.all(12),
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 2,
                        crossAxisSpacing: 12,
                        mainAxisSpacing: 12,
                        childAspectRatio: 0.75,
                      ),
                      itemCount: _items.length,
                      itemBuilder: (ctx, i) {
                        final item = _items[i];
                        return HandDrawnCard(
                          padding: EdgeInsets.zero,
                          onTap: () => _viewImage(item),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              Expanded(
                                child: ClipRRect(
                                  borderRadius: const BorderRadius.only(
                                    topLeft: Radius.circular(18),
                                    topRight: Radius.circular(18),
                                  ),
                                  child: Image.network(
                                    item.imageUrl,
                                    fit: BoxFit.cover,
                                    errorBuilder: (_, __, ___) => Container(
                                      color: AppColors.muted,
                                      child: const Center(
                                        child: Icon(Icons.broken_image,
                                            size: 40),
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                              Padding(
                                padding: const EdgeInsets.all(8),
                                child: Text(
                                  item.description,
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                  style:
                                      AppTheme.bodyStyle.copyWith(fontSize: 14),
                                ),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                  ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: _uploadImage,
        backgroundColor: AppColors.accent,
        child: const Icon(Icons.add_a_photo, color: Colors.white),
      ),
    );
  }
}

class _GalleryViewerScreen extends StatelessWidget {
  final GalleryItem item;

  const _GalleryViewerScreen({required this.item});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black87,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        title: Text(
          item.uploadedAt,
          style: AppTheme.bodyStyle.copyWith(fontSize: 16, color: Colors.white),
        ),
        iconTheme: const IconThemeData(color: Colors.white),
      ),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: Center(
                child: InteractiveViewer(
                  child: Container(
                    decoration: BoxDecoration(
                      border: Border.all(color: AppColors.border, width: 3),
                      borderRadius: AppTheme.wobblyRadius,
                    ),
                    child: ClipRRect(
                      borderRadius: AppTheme.wobblyRadius,
                      child: Image.network(
                        item.imageUrl,
                        fit: BoxFit.contain,
                        errorBuilder: (_, __, ___) => const Icon(
                          Icons.broken_image,
                          size: 64,
                          color: AppColors.muted,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
            if (item.description.isNotEmpty)
              Container(
                margin: const EdgeInsets.all(16),
                padding:
                    const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
                decoration: BoxDecoration(
                  color: Colors.black54,
                  borderRadius: AppTheme.wobblyRadius,
                  border: Border.all(color: AppColors.border, width: 2),
                ),
                child: Text(
                  item.description,
                  textAlign: TextAlign.center,
                  style: AppTheme.bodyStyle.copyWith(
                    fontSize: 16,
                    color: Colors.white,
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}
