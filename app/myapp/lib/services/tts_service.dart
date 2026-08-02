import 'package:just_audio/just_audio.dart';

class TTSService {
  static final TTSService _instance = TTSService._internal();
  factory TTSService() => _instance;
  TTSService._internal();

  final AudioPlayer _player = AudioPlayer();

  Future<void> speak(String word) async {
    await _player.stop();
    final url =
        'https://dict.youdao.com/dictvoice?audio=${Uri.encodeComponent(word)}&type=1';
    await _player.setUrl(url);
    await _player.play();
  }

  void dispose() {
    _player.dispose();
  }
}
