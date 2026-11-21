import 'dart:async';
import 'dart:io';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';
import 'package:flutter/material.dart';
import 'package:flutter_staggered_grid_view/flutter_staggered_grid_view.dart';
import 'package:motion_photos/motion_photos.dart';
import 'package:path_provider/path_provider.dart';
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
  /// 从全局搜索跳转时传入：加载完成后自动定位这条图集
  final String? openItemId;

  /// 通知外层顶部栏图集总数（放在班级名后面）
  final ValueChanged<int>? onCountChanged;

  const GalleryScreen({super.key, this.openItemId, this.onCountChanged});

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  static const _allowedExts = [
    'jpg',
    'jpeg',
    'png',
    'webp',
    'gif',
    'mp4',
    'mov'
  ];
  final _api = ApiService();
  final _storage = StorageService();
  List<GalleryItem> _items = [];
  bool _loading = false;
  int _page = 1;
  bool _hasMore = false;

  Timer? _refreshTimer;
  String _galleryRev = ''; // 图集区块指纹：只有变化时才重新拉取
  final _gridController = ScrollController();
  final Map<String, GlobalKey> _cardKeys = {};
  String? _highlightId;
  String? _focusLoadingId;
  int _focusLoadPages = 0;
  bool _firstLoadDone = false;
  bool _remoteLoadDone = false;

  // 排序（搜索统一走主界面搜索，这里只保留排序）
  String _order = 'desc'; // desc=最新在前

  /// 排序后的展示列表
  List<GalleryItem> get _shownItems {
    final list = _items.toList();
    list.sort((a, b) => _order == 'asc'
        ? a.uploadedAt.compareTo(b.uploadedAt)
        : b.uploadedAt.compareTo(a.uploadedAt));
    return list;
  }

  Widget _buildSortButton() {
    return OutlinedButton.icon(
      onPressed: () =>
          setState(() => _order = _order == 'desc' ? 'asc' : 'desc'),
      icon: Icon(
        _order == 'desc' ? Icons.south_rounded : Icons.north_rounded,
        size: 16,
      ),
      label: Text(
        _order == 'desc' ? '最新' : '最早',
        style: AppTheme.bodyStyle.copyWith(
          fontSize: 13,
          color: AppColors.pencil,
        ),
      ),
      style: OutlinedButton.styleFrom(
        side: const BorderSide(color: AppColors.pencil, width: 2),
        shape: RoundedRectangleBorder(borderRadius: AppTheme.wobblySm),
        foregroundColor: AppColors.pencil,
        backgroundColor: AppColors.white,
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 12),
      ),
    );
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await _loadGallery();
      if (mounted) setState(() => _firstLoadDone = true);
    });
    // S3：10 秒轮询指纹，图集没变就不动列表（不再整表替换导致卡片闪动/滚动跳）
    _refreshTimer = Timer.periodic(const Duration(seconds: 10), (_) {
      _pollRev();
    });
  }

  Future<void> _pollRev() async {
    final auth = context.read<AuthProvider>();
    final classId = auth.currentClassId;
    if (classId == null || classId.isEmpty) return;
    try {
      final res = await _api.getRev(classId);
      if (!mounted) return;
      final data = res['data'];
      final rev = (data is Map ? data['rev'] : null) as Map?;
      final galleryRev = (rev?['gallery'] ?? '').toString();
      if (galleryRev.isEmpty) return;
      if (_galleryRev.isEmpty) {
        _galleryRev = galleryRev;
        // 首次拿到指纹（可能是从连续失败中恢复）：同步一次，避免停在旧列表
        _loadGalleryRemote(classId, reset: true);
        return;
      }
      if (galleryRev == _galleryRev) return;
      _galleryRev = galleryRev;
      _loadGalleryRemote(classId, reset: true);
    } catch (_) {}
  }

  @override
  void dispose() {
    _refreshTimer?.cancel();
    _gridController.dispose();
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
      _notifyCount();
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
        _remoteLoadDone = true;
      });
      _notifyCount();
      _storage.cacheGallery(
        classId,
        _items.map((e) => e.toCacheJson()).toList(),
      );
    } catch (e) {
      setState(() {
        _loading = false;
        _remoteLoadDone = true;
      });
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('加载失败: $e')));
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
          _items = _mergeItems(items);
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
        _remoteLoadDone = true;
      });
      _notifyCount();
      _storage.cacheGallery(
        classId,
        _items.map((e) => e.toCacheJson()).toList(),
      );
    } catch (_) {
      // 静默刷新失败忽略，保留缓存数据
      if (mounted) setState(() => _remoteLoadDone = true);
    }
  }

  /// 刷新合并（S3）：按 id 复用未变化的旧对象，避免整表替换造成卡片重建/滚动跳动。
  /// 服务端顺序是「新的在前」，直接沿用；已加载的后续分页保持原顺序附在末尾。
  List<GalleryItem> _mergeItems(List<GalleryItem> incoming) {
    final oldById = {for (final e in _items) e.id: e};
    final merged = <GalleryItem>[];
    for (final item in incoming) {
      final old = oldById.remove(item.id);
      merged.add(old != null && _sameGalleryItem(old, item) ? old : item);
    }
    // 只加载过一页时以服务端为准（删掉的条目要消失）；多页时才保留没重拉的后续页
    if (_page > 1) merged.addAll(oldById.values);
    return merged;
  }

  static bool _sameGalleryItem(GalleryItem a, GalleryItem b) {
    return a.id == b.id &&
        a.imageUrl == b.imageUrl &&
        a.thumbUrl == b.thumbUrl &&
        a.description == b.description &&
        a.uploadedAt == b.uploadedAt &&
        a.isVideo == b.isVideo &&
        a.isGif == b.isGif &&
        a.isLive == b.isLive &&
        a.liveVideoUrl == b.liveVideoUrl &&
        a.width == b.width &&
        a.height == b.height;
  }

  Future<void> _pickAndUploadMedia() async {
    FilePickerResult? result;
    try {
      result = await FilePicker.platform.pickFiles(
        type: FileType.media,
        allowMultiple: true,
      );
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('打开文件选择器失败，请重试')),
        );
      }
      return;
    }
    if (result == null) return;
    final pending = <_GalleryPendingFile>[];
    for (final f in result.files) {
      final path = f.path;
      if (path == null) continue;
      final ext = f.name.split('.').last.toLowerCase();
      if (!_allowedExts.contains(ext)) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('已跳过不支持的文件：${f.name}')),
          );
        }
        continue;
      }
      final isVideo = ext == 'mp4' || ext == 'mov';
      final isGif = ext == 'gif';
      File? liveVideo;
      if (!isVideo && !isGif) {
        liveVideo = await _extractLiveVideo(path);
      }
      pending.add(_GalleryPendingFile(
        file: File(path),
        name: f.name,
        isVideo: isVideo,
        isGif: isGif,
        liveVideo: liveVideo,
      ));
    }
    if (pending.isEmpty || !mounted) return;
    final uploaded = await Navigator.push<bool>(
      context,
      MaterialPageRoute(
        builder: (_) => _GalleryUploadReviewPage(files: pending),
      ),
    );
    if (uploaded == true) {
      _loadGallery(reset: true);
    }
  }

  Future<File?> _extractLiveVideo(String path) async {
    try {
      final motion = MotionPhotos(path);
      if (!await motion.isMotionPhoto()) return null;
      final dir = await getTemporaryDirectory();
      return await motion.getMotionVideoFile(
        dir,
        fileName: 'lw_live_${DateTime.now().millisecondsSinceEpoch}.mp4',
      );
    } catch (_) {
      return null;
    }
  }

  void _viewImage(GalleryItem item) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => _GalleryViewerScreen(
          item: item,
          onEditDescription: _editDescription,
          onDelete: _deleteItem,
        ),
      ),
    );
  }

  /// 删除：先确认，再调接口（服务端进回收站，7 天内可在网页端恢复）
  Future<void> _deleteItem(GalleryItem item) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.background,
        shape: RoundedRectangleBorder(
          borderRadius: AppTheme.wobblyRadius,
          side: const BorderSide(color: AppColors.border, width: 2),
        ),
        title: Text('删除这一项？', style: AppTheme.headingStyle),
        content: Text(
          '删除后会进入回收站，7 天内可以在网页端恢复。',
          style: AppTheme.bodyStyle.copyWith(fontSize: 14, height: 1.6),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: Text('取消', style: AppTheme.bodyStyle),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              '删除',
              style: AppTheme.bodyStyle.copyWith(color: AppColors.red),
            ),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    final classId = context.read<AuthProvider>().currentClassId;
    if (classId == null || classId.isEmpty) return;
    try {
      await _api.deleteGallery(classId, item.id);
      if (!mounted) return;
      setState(() => _items.removeWhere((e) => e.id == item.id));
      _notifyCount();
      _storage.cacheGallery(
        classId,
        _items.map((e) => e.toCacheJson()).toList(),
      );
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('已移入回收站，7 天内可在网页端恢复')),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('删除失败: $e')));
    }
  }

  Future<void> _editDescription(GalleryItem item) async {
    final controller = TextEditingController(text: item.description);
    final result = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        scrollable: true,
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
              .map(
                (e) => e.id == item.id
                    ? GalleryItem(
                        id: e.id,
                        imageUrl: e.imageUrl,
                        thumbUrl: e.thumbUrl,
                        description: result,
                        uploadedAt: e.uploadedAt,
                        uploaderName: e.uploaderName,
                        isGif: e.isGif,
                        isVideo: e.isVideo,
                        isLive: e.isLive,
                        liveVideoUrl: e.liveVideoUrl,
                        width: e.width,
                        height: e.height,
                      )
                    : e,
              )
              .toList();
        });
        _storage.cacheGallery(
          classId,
          _items.map((e) => e.toCacheJson()).toList(),
        );
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('描述已更新')));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text('更新失败: $e')));
      }
    }
  }

  void _notifyCount() {
    widget.onCountChanged?.call(_items.length);
  }

  /// 瀑布流卡片比例：服务端有真实宽高就用真实比例，旧数据回退到 4:3 / 16:9。
  double _itemAspect(GalleryItem item) {
    if (item.width > 0 && item.height > 0) {
      return (item.width / item.height).clamp(0.6, 1.8);
    }
    return item.isVideo ? 16 / 9 : 4 / 3;
  }

  String? _openedInitialId;
  GlobalKey _cardKey(String id) => _cardKeys.putIfAbsent(id, () => GlobalKey());

  /// 从全局搜索跳转进来：自动定位并高亮目标卡片，不直接打开详情。
  void _maybeOpenInitial() {
    final id = widget.openItemId;
    if (id == null || id.isEmpty || _openedInitialId == id) return;
    if (!_firstLoadDone || !_remoteLoadDone) return;
    final idx = _items.indexWhere((e) => e.id == id);
    if (idx < 0) {
      if (_hasMore && _focusLoadingId != id && _focusLoadPages < 20) {
        _focusLoadingId = id;
        _focusLoadPages++;
        _loadGallery(reset: false).whenComplete(() {
          if (mounted && _focusLoadingId == id) _focusLoadingId = null;
        });
      } else if (!_hasMore && _focusLoadingId != '__not_found__') {
        _focusLoadingId = '__not_found__';
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('这条图集已不存在或已被删除')),
        );
      }
      return;
    }

    _openedInitialId = id;
    setState(() => _highlightId = id);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final offset = (idx / 2) * 220.0;
      if (_gridController.hasClients) {
        final max = _gridController.position.maxScrollExtent;
        _gridController.jumpTo(offset.clamp(0.0, max).toDouble());
      }
      Future.delayed(const Duration(milliseconds: 120), () {
        if (!mounted) return;
        final ctx = _cardKey(id).currentContext;
        if (ctx != null) {
          Scrollable.ensureVisible(
            ctx,
            duration: const Duration(milliseconds: 300),
            alignment: 0.35,
          );
        }
      });
      Future.delayed(const Duration(seconds: 3), () {
        if (mounted && _highlightId == id) setState(() => _highlightId = null);
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    _maybeOpenInitial();
    return Scaffold(
      body: PaperTexture(
        child: _items.isEmpty && _loading
            ? const Center(child: CircularProgressIndicator())
            : _items.isEmpty
                ? const EmptyState(
                    message: '画廊还是空的，上传第一张图片吧', icon: Icons.photo)
                : Column(
                    children: [
                      Expanded(
                        child: RefreshIndicator(
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
                            child: MasonryGridView.count(
                              controller: _gridController,
                              padding: const EdgeInsets.all(12),
                              crossAxisCount: 2,
                              crossAxisSpacing: 12,
                              mainAxisSpacing: 12,
                              itemCount: _shownItems.length,
                              itemBuilder: (ctx, i) {
                                final item = _shownItems[i];
                                return HandDrawnCard(
                                  key: _cardKey(item.id),
                                  padding: EdgeInsets.zero,
                                  borderColor: _highlightId == item.id
                                      ? AppColors.blue
                                      : null,
                                  borderWidth: _highlightId == item.id ? 3 : 2,
                                  onTap: () => _viewImage(item),
                                  child: Column(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.stretch,
                                    children: [
                                      AspectRatio(
                                        aspectRatio: _itemAspect(item),
                                        child: Stack(
                                          fit: StackFit.expand,
                                          children: [
                                            ClipRRect(
                                              borderRadius:
                                                  const BorderRadius.only(
                                                topLeft: Radius.circular(18),
                                                topRight: Radius.circular(18),
                                              ),
                                              child: item.isVideo
                                                  ? (item.thumbUrl.isNotEmpty
                                                      ? _VideoThumbView(
                                                          thumbUrl:
                                                              item.thumbUrl,
                                                          videoUrl:
                                                              item.imageUrl,
                                                        )
                                                      : _VideoThumbnail(
                                                          videoUrl:
                                                              item.imageUrl,
                                                        ))
                                                  : item.isGif
                                                      ? _GifThumbnail(
                                                          imageUrl:
                                                              item.imageUrl)
                                                      : CachedNetworkImage(
                                                          imageUrl:
                                                              item.imageUrl,
                                                          fit: BoxFit.cover,
                                                          placeholder:
                                                              (_, __) =>
                                                                  Container(
                                                            color: AppColors
                                                                .oldPaper,
                                                            child: const Center(
                                                              child:
                                                                  CircularProgressIndicator(
                                                                color: AppColors
                                                                    .red,
                                                                strokeWidth: 2,
                                                              ),
                                                            ),
                                                          ),
                                                          errorWidget:
                                                              (_, __, ___) =>
                                                                  Container(
                                                            color:
                                                                AppColors.muted,
                                                            child: const Center(
                                                              child: Icon(
                                                                Icons
                                                                    .broken_image,
                                                                size: 40,
                                                              ),
                                                            ),
                                                          ),
                                                        ),
                                            ),
                                            if (item.isLive)
                                              Positioned(
                                                top: 8,
                                                right: 8,
                                                child: _LiveBadge(),
                                              ),
                                          ],
                                        ),
                                      ),
                                      Padding(
                                        padding: const EdgeInsets.fromLTRB(
                                            8, 8, 8, 8),
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item.description.isEmpty
                                                  ? '未填写描述'
                                                  : item.description,
                                              maxLines: 3,
                                              overflow: TextOverflow.ellipsis,
                                              style:
                                                  AppTheme.bodyStyle.copyWith(
                                                fontSize: 14,
                                              ),
                                            ),
                                            if (item.uploaderName.isNotEmpty)
                                              Padding(
                                                padding: const EdgeInsets.only(
                                                    top: 2),
                                                child: Text(
                                                  item.uploaderName,
                                                  maxLines: 1,
                                                  overflow:
                                                      TextOverflow.ellipsis,
                                                  style: AppTheme.bodyStyle
                                                      .copyWith(
                                                    fontSize: 11,
                                                    color: AppColors.muted,
                                                  ),
                                                ),
                                              ),
                                          ],
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
                    ],
                  ),
      ),
      floatingActionButton: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          _buildSortButton(),
          const SizedBox(height: 10),
          FloatingActionButton(
            onPressed: _pickAndUploadMedia,
            backgroundColor: AppColors.accent,
            child: const Icon(Icons.add, color: Colors.white),
          ),
        ],
      ),
    );
  }
}

class _LiveBadge extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: AppColors.blue,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.white, width: 1.5),
      ),
      child: const Text(
        '实况',
        style: TextStyle(
          color: Colors.white,
          fontSize: 11,
          fontWeight: FontWeight.bold,
        ),
      ),
    );
  }
}

class _LiveViewer extends StatelessWidget {
  final GalleryItem item;
  const _LiveViewer({required this.item});

  @override
  Widget build(BuildContext context) {
    // 没有实况视频（提取失败/旧数据）：按普通图片处理
    if (item.liveVideoUrl.isEmpty) {
      return InteractiveViewer(
        child: CachedNetworkImage(
          imageUrl: item.imageUrl,
          fit: BoxFit.contain,
          placeholder: (_, __) => const Center(
            child: CircularProgressIndicator(color: Colors.white),
          ),
          errorWidget: (_, __, ___) => const Icon(
            Icons.broken_image,
            size: 64,
            color: AppColors.muted,
          ),
        ),
      );
    }
    // 打开实况卡片即自动播放：静态封面先占位，视频首帧出现后淡出，避免黑屏闪一下
    return _VideoViewer(
      videoUrl: item.liveVideoUrl,
      posterUrl: item.imageUrl,
      live: true,
    );
  }
}

class _GalleryViewerScreen extends StatelessWidget {
  final GalleryItem item;
  final void Function(GalleryItem item) onEditDescription;
  final Future<void> Function(GalleryItem item) onDelete;

  const _GalleryViewerScreen({
    required this.item,
    required this.onEditDescription,
    required this.onDelete,
  });

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
          IconButton(
            tooltip: '删除',
            icon: const Icon(Icons.delete_outline, color: Colors.white),
            onPressed: () async {
              final nav = Navigator.of(context);
              await onDelete(item);
              if (nav.canPop()) nav.pop();
            },
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
                    : item.isLive
                        ? _LiveViewer(item: item)
                        : InteractiveViewer(
                            child: Container(
                              decoration: BoxDecoration(
                                border: Border.all(
                                  color: AppColors.border,
                                  width: 3,
                                ),
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
                                            color: Colors.white,
                                          ),
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
                decoration: BoxDecoration(
                  color: Colors.black54,
                  borderRadius: AppTheme.wobblyRadius,
                  border: Border.all(color: AppColors.border, width: 2),
                ),
                // 描述过长时在固定区域内自动滚动；用户可拖动打断，2 秒后恢复
                child: AutoScrollText(
                  text: item.description,
                  style: AppTheme.bodyStyle.copyWith(
                    fontSize: 16,
                    color: Colors.white,
                  ),
                  height: 70,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 20,
                    vertical: 13,
                  ),
                  fadeColor: Colors.black,
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
                    child: const Center(
                      child: Icon(Icons.broken_image, size: 40),
                    ),
                  ),
                )
              : Container(
                  color: AppColors.oldPaper,
                  child: const Center(
                    child: CircularProgressIndicator(
                      color: AppColors.red,
                      strokeWidth: 2,
                    ),
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
      errorBuilder: (_, __, ___) =>
          const Icon(Icons.broken_image, size: 64, color: AppColors.muted),
    );
  }
}

/// 上传菜单项
class _VideoThumbPool {
  static _VideoThumbnailState? _active;

  static void activate(_VideoThumbnailState s) {
    final prev = _active;
    if (prev != null && prev != s && prev.mounted) {
      prev._releaseNow();
    }
    _active = s;
  }

  static void release(_VideoThumbnailState s) {
    if (_active == s) _active = null;
  }

  /// 释放当前活跃的缩略图（大图播放器打开时调用，避免双解码器竞争）
  static void stopAll() {
    final prev = _active;
    _active = null;
    if (prev != null && prev.mounted) {
      prev._releaseNow();
    }
  }
}

/// 视频缩略图表面：cover 适配，尺寸为 0 时兜底用 AspectRatio（避免 SizedBox 0x0 黑屏）
class _VideoThumbSurface extends StatelessWidget {
  final VideoPlayerController controller;
  const _VideoThumbSurface({required this.controller});

  @override
  Widget build(BuildContext context) {
    final size = controller.value.size;
    if (size.width > 0 && size.height > 0) {
      return FittedBox(
        fit: BoxFit.cover,
        clipBehavior: Clip.hardEdge,
        child: SizedBox(
          width: size.width,
          height: size.height,
          child: VideoPlayer(controller),
        ),
      );
    }
    // 尺寸未知：用宽高比兜底，保证有画面
    return Center(
      child: AspectRatio(
        aspectRatio: controller.value.aspectRatio > 0
            ? controller.value.aspectRatio
            : 16 / 9,
        child: VideoPlayer(controller),
      ),
    );
  }
}

/// MP4 视频卡片缩略图：显示 ffmpeg 生成的视频首帧图（cached_network_image 缓存，秒开）。
/// 与图片卡片无感知差别，但叠加"🎬 视频"角标，明确告诉用户点进去才是真视频。
/// 网格中不再内嵌播放解码器（这是此前缩略图黑屏/卡顿的主要来源）。
class _VideoThumbView extends StatelessWidget {
  final String thumbUrl;

  /// 首帧缩略图接口返回 404（CLI 计划任务还没生成）时的兜底：
  /// 直接用视频现场抽帧，保证卡片始终是“第一帧画面”而不是灰块图标。
  final String videoUrl;

  const _VideoThumbView({required this.thumbUrl, required this.videoUrl});

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        CachedNetworkImage(
          imageUrl: thumbUrl,
          fit: BoxFit.cover,
          placeholder: (_, __) => Container(
            color: AppColors.oldPaper,
            child: const Center(
              child: CircularProgressIndicator(
                color: AppColors.red,
                strokeWidth: 2,
              ),
            ),
          ),
          // 网格里只显示静态首帧；视频/实况只在点击进入查看器后播放。
          errorWidget: (_, __, ___) => Container(
            color: AppColors.muted,
            child: const Center(
              child: Icon(Icons.videocam, size: 40, color: Colors.white70),
            ),
          ),
        ),
        // 手绘风格"视频"贴纸：白纸卡片 + 抖动圆角 + 硬偏移阴影 + 红色播放三角 + 手写标题
        Positioned.fill(
          child: Center(
            child: Transform.rotate(
              angle: -0.04,
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 14,
                  vertical: 7,
                ),
                decoration: BoxDecoration(
                  color: AppColors.white,
                  borderRadius: AppTheme.wobblySm,
                  border: Border.all(color: AppColors.pencil, width: 2.5),
                  boxShadow: AppTheme.hardShadowSm,
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(
                      Icons.play_arrow_rounded,
                      color: AppColors.red,
                      size: 26,
                    ),
                    const SizedBox(width: 5),
                    Text(
                      '视频',
                      style: TextStyle(
                        fontFamily: AppTheme.fontHeading,
                        fontSize: 16,
                        color: AppColors.pencil,
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}

/// MP4 网格缩略图：进入视口才初始化视频播放（静音循环），离开视口彻底释放解码器。
/// 通过全局单例保证同时只有一个视频在解码，避免多实例竞争导致卡顿/失败。
/// （无 thumb_url 的历史视频才走此降级路径；新视频卡片直接用 _VideoThumbView 首帧图）
class _VideoThumbnail extends StatefulWidget {
  final String videoUrl;
  const _VideoThumbnail({required this.videoUrl});

  @override
  State<_VideoThumbnail> createState() => _VideoThumbnailState();
}

class _VideoThumbnailState extends State<_VideoThumbnail> {
  bool _visible = false;
  VideoPlayerController? _controller;
  bool _disposed = false;

  @override
  void dispose() {
    _disposed = true;
    _VideoThumbPool.release(this);
    _controller?.dispose();
    _controller = null;
    super.dispose();
  }

  /// 立即释放控制器（被单例停掉时调用）
  void _releaseNow() {
    if (!mounted || _disposed) return;
    final c = _controller;
    _controller = null;
    c?.dispose();
    if (mounted) setState(() {});
  }

  Future<void> _initAndPlay() async {
    if (_controller != null) return;
    // 全局单例：停掉正在播放的其他缩略图
    _VideoThumbPool.activate(this);
    if (!mounted || _disposed) return;

    // 优先网络流式播放（服务器支持 HEAD/Range，秒开、边下边播），
    // 不整文件下载到本地再播（15MB 下载慢易失败导致一直转圈）
    VideoPlayerController? c;
    try {
      c = VideoPlayerController.networkUrl(Uri.parse(widget.videoUrl));
      _controller = c;
      await c.initialize();
      if (!mounted || _disposed) {
        c.dispose();
        if (_controller == c) _controller = null;
        return;
      }
      await c.setLooping(true);
      await c.setVolume(0);
      await c.play();
      setState(() {});
      // 预热磁盘缓存：预览流式播放时后台把整文件写入缓存，
      // 用户点开灯箱时缓存命中 → 本地播放秒开（服务器带宽小，避免灯箱重新拉 15MB）
      DefaultCacheManager()
          .getSingleFile(widget.videoUrl)
          .then((_) {})
          .catchError((_) {});
      return;
    } catch (_) {
      // 网络播放失败：兜底用磁盘缓存文件
      if (c != null) c.dispose();
      if (_controller == c) _controller = null;
    }

    File? cached;
    try {
      cached = await DefaultCacheManager().getSingleFile(widget.videoUrl);
    } catch (_) {
      cached = null;
    }
    if (!mounted || _disposed) return;
    if (cached == null || !cached.existsSync()) {
      if (mounted) setState(() {});
      return;
    }
    final cf = VideoPlayerController.file(cached);
    _controller = cf;
    try {
      await cf.initialize();
      if (!mounted || _disposed) {
        cf.dispose();
        if (_controller == cf) _controller = null;
        return;
      }
      await cf.setLooping(true);
      await cf.setVolume(0);
      await cf.play();
      setState(() {});
    } catch (_) {
      cf.dispose();
      if (_controller == cf) _controller = null;
      if (mounted) setState(() {});
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
            // 离开视口：彻底释放解码器，避免资源堆积
            _releaseNow();
            _VideoThumbPool.release(this);
          }
        }
      },
      child: _controller != null && _controller!.value.isInitialized
          ? Stack(
              fit: StackFit.expand,
              children: [
                _VideoThumbSurface(controller: _controller!),
                const Center(
                  child: Icon(
                    Icons.play_circle_outline,
                    color: Colors.white70,
                    size: 40,
                  ),
                ),
              ],
            )
          : Container(
              color: AppColors.oldPaper,
              child: const Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    SizedBox(
                      width: 22,
                      height: 22,
                      child: CircularProgressIndicator(
                        color: AppColors.red,
                        strokeWidth: 2,
                      ),
                    ),
                    SizedBox(height: 6),
                    Text(
                      '加载中…',
                      style: TextStyle(fontSize: 11, color: AppColors.muted),
                    ),
                  ],
                ),
              ),
            ),
    );
  }
}

/// 大图查看 MP4：完整播放器（播放/暂停/进度/音量开关），静音循环自动播放。
class _VideoViewer extends StatefulWidget {
  final String videoUrl;

  /// 实况照片用：先显示静态封面，视频出首帧后再淡出，避免黑屏闪一下
  final String? posterUrl;

  /// 实况样式：显示“实况”角标，点击画面可暂停/继续
  final bool live;

  const _VideoViewer({
    required this.videoUrl,
    this.posterUrl,
    this.live = false,
  });

  @override
  State<_VideoViewer> createState() => _VideoViewerState();
}

class _VideoViewerState extends State<_VideoViewer> {
  VideoPlayerController? _controller;
  bool _soundOn = false;
  bool _started = false; // 视频已出首帧：用于淡出封面

  @override
  void initState() {
    super.initState();
    // 大图播放时停掉缩略图的解码器，避免双实例竞争
    _VideoThumbPool.stopAll();
    _init();
  }

  void _onControllerUpdate() {
    if (_started || !mounted) return;
    final c = _controller;
    if (c == null) return;
    final v = c.value;
    if (v.isInitialized && (v.isPlaying || v.position > Duration.zero)) {
      setState(() => _started = true);
    }
  }

  void _togglePlay() {
    final c = _controller;
    if (c == null || !c.value.isInitialized) return;
    if (c.value.isPlaying) {
      c.pause();
    } else {
      c.play();
    }
    setState(() {});
  }

  Future<void> _init() async {
    if (!mounted) return;
    // 1) 缓存命中：直接播放本地缓存文件（秒开、省流量、离线可用）
    try {
      final hit = await DefaultCacheManager().getFileFromCache(widget.videoUrl);
      if (hit != null && hit.file.existsSync() && mounted) {
        final cf = VideoPlayerController.file(hit.file);
        _controller = cf;
        cf.addListener(_onControllerUpdate);
        await cf.initialize();
        if (!mounted) {
          cf.dispose();
          return;
        }
        await cf.setLooping(true);
        await cf.setVolume(0);
        await cf.play();
        setState(() {});
        return;
      }
    } catch (_) {
      /* 无缓存，走网络流式 */
    }

    // 2) 未缓存：网络流式播放（秒开、边下边播），同时后台把整文件写入磁盘缓存，
    //    下次打开同一视频直接从缓存播放。
    VideoPlayerController? c;
    try {
      c = VideoPlayerController.networkUrl(Uri.parse(widget.videoUrl));
      _controller = c;
      c.addListener(_onControllerUpdate);
      await c.initialize();
      if (!mounted) {
        c.dispose();
        return;
      }
      await c.setLooping(true);
      await c.setVolume(0);
      await c.play();
      setState(() {});
      DefaultCacheManager()
          .getSingleFile(widget.videoUrl)
          .then((_) {})
          .catchError((_) {});
      return;
    } catch (_) {
      if (c != null) c.dispose();
      if (_controller == c) _controller = null;
    }

    if (!mounted) return;
    setState(() {});
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
    final initialized = c != null && c.value.isInitialized;
    final poster = widget.posterUrl ?? '';
    final showPoster = poster.isNotEmpty && !_started;
    return Container(
      decoration: BoxDecoration(
        border: Border.all(color: AppColors.border, width: 3),
        borderRadius: AppTheme.wobblyRadius,
      ),
      clipBehavior: Clip.antiAlias,
      child: initialized
          ? GestureDetector(
              onTap: widget.live ? _togglePlay : null,
              behavior: HitTestBehavior.opaque,
              child: Stack(
                alignment: Alignment.center,
                children: [
                  AspectRatio(
                    aspectRatio: c.value.aspectRatio,
                    child: VideoPlayer(c),
                  ),
                  // 实况封面：首帧出现前盖在视频上，出帧后立即移除（不闪黑）
                  if (showPoster)
                    Positioned.fill(
                      child: IgnorePointer(
                        child: CachedNetworkImage(
                          imageUrl: poster,
                          fit: BoxFit.contain,
                          placeholder: (_, __) => const SizedBox.shrink(),
                          errorWidget: (_, __, ___) => const SizedBox.shrink(),
                        ),
                      ),
                    ),
                  if (widget.live)
                    Positioned(top: 10, left: 10, child: _LiveBadge()),
                  // 底部悬浮控制条：播放/暂停 + 声音开关（点击事件不会被视频吞掉）
                  Positioned(
                    left: 0,
                    right: 0,
                    bottom: 0,
                    child: Container(
                      color: Colors.black45,
                      padding: const EdgeInsets.symmetric(
                        vertical: 4,
                        horizontal: 8,
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          IconButton(
                            onPressed: _togglePlay,
                            icon: Icon(
                              c.value.isPlaying
                                  ? Icons.pause_circle
                                  : Icons.play_circle,
                              color: Colors.white,
                              size: 36,
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
                    ),
                  ),
                ],
              ),
            )
          : Stack(
              alignment: Alignment.center,
              children: [
                if (poster.isNotEmpty)
                  Positioned.fill(
                    child: IgnorePointer(
                      child: CachedNetworkImage(
                        imageUrl: poster,
                        fit: BoxFit.contain,
                        placeholder: (_, __) => const SizedBox.shrink(),
                        errorWidget: (_, __, ___) => const SizedBox.shrink(),
                      ),
                    ),
                  ),
                const AspectRatio(
                  aspectRatio: 16 / 9,
                  child: Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        CircularProgressIndicator(color: Colors.white),
                        SizedBox(height: 10),
                        Text(
                          '视频加载中…',
                          style: TextStyle(color: Colors.white70, fontSize: 14),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}

class _GalleryPendingFile {
  final File file;
  final String name;
  final bool isVideo;
  final bool isGif;
  File? liveVideo;
  int originalSize = 0;
  String? thumbPath;

  _GalleryPendingFile({
    required this.file,
    required this.name,
    required this.isVideo,
    required this.isGif,
    this.liveVideo,
  });
}

/// 上传前的预览页：单/多文件统一布局。
/// 压缩功能已停用：这里只做类型识别、首帧/缩略图预览、描述填写和上传。
class _GalleryUploadReviewPage extends StatefulWidget {
  final List<_GalleryPendingFile> files;
  const _GalleryUploadReviewPage({required this.files});

  @override
  State<_GalleryUploadReviewPage> createState() =>
      _GalleryUploadReviewPageState();
}

class _GalleryUploadReviewPageState extends State<_GalleryUploadReviewPage> {
  static const _imageMax = 104857600;
  static const _gifMax = 16777216;
  static const _videoMax = 1073741824;

  final _desc = TextEditingController();
  final _api = ApiService();
  bool _preparing = true;
  bool _uploading = false;

  bool get _single => widget.files.length == 1;

  @override
  void initState() {
    super.initState();
    _prepare();
  }

  @override
  void dispose() {
    _desc.dispose();
    super.dispose();
  }

  Future<void> _prepare() async {
    for (final p in widget.files) {
      try {
        p.originalSize = await p.file.length();
      } catch (_) {}
    }
    if (mounted) setState(() => _preparing = false);
  }

  int _maxFor(_GalleryPendingFile p) {
    if (p.isVideo) return _videoMax;
    if (p.isGif) return _gifMax;
    return _imageMax;
  }

  String _mb(int bytes) => (bytes / 1048576).toStringAsFixed(1);

  void _toast(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text)));
  }

  void _remove(int index) {
    setState(() => widget.files.removeAt(index));
    if (widget.files.isEmpty) Navigator.pop(context, false);
  }

  Future<void> _upload() async {
    if (_preparing || _uploading) return;
    if (_single && _desc.text.trim().isEmpty) {
      _toast('单文件上传请填写描述');
      return;
    }
    for (final p in widget.files) {
      if (p.originalSize > _maxFor(p)) {
        _toast('「${p.name}」超过大小上限');
        return;
      }
    }
    final classId = context.read<AuthProvider>().currentClassId;
    if (classId == null || classId.isEmpty) {
      _toast('请先选择班级');
      return;
    }
    final multi = widget.files.length > 1;
    final desc = _desc.text.trim();
    setState(() => _uploading = true);
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => const LoadingOverlay(message: '上传中...'),
    );
    try {
      for (final p in widget.files) {
        if (p.liveVideo != null) {
          await _api.saveGallery(
            classId,
            p.file,
            desc,
            liveVideo: p.liveVideo,
            multi: multi,
          );
        } else if (p.originalSize > 16 * 1024 * 1024) {
          await _api.saveGalleryChunked(
            classId,
            p.file,
            desc,
            multi: multi,
          );
        } else {
          await _api.saveGallery(
            classId,
            p.file,
            desc,
            multi: multi,
          );
        }
      }
      if (!mounted) return;
      Navigator.pop(context);
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (!mounted) return;
      Navigator.pop(context);
      _toast(e.message.isEmpty ? '上传失败' : e.message);
    } catch (_) {
      if (!mounted) return;
      Navigator.pop(context);
      _toast('网络错误，请检查网络后重试');
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  Widget _preview(_GalleryPendingFile p) {
    return Container(
      height: 92,
      decoration: BoxDecoration(
        color: AppColors.oldPaper,
        borderRadius: AppTheme.wobblySm,
        border: Border.all(color: AppColors.border, width: 1.5),
      ),
      clipBehavior: Clip.antiAlias,
      child: p.isVideo
          ? _LocalVideoThumb(file: p.file)
          : Image.file(
              p.file,
              fit: BoxFit.cover,
              width: double.infinity,
              cacheWidth: 360,
            ),
    );
  }

  Widget _fileCard(_GalleryPendingFile p, int index) {
    final type = p.liveVideo != null
        ? '实况照片'
        : (p.isVideo ? '视频' : (p.isGif ? 'GIF' : '图片'));
    return HandDrawnCard(
      padding: const EdgeInsets.all(10),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 112, child: _preview(p)),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  p.name,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: AppTheme.bodyStyle.copyWith(fontSize: 13),
                ),
                const SizedBox(height: 4),
                Text(
                  '$type · ${_mb(p.originalSize)}MB',
                  style: AppTheme.bodyStyle.copyWith(
                    fontSize: 11,
                    color: AppColors.muted,
                  ),
                ),
                if (p.originalSize > _maxFor(p))
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      '超过大小上限',
                      style: AppTheme.bodyStyle.copyWith(
                        fontSize: 11,
                        color: AppColors.red,
                      ),
                    ),
                  ),
              ],
            ),
          ),
          IconButton(
            visualDensity: VisualDensity.compact,
            tooltip: '移除',
            onPressed: _uploading ? null : () => _remove(index),
            icon: const Icon(Icons.close, size: 18),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final count = _desc.text.length;
    return Scaffold(
      appBar: AppBar(
        title: Text(_single ? '上传文件' : '上传 ${widget.files.length} 个文件'),
        backgroundColor: AppColors.background,
        foregroundColor: AppColors.pencil,
      ),
      body: PaperTexture(
        child: _preparing
            ? const Center(
                child: CircularProgressIndicator(color: AppColors.red),
              )
            : Column(
                children: [
                  Expanded(
                    child: ListView.separated(
                      padding: const EdgeInsets.all(14),
                      itemCount: widget.files.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 10),
                      itemBuilder: (_, i) => _fileCard(widget.files[i], i),
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.fromLTRB(14, 8, 14, 12),
                    decoration: BoxDecoration(
                      color: AppColors.background,
                      border: Border(
                        top: BorderSide(color: AppColors.border, width: 2),
                      ),
                    ),
                    child: SafeArea(
                      top: false,
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: [
                          Expanded(
                            child: TextField(
                              controller: _desc,
                              maxLines: 2,
                              maxLength: 500,
                              onChanged: (_) => setState(() {}),
                              decoration: InputDecoration(
                                hintText: _single
                                    ? '描述这个文件（必填）'
                                    : '多文件描述（可不填，留空显示未填写描述）',
                                counterText: '',
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Column(
                            mainAxisSize: MainAxisSize.min,
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text(
                                '$count/500',
                                style: AppTheme.bodyStyle.copyWith(
                                  fontSize: 11,
                                  color: AppColors.muted,
                                ),
                              ),
                              const SizedBox(height: 4),
                              HandDrawnButton(
                                label: _uploading ? '上传中…' : '上传',
                                onPressed: _uploading ? null : _upload,
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

/// 上传预览页的本地视频首帧（暂停，不自动播放）。
class _LocalVideoThumb extends StatefulWidget {
  final File file;
  const _LocalVideoThumb({required this.file});

  @override
  State<_LocalVideoThumb> createState() => _LocalVideoThumbState();
}

class _LocalVideoThumbState extends State<_LocalVideoThumb> {
  VideoPlayerController? _controller;

  @override
  void initState() {
    super.initState();
    final c = VideoPlayerController.file(widget.file);
    _controller = c;
    c.initialize().then((_) async {
      if (!mounted) return;
      await c.setVolume(0);
      await c.seekTo(Duration.zero);
      await c.pause();
      if (mounted) setState(() {});
    }).catchError((_) {});
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = _controller;
    if (c == null || !c.value.isInitialized) {
      return const Center(
        child: Icon(Icons.videocam_outlined, size: 34),
      );
    }
    return FittedBox(
      fit: BoxFit.cover,
      clipBehavior: Clip.hardEdge,
      child: SizedBox(
        width: c.value.size.width,
        height: c.value.size.height,
        child: Stack(
          alignment: Alignment.center,
          children: [
            VideoPlayer(c),
            const Icon(Icons.play_circle_outline,
                color: Colors.white, size: 30),
          ],
        ),
      ),
    );
  }
}
