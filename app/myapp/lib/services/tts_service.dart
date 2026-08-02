import 'dart:async';
import 'package:just_audio/just_audio.dart';

class TTSService {
  static final TTSService _instance = TTSService._internal();
  factory TTSService() => _instance;
  TTSService._internal();

  final AudioPlayer _player = AudioPlayer();
  String? _lastError;

  String? get lastError => _lastError;

  Future<bool> speak(String word) async {
    _lastError = null;
    try {
      await _player.stop();
      final url =
          'https://dict.youdao.com/dictvoice?audio=${Uri.encodeComponent(word)}&type=1';
      await _player.setUrl(url);
      final dur = await _player.duration;
      if (dur != null && dur.inMilliseconds < 100) {
        _lastError = '暂无"$word"的发音';
        return false;
      }
      await _player.play();
      return true;
    } on PlayerException catch (e) {
      _lastError = '暂无"$word"的发音';
      return false;
    } catch (e) {
      _lastError = '暂无"$word"的发音';
      return false;
    }
  }

  void dispose() {
    _player.dispose();
  }
}