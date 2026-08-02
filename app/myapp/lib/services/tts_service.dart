import 'package:audioplayers/audioplayers.dart';

class TTSService {
  static final TTSService _instance = TTSService._internal();
  factory TTSService() => _instance;
  TTSService._internal();

  final AudioPlayer _player = AudioPlayer();

  Future<void> speak(String word) async {
    await _player.stop();
    final url =
        'https://dict.youdao.com/dictvoice?audio=${Uri.encodeComponent(word)}&type=1';
    await _player.play(UrlSource(url));
  }

  void dispose() {
    _player.dispose();
  }
}
