import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_quill/flutter_quill.dart';
import 'package:flutter_quill_extensions/flutter_quill_extensions.dart';
import 'package:flutter_quill_delta_from_html/flutter_quill_delta_from_html.dart';
import 'package:vsc_quill_delta_to_html/vsc_quill_delta_to_html.dart';
import '../theme/app_theme.dart';

class RichTextEditor extends StatefulWidget {
  final String initialHtml;
  final bool readOnly;
  final double minHeight;
  final Future<String?> Function()? onImageInsert;

  const RichTextEditor({
    super.key,
    this.initialHtml = '',
    this.readOnly = false,
    this.minHeight = 200,
    this.onImageInsert,
  });

  @override
  State<RichTextEditor> createState() => RichTextEditorState();
}

class RichTextEditorState extends State<RichTextEditor> {
  late QuillController _controller;
  late FocusNode _focusNode;
  late ScrollController _scrollController;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _controller = QuillController.basic();
    _focusNode = FocusNode();
    _scrollController = ScrollController();
    _loadHtml(widget.initialHtml);
  }

  void _loadHtml(String html) {
    setState(() => _loading = true);
    try {
      if (html.isNotEmpty) {
        html = _convertColorClassesToInline(html);
        // HtmlToDelta only parses style attributes on <span> tags.
        // vsc_quill_delta_to_html puts color on the inline tag itself
        // (e.g. <strong style="color:#ff0000">), so wrap those in <span>
        // to preserve color/background attributes.
        html = _wrapInlineTagStyles(html);
        final delta = HtmlToDelta().convert(html);
        _controller.document = Document.fromJson(delta.toJson());
      } else {
        _controller.clear();
      }
    } catch (_) {
      _controller.clear();
    }
    setState(() => _loading = false);
  }

  void setHtml(String html) => _loadHtml(html);

  String getDelta() {
    try {
      final ops =
          _controller.document.toDelta().toJson().cast<Map<String, dynamic>>();
      return jsonEncode(_normalizeColorAttrs(ops));
    } catch (_) {
      return '';
    }
  }

  List<Map<String, dynamic>> _normalizeColorAttrs(
      List<Map<String, dynamic>> ops) {
    for (final op in ops) {
      final attrs = op['attributes'];
      if (attrs is Map<String, dynamic>) {
        for (final key in ['color', 'background']) {
          final val = attrs[key];
          if (val is String && val.startsWith('#') && val.length == 9) {
            attrs[key] = '#${val.substring(3)}';
          }
        }
      }
    }
    return ops;
  }

  void setDelta(String delta) {
    setState(() => _loading = true);
    try {
      if (delta.isNotEmpty) {
        final decoded = jsonDecode(delta) as List;
        _controller.document =
            Document.fromJson(decoded.cast<Map<String, dynamic>>());
      } else {
        _controller.clear();
      }
    } catch (_) {
      _controller.clear();
    }
    setState(() => _loading = false);
  }

  String getHtml() {
    try {
      final delta = _controller.document.toDelta();
      final ops = _normalizeColorAttrs(delta.toJson().cast<Map<String, dynamic>>());
      final converter = QuillDeltaToHtmlConverter(
        ops,
        ConverterOptions(
          converterOptions: OpConverterOptions(
            inlineStylesFlag: true,
          ),
        ),
      );
      String html = converter.convert();
      html = _convertColorClassesToInline(html);
      return html;
    } catch (_) {
      return '';
    }
  }

  String _wrapInlineTagStyles(String html) {
    // 只把「带 style 的 inline 标签」整体（开标签+内容+闭标签）包进 <span>，
    // 保证 HTML 平衡；不带 style 的 inline 标签不受影响。
    return html.replaceAllMapped(
      RegExp(
        r'<(strong|b|em|i|u|ins|s|del|sub|sup)([^>]*?style="[^"]*"[^>]*)>(.*?)</\1>',
        caseSensitive: false,
        dotAll: true,
      ),
      (m) {
        final tag = m.group(1)!;
        final attrs = m.group(2)!;
        final content = m.group(3)!;
        return '<span$attrs><$tag>$content</$tag></span>';
      },
    );
  }

  String _convertColorClassesToInline(String html) {
    String result = html;
    result = result.replaceAllMapped(
      RegExp(r'(<span)([^>]*?)(\sclass=")([^"]*?)\bql-color-([a-fA-F0-9]{3,8})\b([^"]*)(")([^>]*>)'),
      (m) {
        final color = m.group(5)!;
        final before = m.group(1)!;
        final middle = m.group(2)!;
        final classOpen = m.group(3)!;
        final classBefore = m.group(4)!;
        final classAfter = m.group(6)!;
        final classClose = m.group(7)!;
        final after = m.group(8)!;
        final cleanClass = '$classBefore$classAfter'.replaceAll(RegExp(r'\s+'), ' ').trim();
        final hasStyle = middle.contains('style=') || after.contains('style=') || classBefore.contains('style=') || classAfter.contains('style=');
        if (cleanClass.isEmpty) {
          return '$before${hasStyle ? '' : ' style="color: #$color"'}$middle$after';
        }
        return '$before${hasStyle ? '' : ' style="color: #$color"'}$middle$classOpen$cleanClass$classClose$after';
      },
    );
    result = result.replaceAllMapped(
      RegExp(r'(<span)([^>]*?)(\sclass=")([^"]*?)\bql-background-([a-fA-F0-9]{3,8})\b([^"]*)(")([^>]*>)'),
      (m) {
        final color = m.group(5)!;
        final before = m.group(1)!;
        final middle = m.group(2)!;
        final classOpen = m.group(3)!;
        final classBefore = m.group(4)!;
        final classAfter = m.group(6)!;
        final classClose = m.group(7)!;
        final after = m.group(8)!;
        final cleanClass = '$classBefore$classAfter'.replaceAll(RegExp(r'\s+'), ' ').trim();
        final hasStyle = middle.contains('style=') || after.contains('style=') || classBefore.contains('style=') || classAfter.contains('style=');
        if (cleanClass.isEmpty) {
          return '$before${hasStyle ? '' : ' style="background-color: #$color"'}$middle$after';
        }
        return '$before${hasStyle ? '' : ' style="background-color: #$color"'}$middle$classOpen$cleanClass$classClose$after';
      },
    );
    return result;
  }

  void insertImage(String url) {
    final index = _controller.selection.baseOffset;
    _controller.replaceText(index, 0, BlockEmbed.image(url), null);
  }

  Future<void> _handleImageInsert() async {
    if (widget.onImageInsert == null) return;
    final url = await widget.onImageInsert!();
    if (url != null && url.isNotEmpty) {
      insertImage(url);
    }
  }

  @override
  void didUpdateWidget(RichTextEditor oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initialHtml != widget.initialHtml) {
      _loadHtml(widget.initialHtml);
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    _focusNode.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const SizedBox(
        height: 200,
        child: Center(child: CircularProgressIndicator(color: AppColors.red)),
      );
    }
    _controller.readOnly = widget.readOnly;
    return Container(
      decoration: BoxDecoration(
        color: AppColors.white,
        borderRadius: AppTheme.wobblyRadius,
        border: Border.all(color: AppColors.pencil, width: 2),
        boxShadow: AppTheme.softShadow,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          if (!widget.readOnly)
            Container(
              decoration: BoxDecoration(
                color: AppColors.paper,
                border: Border(
                  bottom: BorderSide(color: AppColors.pencil.withValues(alpha: 0.3), width: 1.5),
                ),
              ),
              child: QuillSimpleToolbar(
                controller: _controller,
                config: QuillSimpleToolbarConfig(
                  embedButtons: widget.onImageInsert != null
                      ? [
                          (context, embedContext) => IconButton(
                                icon: const Icon(Icons.image),
                                iconSize: kDefaultIconSize,
                                tooltip: '插入图片',
                                onPressed: _handleImageInsert,
                              ),
                        ]
                      : FlutterQuillEmbeds.toolbarButtons(),
                  showCodeBlock: false,
                  showInlineCode: false,
                  showSearchButton: false,
                  showSubscript: false,
                  showSuperscript: false,
                  showIndent: false,
                  showLink: false,
                ),
              ),
            ),
          // 固定视口高度 + 内部滚动：内容再多也不会把编辑器撑高（否则外层 SingleChildScrollView
          // 无法滚动到编辑器下方的保存按钮），上下叠加渐隐蒙版提示可滚动、更美观。
          SizedBox(
            height: widget.minHeight,
            child: Stack(
              children: [
                Positioned.fill(
                  child: Scrollbar(
                    controller: _scrollController,
                    thumbVisibility: true,
                    child: QuillEditor.basic(
                      controller: _controller,
                      focusNode: _focusNode,
                      scrollController: _scrollController,
                      config: QuillEditorConfig(
                        embedBuilders: FlutterQuillEmbeds.editorBuilders(),
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                        showCursor: true,
                        textSelectionThemeData: TextSelectionThemeData(
                          cursorColor: AppColors.red,
                          selectionColor: AppColors.postIt,
                        ),
                        customStyles: const DefaultStyles(
                          placeHolder: DefaultTextBlockStyle(
                            TextStyle(
                              color: Color(0xFFBDBDBD),
                              fontSize: 15,
                            ),
                            HorizontalSpacing.zero,
                            VerticalSpacing.zero,
                            VerticalSpacing.zero,
                            null,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
                // 顶部渐隐蒙版
                Positioned(
                  top: 0, left: 0, right: 0,
                  height: 14,
                  child: IgnorePointer(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.topCenter,
                          end: Alignment.bottomCenter,
                          colors: [
                            AppColors.white.withValues(alpha: 0.95),
                            AppColors.white.withValues(alpha: 0.0),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
                // 底部渐隐蒙版
                Positioned(
                  bottom: 0, left: 0, right: 0,
                  height: 14,
                  child: IgnorePointer(
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        gradient: LinearGradient(
                          begin: Alignment.bottomCenter,
                          end: Alignment.topCenter,
                          colors: [
                            AppColors.white.withValues(alpha: 0.95),
                            AppColors.white.withValues(alpha: 0.0),
                          ],
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
