import 'dart:async';
import 'dart:io';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';
import 'package:visibility_detector/visibility_detector.dart';
import 'package:video_player/video_player.dart';
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
  static const _maxBytes = 12582912; // 静态图 12MB
  static const _gifMaxBytes = 16777216; // GIF 动图 16MB
  static const _mp4MaxBytes = 15728640; // MP4 视频 15MB
  static const _allowedTypes = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/gif'
  ];
  static const _allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
  final _api = ApiService();
  final _storage = StorageService();
  List<GalleryItem> _items = [];
  bool _loading = false;
  int _page = 1;
  bool _hasMore = false;

  Timer? _refreshTimer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _loadGallery());
    _refreshTimer = Timer.periodic(const Duration(seconds: 30), (_) {
      _loadGallery(reset: true);
    });
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadGallery({bool reset = true}) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    final cached = _storage.getCachedGallery(classId);
    final hasCache = cached != null && cached.isNotEmpty;
    if (hasCache && reset) {
      setState(() {
        _items = cached.map((e) => GalleryItem.fromJson(e)).toList();
      });
    }

    // 有可用缓存时先展示，不弹全屏 loading，避免每次进入都等待网络
    if (hasCache) {
      _loadGalleryRemote(classId, reset: reset);
      return;
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
          classId, _items.map((e) => e.toCacheJson()).toList());
    } catch (e) {
      setState(() => _loading = false);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('加载失败: $e')),
        );
      }
    }
  }

  /// 后台静默刷新图集列表（有缓存时使用，不弹 loading，失败静默忽略）
  Future<void> _loadGalleryRemote(String classId, {bool reset = true}) async {
    try {
      final page = reset ? 1 : _page + 1;
      final res = await _api.getGallery(classId, page: page);
      final data = res['data'] as Map<String, dynamic>;
      final items = (data['items'] as List)
          .map((e) => GalleryItem.fromJson(e as Map<String, dynamic>))
          .toList();
      if (!mounted) return;
      setState(() {
        if (reset) {
          _items = items;
        } else {
          final existingIds = {for (final e in _items) e.id};
          for (final e in items) {
            if (!existingIds.contains(e.id)) {
              existingIds.add(e.id);
              _items.add(e);
            }
          }
        }
        _page = page;
        _hasMore = data['has_more'] ?? false;
        _loading = false;
      });
      _storage.cacheGallery(
          classId, _items.map((e) => e.toCacheJson()).toList());
    } catch (_) {
      // 静默刷新失败忽略，保留缓存数据
    }
  }

  void _showUploadMenu() {
    showModalBottomSheet(
      context: context,
      backgroundColor: AppColors.background,
      shape: RoundedRectangleBorder(
        borderRadius: AppTheme.wobblyRadius,
        side: const BorderSide(color: AppColors.border, width: 2),
      ),
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text('上传到画廊', style: AppTheme.headingStyle),
              const SizedBox(height: 16),
              _UploadMenuTile(
                icon: Icons.photo_outlined,
                label: '上传图片（JPG / PNG / WebP / GIF）',
                onTap: () {
                  Navigator.pop(ctx);
                  _pickAndUploadImage();
                },
              ),
              const SizedBox(height: 10),
              _UploadMenuTile(
                icon: Icons.videocam_outlined,
                label: '上传视频（MP4，30秒 / 15MB 以内）',
                onTap: () {
                  Navigator.pop(ctx);
                  _pickAndUploadVideo();
                },
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _pickAndUploadImage() async {
    final picker = ImagePicker();
    // 不限制 maxWidth/maxHeight：避免 image_picker 重新采样导致 GIF 动图丢失动画
    final image = await picker.pickImage(
      source: ImageSource.gallery,
    );
    if (image == null) return;

    final ext = image.name.split('.').last.toLowerCase();
    final isGif = ext == 'gif';
    final fileSize = await image.length();
    final maxBytes = isGif ? _gifMaxBytes : _maxBytes;
    if (fileSize > maxBytes) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
              content: Text(isGif ? 'GIF 动图大小不能超过 16MB' : '图片大小不能超过 12MB')),
        );
      }
      return;
    }

    final mimeType = image.mimeType;
    final typeOk = mimeType != null && _allowedTypes.contains(mimeType);
    final extOk = _allowedExts.contains(ext);
    if (!typeOk && !extOk) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('仅支持 JPEG、PNG、WebP 或 GIF 格式')),
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
    await _uploadFile(File(image.path), result);
  }

  Future<void> _pickAndUploadVideo() async {
    final picker = ImagePicker();
    final video = await picker.pickVideo(
      source: ImageSource.gallery,
      maxDuration: const Duration(seconds: 30),
    );
    if (video == null) return;

    final ext = video.name.split('.').last.toLowerCase();
    if (ext != 'mp4' && ext != 'mov') {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('仅支持 MP4 / MOV 视频格式')),
        );
      }
      return;
    }
    final fileSize = await video.length();
    if (fileSize > _mp4MaxBytes) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('视频大小不能超过 15MB')),
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
          decoration: const InputDecoration(hintText: '描述这段视频...'),
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
    await _uploadFile(File(video.path), result);
  }

  Future<void> _uploadFile(File file, String desc) async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId!;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const LoadingOverlay(message: '上传中...'),
    );

    try {
      await _api.saveGallery(classId, file, desc);
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
        builder: (_) => _GalleryViewerScreen(
          item: item,
          onEditDescription: _editDescription,
        ),
      ),
    );
  }

  Future<void> _editDescription(GalleryItem item) async {
    final controller = TextEditingController(text: item.description);
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.background,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.border, width: 2),
        ),
        title: Text('编辑描述', style: AppTheme.headingStyle),
        content: TextField(
          controller: controller,
          decoration: const InputDecoration(hintText: '描述这张图片...'),
          maxLines: 3,
          maxLength: 500,
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: Text('取消', style: AppTheme.bodyStyle),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, controller.text.trim()),
            child: Text('保存', style: AppTheme.bodyStyle),
          ),
        ],
      ),
    );
    if (result == null || result.isEmpty || result == item.description) return;
    if (!mounted) return;

    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;

    try {
      await _api.updateGalleryDescription(classId, item.id, result);
      if (mounted) {
        setState(() {
          _items = _items
              .map((e) => e.id == item.id
                  ? GalleryItem(
                      id: e.id,
                      imageUrl: e.imageUrl,
                      description: result,
                      uploadedAt: e.uploadedAt,
                      isGif: e.isGif,
                      isVideo: e.isVideo,
                    )
                  : e)
              .toList();
        });
        _storage.cacheGallery(
            classId, _items.map((e) => e.toCacheJson()).toList());
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('描述已更新')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('更新失败: $e')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: PaperTexture(
        child: _items.isEmpty && _loading
            ? const Center(child: CircularProgressIndicator())
            : _items.isEmpty
                ? const EmptyState(
                    message: '画廊还是空的，上传第一张图片吧',
                    icon: Icons.photo,
                  )
                : RefreshIndicator(
                    onRefresh: () => _loadGallery(reset: true),
                    color: AppColors.red,
                    child: NotificationListener<ScrollNotification>(
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
                                    child: item.isVideo
                                        ? _VideoThumbnail(
                                            videoUrl: item.imageUrl)
                                        : item.isGif
                                            ? _GifThumbnail(
                                                imageUrl: item.imageUrl)
                                            : CachedNetworkImage(
                                                imageUrl: item.imageUrl,
                                                fit: BoxFit.cover,
                                                placeholder: (_, __) =>
                                                    Container(
                                                  color: AppColors.oldPaper,
                                                  child: const Center(
                                                    child:
                                                        CircularProgressIndicator(
                                                            color:
                                                                AppColors.red,
                                                            strokeWidth: 2),
                                                  ),
                                                ),
                                                errorWidget: (_, __, ___) =>
                                                    Container(
                                                  color: AppColors.muted,
                                                  child: const Center(
                                                    child: Icon(
                                                        Icons.broken_image,
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
                                    style: AppTheme.bodyStyle
                                        .copyWith(fontSize: 14),
                                  ),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                    ),
                  ),
      ),
      floatingActionButton: FloatingActionButton(
        onPressed: _showUploadMenu,
        backgroundColor: AppColors.accent,
        child: const Icon(Icons.add, color: Colors.white),
      ),
    );
  }
}

class _GalleryViewerScreen extends StatelessWidget {
  final GalleryItem item;
  final void Function(GalleryItem item) onEditDescription;

  const _GalleryViewerScreen(
      {required this.item, required this.onEditDescription});

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
        actions: [
          IconButton(
            tooltip: '编辑描述',
            icon: const Icon(Icons.edit, color: Colors.white),
            onPressed: () => onEditDescription(item),
          ),
        ],
      ),
      body: SafeArea(
        child: Column(
          children: [
            Expanded(
              child: Center(
                child: item.isVideo
                    ? _VideoViewer(videoUrl: item.imageUrl)
                    : InteractiveViewer(
                        child: Container(
                          decoration: BoxDecoration(
                            border:
                                Border.all(color: AppColors.border, width: 3),
                            borderRadius: AppTheme.wobblyRadius,
                          ),
                          child: ClipRRect(
                            borderRadius: AppTheme.wobblyRadius,
                            child: item.isGif
                                ? _GifViewer(imageUrl: item.imageUrl)
                                : CachedNetworkImage(
                                    imageUrl: item.imageUrl,
                                    fit: BoxFit.contain,
                                    placeholder: (_, __) => const Center(
                                      child: CircularProgressIndicator(
                                          color: Colors.white),
                                    ),
                                    errorWidget: (_, __, ___) => const Icon(
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

/// GIF 网格缩略图：进入视口才加载并播放动图，离开视口释放内存（省流量+性能）。
/// 用 flutter_cache_manager 磁盘缓存 GIF 文件，避免反复进出视口重复下载。
class _GifThumbnail extends StatefulWidget {
  final String imageUrl;
  const _GifThumbnail({required this.imageUrl});

  @override
  State<_GifThumbnail> createState() => _GifThumbnailState();
}

class _GifThumbnailState extends State<_GifThumbnail> {
  bool _visible = false;
  File? _cachedFile;

  @override
  void didUpdateWidget(covariant _GifThumbnail oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.imageUrl != widget.imageUrl) {
      _cachedFile = null;
      _visible = false;
    }
  }

  Future<void> _ensureCache() async {
    try {
      final file = await DefaultCacheManager().getSingleFile(widget.imageUrl);
      if (mounted && file.existsSync()) {
        setState(() => _cachedFile = file);
      }
    } catch (_) {
      // 下载失败，交给 errorBuilder 兜底
    }
  }

  @override
  Widget build(BuildContext context) {
    return VisibilityDetector(
      key: Key('gif_thumb_${widget.imageUrl}'),
      onVisibilityChanged: (info) {
        final visible = info.visibleFraction > 0.1;
        if (visible != _visible) {
          setState(() => _visible = visible);
          if (visible && _cachedFile == null) _ensureCache();
        }
      },
      child: _visible
          ? _cachedFile != null
              ? Image.file(
                  _cachedFile!,
                  fit: BoxFit.cover,
                  gaplessPlayback: true,
                  errorBuilder: (_, __, ___) => Container(
                    color: AppColors.muted,
                    child:
                        const Center(child: Icon(Icons.broken_image, size: 40)),
                  ),
                )
              : Container(
                  color: AppColors.oldPaper,
                  child: const Center(
                    child: CircularProgressIndicator(
                        color: AppColors.red, strokeWidth: 2),
                  ),
                )
          : Container(color: AppColors.oldPaper),
    );
  }
}

/// 大图查看 GIF：磁盘缓存后播放动图，支持 InteractiveViewer 缩放。
class _GifViewer extends StatefulWidget {
  final String imageUrl;
  const _GifViewer({required this.imageUrl});

  @override
  State<_GifViewer> createState() => _GifViewerState();
}

class _GifViewerState extends State<_GifViewer> {
  File? _cachedFile;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final file = await DefaultCacheManager().getSingleFile(widget.imageUrl);
      if (mounted && file.existsSync()) {
        setState(() => _cachedFile = file);
      } else {
        setState(() => _failed = true);
      }
    } catch (_) {
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_failed) {
      return const Icon(Icons.broken_image, size: 64, color: AppColors.muted);
    }
    if (_cachedFile == null) {
      return const Center(
        child: CircularProgressIndicator(color: Colors.white),
      );
    }
    return Image.file(
      _cachedFile!,
      fit: BoxFit.contain,
      gaplessPlayback: true,
      errorBuilder: (_, __, ___) => const Icon(
        Icons.broken_image,
        size: 64,
        color: AppColors.muted,
      ),
    );
  }
}

/// 上传菜单项
class _UploadMenuTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  const _UploadMenuTile({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: AppTheme.wobblyRadius,
      child: Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 16),
        decoration: BoxDecoration(
          color: AppColors.white,
          border: Border.all(color: AppColors.border, width: 2),
          borderRadius: AppTheme.wobblyRadius,
          boxShadow: const [
            BoxShadow(color: AppColors.border, offset: Offset(3, 3))
          ],
        ),
        child: Row(
          children: [
            Icon(icon, color: AppColors.pencil, size: 24),
            const SizedBox(width: 12),
            Expanded(
              child:
                  Text(label, style: AppTheme.bodyStyle.copyWith(fontSize: 15)),
            ),
            const Icon(Icons.chevron_right, color: AppColors.muted),
          ],
        ),
      ),
    );
  }
}

/// MP4 网格缩略图：进入视口才初始化视频播放（静音循环），离开视口释放控制器。
class _VideoThumbnail extends StatefulWidget {
  final String videoUrl;
  const _VideoThumbnail({required this.videoUrl});

  @override
  State<_VideoThumbnail> createState() => _VideoThumbnailState();
}

class _VideoThumbnailState extends State<_VideoThumbnail> {
  bool _visible = false;
  VideoPlayerController? _controller;

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _initAndPlay() async {
    if (_controller != null) return;
    final c = VideoPlayerController.networkUrl(Uri.parse(widget.videoUrl));
    _controller = c;
    try {
      await c.initialize();
      if (!mounted) {
        c.dispose();
        return;
      }
      await c.setLooping(true);
      await c.setVolume(0);
      await c.play();
      setState(() {});
    } catch (_) {
      c.dispose();
      if (_controller == c) _controller = null;
    }
  }

  @override
  Widget build(BuildContext context) {
    return VisibilityDetector(
      key: Key('video_thumb_${widget.videoUrl}'),
      onVisibilityChanged: (info) {
        final visible = info.visibleFraction > 0.1;
        if (visible != _visible) {
          _visible = visible;
          if (visible) {
            _initAndPlay();
          } else {
            _controller?.pause();
          }
        }
      },
      child: _controller != null && _controller!.value.isInitialized
          ? Stack(
              fit: StackFit.expand,
              children: [
                FittedBox(
                  fit: BoxFit.cover,
                  clipBehavior: Clip.hardEdge,
                  child: SizedBox(
                    width: _controller!.value.size.width,
                    height: _controller!.value.size.height,
                    child: VideoPlayer(_controller!),
                  ),
                ),
                const Center(
                  child: Icon(Icons.play_circle_outline,
                      color: Colors.white70, size: 40),
                ),
              ],
            )
          : Container(
              color: AppColors.oldPaper,
              child: const Center(
                child: Icon(Icons.videocam, size: 36, color: AppColors.muted),
              ),
            ),
    );
  }
}

/// 大图查看 MP4：完整播放器（播放/暂停/进度/音量开关），静音循环自动播放。
class _VideoViewer extends StatefulWidget {
  final String videoUrl;
  const _VideoViewer({required this.videoUrl});

  @override
  State<_VideoViewer> createState() => _VideoViewerState();
}

class _VideoViewerState extends State<_VideoViewer> {
  VideoPlayerController? _controller;
  bool _soundOn = false;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final c = VideoPlayerController.networkUrl(Uri.parse(widget.videoUrl));
    _controller = c;
    try {
      await c.initialize();
      if (!mounted) {
        c.dispose();
        return;
      }
      await c.setLooping(true);
      await c.setVolume(0);
      await c.play();
      setState(() {});
    } catch (_) {
      c.dispose();
      if (_controller == c) _controller = null;
      setState(() {});
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  void _toggleSound() {
    final c = _controller;
    if (c == null) return;
    final on = !_soundOn;
    setState(() => _soundOn = on);
    c.setVolume(on ? 1 : 0);
    if (on) c.play();
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          decoration: BoxDecoration(
            border: Border.all(color: AppColors.border, width: 3),
            borderRadius: AppTheme.wobblyRadius,
          ),
          clipBehavior: Clip.antiAlias,
          child: c != null && c.value.isInitialized
              ? AspectRatio(
                  aspectRatio: c.value.aspectRatio,
                  child: VideoPlayer(c),
                )
              : const AspectRatio(
                  aspectRatio: 16 / 9,
                  child: Center(
                    child: CircularProgressIndicator(color: Colors.white),
                  ),
                ),
        ),
        const SizedBox(height: 8),
        if (c != null && c.value.isInitialized)
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              IconButton(
                onPressed: () {
                  if (c.value.isPlaying) {
                    c.pause();
                  } else {
                    c.play();
                  }
                  setState(() {});
                },
                icon: Icon(
                  c.value.isPlaying ? Icons.pause_circle : Icons.play_circle,
                  color: Colors.white,
                  size: 34,
                ),
              ),
              IconButton(
                onPressed: _toggleSound,
                icon: Icon(
                  _soundOn ? Icons.volume_up : Icons.volume_off,
                  color: Colors.white,
                  size: 30,
                ),
              ),
            ],
          ),
      ],
    );
  }
}
