import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:image_picker/image_picker.dart';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:listenwrite/providers/auth_provider.dart';
import 'package:listenwrite/models/gallery_item.dart';
import 'package:listenwrite/widgets/hand_drawn_widgets.dart';
import 'package:listenwrite/theme/app_theme.dart';
import 'package:listenwrite/config/api_config.dart';

class GalleryScreen extends StatefulWidget {
  const GalleryScreen({super.key});

  @override
  State<GalleryScreen> createState() => _GalleryScreenState();
}

class _GalleryScreenState extends State<GalleryScreen> {
  List<GalleryItem> _items = [];
  bool _loading = true;
  int _page = 1;
  bool _hasMore = true;
  String? _currentClassId;
  final _scrollController = ScrollController();
  final _imagePicker = ImagePicker();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadClassId();
    });
  }

  @override
  void dispose() {
    _scrollController.dispose();
    super.dispose();
  }

  void _loadClassId() {
    final auth = context.read<AuthProvider>();
    setState(() {
      _currentClassId = auth.user?.classIds.isNotEmpty == true
          ? auth.user!.classIds.first
          : null;
    });
    _loadGallery();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
            _scrollController.position.maxScrollExtent - 200 &&
        !_loading &&
        _hasMore) {
      _loadGallery();
    }
  }

  Future<void> _loadGallery() async {
    if (_currentClassId == null || _loading) return;
    setState(() => _loading = true);
    final auth = context.read<AuthProvider>();
    try {
      final result = await auth.api.post('get_gallery', {
        'class_id': _currentClassId!,
        'page': _page.toString(),
        'per_page': '10',
      });
      if (result['success'] == true) {
        final data = result['data'] ?? {};
        final list = (data['items'] as List?)
                ?.map((j) => GalleryItem.fromJson(j))
                .toList() ??
            [];
        setState(() {
          if (_page == 1) _items = list;
          else _items.addAll(list);
          _page++;
          _hasMore = data['has_more'] ?? false;
          _loading = false;
        });
      }
    } catch (_) {
      setState(() => _loading = false);
    }
  }

  Future<void> _uploadImage() async {
    final picked = await _imagePicker.pickImage(source: ImageSource.gallery);
    if (picked == null) return;

    final description = await showDialog<String>(
      context: context,
      builder: (ctx) {
        final controller = TextEditingController();
        return AlertDialog(
          backgroundColor: HandDrawnTheme.warmPaper,
          shape: RoundedRectangleBorder(
            borderRadius: HandDrawnTheme.wobblyRadiusMd,
            side: const BorderSide(color: HandDrawnTheme.pencil, width: 2),
          ),
          title: Text(
            '添加描述',
            style: TextStyle(
              fontFamily: 'Kalam',
              fontWeight: FontWeight.w700,
              color: HandDrawnTheme.pencil,
            ),
          ),
          content: HandDrawnInput(
            label: '描述',
            hint: '请输入图片描述',
            controller: controller,
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(
                '取消',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  color: HandDrawnTheme.pencil,
                ),
              ),
            ),
            TextButton(
              onPressed: () => Navigator.pop(ctx, controller.text),
              child: Text(
                '确认',
                style: TextStyle(
                  fontFamily: 'Patrick Hand',
                  color: HandDrawnTheme.blue,
                ),
              ),
            ),
          ],
        );
      },
    );

    if (description == null || description.isEmpty) return;

    final auth = context.read<AuthProvider>();
    try {
      final uri = Uri.parse(ApiConfig.baseUrl);
      final request = http.MultipartRequest('POST', uri)
        ..fields['action'] = 'save_gallery'
        ..fields['class_id'] = _currentClassId!
        ..fields['description'] = description
        ..files.add(await http.MultipartFile.fromPath('image', picked.path));
      if (auth.user?.token != null) {
        request.headers['Authorization'] = 'Bearer ${auth.user!.token}';
      }
      final response = await request.send();
      if (response.statusCode == 200 && mounted) {
        setState(() {
          _page = 1;
          _items.clear();
        });
        _loadGallery();
      }
    } catch (_) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: const Text('上传失败'),
            backgroundColor: HandDrawnTheme.accent,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_currentClassId == null) {
      return Center(
        child: Text(
          '请先选择班级',
          style: TextStyle(
            fontFamily: 'Patrick Hand',
            fontSize: 16,
            color: HandDrawnTheme.pencil.withValues(alpha: 0.5),
          ),
        ),
      );
    }

    return Stack(
      children: [
        RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _page = 1;
              _items.clear();
            });
            await _loadGallery();
          },
          child: _loading && _items.isEmpty
              ? const Center(
                  child: CircularProgressIndicator(
                      color: HandDrawnTheme.pencil),
                )
              : _items.isEmpty
                  ? Center(
                      child: Text(
                        '画廊暂无内容',
                        style: TextStyle(
                          fontFamily: 'Patrick Hand',
                          fontSize: 16,
                          color: HandDrawnTheme.pencil
                              .withValues(alpha: 0.5),
                        ),
                      ),
                    )
                  : GridView.builder(
                      controller: _scrollController,
                      padding: const EdgeInsets.all(12),
                      gridDelegate:
                          const SliverGridDelegateWithFixedCrossAxisCount(
                        crossAxisCount: 2,
                        crossAxisSpacing: 12,
                        mainAxisSpacing: 12,
                        childAspectRatio: 0.85,
                      ),
                      itemCount: _items.length + (_hasMore ? 2 : 0),
                      itemBuilder: (context, index) {
                        if (index >= _items.length) {
                          return const Center(
                            child: CircularProgressIndicator(),
                          );
                        }
                        return _buildGalleryCard(_items[index]);
                      },
                    ),
        ),
        Positioned(
          bottom: 24,
          right: 24,
          child: HandDrawnButton(
            text: '上传',
            icon: Icons.add_a_photo,
            onPressed: _uploadImage,
          ),
        ),
      ],
    );
  }

  Widget _buildGalleryCard(GalleryItem item) {
    return HandDrawnCard(
      padding: EdgeInsets.zero,
      rotation: (item.id.hashCode % 5 - 2).toDouble(),
      child: ClipRRect(
        borderRadius: HandDrawnTheme.wobblyRadiusSm,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Expanded(
              flex: 3,
              child: Image.network(
                item.imageUrl,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => Container(
                  color: HandDrawnTheme.muted,
                  child: const Icon(Icons.broken_image,
                      color: HandDrawnTheme.pencil),
                ),
              ),
            ),
            if (item.description.isNotEmpty)
              Expanded(
                flex: 1,
                child: Padding(
                  padding: const EdgeInsets.all(8),
                  child: Text(
                    item.description,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(
                      fontFamily: 'Patrick Hand',
                      fontSize: 13,
                      color: HandDrawnTheme.pencil,
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}