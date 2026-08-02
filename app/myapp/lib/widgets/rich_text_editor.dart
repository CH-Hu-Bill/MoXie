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
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _controller = QuillController.basic();
    _loadHtml(widget.initialHtml);
  }

  void _loadHtml(String html) {
    setState(() => _loading = true);
    try {
      if (html.isNotEmpty) {
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

  String getHtml() {
    try {
      final delta = _controller.document.toDelta();
      final ops = delta.toJson().cast<Map<String, dynamic>>();
      final converter = QuillDeltaToHtmlConverter(ops);
      return converter.convert();
    } catch (_) {
      return '';
    }
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
          SizedBox(
            height: widget.minHeight,
            child: QuillEditor.basic(
              controller: _controller,
              config: QuillEditorConfig(
                embedBuilders: FlutterQuillEmbeds.editorBuilders(),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
