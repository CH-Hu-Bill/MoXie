import 'package:flutter/material.dart';

import '../config/api_config.dart';
import '../services/storage_service.dart';

/// 首次启动 / 未配置时的「服务器端点」设置页。
///
/// 服务器已改为本地运行，用户在启动页填写自己电脑的地址（允许 HTTP+HTTPS），
/// 保存后写入 SharedPreferences 并覆盖 [ApiConfig.baseUrl]，后续默认使用该端点。
class EndpointSetupScreen extends StatefulWidget {
  final VoidCallback onConfigured;

  const EndpointSetupScreen({super.key, required this.onConfigured});

  @override
  State<EndpointSetupScreen> createState() => _EndpointSetupScreenState();
}

class _EndpointSetupScreenState extends State<EndpointSetupScreen> {
  late final TextEditingController _controller;
  String? _error;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(
      text: StorageService().getServerBaseUrl() ?? ApiConfig.baseUrl,
    );
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  /// 容错解析：自动补 scheme、去尾斜杠、去掉误填的 /app_api.php
  static String? normalize(String raw) {
    var s = raw.trim();
    if (s.isEmpty) return null;
    if (!RegExp(r'^https?://', caseSensitive: false).hasMatch(s)) {
      s = 'http://$s';
    }
    s = s.replaceAll(RegExp(r'/+$'), '');
    s = s.replaceAll(RegExp(r'/app_api\.php$', caseSensitive: false), '');
    s = s.replaceAll(RegExp(r'/+$'), '');
    return s;
  }

  Future<void> _save() async {
    final url = normalize(_controller.text);
    if (url == null) {
      setState(() => _error = '请输入服务器地址');
      return;
    }
    final uri = Uri.tryParse(url);
    if (uri == null || uri.host.isEmpty) {
      setState(() => _error = '地址格式不正确，例如 http://192.168.1.5:8000');
      return;
    }
    setState(() => _error = null);
    ApiConfig.baseUrl = url;
    await StorageService().saveServerBaseUrl(url);
    widget.onConfigured();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      '连接服务器',
                      textAlign: TextAlign.center,
                      style: Theme.of(context).textTheme.headlineSmall,
                    ),
                    const SizedBox(height: 10),
                    const Text(
                      '请填写你电脑上运行的 ListenWrite 服务地址（HTTP/HTTPS 均可）。\n例如 http://192.168.1.5:8000',
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 18),
                    TextField(
                      controller: _controller,
                      keyboardType: TextInputType.url,
                      autocorrect: false,
                      decoration: InputDecoration(
                        labelText: '服务器地址',
                        hintText: 'http://192.168.1.5:8000',
                        errorText: _error,
                        border: const OutlineInputBorder(),
                      ),
                      onSubmitted: (_) => _save(),
                    ),
                    const SizedBox(height: 18),
                    FilledButton(
                      onPressed: _save,
                      child: const Padding(
                        padding: EdgeInsets.symmetric(vertical: 6),
                        child: Text('保存并进入'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
