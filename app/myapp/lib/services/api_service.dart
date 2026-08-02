import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:listenwrite/config/api_config.dart';

class ApiService {
  final String? token;

  ApiService({this.token});

  Future<Map<String, dynamic>> post(String action, Map<String, dynamic> params,
      {List<http.MultipartFile>? files}) async {
    final uri = Uri.parse(ApiConfig.baseUrl);

    if (files != null && files.isNotEmpty) {
      final request = http.MultipartRequest('POST', uri);
      request.fields['action'] = action;
      params.forEach((key, value) {
        request.fields[key] = value.toString();
      });
      request.files.addAll(files);
      if (token != null) {
        request.headers['Authorization'] = 'Bearer $token';
      }
      final streamed = await request.send().timeout(ApiConfig.requestTimeout);
      final response = await http.Response.fromStream(streamed);
      return _handleResponse(response);
    }

    final headers = <String, String>{
      'Content-Type': 'application/x-www-form-urlencoded',
    };
    if (token != null) {
      headers['Authorization'] = 'Bearer $token';
    }

    final body = <String, String>{'action': action};
    params.forEach((key, value) {
      body[key] = value.toString();
    });

    final response = await http
        .post(uri, headers: headers, body: body)
        .timeout(ApiConfig.requestTimeout);

    return _handleResponse(response);
  }

  Map<String, dynamic> _handleResponse(http.Response response) {
    if (response.statusCode == 429) {
      final retryAfter = response.headers['retry-after'] ?? '60';
      return {'success': false, 'error': '操作过于频繁，请${retryAfter}秒后再试'};
    }

    try {
      final data = json.decode(response.body) as Map<String, dynamic>;
      return data;
    } catch (_) {
      return {'success': false, 'error': '服务器响应异常'};
    }
  }
}